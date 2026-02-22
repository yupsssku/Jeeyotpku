<?php
session_start();
error_reporting(0);

// ============================================================
// KONFIGURASI - WAJIB SESUAIKAN
// ============================================================
define('API_KEY',         'otp_YLOvdUvCMMVjlAVr');
define('API_BASE',        'https://www.rumahotp.com');
define('PROFIT_NOKOS',    500);    // Markup per order (Rp)
define('PROFIT_DEPOSIT',  500);    // Biaya admin deposit (Rp)
define('MIN_DEPOSIT',     2000);   // Minimal deposit (Rp)
define('SITE_NAME',       'JeeyOtp');
define('SITE_DESC',       'Nomor Virtual OTP 24/7');
define('DB_FILE',         __DIR__ . '/data.db');

// ============================================================
// DATABASE SQLITE
// ============================================================
try {
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            saldo INTEGER DEFAULT 0,
            is_admin INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id TEXT,
            service TEXT,
            country TEXT,
            operator TEXT,
            phone_number TEXT,
            otp_code TEXT DEFAULT '-',
            price INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending',
            expires_at INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS deposits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            deposit_id TEXT,
            amount INTEGER,
            received INTEGER DEFAULT 0,
            fee INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending',
            method TEXT DEFAULT 'QRIS',
            qr_image TEXT,
            expired_at INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// ============================================================
// HELPERS
// ============================================================
function apiGet($endpoint, $params = []) {
    $url = API_BASE . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-apikey: ' . API_KEY, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
function isLoggedIn() { return isset($_SESSION['uid']); }
function getUser() {
    global $db;
    if (!isLoggedIn()) return null;
    $s = $db->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([$_SESSION['uid']]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function rp($n) { return 'Rp ' . number_format($n, 0, ',', '.'); }
function json_out($d) { header('Content-Type: application/json'); echo json_encode($d); exit; }

// ============================================================
// AJAX HANDLER
// ============================================================
$action = $_REQUEST['action'] ?? null;
if ($action) {
    if (!isLoggedIn() && !in_array($action, ['login','register'])) {
        json_out(['ok' => false, 'msg' => 'Login dulu']);
    }
    $u = getUser();

    // --- AUTH ---
    if ($action === 'login') {
        $s = $db->prepare("SELECT * FROM users WHERE email=?");
        $s->execute([trim($_POST['email'] ?? '')]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($_POST['password'] ?? '', $row['password'])) {
            $_SESSION['uid'] = $row['id'];
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'msg' => 'Email atau password salah']);
    }
    if ($action === 'register') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        if (!$name || !$email || !$pass) json_out(['ok'=>false,'msg'=>'Semua field wajib diisi']);
        try {
            $s = $db->prepare("INSERT INTO users (name,email,password) VALUES (?,?,?)");
            $s->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $_SESSION['uid'] = $db->lastInsertId();
            json_out(['ok' => true]);
        } catch (Exception $e) {
            json_out(['ok' => false, 'msg' => 'Email sudah terdaftar']);
        }
    }
    if ($action === 'logout') { session_destroy(); json_out(['ok'=>true]); }

    // --- USER ---
    if ($action === 'me') {
        json_out(['ok'=>true,'data'=>[
            'name'    => $u['name'],
            'email'   => $u['email'],
            'saldo'   => $u['saldo'],
            'saldo_f' => rp($u['saldo']),
            'admin'   => $u['is_admin'],
            'joined'  => date('d M Y', strtotime($u['created_at'])),
        ]]);
    }

    // --- SERVICES ---
    if ($action === 'services') {
        $res = apiGet('/api/v2/services');
        if (!($res['success'] ?? false)) json_out(['ok'=>false,'msg'=>'Gagal memuat layanan']);
        json_out(['ok'=>true,'data'=>$res['data']]);
    }

    // --- COUNTRIES ---
    if ($action === 'countries') {
        $sid = $_GET['sid'] ?? '';
        if (!$sid) json_out(['ok'=>false,'msg'=>'service_id wajib']);
        $res = apiGet('/api/v2/countries', ['service_id'=>$sid]);
        if (!($res['success'] ?? false)) json_out(['ok'=>false,'msg'=>'Gagal memuat negara']);
        $list = [];
        foreach ($res['data'] as $c) {
            if (empty($c['pricelist'])) continue;
            foreach ($c['pricelist'] as &$p) {
                $p['orig_price'] = $p['price'];
                $p['price'] += PROFIT_NOKOS;
                $p['price_f'] = rp($p['price']);
            }
            $list[] = $c;
        }
        json_out(['ok'=>true,'data'=>$list]);
    }

    // --- OPERATORS ---
    if ($action === 'operators') {
        $country = $_GET['country'] ?? '';
        $pid     = $_GET['pid']     ?? '';
        $res = apiGet('/api/v2/operators', ['country'=>$country,'provider_id'=>$pid]);
        json_out(['ok'=>true,'data'=>$res['data'] ?? []]);
    }

    // --- CREATE ORDER ---
    if ($action === 'order') {
        $nid   = $_POST['nid']   ?? '';
        $pid   = $_POST['pid']   ?? '';
        $oid   = $_POST['oid']   ?? '';
        $price = intval($_POST['price'] ?? 0);
        if ($price <= 0) json_out(['ok'=>false,'msg'=>'Harga tidak valid']);
        if ($u['saldo'] < $price) json_out(['ok'=>false,'msg'=>'Saldo tidak cukup. Silakan top up.']);
        $res = apiGet('/api/v2/orders', ['number_id'=>$nid,'provider_id'=>$pid,'operator_id'=>$oid]);
        if (!($res['success'] ?? false) || !($res['data'] ?? null)) {
            json_out(['ok'=>false,'msg'=>'Gagal order. Stok habis, coba provider lain.']);
        }
        $d = $res['data'];
        $db->prepare("UPDATE users SET saldo=saldo-? WHERE id=?")->execute([$price, $u['id']]);
        $exp = time() + ($d['expires_in_minute'] * 60);
        $db->prepare("INSERT INTO orders (user_id,order_id,service,country,operator,phone_number,price,status,expires_at) VALUES (?,?,?,?,?,?,?,'pending',?)")
           ->execute([$u['id'],$d['order_id'],$d['service'],$d['country'],$d['operator'],$d['phone_number'],$price,$exp]);
        json_out(['ok'=>true,'data'=>[
            'order_id'   => $d['order_id'],
            'phone'      => $d['phone_number'],
            'service'    => $d['service'],
            'country'    => $d['country'],
            'operator'   => $d['operator'],
            'price_f'    => rp($price),
            'status'     => $d['status'] ?? 'pending',
            'expires'    => $d['expires_in_minute'],
            'saldo_f'    => rp($u['saldo'] - $price),
        ]]);
    }

    // --- CHECK OTP ---
    if ($action === 'check_otp') {
        $oid = $_GET['oid'] ?? '';
        $res = apiGet('/api/v1/orders/get_status', ['order_id'=>$oid]);
        if (!($res['data'] ?? null)) json_out(['ok'=>false,'msg'=>'Order tidak ditemukan']);
        $d = $res['data'];
        $otp = ($d['otp_code'] && $d['otp_code'] !== '-') ? $d['otp_code'] : null;
        if ($otp) {
            $db->prepare("UPDATE orders SET otp_code=?,status='completed' WHERE order_id=?")->execute([$otp,$oid]);
        }
        json_out(['ok'=>true,'otp'=>$otp,'status'=>$d['status'],'phone'=>$d['phone_number']]);
    }

    // --- CANCEL ORDER ---
    if ($action === 'cancel_order') {
        $oid = $_POST['oid'] ?? '';
        $s = $db->prepare("SELECT * FROM orders WHERE order_id=? AND user_id=?");
        $s->execute([$oid, $u['id']]);
        $ord = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ord) json_out(['ok'=>false,'msg'=>'Order tidak ditemukan']);
        $res = apiGet('/api/v1/orders/set_status', ['order_id'=>$oid,'status'=>'cancel']);
        if ($res['success'] ?? false) {
            $db->prepare("UPDATE users SET saldo=saldo+? WHERE id=?")->execute([$ord['price'],$u['id']]);
            $db->prepare("UPDATE orders SET status='cancelled' WHERE order_id=?")->execute([$oid]);
            json_out(['ok'=>true,'refund'=>rp($ord['price']),'saldo'=>rp($u['saldo']+$ord['price'])]);
        }
        json_out(['ok'=>false,'msg'=>'Gagal cancel order']);
    }

    // --- ORDER HISTORY ---
    if ($action === 'history') {
        $s = $db->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
        $s->execute([$u['id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['price_f'] = rp($r['price']); }
        json_out(['ok'=>true,'data'=>$rows]);
    }

    // --- CREATE DEPOSIT ---
    if ($action === 'deposit') {
        $amt = intval($_POST['amount'] ?? 0);
        if ($amt < MIN_DEPOSIT) json_out(['ok'=>false,'msg'=>'Minimal deposit '.rp(MIN_DEPOSIT)]);
        $s = $db->prepare("SELECT id FROM deposits WHERE user_id=? AND status='pending' AND expired_at>?");
        $s->execute([$u['id'], time()]);
        if ($s->fetch()) json_out(['ok'=>false,'msg'=>'Kamu masih punya tagihan yang belum dibayar']);
        $total = $amt + PROFIT_DEPOSIT;
        $res = apiGet('/api/v2/deposit/create', ['amount'=>$total,'payment_id'=>'qris']);
        if (!($res['success'] ?? false) || !($res['data'] ?? null)) {
            json_out(['ok'=>false,'msg'=>'Gagal membuat QRIS. Server sedang maintenance.']);
        }
        $d = $res['data'];
        $fee = $d['total'] - $amt;
        $exp = (int)($d['expired_at_ts'] / 1000);
        $db->prepare("INSERT INTO deposits (user_id,deposit_id,amount,received,fee,status,method,qr_image,expired_at) VALUES (?,?,?,?,?,'pending','QRIS',?,?)")
           ->execute([$u['id'],$d['id'],$d['total'],$amt,$fee,$d['qr_image'],$exp]);
        json_out(['ok'=>true,'data'=>[
            'dep_id'  => $d['id'],
            'total'   => $d['total'],
            'total_f' => rp($d['total']),
            'recv_f'  => rp($amt),
            'fee_f'   => rp($fee),
            'qr'      => $d['qr_image'],
            'exp'     => $exp,
        ]]);
    }

    // --- CHECK DEPOSIT ---
    if ($action === 'check_dep') {
        $did = $_GET['did'] ?? '';
        $res = apiGet('/api/v2/deposit/get_status', ['deposit_id'=>$did]);
        if (!($res['data'] ?? null)) json_out(['ok'=>false,'msg'=>'Deposit tidak ditemukan']);
        $d = $res['data'];
        if ($d['status'] === 'success') {
            $s = $db->prepare("SELECT * FROM deposits WHERE deposit_id=? AND user_id=? AND status='pending'");
            $s->execute([$did, $u['id']]);
            $dep = $s->fetch(PDO::FETCH_ASSOC);
            if ($dep) {
                $db->prepare("UPDATE users SET saldo=saldo+? WHERE id=?")->execute([$dep['received'],$u['id']]);
                $db->prepare("UPDATE deposits SET status='success',method=? WHERE deposit_id=?")
                   ->execute([$d['brand_name'] ?? 'QRIS', $did]);
                json_out(['ok'=>true,'status'=>'success','recv_f'=>rp($dep['received']),'saldo_f'=>rp($u['saldo']+$dep['received'])]);
            }
        }
        json_out(['ok'=>true,'status'=>$d['status']]);
    }

    // --- CANCEL DEPOSIT ---
    if ($action === 'cancel_dep') {
        $did = $_POST['did'] ?? '';
        $res = apiGet('/api/v1/deposit/cancel', ['deposit_id'=>$did]);
        if ($res['success'] ?? false) {
            $db->prepare("UPDATE deposits SET status='cancelled' WHERE deposit_id=? AND user_id=?")->execute([$did,$u['id']]);
            json_out(['ok'=>true]);
        }
        json_out(['ok'=>false,'msg'=>'Gagal cancel deposit']);
    }

    // --- DEPOSIT HISTORY ---
    if ($action === 'dep_history') {
        $s = $db->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
        $s->execute([$u['id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['amount_f'] = rp($r['amount']); $r['recv_f'] = rp($r['received']); }
        json_out(['ok'=>true,'data'=>$rows]);
    }

    // --- USER BALANCE API ---
    if ($action === 'api_balance') {
        $res = apiGet('/api/v1/user/balance');
        json_out(['ok'=>true,'data'=>$res['data'] ?? null]);
    }

    json_out(['ok'=>false,'msg'=>'Action tidak dikenal']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title><?= SITE_NAME ?> - <?= SITE_DESC ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#080f1f;--bg2:#0d1626;--card:#111827;--card2:#1a2235;
  --border:#1f2d42;--blue:#3b82f6;--blue2:#2563eb;--blue-glow:rgba(59,130,246,.25);
  --green:#22c55e;--red:#ef4444;--yellow:#f59e0b;
  --text:#f1f5f9;--text2:#94a3b8;--text3:#64748b;
  --radius:14px;--radius-sm:10px;--nav-h:70px;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;font-size:15px;overflow-x:hidden}
.page{display:none;min-height:calc(100vh - var(--nav-h));padding:0 0 var(--nav-h)}
.page.active{display:block}

/* ========== AUTH ========== */
#auth-page{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg);padding:20px}
.auth-box{width:100%;max-width:420px}
.auth-logo{text-align:center;margin-bottom:32px}
.auth-logo .logo-icon{width:64px;height:64px;background:linear-gradient(135deg,var(--blue),#6366f1);border-radius:18px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:12px;box-shadow:0 0 30px var(--blue-glow)}
.auth-logo h1{font-size:24px;font-weight:700;color:var(--text)}
.auth-logo p{color:var(--text2);font-size:14px;margin-top:4px}
.auth-card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:28px 24px}
.auth-tabs{display:flex;background:var(--bg2);border-radius:var(--radius-sm);padding:4px;margin-bottom:24px;gap:4px}
.auth-tab{flex:1;padding:9px;text-align:center;border-radius:8px;cursor:pointer;font-size:14px;font-weight:500;color:var(--text2);transition:.2s}
.auth-tab.active{background:var(--blue);color:#fff}
.field{margin-bottom:16px}
.field label{display:block;font-size:13px;color:var(--text2);margin-bottom:6px;font-weight:500}
.field input{width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 14px;color:var(--text);font-size:15px;outline:none;transition:.2s}
.field input:focus{border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-glow)}
.btn{width:100%;padding:13px;background:var(--blue);color:#fff;border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:600;cursor:pointer;transition:.15s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn:hover{background:var(--blue2)}
.btn:active{transform:scale(.98)}
.btn.outline{background:transparent;border:1px solid var(--border);color:var(--text2)}
.btn.red{background:var(--red)}
.btn.green{background:var(--green)}
.btn.sm{padding:8px 16px;width:auto;font-size:13px;border-radius:8px}
.err-msg{color:var(--red);font-size:13px;margin-top:8px;text-align:center;display:none}

/* ========== BOTTOM NAV ========== */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;height:var(--nav-h);background:var(--card);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-around;z-index:100;padding:0 8px}
.nav-item{display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 16px;cursor:pointer;color:var(--text3);transition:.2s;border-radius:12px;position:relative}
.nav-item.active{color:var(--blue)}
.nav-item.active::after{content:'';position:absolute;bottom:-4px;left:50%;transform:translateX(-50%);width:4px;height:4px;background:var(--blue);border-radius:50%}
.nav-item svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.nav-item span{font-size:11px;font-weight:500}
.nav-center{width:52px;height:52px;background:linear-gradient(135deg,var(--blue),#6366f1);border-radius:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px var(--blue-glow);cursor:pointer;margin-top:-10px}
.nav-center svg{width:24px;height:24px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ========== HEADER ========== */
.app-header{display:flex;align-items:center;justify-content:space-between;padding:16px 16px 0;position:sticky;top:0;background:var(--bg);z-index:50;padding-bottom:12px}
.app-header .logo{display:flex;align-items:center;gap:10px}
.app-header .logo .icon{width:36px;height:36px;background:linear-gradient(135deg,var(--blue),#6366f1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
.app-header .logo h2{font-size:18px;font-weight:700}
.header-actions{display:flex;gap:10px}
.icon-btn{width:38px;height:38px;background:var(--card);border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);transition:.2s}
.icon-btn:hover{background:var(--card2)}
.icon-btn svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}

/* ========== CARDS ========== */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:12px}
.card.blue-card{background:linear-gradient(135deg,#1d3a6e,#1e3a8a);border-color:#2563eb55}

/* ========== DASHBOARD ========== */
.section-title{font-size:16px;font-weight:700;color:var(--text);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between}
.section-title a{font-size:13px;color:var(--blue);font-weight:500;cursor:pointer;text-decoration:none}
.saldo-card{background:linear-gradient(135deg,#1a2d5a,#1e3a8a);border:1px solid #3b82f633;border-radius:var(--radius);padding:20px 16px;margin-bottom:16px}
.saldo-label{font-size:13px;color:#94a3b8;margin-bottom:6px}
.saldo-amount{font-size:30px;font-weight:700;color:#fff;letter-spacing:-0.5px}
.saldo-row{display:flex;align-items:center;justify-content:space-between;margin-top:12px}
.saldo-status{display:flex;align-items:center;gap:6px;font-size:13px;color:#94a3b8}
.dot-green{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.topup-btn{background:rgba(59,130,246,.2);border:1px solid rgba(59,130,246,.4);color:var(--blue);padding:7px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:.2s}
.topup-btn:hover{background:rgba(59,130,246,.3)}

.banner-scroll{display:flex;gap:12px;overflow-x:auto;padding:0 16px 8px;margin:0 -16px;scrollbar-width:none}
.banner-scroll::-webkit-scrollbar{display:none}
.banner-item{min-width:200px;height:110px;border-radius:var(--radius);overflow:hidden;background:linear-gradient(135deg,#1a3a6e,#2563eb);display:flex;align-items:center;padding:16px;gap:12px;flex-shrink:0;border:1px solid #3b82f622}
.banner-item .bi{font-size:32px}
.banner-item .bt{font-size:13px;font-weight:600;color:#fff;line-height:1.4}
.banner-item .bs{font-size:11px;color:#94a3b8;margin-top:3px}

.popular-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:4px}
.app-icon{display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;padding:6px;border-radius:10px;transition:.2s}
.app-icon:hover{background:var(--card2)}
.app-icon:active{transform:scale(.95)}
.app-icon .icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;border:1px solid var(--border)}
.app-icon span{font-size:11px;color:var(--text2);text-align:center;max-width:60px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.pending-empty{text-align:center;padding:24px;color:var(--text3);font-size:14px}
.pending-empty div{font-size:32px;margin-bottom:8px}
.order-card{background:var(--card2);border-radius:var(--radius-sm);padding:14px;margin-bottom:10px;border:1px solid var(--border)}
.order-card .oc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.order-card .oc-service{font-weight:600;font-size:15px}
.status-badge{font-size:11px;padding:3px 10px;border-radius:20px;font-weight:600}
.status-badge.pending{background:rgba(245,158,11,.15);color:var(--yellow)}
.status-badge.completed{background:rgba(34,197,94,.15);color:var(--green)}
.status-badge.cancelled{background:rgba(239,68,68,.15);color:var(--red)}
.order-card .oc-num{font-family:monospace;font-size:16px;font-weight:700;color:var(--blue);margin:6px 0}
.order-card .oc-otp{font-size:22px;font-weight:700;color:var(--green);font-family:monospace;letter-spacing:2px}
.order-card .oc-info{font-size:12px;color:var(--text3);display:flex;gap:12px;flex-wrap:wrap}
.order-btns{display:flex;gap:8px;margin-top:10px}

/* ========== ORDER PAGE ========== */
.search-bar{position:relative;margin:0 16px 16px}
.search-bar input{width:100%;background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 14px 12px 42px;color:var(--text);font-size:15px;outline:none;transition:.2s}
.search-bar input:focus{border-color:var(--blue)}
.search-bar .si{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text3)}
.search-bar .si svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.all-apps-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;padding:0 16px 16px}
.loading-state{text-align:center;padding:40px 16px;color:var(--text3)}
.loading-spin{width:36px;height:36px;border:3px solid var(--border);border-top-color:var(--blue);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}

/* ========== MODAL / SHEET ========== */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;display:none;align-items:flex-end;justify-content:center;backdrop-filter:blur(4px)}
.overlay.active{display:flex}
.sheet{background:var(--card);border-radius:20px 20px 0 0;width:100%;max-width:480px;max-height:85vh;overflow-y:auto;padding:0 16px 24px;animation:slideUp .25s ease}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.sheet-handle{width:40px;height:4px;background:var(--border);border-radius:2px;margin:12px auto 16px}
.sheet-title{font-size:17px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between}
.sheet-title .close-btn{width:32px;height:32px;background:var(--bg2);border:none;border-radius:8px;cursor:pointer;color:var(--text2);display:flex;align-items:center;justify-content:center}
.country-item{display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--card2);border-radius:10px;margin-bottom:8px;cursor:pointer;border:1px solid transparent;transition:.2s}
.country-item:hover{border-color:var(--blue);background:rgba(59,130,246,.05)}
.country-item .ci-left{display:flex;align-items:center;gap:10px}
.country-item .ci-flag{font-size:22px}
.country-item .ci-info{flex:1}
.country-item .ci-name{font-size:14px;font-weight:600}
.country-item .ci-sub{font-size:12px;color:var(--text3)}
.country-item .ci-price{font-size:14px;font-weight:700;color:var(--blue)}
.price-item{padding:12px;background:var(--card2);border-radius:10px;margin-bottom:8px;cursor:pointer;border:1px solid transparent;transition:.2s;display:flex;justify-content:space-between;align-items:center}
.price-item:hover{border-color:var(--blue)}
.price-item .pi-left .pi-price{font-size:17px;font-weight:700;color:var(--blue)}
.price-item .pi-left .pi-meta{font-size:12px;color:var(--text3);margin-top:2px}
.price-item .pi-stock{font-size:12px;background:rgba(34,197,94,.1);color:var(--green);padding:4px 10px;border-radius:20px}
.op-item{padding:12px;background:var(--card2);border-radius:10px;margin-bottom:8px;cursor:pointer;border:1px solid transparent;transition:.2s;display:flex;align-items:center;gap:10px}
.op-item:hover{border-color:var(--blue)}
.op-item .op-dot{width:10px;height:10px;background:var(--green);border-radius:50%;flex-shrink:0}
.confirm-detail{background:var(--bg2);border-radius:12px;padding:14px;margin-bottom:16px}
.confirm-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:14px}
.confirm-row:last-child{border:none;font-weight:700;font-size:16px;color:var(--blue)}
.confirm-row .cr-label{color:var(--text2)}

/* ========== ACTIVE ORDER (after purchase) ========== */
.active-order-box{background:var(--card2);border-radius:var(--radius);padding:20px;text-align:center;border:1px solid var(--border)}
.aob-phone{font-size:26px;font-weight:700;font-family:monospace;color:var(--text);margin:12px 0;word-break:break-all}
.aob-otp{font-size:36px;font-weight:700;font-family:monospace;color:var(--green);margin:12px 0;letter-spacing:4px}
.aob-wait{display:flex;align-items:center;justify-content:center;gap:8px;color:var(--text3);font-size:14px;margin-bottom:16px}
.aob-timer{font-size:13px;color:var(--yellow);margin-top:6px}
.copy-btn{background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:var(--blue);padding:8px 18px;border-radius:8px;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;margin-top:8px}

/* ========== DEPOSIT PAGE ========== */
.dep-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:12px}
.dep-icon{width:48px;height:48px;background:rgba(59,130,246,.15);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:12px}
.dep-card h3{font-size:16px;font-weight:700}
.dep-card p{font-size:13px;color:var(--text3);margin-top:3px}
.amount-input{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;display:flex;align-items:center;gap:8px;margin:14px 0}
.amount-input span{font-size:16px;font-weight:700;color:var(--text2)}
.amount-input input{background:transparent;border:none;outline:none;color:var(--text);font-size:22px;font-weight:700;flex:1;width:100%}
.amount-input input::placeholder{color:var(--text3)}
.dep-hint{font-size:12px;color:var(--text3);background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.15);border-radius:8px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start;margin-bottom:14px}
.qr-box{text-align:center;padding:16px 0}
.qr-box img{max-width:240px;border-radius:12px;margin:0 auto;display:block;background:#fff;padding:8px}
.qr-id{font-size:13px;color:var(--text3);margin-top:8px}
.qr-timer{font-size:15px;font-weight:600;color:var(--yellow);margin-top:6px}
.dep-history-item{background:var(--card2);border-radius:10px;padding:12px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between}
.dhi-left .dhi-id{font-size:12px;color:var(--text3);margin-top:2px}
.dhi-amount{font-size:15px;font-weight:700}

/* ========== ACTIVITY PAGE ========== */
.activity-filter{display:flex;gap:8px;padding:0 16px 12px;overflow-x:auto;scrollbar-width:none}
.filter-btn{padding:7px 16px;border-radius:20px;font-size:13px;font-weight:500;cursor:pointer;background:var(--card);border:1px solid var(--border);color:var(--text2);white-space:nowrap;transition:.2s}
.filter-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}
.activity-item{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin:0 16px 10px;display:flex;align-items:center;gap:12px}
.ai-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.ai-icon.order{background:rgba(59,130,246,.15)}
.ai-icon.deposit{background:rgba(34,197,94,.15)}
.ai-info{flex:1}
.ai-service{font-size:14px;font-weight:600}
.ai-sub{font-size:12px;color:var(--text3);margin-top:2px}
.ai-price{font-size:15px;font-weight:700;text-align:right}
.ai-price.green{color:var(--green)}
.ai-price.blue{color:var(--blue)}

/* ========== PROFILE PAGE ========== */
.profile-hero{background:linear-gradient(135deg,#1a2d5a,#1e3a8a);border-radius:var(--radius);padding:20px;margin:0 16px 12px;border:1px solid #3b82f633;display:flex;align-items:center;gap:14px}
.profile-avatar{width:60px;height:60px;border-radius:16px;background:linear-gradient(135deg,var(--blue),#6366f1);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:#fff;flex-shrink:0}
.profile-info h3{font-size:17px;font-weight:700}
.profile-info p{font-size:13px;color:#94a3b8;margin-top:2px}
.profile-info .joined{font-size:12px;color:#64748b;margin-top:4px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0 16px 12px}
.stat-box{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center}
.stat-box .sv{font-size:18px;font-weight:700;color:var(--blue)}
.stat-box .sl{font-size:11px;color:var(--text3);margin-top:4px}
.menu-group{margin:0 16px 12px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.menu-group h4{font-size:12px;color:var(--text3);padding:12px 14px 8px;text-transform:uppercase;letter-spacing:.5px}
.menu-item{display:flex;align-items:center;gap:12px;padding:13px 14px;border-top:1px solid var(--border);cursor:pointer;transition:.15s}
.menu-item:hover{background:var(--card2)}
.menu-item .mi-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.menu-item .mi-info{flex:1}
.menu-item .mi-label{font-size:14px;font-weight:500}
.menu-item .mi-sub{font-size:12px;color:var(--text3);margin-top:2px}
.menu-item .mi-badge{font-size:12px;background:var(--card2);border:1px solid var(--border);padding:3px 10px;border-radius:20px;color:var(--text2)}
.menu-item .mi-badge.red{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.2);color:var(--red)}
.menu-item .chevron{color:var(--text3)}
.menu-item .chevron svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ========== TOAST ========== */
.toast{position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-80px);background:#1e293b;border:1px solid var(--border);border-radius:12px;padding:12px 20px;font-size:14px;color:var(--text);z-index:999;transition:transform .3s cubic-bezier(.34,1.56,.64,1);white-space:nowrap;max-width:90vw;text-align:center}
.toast.show{transform:translateX(-50%) translateY(0)}
.toast.success{border-color:rgba(34,197,94,.4);color:var(--green)}
.toast.error{border-color:rgba(239,68,68,.4);color:var(--red)}

/* ========== UTILS ========== */
.px-16{padding-left:16px;padding-right:16px}
.mb-12{margin-bottom:12px}
.text-center{text-align:center}
.flex-between{display:flex;align-items:center;justify-content:space-between}
.text-blue{color:var(--blue)}
.text-green{color:var(--green)}
.text-red{color:var(--red)}
.text-gray{color:var(--text3)}
.hidden{display:none}
.pt-16{padding-top:16px}
.mt-16{margin-top:16px}
.gap-8{gap:8px}
.fw7{font-weight:700}
hr.divider{border:none;border-top:1px solid var(--border);margin:12px 0}
</style>
</head>
<body>

<!-- ====== AUTH ====== -->
<div id="auth-page" class="<?= isLoggedIn() ? 'hidden' : '' ?>">
  <div class="auth-box">
    <div class="auth-logo">
      <div class="logo-icon">💳</div>
      <h1><?= SITE_NAME ?></h1>
      <p><?= SITE_DESC ?></p>
    </div>
    <div class="auth-card">
      <div class="auth-tabs">
        <div class="auth-tab active" onclick="switchTab('login')">Masuk</div>
        <div class="auth-tab" onclick="switchTab('register')">Daftar</div>
      </div>
      <!-- Login -->
      <div id="tab-login">
        <div class="field"><label>Email</label><input type="email" id="l-email" placeholder="email@anda.com"></div>
        <div class="field"><label>Password</label><input type="password" id="l-pass" placeholder="Password" onkeypress="if(event.key==='Enter')doLogin()"></div>
        <div class="err-msg" id="l-err"></div>
        <button class="btn mt-16" onclick="doLogin()">
          <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:none;stroke:#fff;stroke-width:2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Masuk Sekarang
        </button>
      </div>
      <!-- Register -->
      <div id="tab-register" class="hidden">
        <div class="field"><label>Nama Lengkap</label><input type="text" id="r-name" placeholder="Nama kamu"></div>
        <div class="field"><label>Email</label><input type="email" id="r-email" placeholder="email@anda.com"></div>
        <div class="field"><label>Password</label><input type="password" id="r-pass" placeholder="Min. 8 karakter" onkeypress="if(event.key==='Enter')doRegister()"></div>
        <div class="err-msg" id="r-err"></div>
        <button class="btn mt-16" onclick="doRegister()">
          <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:none;stroke:#fff;stroke-width:2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
          Buat Akun
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ====== MAIN APP ====== -->
<div id="app" class="<?= !isLoggedIn() ? 'hidden' : '' ?>">

  <!-- HOME PAGE -->
  <div id="pg-home" class="page active">
    <div class="app-header">
      <div class="logo">
        <div class="icon">💳</div>
        <h2><?= SITE_NAME ?></h2>
      </div>
      <div class="header-actions">
        <div class="icon-btn" onclick="loadHome()">
          <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>
        </div>
        <div class="icon-btn" onclick="goPage('pg-profile')">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
      </div>
    </div>
    <div style="padding:0 16px">
      <!-- Saldo -->
      <div class="saldo-card">
        <div class="saldo-label">Saldo Kamu</div>
        <div class="saldo-amount" id="home-saldo">Rp 0</div>
        <div class="saldo-row">
          <div class="saldo-status"><div class="dot-green"></div><span id="server-ping">Online</span></div>
          <button class="topup-btn" onclick="goPage('pg-deposit')">Top Up</button>
        </div>
      </div>
    </div>
    <!-- Banner -->
    <div class="banner-scroll">
      <div class="banner-item">
        <div class="bi">📱</div>
        <div><div class="bt">1,038+ Aplikasi<br>Tersedia</div><div class="bs">OTP Real-Time</div></div>
      </div>
      <div class="banner-item">
        <div class="bi">🌍</div>
        <div><div class="bt">193+ Negara<br>Didukung</div><div class="bs">Semua Provider</div></div>
      </div>
      <div class="banner-item">
        <div class="bi">⚡</div>
        <div><div class="bt">Auto Refund<br>Jika Gagal</div><div class="bs">100% Aman</div></div>
      </div>
    </div>
    <div style="padding:16px 16px 0">
      <!-- Popular -->
      <div class="section-title">🔥 Lagi Populer <a onclick="goPage('pg-order')">Lihat Semua</a></div>
      <div class="popular-grid" id="popular-grid">
        <div class="app-icon" onclick="startOrder('6','WhatsApp')"><div class="icon" style="background:#25d36622">💬</div><span>WhatsApp</span></div>
        <div class="app-icon" onclick="startOrder('1','Telegram')"><div class="icon" style="background:#2ca5e022">✈️</div><span>Telegram</span></div>
        <div class="app-icon" onclick="startOrder('3','Instagram')"><div class="icon" style="background:#e1306c22">📸</div><span>Instagram</span></div>
        <div class="app-icon" onclick="startOrder('4','Facebook')"><div class="icon" style="background:#1877f222">👤</div><span>Facebook</span></div>
        <div class="app-icon" onclick="startOrder('9','Tiktok')"><div class="icon" style="background:#00000044">🎵</div><span>TikTok</span></div>
        <div class="app-icon" onclick="startOrder('11','Shopee')"><div class="icon" style="background:#ee4d2d22">🛒</div><span>Shopee</span></div>
        <div class="app-icon" onclick="startOrder('17','Gojek')"><div class="icon" style="background:#00880022">🛵</div><span>Gojek</span></div>
        <div class="app-icon" onclick="goPage('pg-order')"><div class="icon" style="background:#3b82f622">➕</div><span>Semua</span></div>
      </div>
      <!-- Pesanan Pending -->
      <div class="section-title mt-16">📦 Pesanan Pending <a onclick="goPage('pg-activity')">Lihat Semua</a></div>
      <div id="home-pending">
        <div class="pending-empty"><div>📭</div>Tidak ada pesanan aktif<br><span style="font-size:12px">Pesanan aktif akan muncul di sini</span></div>
      </div>
    </div>
  </div>

  <!-- ORDER PAGE -->
  <div id="pg-order" class="page">
    <div class="app-header">
      <div class="logo"><div class="icon">📱</div><h2>Beli Nomor</h2></div>
      <div class="header-actions">
        <div class="icon-btn" onclick="goPage('pg-home')">
          <svg viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </div>
      </div>
    </div>
    <div class="search-bar">
      <div class="si"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
      <input type="text" id="srv-search" placeholder="Cari layanan..." oninput="filterServices()">
    </div>
    <div id="all-services" class="all-apps-grid">
      <div class="loading-state" style="grid-column:1/-1">
        <div class="loading-spin"></div>Memuat layanan...
      </div>
    </div>
  </div>

  <!-- DEPOSIT PAGE -->
  <div id="pg-deposit" class="page">
    <div class="app-header">
      <div class="logo"><div class="icon">💰</div><h2>Isi Saldo</h2></div>
    </div>
    <div style="padding:0 16px">
      <div class="dep-card">
        <div class="dep-icon">🏦</div>
        <h3>Topup QRIS Instant</h3>
        <p>Otomatis masuk dalam hitungan menit</p>
        <div class="amount-input">
          <span>Rp</span>
          <input type="number" id="dep-amount" placeholder="0" min="2000">
        </div>
        <div class="dep-hint">
          <span>ℹ️</span>
          <span>Minimal deposit <strong>Rp <?= number_format(MIN_DEPOSIT,0,',','.') ?></strong>. Biaya admin Rp <?= number_format(PROFIT_DEPOSIT,0,',','.') ?> berlaku.</span>
        </div>
        <button class="btn" onclick="createDeposit()">
          <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:none;stroke:#fff;stroke-width:2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          Buat Tagihan QRIS
        </button>
      </div>
      <!-- Riwayat deposit -->
      <div class="section-title mt-16">🕐 Riwayat Terakhir</div>
      <div id="dep-history">
        <div class="pending-empty"><div>📄</div>Belum ada riwayat deposit</div>
      </div>
    </div>
  </div>

  <!-- ACTIVITY PAGE -->
  <div id="pg-activity" class="page">
    <div class="app-header">
      <div class="logo"><div class="icon">📊</div><h2>Aktivitas</h2></div>
    </div>
    <div class="activity-filter">
      <button class="filter-btn active" onclick="filterAct('all',this)">Semua</button>
      <button class="filter-btn" onclick="filterAct('pending',this)">Pending</button>
      <button class="filter-btn" onclick="filterAct('completed',this)">Selesai</button>
      <button class="filter-btn" onclick="filterAct('cancelled',this)">Dibatalkan</button>
    </div>
    <div id="act-list">
      <div class="loading-state"><div class="loading-spin"></div>Memuat...</div>
    </div>
  </div>

  <!-- PROFILE PAGE -->
  <div id="pg-profile" class="page">
    <div class="app-header">
      <div class="logo"><div class="icon">👤</div><h2>Profil Saya</h2></div>
    </div>
    <div class="profile-hero">
      <div class="profile-avatar" id="pf-avatar">J</div>
      <div class="profile-info">
        <h3 id="pf-name">...</h3>
        <p id="pf-email">...</p>
        <div class="joined" id="pf-joined">...</div>
      </div>
    </div>
    <div class="stats-row">
      <div class="stat-box"><div class="sv" id="pf-saldo">Rp 0</div><div class="sl">Saldo</div></div>
      <div class="stat-box"><div class="sv" id="pf-orders">0x</div><div class="sl">Total Order</div></div>
      <div class="stat-box"><div class="sv" id="pf-deps">0x</div><div class="sl">Deposit</div></div>
    </div>
    <div class="menu-group">
      <h4>Akun</h4>
      <div class="menu-item" onclick="goPage('pg-deposit')">
        <div class="mi-icon" style="background:rgba(34,197,94,.15)">💰</div>
        <div class="mi-info"><div class="mi-label">Isi Saldo</div><div class="mi-sub">Top up via QRIS</div></div>
        <div class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></div>
      </div>
      <div class="menu-item" onclick="goPage('pg-activity')">
        <div class="mi-icon" style="background:rgba(59,130,246,.15)">📋</div>
        <div class="mi-info"><div class="mi-label">Riwayat Order</div><div class="mi-sub">Semua transaksi kamu</div></div>
        <div class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></div>
      </div>
    </div>
    <div class="menu-group">
      <h4>Developer</h4>
      <div class="menu-item" onclick="showApiDocs()">
        <div class="mi-icon" style="background:rgba(168,85,247,.15)">⚙️</div>
        <div class="mi-info"><div class="mi-label">Dokumentasi API</div><div class="mi-sub">Endpoint & panduan integrasi</div></div>
        <div class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></div>
      </div>
    </div>
    <div class="menu-group">
      <h4>Lainnya</h4>
      <div class="menu-item" onclick="doLogout()">
        <div class="mi-icon" style="background:rgba(239,68,68,.15)">🚪</div>
        <div class="mi-info"><div class="mi-label" style="color:var(--red)">Keluar Akun</div><div class="mi-sub">Logout dari sesi ini</div></div>
      </div>
    </div>
  </div>

  <!-- BOTTOM NAV -->
  <nav class="bottom-nav">
    <div class="nav-item active" id="nav-home" onclick="goPage('pg-home')">
      <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      <span>Home</span>
    </div>
    <div class="nav-item" id="nav-deposit" onclick="goPage('pg-deposit')">
      <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      <span>Deposit</span>
    </div>
    <div class="nav-center" onclick="goPage('pg-order')">
      <svg viewBox="0 0 24 24" style="width:26px;height:26px"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
    </div>
    <div class="nav-item" id="nav-activity" onclick="goPage('pg-activity')">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span>Aktivitas</span>
    </div>
    <div class="nav-item" id="nav-profile" onclick="goPage('pg-profile')">
      <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      <span>Profil</span>
    </div>
  </nav>
</div>

<!-- ====== SHEETS / MODALS ====== -->

<!-- Country Sheet -->
<div class="overlay" id="sheet-country" onclick="closeSheet('sheet-country')">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>
    <div class="sheet-title">
      <span>🌍 Pilih Negara</span>
      <button class="close-btn" onclick="closeSheet('sheet-country')">✕</button>
    </div>
    <div id="country-list"><div class="loading-state"><div class="loading-spin"></div>Memuat negara...</div></div>
  </div>
</div>

<!-- Price Sheet -->
<div class="overlay" id="sheet-price" onclick="closeSheet('sheet-price')">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>
    <div class="sheet-title">
      <span>💰 Pilih Harga</span>
      <button class="close-btn" onclick="closeSheet('sheet-price')">✕</button>
    </div>
    <div id="price-info" style="color:var(--text3);font-size:13px;margin-bottom:12px"></div>
    <div id="price-list"></div>
  </div>
</div>

<!-- Operator Sheet -->
<div class="overlay" id="sheet-op" onclick="closeSheet('sheet-op')">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>
    <div class="sheet-title">
      <span>📶 Pilih Operator</span>
      <button class="close-btn" onclick="closeSheet('sheet-op')">✕</button>
    </div>
    <div id="op-info" style="color:var(--text3);font-size:13px;margin-bottom:12px"></div>
    <div id="op-list"></div>
  </div>
</div>

<!-- Confirm Sheet -->
<div class="overlay" id="sheet-confirm" onclick="closeSheet('sheet-confirm')">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>
    <div class="sheet-title">
      <span>✅ Konfirmasi Order</span>
      <button class="close-btn" onclick="closeSheet('sheet-confirm')">✕</button>
    </div>
    <div id="confirm-detail" class="confirm-detail"></div>
    <div id="user-saldo-confirm" style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:10px 14px;font-size:14px;margin-bottom:16px;color:var(--text2)"></div>
    <button class="btn green" id="btn-confirm-order" onclick="confirmOrder()">
      <svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:none;stroke:#fff;stroke-width:2"><polyline points="20 6 9 17 4 12"/></svg>
      Pesan Nomor
    </button>
    <button class="btn outline mt-16" style="margin-top:10px" onclick="closeSheet('sheet-confirm')">Batal</button>
  </div>
</div>

<!-- Active Order Sheet -->
<div class="overlay" id="sheet-active-order">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>
    <div class="sheet-title">
      <span>📱 Nomor Virtual</span>
      <button class="close-btn" onclick="closeSheet('sheet-active-order')">✕</button>
    </div>
    <div class="active-order-box">
      <div style="font-size:13px;color:var(--text2)" id="ao-service"></div>
      <div class="aob-phone" id="ao-phone"></div>
      <button class="copy-btn" onclick="copyPhone()">📋 Salin Nomor</button>
      <hr class="divider">
      <div id="ao-otp-section">
        <div class="aob-wait"><div class="loading-spin" style="width:20px;height:20px;border-width:2px"></div>Menunggu kode OTP...</div>
      </div>
      <div class="aob-timer" id="ao-timer"></div>
    </div>
    <div style="margin-top:16px;display:flex;gap:8px">
      <button class="btn sm outline flex:1" style="flex:1" onclick="cancelCurrentOrder()">❌ Batalkan</button>
      <button class="btn sm" style="flex:2;padding:8px 16px" onclick="checkOTP()">🔄 Cek OTP</button>
    </div>
    <p style="font-size:12px;color:var(--text3);text-align:center;margin-top:10px">Saldo akan dikembalikan otomatis jika OTP tidak masuk</p>
  </div>
</div>

<!-- QRIS Sheet -->
<div class="overlay" id="sheet-qris">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>
    <div class="sheet-title">
      <span>🏦 Scan QRIS</span>
      <button class="close-btn" onclick="cancelDeposit()">✕</button>
    </div>
    <div class="qr-box">
      <img id="qr-img" src="" alt="QRIS">
      <div class="qr-id" id="qr-id"></div>
    </div>
    <div style="background:var(--card2);border-radius:10px;padding:14px;margin:12px 0">
      <div style="display:flex;justify-content:space-between;font-size:14px;padding:5px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text2)">Total Bayar</span><span id="qr-total" style="font-weight:700"></span></div>
      <div style="display:flex;justify-content:space-between;font-size:14px;padding:5px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text2)">Biaya Admin</span><span id="qr-fee" style="color:var(--yellow)"></span></div>
      <div style="display:flex;justify-content:space-between;font-size:14px;padding:5px 0"><span style="color:var(--text2)">Saldo Diterima</span><span id="qr-recv" style="font-weight:700;color:var(--green)"></span></div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:10px;padding:10px 14px;font-size:13px;color:var(--yellow);margin-bottom:14px">
      ⏳ <span>Kadaluarsa: <strong id="qr-timer">--:--</strong></span>
    </div>
    <div style="font-size:13px;color:var(--text3);text-align:center;margin-bottom:14px">Auto cek pembayaran setiap 5 detik</div>
    <button class="btn outline red" onclick="cancelDeposit()">❌ Batalkan Pembayaran</button>
  </div>
</div>

<!-- API Docs Sheet -->
<div class="overlay" id="sheet-apidocs" onclick="closeSheet('sheet-apidocs')">
  <div class="sheet" onclick="event.stopPropagation()">
    <div class="sheet-handle"></div>
    <div class="sheet-title">
      <span>⚙️ Dokumentasi API</span>
      <button class="close-btn" onclick="closeSheet('sheet-apidocs')">✕</button>
    </div>
    <div style="background:var(--bg2);border-radius:10px;padding:14px;margin-bottom:12px">
      <div style="font-size:12px;color:var(--text3);margin-bottom:6px">BASE URL</div>
      <div style="font-family:monospace;font-size:13px;color:var(--blue);word-break:break-all"><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['PHP_SELF']); ?></div>
    </div>
    <div style="font-size:14px;font-weight:700;margin-bottom:10px">Endpoints</div>
    <?php
    $docs = [
      ['POST','?action=login','Login user','email, password'],
      ['POST','?action=register','Register user','name, email, password'],
      ['GET','?action=services','Daftar layanan','–'],
      ['GET','?action=countries&sid=X','Daftar negara','sid = service_code'],
      ['GET','?action=operators&country=X&pid=X','List operator','country, pid = provider_id'],
      ['POST','?action=order','Buat order','nid, pid, oid, price'],
      ['GET','?action=check_otp&oid=X','Cek status OTP','oid = order_id'],
      ['POST','?action=cancel_order','Cancel order','oid = order_id'],
      ['POST','?action=deposit','Buat QRIS deposit','amount'],
      ['GET','?action=check_dep&did=X','Cek status deposit','did = deposit_id'],
      ['GET','?action=history','Riwayat order','–'],
      ['GET','?action=dep_history','Riwayat deposit','–'],
      ['GET','?action=me','Info user','–'],
      ['POST','?action=logout','Logout','–'],
    ];
    foreach($docs as $d) {
      $color = $d[0]==='POST' ? '#f59e0b' : '#3b82f6';
      echo "<div style='background:var(--card2);border-radius:10px;padding:11px 12px;margin-bottom:8px'>";
      echo "<div style='display:flex;align-items:center;gap:8px;margin-bottom:6px'>";
      echo "<span style='background:{$color}22;color:{$color};font-size:11px;font-weight:700;padding:2px 8px;border-radius:5px'>{$d[0]}</span>";
      echo "<code style='font-size:12px;color:var(--text2)'>{$d[1]}</code></div>";
      echo "<div style='font-size:13px;font-weight:500'>{$d[2]}</div>";
      echo "<div style='font-size:12px;color:var(--text3);margin-top:2px'>Params: {$d[3]}</div>";
      echo "</div>";
    }
    ?>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ============================================================
// STATE
// ============================================================
let userData = {};
let allServices = [];
let currentService = null;
let currentCountry = null;
let currentProvider = null;
let currentOperator = null;
let currentPrice = 0;
let currentOrderId = null;
let currentDepositId = null;
let otpInterval = null;
let depInterval = null;
let depTimer = null;

// ============================================================
// AUTH
// ============================================================
function switchTab(t) {
  document.getElementById('tab-login').classList.toggle('hidden', t !== 'login');
  document.getElementById('tab-register').classList.toggle('hidden', t !== 'register');
  document.querySelectorAll('.auth-tab').forEach((el,i) => el.classList.toggle('active', (i===0&&t==='login')||(i===1&&t==='register')));
}
async function doLogin() {
  const email = document.getElementById('l-email').value.trim();
  const pass  = document.getElementById('l-pass').value;
  const err   = document.getElementById('l-err');
  err.style.display = 'none';
  if (!email || !pass) { showErr('l-err', 'Isi semua field'); return; }
  const res = await api('login', {email, password: pass});
  if (res.ok) location.reload();
  else showErr('l-err', res.msg);
}
async function doRegister() {
  const name  = document.getElementById('r-name').value.trim();
  const email = document.getElementById('r-email').value.trim();
  const pass  = document.getElementById('r-pass').value;
  if (!name||!email||!pass) { showErr('r-err','Isi semua field'); return; }
  const res = await api('register', {name, email, password: pass});
  if (res.ok) location.reload();
  else showErr('r-err', res.msg);
}
async function doLogout() {
  await api('logout');
  location.reload();
}
function showErr(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.style.display = 'block';
}

// ============================================================
// API HELPER
// ============================================================
async function api(action, body = null) {
  try {
    const opts = { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } };
    if (body) {
      opts.method = 'POST';
      opts.body = new URLSearchParams({ action, ...body });
    } else {
      opts.method = 'GET';
    }
    const url = body ? '?action=' + action : '?action=' + action;
    const r = await fetch(window.location.pathname + url, body ? { method:'POST', headers:opts.headers, body:opts.body } : { method:'GET' });
    return await r.json();
  } catch(e) {
    return { ok: false, msg: 'Koneksi gagal' };
  }
}
async function apiGet(action, params = {}) {
  const qs = Object.entries(params).map(([k,v]) => `${k}=${encodeURIComponent(v)}`).join('&');
  const url = window.location.pathname + '?action=' + action + (qs ? '&' + qs : '');
  try {
    const r = await fetch(url);
    return await r.json();
  } catch(e) {
    return { ok: false, msg: 'Koneksi gagal' };
  }
}

// ============================================================
// NAVIGATION
// ============================================================
const pageMap = {
  'pg-home':     'nav-home',
  'pg-deposit':  'nav-deposit',
  'pg-activity': 'nav-activity',
  'pg-profile':  'nav-profile',
};
function goPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (pageMap[id]) document.getElementById(pageMap[id]).classList.add('active');
  window.scrollTo(0,0);
  if (id === 'pg-home')     loadHome();
  if (id === 'pg-order')    loadServices();
  if (id === 'pg-activity') loadActivity();
  if (id === 'pg-deposit')  loadDepHistory();
  if (id === 'pg-profile')  loadProfile();
}
function openSheet(id) { document.getElementById(id).classList.add('active'); }
function closeSheet(id) { document.getElementById(id).classList.remove('active'); }

// ============================================================
// TOAST
// ============================================================
let toastTimer;
function toast(msg, type='') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast ' + type + ' show';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
}

// ============================================================
// HOME
// ============================================================
async function loadHome() {
  const res = await apiGet('me');
  if (!res.ok) return;
  userData = res.data;
  document.getElementById('home-saldo').textContent = res.data.saldo_f;
  loadPendingOrders();
}
async function loadPendingOrders() {
  const res = await apiGet('history');
  if (!res.ok) return;
  const pending = (res.data || []).filter(o => o.status === 'pending');
  const el = document.getElementById('home-pending');
  if (pending.length === 0) {
    el.innerHTML = '<div class="pending-empty"><div>📭</div>Tidak ada pesanan aktif</div>';
    return;
  }
  el.innerHTML = pending.map(o => orderCardHTML(o)).join('');
}
function orderCardHTML(o) {
  const badge = {pending:'pending',completed:'completed',cancelled:'cancelled'};
  const label = {pending:'⏳ Menunggu OTP',completed:'✅ Selesai',cancelled:'❌ Dibatalkan'};
  return `<div class="order-card">
    <div class="oc-top">
      <div class="oc-service">${o.service}</div>
      <span class="status-badge ${badge[o.status]}">${label[o.status]}</span>
    </div>
    <div class="oc-num">${o.phone_number}</div>
    ${o.otp_code && o.otp_code !== '-' ? `<div class="oc-otp">${o.otp_code}</div>` : ''}
    <div class="oc-info">
      <span>🌍 ${o.country}</span>
      <span>📶 ${o.operator}</span>
      <span>💰 ${o.price_f}</span>
    </div>
    ${o.status === 'pending' ? `<div class="order-btns">
      <button class="btn sm" onclick="openExistingOrder('${o.order_id}','${o.phone_number}','${o.service}','${o.country}')">📩 Cek OTP</button>
      <button class="btn sm outline red" onclick="cancelOrderById('${o.order_id}')">❌ Batal</button>
    </div>` : ''}
  </div>`;
}

// ============================================================
// SERVICES
// ============================================================
const appColors = ['#25d366','#2ca5e0','#e1306c','#1877f2','#000000','#ee4d2d','#008800','#ff6600','#cc0000','#0088cc','#ff0000','#128c7e','#6441a5','#ff9000','#4e69a2'];
const appEmoji = {'whatsapp':'💬','telegram':'✈️','instagram':'📸','facebook':'👤','tiktok':'🎵','shopee':'🛒','gojek':'🛵','grab':'🚗','netflix':'🎬','twitter':'🐦','discord':'💬','google':'🔵','gmail':'📧','youtube':'▶️','amazon':'📦','paypal':'💳','steam':'🎮','uber':'🚖','tinder':'🔥','linkedin':'💼','viber':'📞','line':'💚','yahoo':'💜','snapchat':'👻','ebay':'🛍️','airbnb':'🏠','microsoft':'🪟','blizzard':'🎮','netflix':'🎬','dana':'💳','ovo':'💜','gopay':'🟢','default':'📱'};
function getEmoji(name) {
  const n = name.toLowerCase();
  for (const [k,v] of Object.entries(appEmoji)) if (n.includes(k)) return v;
  return appEmoji.default;
}
function getColor(idx) { return appColors[idx % appColors.length] + '33'; }

async function loadServices() {
  if (allServices.length > 0) { renderServices(allServices); return; }
  document.getElementById('all-services').innerHTML = '<div class="loading-state" style="grid-column:1/-1"><div class="loading-spin"></div>Memuat layanan...</div>';
  const res = await apiGet('services');
  if (!res.ok) {
    document.getElementById('all-services').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;color:var(--red)">❌ Gagal memuat layanan</div>';
    return;
  }
  allServices = res.data;
  renderServices(allServices);
}
function renderServices(list) {
  const el = document.getElementById('all-services');
  el.innerHTML = list.map((s,i) => `<div class="app-icon" onclick="startOrder('${s.service_code}','${s.service_name.replace(/'/g,"\\'")}')">
    <div class="icon" style="background:${getColor(i)}">${getEmoji(s.service_name)}</div>
    <span>${s.service_name}</span>
  </div>`).join('');
}
function filterServices() {
  const q = document.getElementById('srv-search').value.toLowerCase();
  if (!q) { renderServices(allServices); return; }
  renderServices(allServices.filter(s => s.service_name.toLowerCase().includes(q)));
}

// ============================================================
// ORDER FLOW
// ============================================================
async function startOrder(serviceCode, serviceName) {
  currentService = { code: serviceCode, name: serviceName };
  document.getElementById('country-list').innerHTML = '<div class="loading-state"><div class="loading-spin"></div>Memuat negara...</div>';
  openSheet('sheet-country');
  const res = await apiGet('countries', { sid: serviceCode });
  if (!res.ok) {
    document.getElementById('country-list').innerHTML = '<p style="color:var(--red);text-align:center;padding:20px">❌ Gagal memuat negara</p>';
    return;
  }
  const countries = res.data;
  document.getElementById('country-list').innerHTML = countries.map(c => {
    const cheapest = c.pricelist.filter(p => p.available && p.stock > 0).sort((a,b)=>a.price-b.price)[0];
    if (!cheapest) return '';
    return `<div class="country-item" onclick="selectCountry(${JSON.stringify(c).replace(/'/g,"&#39;").replace(/"/g,'&quot;')})">
      <div class="ci-left">
        <div class="ci-flag">🌍</div>
        <div class="ci-info">
          <div class="ci-name">${c.name} (${c.prefix})</div>
          <div class="ci-sub">Stok: ${c.stock_total}</div>
        </div>
      </div>
      <div class="ci-price">${cheapest.price_f}</div>
    </div>`;
  }).join('');
}

function selectCountry(country) {
  currentCountry = country;
  closeSheet('sheet-country');
  const providers = country.pricelist.filter(p => p.available && p.stock > 0).sort((a,b)=>a.price-b.price);
  document.getElementById('price-info').textContent = `${currentService.name} · ${country.name} (${country.prefix})`;
  document.getElementById('price-list').innerHTML = providers.map(p => `
    <div class="price-item" onclick="selectPrice(${JSON.stringify(p).replace(/"/g,'&quot;')})">
      <div class="pi-left">
        <div class="pi-price">${p.price_f}</div>
        <div class="pi-meta">Provider ${p.provider_id} · Server ${p.server_id||'-'}</div>
      </div>
      <div class="pi-stock">Stok ${p.stock}</div>
    </div>`).join('');
  openSheet('sheet-price');
}

async function selectPrice(provider) {
  currentProvider = provider;
  currentPrice = provider.price;
  closeSheet('sheet-price');
  document.getElementById('op-info').textContent = `${currentService.name} · ${currentCountry.name} · ${provider.price_f}`;
  document.getElementById('op-list').innerHTML = '<div class="loading-state"><div class="loading-spin"></div>Memuat operator...</div>';
  openSheet('sheet-op');
  const res = await apiGet('operators', { country: currentCountry.name, pid: provider.provider_id });
  if (!res.ok || !res.data.length) {
    document.getElementById('op-list').innerHTML = '<p style="text-align:center;padding:20px;color:var(--red)">❌ Tidak ada operator tersedia</p>';
    return;
  }
  document.getElementById('op-list').innerHTML = res.data.map(op => `
    <div class="op-item" onclick="selectOperator(${JSON.stringify(op).replace(/"/g,'&quot;')})">
      <div class="op-dot"></div>
      <span>${op.name}</span>
    </div>`).join('');
}

function selectOperator(op) {
  currentOperator = op;
  closeSheet('sheet-op');
  const userSaldo = userData.saldo || 0;
  const enough = userSaldo >= currentPrice;
  document.getElementById('confirm-detail').innerHTML = `
    <div class="confirm-row"><span class="cr-label">Layanan</span><span>${currentService.name}</span></div>
    <div class="confirm-row"><span class="cr-label">Negara</span><span>${currentCountry.name} (${currentCountry.prefix})</span></div>
    <div class="confirm-row"><span class="cr-label">Operator</span><span>${op.name}</span></div>
    <div class="confirm-row"><span class="cr-label">Provider</span><span>${currentProvider.provider_id}</span></div>
    <div class="confirm-row"><span class="cr-label">Stok</span><span>${currentProvider.stock}</span></div>
    <div class="confirm-row"><span class="cr-label">Total Bayar</span><span>${currentProvider.price_f}</span></div>`;
  document.getElementById('user-saldo-confirm').innerHTML = `💳 Saldo kamu: <strong>${userData.saldo_f}</strong> ${enough ? '✅' : '⚠️ <span style="color:var(--red)">Tidak cukup</span>'}`;
  document.getElementById('btn-confirm-order').disabled = !enough;
  document.getElementById('btn-confirm-order').style.opacity = enough ? '1' : '0.5';
  openSheet('sheet-confirm');
}

async function confirmOrder() {
  document.getElementById('btn-confirm-order').disabled = true;
  document.getElementById('btn-confirm-order').textContent = '⏳ Memproses...';
  const res = await api('order', {
    nid:   currentCountry.number_id,
    pid:   currentProvider.provider_id,
    oid:   currentOperator.id,
    price: currentPrice,
  });
  document.getElementById('btn-confirm-order').disabled = false;
  document.getElementById('btn-confirm-order').innerHTML = '<svg viewBox="0 0 24 24" style="width:18px;height:18px;fill:none;stroke:#fff;stroke-width:2"><polyline points="20 6 9 17 4 12"/></svg> Pesan Nomor';
  if (!res.ok) { toast('❌ ' + res.msg, 'error'); return; }
  closeSheet('sheet-confirm');
  userData.saldo = res.data.saldo_after;
  userData.saldo_f = res.data.saldo_f;
  document.getElementById('home-saldo').textContent = res.data.saldo_f;
  currentOrderId = res.data.order_id;
  showActiveOrder(res.data);
}

function showActiveOrder(d) {
  document.getElementById('ao-service').textContent = `${d.service} · ${d.country} · ${d.operator}`;
  document.getElementById('ao-phone').textContent = d.phone;
  document.getElementById('ao-otp-section').innerHTML = '<div class="aob-wait"><div class="loading-spin" style="width:20px;height:20px;border-width:2px"></div>Menunggu kode OTP masuk...</div>';
  openSheet('sheet-active-order');
  startOTPTimer(d.expires);
  startOTPPolling();
}

function openExistingOrder(oid, phone, service, country) {
  currentOrderId = oid;
  document.getElementById('ao-service').textContent = `${service} · ${country}`;
  document.getElementById('ao-phone').textContent = phone;
  document.getElementById('ao-otp-section').innerHTML = '<div class="aob-wait"><div class="loading-spin" style="width:20px;height:20px;border-width:2px"></div>Menunggu kode OTP masuk...</div>';
  openSheet('sheet-active-order');
  startOTPPolling();
}

function startOTPTimer(minutes) {
  let secs = minutes * 60;
  const el = document.getElementById('ao-timer');
  if (otpInterval) clearInterval(otpInterval);
  otpInterval = setInterval(() => {
    secs--;
    if (secs <= 0) { clearInterval(otpInterval); el.textContent = 'Waktu habis!'; return; }
    const m = Math.floor(secs/60), s = secs%60;
    el.textContent = `⏳ Kadaluarsa: ${m}:${String(s).padStart(2,'0')}`;
  }, 1000);
}

async function checkOTP() {
  if (!currentOrderId) return;
  const res = await apiGet('check_otp', { oid: currentOrderId });
  if (res.ok && res.otp) {
    clearInterval(otpInterval);
    document.getElementById('ao-otp-section').innerHTML = `
      <div style="font-size:13px;color:var(--green);margin-bottom:6px">✅ Kode OTP Diterima!</div>
      <div class="aob-otp">${res.otp}</div>
      <button class="copy-btn" onclick="navigator.clipboard.writeText('${res.otp}');toast('✅ OTP disalin!','success')">📋 Salin OTP</button>`;
    document.getElementById('ao-timer').textContent = '';
    toast('✅ OTP berhasil diterima!', 'success');
    loadHome();
  }
  return res;
}

let otpPollInterval;
function startOTPPolling() {
  if (otpPollInterval) clearInterval(otpPollInterval);
  otpPollInterval = setInterval(async () => {
    const res = await checkOTP();
    if (res && res.otp) clearInterval(otpPollInterval);
  }, 5000);
}

function copyPhone() {
  const phone = document.getElementById('ao-phone').textContent;
  navigator.clipboard.writeText(phone).then(() => toast('✅ Nomor disalin!', 'success'));
}

async function cancelCurrentOrder() {
  if (!currentOrderId) return;
  await cancelOrderById(currentOrderId);
  closeSheet('sheet-active-order');
  clearInterval(otpInterval);
  clearInterval(otpPollInterval);
}

async function cancelOrderById(oid) {
  const res = await api('cancel_order', { oid });
  if (res.ok) {
    toast(`✅ Dibatalkan! Refund ${res.refund}`, 'success');
    loadHome();
    const meRes = await apiGet('me');
    if (meRes.ok) { userData = meRes.data; document.getElementById('home-saldo').textContent = meRes.data.saldo_f; }
  } else {
    toast('❌ ' + (res.msg || 'Gagal cancel'), 'error');
  }
}

// ============================================================
// DEPOSIT
// ============================================================
async function createDeposit() {
  const amt = parseInt(document.getElementById('dep-amount').value);
  if (!amt || amt < 2000) { toast('❌ Minimal deposit Rp 2.000', 'error'); return; }
  const res = await api('deposit', { amount: amt });
  if (!res.ok) { toast('❌ ' + res.msg, 'error'); return; }
  const d = res.data;
  currentDepositId = d.dep_id;
  document.getElementById('qr-img').src = d.qr;
  document.getElementById('qr-id').textContent = 'ID: ' + d.dep_id;
  document.getElementById('qr-total').textContent = d.total_f;
  document.getElementById('qr-fee').textContent = d.fee_f;
  document.getElementById('qr-recv').textContent = d.recv_f;
  openSheet('sheet-qris');
  startDepTimer(d.exp);
  startDepPolling(d.dep_id);
  document.getElementById('dep-amount').value = '';
  loadDepHistory();
}

function startDepTimer(expTs) {
  if (depTimer) clearInterval(depTimer);
  depTimer = setInterval(() => {
    const secs = Math.max(0, expTs - Math.floor(Date.now()/1000));
    if (secs === 0) { clearInterval(depTimer); document.getElementById('qr-timer').textContent = 'Kadaluarsa!'; return; }
    const m = Math.floor(secs/60), s = secs%60;
    document.getElementById('qr-timer').textContent = `${m}:${String(s).padStart(2,'0')}`;
  }, 1000);
}

function startDepPolling(did) {
  if (depInterval) clearInterval(depInterval);
  depInterval = setInterval(async () => {
    const res = await apiGet('check_dep', { did });
    if (res.ok && res.status === 'success') {
      clearInterval(depInterval);
      clearInterval(depTimer);
      closeSheet('sheet-qris');
      toast(`✅ Deposit berhasil! +${res.recv_f}`, 'success');
      const meRes = await apiGet('me');
      if (meRes.ok) { userData = meRes.data; document.getElementById('home-saldo').textContent = meRes.data.saldo_f; }
      loadDepHistory();
    }
  }, 5000);
}

async function cancelDeposit() {
  if (!currentDepositId) { closeSheet('sheet-qris'); return; }
  await api('cancel_dep', { did: currentDepositId });
  closeSheet('sheet-qris');
  clearInterval(depInterval);
  clearInterval(depTimer);
  loadDepHistory();
  toast('Pembayaran dibatalkan', '');
}

async function loadDepHistory() {
  const res = await apiGet('dep_history');
  const el = document.getElementById('dep-history');
  if (!res.ok || !res.data.length) {
    el.innerHTML = '<div class="pending-empty"><div>📄</div>Belum ada riwayat deposit</div>';
    return;
  }
  el.innerHTML = res.data.map(d => {
    const status = {pending:'⏳',success:'✅',cancelled:'❌',failed:'❌'}[d.status] || '❓';
    const color = d.status==='success'?'var(--green)':d.status==='pending'?'var(--yellow)':'var(--red)';
    return `<div class="dep-history-item">
      <div class="dhi-left"><div class="dhi-amount" style="color:${color}">${status} ${d.amount_f}</div><div class="dhi-id">ID: ${d.deposit_id||'-'} · ${d.method}</div></div>
      <div><div style="font-size:13px;font-weight:600;color:var(--green)">${d.status==='success'?'+'+d.recv_f:''}</div><div style="font-size:11px;color:var(--text3)">${d.created_at?.substring(0,16)||''}</div></div>
    </div>`;
  }).join('');
}

// ============================================================
// ACTIVITY
// ============================================================
let allOrders = [];
async function loadActivity() {
  document.getElementById('act-list').innerHTML = '<div class="loading-state"><div class="loading-spin"></div>Memuat...</div>';
  const res = await apiGet('history');
  if (!res.ok) { document.getElementById('act-list').innerHTML = '<p style="text-align:center;padding:20px;color:var(--red)">Gagal memuat</p>'; return; }
  allOrders = res.data;
  renderActivity('all');
}
function filterAct(f, btn) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderActivity(f);
}
function renderActivity(f) {
  const orders = f === 'all' ? allOrders : allOrders.filter(o => o.status === f);
  const el = document.getElementById('act-list');
  if (!orders.length) { el.innerHTML = '<div class="pending-empty" style="padding:40px 16px"><div>📭</div>Tidak ada data</div>'; return; }
  el.innerHTML = orders.map(o => {
    const statusColor = {pending:'var(--yellow)',completed:'var(--green)',cancelled:'var(--red)'}[o.status]||'var(--text2)';
    const statusLabel = {pending:'Menunggu OTP',completed:'✅ Selesai',cancelled:'❌ Dibatalkan'}[o.status]||o.status;
    return `<div class="activity-item" onclick="${o.status==='pending'?`openExistingOrder('${o.order_id}','${o.phone_number}','${o.service}','${o.country}')`:''}" style="${o.status==='pending'?'cursor:pointer':''}">
      <div class="ai-icon order">📱</div>
      <div class="ai-info">
        <div class="ai-service">${o.service}</div>
        <div class="ai-sub">
          <span style="font-family:monospace">${o.phone_number}</span>
          ${o.otp_code&&o.otp_code!=='-'?` · <span style="color:var(--green);font-weight:700">${o.otp_code}</span>`:''}
        </div>
        <div style="font-size:11px;color:${statusColor};margin-top:3px">${statusLabel}</div>
      </div>
      <div class="ai-price blue">
        ${o.price_f}
        <div style="font-size:11px;color:var(--text3);font-weight:400">${o.created_at?.substring(0,10)||''}</div>
      </div>
    </div>`;
  }).join('');
}

// ============================================================
// PROFILE
// ============================================================
async function loadProfile() {
  const res = await apiGet('me');
  if (!res.ok) return;
  const d = res.data;
  userData = d;
  const initials = d.name.split(' ').map(n=>n[0]).join('').substring(0,2).toUpperCase();
  document.getElementById('pf-avatar').textContent = initials;
  document.getElementById('pf-name').textContent = d.name;
  document.getElementById('pf-email').textContent = d.email;
  document.getElementById('pf-joined').textContent = '📅 Bergabung ' + d.joined;
  document.getElementById('pf-saldo').textContent = d.saldo_f;
  const histRes = await apiGet('history');
  const depRes = await apiGet('dep_history');
  document.getElementById('pf-orders').textContent = (histRes.data||[]).length + 'x';
  document.getElementById('pf-deps').textContent = (depRes.data||[]).filter(d=>d.status==='success').length + 'x';
}

function showApiDocs() { openSheet('sheet-apidocs'); }

// ============================================================
// INIT
// ============================================================
<?php if (isLoggedIn()): ?>
(async () => {
  await loadHome();
  await loadServices();
})();
<?php endif; ?>
</script>
</body>
</html>
