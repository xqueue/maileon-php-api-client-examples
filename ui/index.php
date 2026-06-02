<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

require dirname(__DIR__) . '/vendor/autoload.php';

session_name('maileon_ui');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

// ─────────────────────────────────────────────────────────────────────────────
// Encryption helpers
// ─────────────────────────────────────────────────────────────────────────────

const CIPHER       = 'AES-256-CBC';
const COOKIE_NAME  = 'maileon_vault';
const COOKIE_DAYS  = 365;
const PBKDF2_ITER  = 100000;
const KEY_LEN      = 32;
const SALT_LEN     = 16;
const IV_LEN       = 16;
const VAULT_VER    = '1';

function derive_key(string $password, string $salt): string
{
    return hash_pbkdf2('sha256', $password, $salt, PBKDF2_ITER, KEY_LEN, true);
}

function encrypt_vault(array $data, string $key): string
{
    $iv         = random_bytes(IV_LEN);
    $plaintext  = json_encode($data, JSON_UNESCAPED_UNICODE);
    $ciphertext = openssl_encrypt($plaintext, CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    return VAULT_VER . ':' . base64_encode($iv) . ':' . base64_encode($ciphertext);
}

function decrypt_vault(string $blob, string $key): ?array
{
    $parts = explode(':', $blob, 3);
    if (count($parts) !== 3 || $parts[0] !== VAULT_VER) {
        return null;
    }
    [, $iv64, $ct64] = $parts;
    $iv         = base64_decode($iv64, true);
    $ciphertext = base64_decode($ct64, true);
    if ($iv === false || $ciphertext === false) {
        return null;
    }
    $plaintext = openssl_decrypt($ciphertext, CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) {
        return null;
    }
    $data = json_decode($plaintext, true);
    return is_array($data) ? $data : null;
}

function write_vault(array $data): void
{
    $key  = $_SESSION['vault_key'] ?? '';
    $salt = base64_decode($_SESSION['vault_salt'] ?? '');
    if ($key === '' || $salt === false) {
        return;
    }
    $blob = encrypt_vault($data, $key);
    $full = base64_encode($salt) . '|' . $blob;
    setcookie(
        COOKIE_NAME,
        $full,
        ['expires' => time() + 86400 * COOKIE_DAYS, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']
    );
    $_SESSION['vault'] = $data;
}

function read_vault_cookie(string $password): ?array
{
    $raw = $_COOKIE[COOKIE_NAME] ?? '';
    if ($raw === '') {
        return [];
    }
    $pos = strpos($raw, '|');
    if ($pos === false) {
        return null;
    }
    $salt64 = substr($raw, 0, $pos);
    $blob   = substr($raw, $pos + 1);
    $salt   = base64_decode($salt64, true);
    if ($salt === false) {
        return null;
    }
    $key = derive_key($password, $salt);
    return decrypt_vault($blob, $key);
}

function init_new_vault(string $password): void
{
    $salt = random_bytes(SALT_LEN);
    $key  = derive_key($password, $salt);
    $_SESSION['vault_salt'] = base64_encode($salt);
    $_SESSION['vault_key']  = $key;
    $_SESSION['authed']     = true;
    $_SESSION['vault']      = [];
    write_vault([]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Default config shape
// ─────────────────────────────────────────────────────────────────────────────

function default_vault(): array
{
    return [
        'api_key'              => '',
        'base_uri'             => 'https://api.maileon.com/1.0',
        'debug'                => true,
        'test_email'           => 'test@baunzt.de',
        'test_email2'          => 'test2@baunzt.de',
        'test_external_id'     => '',
        'test_external_id2'    => '',
        'test_mailing_id'      => '',
        'test_cf_id'           => '',
        'test_blacklist_id'    => '',
        'test_de_id'           => '',
        'test_tx_type_id'      => '',
        'test_tx_id'           => '',
        'test_webhook_id'      => '',
        'test_doi_mailing_key' => '',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Request routing
// ─────────────────────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$errors = [];
$authed = !empty($_SESSION['authed']);

// Handle login
if ($action === 'login' && !$authed) {
    $password = $_POST['password'] ?? '';
    if (strlen($password) < 4) {
        $errors[] = 'Password must be at least 4 characters.';
    } else {
        $vault = read_vault_cookie($password);
        if ($vault === null) {
            $errors[] = 'Wrong password or corrupted vault.';
        } else {
            $salt64 = explode('|', $_COOKIE[COOKIE_NAME] ?? '')[0] ?? '';
            $salt   = base64_decode($salt64, true);
            $_SESSION['vault_key']  = $salt !== false ? derive_key($password, $salt) : '';
            $_SESSION['vault_salt'] = $salt64;
            $_SESSION['authed']     = true;
            $_SESSION['vault']      = array_merge(default_vault(), $vault);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    }
}

// Handle new vault creation
if ($action === 'create_vault' && !$authed) {
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';
    if (strlen($password) < 4) {
        $errors[] = 'Password must be at least 4 characters.';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    } else {
        init_new_vault($password);
        $_SESSION['vault'] = default_vault();
        write_vault($_SESSION['vault']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// Handle logout
if ($action === 'logout' && $authed) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Handle config save
if ($action === 'save_config' && $authed) {
    $vault = array_merge(default_vault(), $_SESSION['vault'] ?? []);
    foreach (array_keys(default_vault()) as $key) {
        if (isset($_POST[$key])) {
            $vault[$key] = match ($key) {
                'debug' => $_POST[$key] === '1',
                default => trim($_POST[$key]),
            };
        }
    }
    $_SESSION['vault'] = $vault;
    write_vault($vault);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
    exit;
}

// Handle AJAX test run
if ($action === 'run_tests' && $authed) {
    header('Content-Type: application/json');
    require __DIR__ . '/run_tests.php';
    exit;
}

$vault  = $_SESSION['vault'] ?? default_vault();
$saved  = isset($_GET['saved']);

// ─────────────────────────────────────────────────────────────────────────────
// HTML output
// ─────────────────────────────────────────────────────────────────────────────

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maileon API Playground</title>
<style>
:root {
    --bg:        #0f1117;
    --surface:   #1a1d27;
    --surface2:  #232635;
    --border:    #2e3250;
    --accent:    #5c7cfa;
    --accent2:   #748ffc;
    --text:      #e8eaf6;
    --muted:     #8892b0;
    --success:   #51cf66;
    --error:     #ff6b6b;
    --warning:   #fcc419;
    --font:      'Segoe UI', system-ui, sans-serif;
    --mono:      'Cascadia Code', 'Fira Code', monospace;
    --radius:    6px;
    --shadow:    0 2px 12px rgba(0,0,0,.4);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 14px; }
body { background: var(--bg); color: var(--text); font-family: var(--font); min-height: 100vh; }

/* ── Login ── */
.login-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-box  { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
              padding: 40px; width: 360px; box-shadow: var(--shadow); }
.login-box h1 { font-size: 1.4rem; margin-bottom: 8px; color: var(--accent2); }
.login-box p  { color: var(--muted); margin-bottom: 24px; font-size: .9rem; }
.tabs { display: flex; gap: 0; margin-bottom: 24px; border-bottom: 1px solid var(--border); }
.tab  { padding: 8px 16px; cursor: pointer; color: var(--muted); border-bottom: 2px solid transparent;
        margin-bottom: -1px; font-size: .9rem; transition: color .15s; }
.tab.active { color: var(--accent2); border-color: var(--accent2); }
.tab-panel  { display: none; }
.tab-panel.active { display: block; }

/* ── App layout ── */
.app    { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
.sidebar { background: var(--surface); border-right: 1px solid var(--border);
           display: flex; flex-direction: column; }
.sidebar-header { padding: 16px 20px; border-bottom: 1px solid var(--border); }
.sidebar-header h1 { font-size: 1.1rem; color: var(--accent2); }
.sidebar-header p  { color: var(--muted); font-size: .8rem; margin-top: 2px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 8px 0; }
.nav-section { padding: 4px 0; }
.nav-section-title { padding: 6px 20px; font-size: .75rem; font-weight: 600; letter-spacing: .05em;
                     text-transform: uppercase; color: var(--muted); }
.nav-item { display: flex; align-items: center; gap: 8px; padding: 6px 20px; cursor: pointer;
            color: var(--text); font-size: .88rem; transition: background .1s; }
.nav-item:hover { background: var(--surface2); }
.nav-item.active { background: rgba(92,124,250,.12); color: var(--accent2); }
.sidebar-footer { padding: 12px 20px; border-top: 1px solid var(--border); display: flex;
                  justify-content: space-between; align-items: center; }
.sidebar-footer small { color: var(--muted); font-size: .8rem; }

/* ── Main ── */
.main { display: flex; flex-direction: column; overflow: hidden; }
.topbar { display: flex; align-items: center; justify-content: space-between;
          padding: 12px 24px; border-bottom: 1px solid var(--border); background: var(--surface);
          gap: 12px; }
.topbar h2 { font-size: 1rem; font-weight: 600; }
.topbar-actions { display: flex; gap: 8px; }
.content { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }

/* ── Panels ── */
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
.panel-head { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex;
              align-items: center; justify-content: space-between; }
.panel-head h3 { font-size: .9rem; font-weight: 600; }
.panel-body { padding: 16px; }

/* ── Forms ── */
.form-row  { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-row.cols3 { grid-template-columns: 1fr 1fr 1fr; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: .82rem; color: var(--muted); }
input[type=text], input[type=password], input[type=number] {
    background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); padding: 7px 10px; font-size: .88rem; width: 100%;
    transition: border-color .15s; font-family: inherit; }
input[type=text]:focus, input[type=password]:focus, input[type=number]:focus {
    outline: none; border-color: var(--accent); }
textarea.body-ta { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); padding: 7px 10px; font-size: .78rem; width: 100%; box-sizing: border-box; font-family: var(--mono); resize: vertical; min-height: 150px; line-height: 1.5; transition: border-color .15s; }
textarea.body-ta:focus { outline: none; border-color: var(--accent); }
select.param-sel { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); padding: 7px 10px; font-size: .88rem; width: 100%; cursor: pointer;
    font-family: inherit; transition: border-color .15s; -webkit-appearance: none; appearance: none; }
select.param-sel:focus { outline: none; border-color: var(--accent); }
.form-group-full { grid-column: 1 / -1; }
input[type=password] { font-family: var(--mono); letter-spacing: .1em; }
.sensitive { font-family: var(--mono); letter-spacing: .08em; }

/* ── Checkboxes ── */
.check-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 4px; }
.check-item { display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 4px;
              cursor: pointer; transition: background .1s; user-select: none; }
.check-item:hover { background: var(--surface2); }
.check-item input { accent-color: var(--accent); cursor: pointer; }
.check-item .badge { font-size: .7rem; padding: 1px 6px; border-radius: 10px; }
.badge-read    { background: rgba(81,207,102,.15); color: var(--success); }
.badge-write   { background: rgba(252,196,25,.15);  color: var(--warning); }
.badge-send    { background: rgba(255,107,107,.2);  color: var(--error); }
.badge-destroy { background: rgba(255,107,107,.3);  color: var(--error); }
.badge-req { font-size: .68rem; padding: 1px 6px; border-radius: 10px; margin-left: 5px;
             background: rgba(255,107,107,.18); color: var(--error); font-weight: 600; }
.badge-opt { font-size: .68rem; padding: 1px 6px; border-radius: 10px; margin-left: 5px;
             background: rgba(136,146,176,.13); color: var(--muted); }
.select-row { display: flex; gap: 6px; margin-bottom: 10px; }

/* ── Output ── */
.output-panel { background: var(--bg); border-radius: var(--radius); min-height: 200px;
                padding: 12px; font-family: var(--mono); font-size: .83rem; overflow: auto; }
.result-item { margin-bottom: 14px; }
.result-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.result-header .label { color: var(--muted); font-family: var(--font); font-size: .85rem; }
.result-ok   { color: var(--success); }
.result-fail { color: var(--error); }
.result-body { background: var(--surface); border: 1px solid var(--border); border-radius: 4px;
               padding: 8px; white-space: pre-wrap; word-break: break-all; font-size: .8rem; max-height: 300px;
               overflow-y: auto; }

/* ── Buttons ── */
button, .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
               border-radius: var(--radius); border: none; cursor: pointer; font-size: .85rem;
               font-family: inherit; transition: filter .15s; }
.btn-primary  { background: var(--accent); color: #fff; }
.btn-primary:hover  { filter: brightness(1.1); }
.btn-outline  { background: transparent; color: var(--text); border: 1px solid var(--border); }
.btn-outline:hover  { background: var(--surface2); }
.btn-danger   { background: rgba(255,107,107,.15); color: var(--error); border: 1px solid rgba(255,107,107,.3); }
.btn-danger:hover   { background: rgba(255,107,107,.25); }
.btn-sm       { padding: 4px 10px; font-size: .8rem; }

/* ── Misc ── */
.error-box   { background: rgba(255,107,107,.1); border: 1px solid rgba(255,107,107,.3); border-radius: var(--radius);
               padding: 10px 14px; color: var(--error); font-size: .88rem; margin-bottom: 16px; }
.success-box { background: rgba(81,207,102,.1); border: 1px solid rgba(81,207,102,.3); border-radius: var(--radius);
               padding: 10px 14px; color: var(--success); font-size: .88rem; margin-bottom: 16px; }
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.3);
           border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.dim { opacity: .5; pointer-events: none; }
hr.sep { border: none; border-top: 1px solid var(--border); margin: 14px 0; }
.warning-note { color: var(--warning); font-size: .82rem; margin-top: 6px; }

/* ── Test search ── */
#test-search-wrap { margin-bottom: 10px; }

/* ── Status badge ── */
.status-badge { font-family: var(--mono); font-size: .75rem; font-weight: 600;
                padding: 2px 8px; border-radius: 4px; }

/* ── Result tabs ── */
.result-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border); margin: 8px 0 0; }
.result-tab  { padding: 5px 12px; font-size: .78rem; cursor: pointer; color: var(--muted);
               border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .12s;
               user-select: none; }
.result-tab:hover  { color: var(--text); }
.result-tab.active { color: var(--accent2); border-color: var(--accent2); }
.result-tab-panel  { display: none; }
.result-tab-panel.active { display: block; padding-top: 6px; }

/* ── Header pre ── */
.hdr-pre { background: var(--bg); border: 1px solid var(--border); border-radius: 4px;
           padding: 10px; font-size: .78rem; font-family: var(--mono); white-space: pre-wrap;
           word-break: break-all; max-height: 240px; overflow-y: auto; }

/* ── Method badge ── */
.method-badge { font-family: var(--mono); font-size: .7rem; font-weight: 700;
                padding: 2px 6px; border-radius: 3px; letter-spacing: .04em; white-space: nowrap; }
.method-GET    { background: rgba(81,207,102,.15);  color: var(--success); }
.method-POST   { background: rgba(252,196,25,.15);  color: var(--warning); }
.method-PUT    { background: rgba(116,192,252,.15); color: #74c0fc; }
.method-DELETE { background: rgba(255,107,107,.15); color: var(--error); }
.method-PATCH  { background: rgba(188,132,252,.15); color: #bc84fc; }

/* ── Timing badge ── */
.timing-badge { font-family: var(--mono); font-size: .72rem; color: var(--muted);
                padding: 1px 6px; border-radius: 3px; background: rgba(136,146,176,.1); white-space: nowrap; }

/* ── URL display ── */
.req-url { font-family: var(--mono); font-size: .75rem; color: var(--muted);
           overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
           flex: 1; min-width: 0; max-width: 480px; }

/* ── Request details (collapsed) ── */
.req-details { margin: 4px 0 6px; border: 1px solid var(--border); border-radius: 4px; overflow: hidden; }
.req-details > summary { padding: 5px 10px; cursor: pointer; font-size: .78rem;
                          color: var(--muted); user-select: none; list-style: none;
                          display: flex; align-items: center; gap: 8px; }
.req-details > summary::-webkit-details-marker { display: none; }
.req-details > summary .req-arrow { font-size: .6rem; transition: transform .15s;
                                     display: inline-block; flex-shrink: 0; }
.req-details[open] > summary .req-arrow { transform: rotate(90deg); }
.req-details-body { padding: 8px; border-top: 1px solid var(--border); }

/* ── Check-wrap (code-snippet button alongside each test) ── */
.check-wrap { display: flex; align-items: center; }
.check-wrap .check-item { flex: 1; min-width: 0; }
.code-btn { background: rgba(92,124,250,.08); border: 1px solid var(--border); padding: 2px 7px;
            color: var(--accent2); font-family: var(--mono); font-size: .72rem; cursor: pointer;
            border-radius: 3px; flex-shrink: 0; opacity: .6; transition: opacity .1s, background .1s; }
.code-btn:hover { opacity: 1; background: rgba(92,124,250,.18); }

/* ── Code-snippet modal ── */
#code-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.58); z-index: 1000;
                display: flex; align-items: center; justify-content: center; }
#code-modal { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
              padding: 20px; width: min(740px, calc(100vw - 32px)); max-height: 82vh;
              display: flex; flex-direction: column; gap: 10px; box-shadow: 0 8px 32px rgba(0,0,0,.65); }
#code-modal-pre { flex: 1; overflow-y: auto; background: var(--bg); border: 1px solid var(--border);
                  border-radius: 4px; padding: 12px 14px; font-family: var(--mono); font-size: .78rem;
                  white-space: pre; line-height: 1.6; min-height: 160px; }

/* ── PHP syntax highlighting ── */
.php-tag { color: #ff5370; }
.php-kw  { color: #c792ea; }
.php-lit { color: #f78c6c; }
.php-cls { color: #ffcb6b; }
.php-var { color: #82aaff; }
.php-str { color: #c3e88d; }
.php-num { color: #f78c6c; }
.php-op  { color: #89ddff; }
.php-cmt { color: #546e7a; font-style: italic; }

/* ── Tooltip ── */
.tip-wrap { position: relative; display: inline-block; }
.tip-box  { display: none; position: absolute; bottom: calc(100% + 8px); left: 0;
            background: var(--surface2); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 12px 14px; width: 280px;
            font-size: .79rem; color: var(--text); line-height: 1.6;
            box-shadow: var(--shadow); z-index: 300; pointer-events: none; }
.tip-box.tip-down  { bottom: auto; top: calc(100% + 8px); }
.tip-box.tip-right { left: auto; right: 0; }
.tip-box h4 { font-size: .79rem; font-weight: 600; color: var(--accent2); margin-bottom: 6px; }
.tip-box ol, .tip-box ul { padding-left: 14px; margin: 0; }
.tip-box li { margin-bottom: 3px; }
.tip-wrap:hover .tip-box { display: block; }
.tip-icon { display: inline-flex; align-items: center; justify-content: center;
            width: 14px; height: 14px; border-radius: 50%;
            background: rgba(92,124,250,.2); color: var(--accent2);
            font-size: .65rem; font-weight: 700; font-style: normal; line-height: 1;
            cursor: help; vertical-align: middle; margin-left: 4px; flex-shrink: 0; }
</style>
</head>
<body>

<!-- ── Code-snippet modal ──────────────────────────────────────────────── -->
<div id="code-overlay" style="display:none" onclick="if(event.target===this)closeCodeModal()">
    <div id="code-modal">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
            <h4 style="font-size:.9rem;color:var(--accent2)" id="code-modal-title"></h4>
            <div style="display:flex;gap:8px;align-items:center">
                <button class="btn btn-outline btn-sm" onclick="copyCode()">Copy</button>
                <button class="btn btn-outline btn-sm" onclick="closeCodeModal()" title="Close">✕</button>
            </div>
        </div>
        <pre id="code-modal-pre"></pre>
    </div>
</div>

<?php if (!$authed): ?>
<!-- ── LOGIN ─────────────────────────────────────────────────────────────── -->
<div class="login-wrap">
<div class="login-box">
    <h1>Maileon API Playground</h1>
    <p>All settings are encrypted with your password and stored in a browser cookie.<span class="tip-wrap"><i class="tip-icon">i</i><span class="tip-box tip-down"><h4>Security model</h4><ul>
        <li>AES-256-CBC encryption, fresh random 16-byte IV on every save</li>
        <li>Key derived via PBKDF2-SHA256, 100,000 iterations, random 16-byte salt</li>
        <li>Cookie stores salt + IV + ciphertext — the key is never persisted anywhere</li>
        <li>Credentials exist in plain text only in PHP memory during authenticated requests</li>
    </ul></span></span></p>

    <?php foreach ($errors as $e): ?>
    <div class="error-box"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php $hasCookie = !empty($_COOKIE[COOKIE_NAME]); ?>
    <div class="tabs">
        <div class="tab <?= $hasCookie ? 'active' : '' ?>" onclick="showTab('login')">Unlock</div>
        <div class="tab <?= !$hasCookie ? 'active' : '' ?>" onclick="showTab('create')">New vault</div>
    </div>

    <div id="tab-login" class="tab-panel <?= $hasCookie ? 'active' : '' ?>">
        <form method="post">
            <input type="hidden" name="action" value="login">
            <div class="form-group" style="margin-bottom:16px">
                <label>Password</label>
                <input type="password" name="password" autofocus autocomplete="current-password" placeholder="Enter your vault password">
            </div>
            <button class="btn btn-primary" style="width:100%" type="submit">Unlock vault</button>
        </form>
        <p style="color:var(--muted);font-size:.79rem;margin-top:10px"><span class="tip-wrap" style="border-bottom:1px dotted var(--muted);cursor:help">How unlocking works<span class="tip-box tip-down"><h4>Unlock process</h4><ol>
            <li>Salt is extracted from the cookie</li>
            <li>PBKDF2-SHA256 re-derives the same 256-bit key from your password + stored salt</li>
            <li>AES-256-CBC decrypts the ciphertext — wrong password means decryption fails and access is denied</li>
            <li>On success the derived key is stored in your PHP session for this browser session only</li>
        </ol></span></span></p>
        <?php if (!$hasCookie): ?>
        <p style="color:var(--muted);font-size:.8rem;margin-top:12px">No vault cookie found — create a new vault first.</p>
        <?php endif; ?>
    </div>

    <div id="tab-create" class="tab-panel <?= !$hasCookie ? 'active' : '' ?>">
        <form method="post">
            <input type="hidden" name="action" value="create_vault">
            <div class="form-group" style="margin-bottom:12px">
                <label>New password</label>
                <input type="password" name="password" autocomplete="new-password" placeholder="Choose a strong password">
            </div>
            <div class="form-group" style="margin-bottom:16px">
                <label>Confirm password</label>
                <input type="password" name="password2" autocomplete="new-password" placeholder="Repeat password">
            </div>
            <button class="btn btn-primary" style="width:100%" type="submit">Create vault</button>
        </form>
        <p style="color:var(--muted);font-size:.8rem;margin-top:12px">
            A new AES-256 encrypted cookie will be created. Your API keys never leave the server in plain text.<span class="tip-wrap"><i class="tip-icon">i</i><span class="tip-box tip-right"><h4>Vault creation</h4><ol>
                <li>Server generates a random 16-byte salt</li>
                <li>PBKDF2-SHA256 (100,000 iterations) derives a 256-bit key from your password + salt</li>
                <li>Config JSON is encrypted with AES-256-CBC using a fresh random IV</li>
                <li>Cookie stores: <code style="font-family:var(--mono)">base64(salt) | version:base64(IV):base64(ciphertext)</code></li>
                <li>Your password is never stored — lose it and the vault is permanently unreadable</li>
            </ol></span></span>
        </p>
    </div>
</div>
</div>
<script>
function showTab(id) {
    document.querySelectorAll('.tab').forEach((t,i)=> t.classList.toggle('active', ['login','create'][i]===id));
    document.querySelectorAll('.tab-panel').forEach((p,i)=> p.classList.toggle('active', ['tab-login','tab-create'][i]==='tab-'+id));
}
</script>

<?php else: ?>
<!-- ── APP ───────────────────────────────────────────────────────────────── -->

<?php
// Test definitions: [command_key, label, safety, service_section]
$allTests = [
    // Ping
    ['ping_get',    'GET',    'read',    'ping'],
    ['ping_put',    'PUT',    'write',   'ping'],
    ['ping_post',   'POST',   'write',   'ping'],
    ['ping_delete', 'DELETE', 'write',   'ping'],

    // Contacts – read
    ['contact_count',             'Count',                     'read',    'contacts'],
    ['contact_get_by_email',      'Get by email',              'read',    'contacts'],
    ['contact_get_by_ext_id',     'Get by external ID',        'read',    'contacts'],
    ['contact_list',              'List (paginated)',           'read',    'contacts'],
    ['contact_list_update_after', 'List (updated after)',       'read',    'contacts'],
    ['contact_blocked',           'Blocked contacts',          'read',    'contacts'],
    ['contact_custom_fields',     'Custom fields list',        'read',    'contacts'],
    // Contacts – write
    ['contact_create',            'Create',                    'write',   'contacts'],
    ['contact_create_ext_id',     'Create by external ID',     'write',   'contacts'],
    ['contact_update',            'Update',                    'write',   'contacts'],
    ['contact_sync',              'Synchronize',               'write',   'contacts'],
    ['contact_create_custom_field','Create custom field',      'write',   'contacts'],
    ['contact_rename_custom_field','Rename custom field',      'write',   'contacts'],
    ['contact_del_custom_field_vals','Delete custom field values','write', 'contacts'],
    ['contact_del_custom_field',  'Delete custom field',       'write',   'contacts'],
    ['contact_del_std_field_vals','Delete std field values',   'write',   'contacts'],
    // Contacts – destructive
    ['contact_unsubscribe_email', 'Unsubscribe (by email)',    'send',    'contacts'],
    ['contact_unsubscribe_id',    'Unsubscribe (by ID)',       'send',    'contacts'],
    ['contact_delete',            'Delete (by email)',         'destroy', 'contacts'],
    ['contact_delete_ext_id',     'Delete (by external ID)',   'destroy', 'contacts'],
    // Preference categories
    ['pref_cat_list',   'List preference categories',  'read',  'contacts'],
    ['pref_cat_create', 'Create preference category',  'write', 'contacts'],
    ['pref_cat_get',    'Get preference category',     'read',  'contacts'],
    ['pref_cat_update', 'Update preference category',  'write', 'contacts'],
    ['pref_cat_delete', 'Delete preference category',  'destroy','contacts'],
    ['pref_list',       'List preferences',            'read',  'contacts'],
    ['pref_create',     'Create preference',           'write', 'contacts'],
    ['pref_get',        'Get preference',              'read',  'contacts'],
    ['pref_update',     'Update preference',           'write', 'contacts'],
    ['pref_delete',     'Delete preference',           'destroy','contacts'],

    // Contact filters
    ['cf_count',   'Count',            'read',    'contactfilters'],
    ['cf_list',    'List (paginated)', 'read',    'contactfilters'],
    ['cf_get',     'Get (config ID)','read',    'contactfilters'],
    ['cf_create',  'Create',         'write',   'contactfilters'],
    ['cf_update',  'Update name',    'write',   'contactfilters'],
    ['cf_refresh', 'Refresh',        'write',   'contactfilters'],
    ['cf_delete',  'Delete created', 'destroy', 'contactfilters'],

    // Target groups
    ['tg_count',  'Count',            'read',    'targetgroups'],
    ['tg_list',   'List (paginated)', 'read',    'targetgroups'],
    ['tg_create', 'Create',  'write',   'targetgroups'],
    ['tg_get',    'Get',     'read',    'targetgroups'],
    ['tg_delete', 'Delete',  'destroy', 'targetgroups'],

    // Mailings – read
    ['mail_list',       'List by type (paginated)',  'read',  'mailings'],
    ['mail_list_state', 'List by state (paginated)', 'read',  'mailings'],
    ['mail_subject',    'Get subject',           'read',  'mailings'],
    ['mail_sender',     'Get sender',            'read',  'mailings'],
    ['mail_sender_alias','Get sender alias',     'read',  'mailings'],
    ['mail_replyto',    'Get reply-to',          'read',  'mailings'],
    ['mail_preview',    'Get preview text',      'read',  'mailings'],
    ['mail_tags',       'Get tags',              'read',  'mailings'],
    ['mail_locale',     'Get locale',            'read',  'mailings'],
    ['mail_html',       'Get HTML',              'read',  'mailings'],
    ['mail_archive_url','Get archive URL',       'read',  'mailings'],
    ['mail_report_url', 'Get report URL',        'read',  'mailings'],
    ['mail_domain',     'Get domain',            'read',  'mailings'],
    ['mail_state',      'Get state',             'read',  'mailings'],
    ['mail_type',       'Get type',              'read',  'mailings'],
    ['mail_cf_restrictions','CF restrictions count','read','mailings'],
    ['mail_custom_props','Get custom properties','read',  'mailings'],
    ['mail_exists',     'Check exists by name',  'read',  'mailings'],
    // Mailings – write
    ['mail_create',      'Create draft',         'write', 'mailings'],
    ['mail_set_html',    'Set HTML',             'write', 'mailings'],
    ['mail_set_sender',  'Set sender',           'write', 'mailings'],
    ['mail_set_replyto', 'Set reply-to',         'write', 'mailings'],
    ['mail_set_preview', 'Set preview text',     'write', 'mailings'],
    ['mail_set_tags',    'Set tags',             'write', 'mailings'],
    ['mail_set_locale',  'Set locale',           'write', 'mailings'],
    ['mail_add_custom_prop','Add custom prop',   'write', 'mailings'],
    ['mail_upd_custom_prop','Update custom prop','write', 'mailings'],
    ['mail_del_custom_prop','Delete custom prop','write', 'mailings'],
    ['mail_disable_qos', 'Disable QoS checks',  'write', 'mailings'],
    ['mail_copy',        'Copy mailing',         'write', 'mailings'],
    ['mail_delete',      'Delete created',       'destroy','mailings'],

    // Media
    ['media_templates',      'CMS1 templates', 'read', 'media'],
    ['media_cms2_templates', 'CMS2 templates', 'read', 'media'],

    // Reports
    ['rep_recipients',         'Recipients count',         'read', 'reports'],
    ['rep_recipients_list',    'Recipients',               'read', 'reports'],
    ['rep_opens',              'Opens count',              'read', 'reports'],
    ['rep_opens_list',         'Opens',                    'read', 'reports'],
    ['rep_unique_opens',       'Unique opens count',       'read', 'reports'],
    ['rep_unique_opens_list',  'Unique opens',             'read', 'reports'],
    ['rep_clicks',             'Clicks count',             'read', 'reports'],
    ['rep_clicks_list',        'Clicks',                   'read', 'reports'],
    ['rep_unique_clicks',      'Unique clicks count',      'read', 'reports'],
    ['rep_unique_clicks_list', 'Unique clicks',            'read', 'reports'],
    ['rep_bounces',            'Bounces count',            'read', 'reports'],
    ['rep_bounces_list',       'Bounces',                  'read', 'reports'],
    ['rep_unsubs',             'Unsubscribers count',      'read', 'reports'],
    ['rep_unsubs_list',        'Unsubscribers',            'read', 'reports'],
    ['rep_unsub_reasons',      'Unsubscriber reasons',     'read', 'reports'],
    ['rep_subscribers',        'Subscribers count',        'read', 'reports'],
    ['rep_subscribers_list',   'Subscribers',              'read', 'reports'],
    ['rep_blocks',             'Blocks count',             'read', 'reports'],
    ['rep_blocks_list',        'Blocks',                   'read', 'reports'],
    ['rep_conversions',        'Conversions count',        'read', 'reports'],
    ['rep_conversions_list',   'Conversions',              'read', 'reports'],
    ['rep_uniq_conv',          'Unique conversions count', 'read', 'reports'],
    ['rep_uniq_conv_list',     'Unique conversions',       'read', 'reports'],
    ['rep_revenue',            'Revenue',                  'read', 'reports'],
    ['rep_mailing_summaries',  'Mailing summaries',        'read', 'reports'],

    // Transactions
    ['tx_type_count',  'Type count',            'read',    'transactions'],
    ['tx_type_list',   'Type list (paginated)', 'read',    'transactions'],
    ['tx_type_get',    'Get type (config ID)','read',    'transactions'],
    ['tx_type_create', 'Create type',         'write',   'transactions'],
    ['tx_type_create2','Create complex type', 'write',   'transactions'],
    ['tx_send',        'Send transaction',    'send',    'transactions'],
    ['tx_send_multi',  'Send 3 transactions', 'send',    'transactions'],
    ['tx_recent',      'Get recent',          'read',    'transactions'],
    ['tx_get',         'Get (config ID)',     'read',    'transactions'],
    ['tx_delete',      'Delete (config ID)',  'destroy', 'transactions'],
    ['tx_delete_by_date','Delete by date',   'destroy', 'transactions'],
    ['tx_type_delete', 'Delete created type','destroy', 'transactions'],

    // Blacklists
    ['bl_list',    'List',             'read',    'blacklists'],
    ['bl_get',     'Get (config ID)',  'read',    'blacklists'],
    ['bl_entries', 'Add entry',        'write',   'blacklists'],

    // Mailing blacklists
    ['mbl_list',    'List',    'read',    'mailingblacklists'],
    ['mbl_create',  'Create',  'write',   'mailingblacklists'],
    ['mbl_get',     'Get',     'read',    'mailingblacklists'],
    ['mbl_update',  'Update',  'write',   'mailingblacklists'],
    ['mbl_entries', 'Add entries','write','mailingblacklists'],
    ['mbl_get_entries','Get entries','read','mailingblacklists'],
    ['mbl_delete',  'Delete',  'destroy', 'mailingblacklists'],

    // Account
    ['acc_info',     'Account info',         'read',  'account'],
    ['acc_ph_list',  'List placeholders',    'read',  'account'],
    ['acc_ph_set',   'Set placeholders',     'write', 'account'],
    ['acc_ph_update','Update placeholders',  'write', 'account'],
    ['acc_ph_delete','Delete placeholder',   'destroy','account'],
    ['acc_domains',  'Mailing domains',      'read',  'account'],

    // Webhooks
    ['wh_list',   'List',             'read',    'webhooks'],
    ['wh_get',    'Get (config ID)',  'read',    'webhooks'],
    ['wh_create', 'Create',          'write',   'webhooks'],
    ['wh_get_created','Get created', 'read',    'webhooks'],
    ['wh_update', 'Update',          'write',   'webhooks'],
    ['wh_delete', 'Delete created',  'destroy', 'webhooks'],

    // Data Extensions
    ['de_datatypes',        'Get data types',                                    'read',    'dataextensions'],
    ['de_list',             'List extensions',                                   'read',    'dataextensions'],
    ['de_list_paged',       'List extensions (page 2)',                          'read',    'dataextensions'],
    ['de_create',           'Create extension',                                  'write',   'dataextensions'],
    ['de_get',              'Get extension',                                     'read',    'dataextensions'],
    ['de_get_fields',       'Get extension fields',                              'read',    'dataextensions'],
    ['de_update',           'Update extension',                                  'write',   'dataextensions'],
    ['de_records',          'Get records',                                       'read',    'dataextensions'],
    ['de_records_desc',     'Get records (desc)',                                'read',    'dataextensions'],
    ['de_records_filtered', 'Get records (filtered)',                            'read',    'dataextensions'],
    ['de_sync_upsert',      'Synchronize records (UPSERT)',                      'write',   'dataextensions'],
    ['de_sync_insert_ign',  'Synchronize records (INSERT_IGNORE_DUPLICATES)',    'write',   'dataextensions'],
    ['de_delete_records',   'Delete all records',                                'destroy', 'dataextensions'],
    ['de_sync_empty',       'Synchronize records (empty payload)',               'read',    'dataextensions'],
    ['de_delete',           'Delete extension',                                  'destroy', 'dataextensions'],
];

$sections = [
    'ping'              => 'Ping',
    'contacts'          => 'Contacts',
    'contactfilters'    => 'Contact Filters',
    'targetgroups'      => 'Target Groups',
    'mailings'          => 'Mailings',
    'media'             => 'Media',
    'reports'           => 'Reports',
    'transactions'      => 'Transactions',
    'blacklists'        => 'Blacklists',
    'mailingblacklists' => 'Mailing Blacklists',
    'account'           => 'Account',
    'webhooks'          => 'Webhooks',
    'dataextensions'    => 'Data Extensions',
];

$safetyBadge = [
    'read'    => ['read-only', 'badge-read'],
    'write'   => ['write',     'badge-write'],
    'send'    => ['send',      'badge-send'],
    'destroy' => ['destroy',   'badge-destroy'],
];

$activeSection = $_GET['section'] ?? 'ping';
$testsInSection = array_filter($allTests, fn($t) => $t[3] === $activeSection);
$sectionLabel = $sections[$activeSection] ?? $activeSection;
?>

<div class="app">
<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>Maileon Playground</h1>
        <p>API integration tests</p>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($sections as $key => $label): ?>
        <div class="nav-item <?= $activeSection === $key ? 'active' : '' ?>"
             onclick="location='?section=<?= $key ?>'"><?= htmlspecialchars($label) ?></div>
        <?php endforeach; ?>
        <hr class="sep">
        <div class="nav-item" onclick="location='?section=__config'">⚙ Configuration</div>
    </nav>
    <div class="sidebar-footer">
        <small><span class="tip-wrap" style="border-bottom:1px dotted var(--muted);cursor:help">AES-256 encrypted vault<span class="tip-box tip-right"><h4>Security model</h4><ul>
            <li>AES-256-CBC, fresh random IV on every save</li>
            <li>Key: PBKDF2-SHA256, 100,000 iterations + random 16-byte salt</li>
            <li>Cookie = salt + IV + ciphertext — key lives in session only</li>
            <li>Credentials in plain text only in PHP memory during requests</li>
            <li>Losing your password makes the vault permanently unreadable</li>
        </ul></span></span></small>
        <form method="post" style="margin:0">
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-danger btn-sm" type="submit">Lock</button>
        </form>
    </div>
</aside>

<!-- Main -->
<main class="main">

<?php if ($activeSection === '__config'): ?>
<!-- ── CONFIG ── -->
<div class="topbar">
    <h2>Configuration</h2>
    <?php if ($saved): ?><span style="color:var(--success);font-size:.85rem">✓ Saved</span><?php endif; ?>
</div>
<div class="content">
<?php if ($saved): ?>
<div class="success-box">Configuration saved and encrypted in your vault cookie.</div>
<?php endif; ?>
<form method="post">
<input type="hidden" name="action" value="save_config">

<div class="panel">
    <div class="panel-head"><h3>API Connection</h3></div>
    <div class="panel-body">
        <div class="form-row" style="margin-bottom:12px">
            <div class="form-group">
                <label>API Key</label>
                <input type="text" name="api_key" class="sensitive" value="<?= htmlspecialchars($vault['api_key'] ?? '') ?>" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX">
            </div>
            <div class="form-group">
                <label>Base URI</label>
                <input type="text" name="base_uri" value="<?= htmlspecialchars($vault['base_uri'] ?? 'https://api.maileon.com/1.0') ?>">
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <input type="hidden" name="debug" value="0">
            <input type="checkbox" name="debug" id="cfg-debug" value="1" <?= !empty($vault['debug']) ? 'checked' : '' ?> style="accent-color:var(--accent);cursor:pointer">
            <label for="cfg-debug" style="cursor:pointer;font-size:.88rem;color:var(--text)">Show debug tab in results <span style="color:var(--muted);font-size:.8rem">(curl verbose log per call)</span></label>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Test Data</h3></div>
    <div class="panel-body">
        <div class="form-row" style="margin-bottom:12px">
            <div class="form-group">
                <label>Test email 1 <span style="color:var(--warning)">will be created/deleted</span></label>
                <input type="text" name="test_email" value="<?= htmlspecialchars($vault['test_email'] ?? '') ?>" placeholder="api-test@example.com">
            </div>
            <div class="form-group">
                <label>Test email 2 <span style="color:var(--warning)">will be created/deleted</span></label>
                <input type="text" name="test_email2" value="<?= htmlspecialchars($vault['test_email2'] ?? '') ?>" placeholder="api-test-2@example.com">
            </div>
        </div>
        <div class="form-row" style="margin-bottom:12px">
            <div class="form-group">
                <label>External ID 1</label>
                <input type="text" name="test_external_id" value="<?= htmlspecialchars($vault['test_external_id'] ?? '') ?>" placeholder="php-api-ext-001">
            </div>
            <div class="form-group">
                <label>External ID 2</label>
                <input type="text" name="test_external_id2" value="<?= htmlspecialchars($vault['test_external_id2'] ?? '') ?>" placeholder="php-api-ext-002">
            </div>
        </div>
        <hr class="sep">
        <p style="color:var(--muted);font-size:.82rem;margin-bottom:12px">Pre-existing objects (leave 0 to skip dependent tests)</p>
        <div class="form-row cols3">
            <div class="form-group">
                <label>Mailing ID (reports)</label>
                <input type="number" name="test_mailing_id" value="<?= htmlspecialchars((string)($vault['test_mailing_id'] ?? '')) ?>" placeholder="0">
            </div>
            <div class="form-group">
                <label>Contact Filter ID</label>
                <input type="number" name="test_cf_id" value="<?= htmlspecialchars((string)($vault['test_cf_id'] ?? '')) ?>" placeholder="0">
            </div>
            <div class="form-group">
                <label>Blacklist ID</label>
                <input type="number" name="test_blacklist_id" value="<?= htmlspecialchars((string)($vault['test_blacklist_id'] ?? '')) ?>" placeholder="0">
            </div>
            <div class="form-group">
                <label>Data Extension ID</label>
                <input type="number" name="test_de_id" value="<?= htmlspecialchars((string)($vault['test_de_id'] ?? '')) ?>" placeholder="0">
            </div>
            <div class="form-group">
                <label>Transaction Type ID</label>
                <input type="number" name="test_tx_type_id" value="<?= htmlspecialchars((string)($vault['test_tx_type_id'] ?? '')) ?>" placeholder="0">
            </div>
            <div class="form-group">
                <label>Transaction ID</label>
                <input type="text" name="test_tx_id" value="<?= htmlspecialchars($vault['test_tx_id'] ?? '') ?>" placeholder="">
            </div>
            <div class="form-group">
                <label>Webhook ID</label>
                <input type="number" name="test_webhook_id" value="<?= htmlspecialchars((string)($vault['test_webhook_id'] ?? '')) ?>" placeholder="0">
            </div>
            <div class="form-group">
                <label>DOI Mailing Key</label>
                <input type="text" name="test_doi_mailing_key" value="<?= htmlspecialchars($vault['test_doi_mailing_key'] ?? '') ?>">
            </div>
        </div>
    </div>
</div>

<div style="display:flex;justify-content:flex-end">
    <button class="btn btn-primary" type="submit">Save &amp; encrypt</button>
</div>
</form>
</div>

<?php else: ?>
<!-- ── TEST SECTION ── -->
<div class="topbar">
    <h2><?= htmlspecialchars($sectionLabel) ?> Tests</h2>
    <div class="topbar-actions">
<button class="btn btn-primary" id="run-btn" onclick="runTests()">
            <span id="run-label">Run</span>
        </button>
    </div>
</div>
<div class="content">

<?php if (empty($vault['api_key'])): ?>
<div class="error-box">API key not configured. <a href="?section=__config" style="color:var(--accent2)">Go to Configuration →</a></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h3>Select tests</h3></div>
    <div class="panel-body">
        <div id="test-search-wrap" style="position:relative">
            <input type="text" id="test-search" placeholder="Filter tests…" oninput="filterTests()" style="padding-right:28px">
            <button id="test-search-clear" onclick="clearFilter()" title="Clear filter"
                    style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;
                           border:none;color:var(--muted);cursor:pointer;padding:2px 5px;
                           font-size:1.1rem;line-height:1;display:none">×</button>
        </div>
        <div class="check-grid" id="test-checks">
        <?php foreach ($testsInSection as $test): ?>
            <?php [$key, $label, $safety, ] = $test; ?>
            <?php [$badgeText, $badgeClass] = $safetyBadge[$safety] ?? ['?', '']; ?>
            <div class="check-wrap">
                <label class="check-item">
                    <input type="radio" name="test" value="<?= htmlspecialchars($key) ?>" onchange="updateParamsPanel()">
                    <span><?= htmlspecialchars($label) ?></span>
                    <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                </label>
                <button type="button" class="code-btn" onclick="showCode('<?= htmlspecialchars($key, ENT_QUOTES) ?>')" title="Show PHP snippet">&lt;/&gt;</button>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if ($activeSection === 'reports' || $activeSection === 'blacklists' || $activeSection === 'dataextensions'): ?>
        <p class="warning-note">⚠ Some tests require pre-existing IDs from <a href="?section=__config" style="color:var(--accent2)">Configuration</a>. Tests with missing IDs are skipped automatically.</p>
        <?php endif; ?>
    </div>
</div>

<div id="params-panel" class="panel" style="display:none">
    <div class="panel-head" style="justify-content:flex-start;gap:14px">
        <h3>Parameters</h3>
        <span style="color:var(--muted);font-size:.8rem">Values are saved in your browser (localStorage) and pre-filled next visit</span>
    </div>
    <div class="panel-body" id="params-body"></div>
    <div class="panel-foot" style="padding:10px 16px;border-top:1px solid var(--border)">
        <button class="btn btn-primary" onclick="runTests()">&#9654; Run selected</button>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Output</h3>
        <button class="btn btn-outline btn-sm" onclick="clearOutput()">Clear</button>
    </div>
    <div class="panel-body">
        <div class="output-panel" id="output">
            <span style="color:var(--muted)">Select a test and click "Run".</span>
        </div>
    </div>
</div>

</div><!-- .content -->
<?php endif; ?>

</main>
</div><!-- .app -->

<script>
const section      = <?= json_encode($activeSection) ?>;
const VAULT_PARAMS = <?= json_encode([
    'test_email'        => $vault['test_email']        ?? '',
    'test_email2'       => $vault['test_email2']       ?? '',
    'test_external_id'  => $vault['test_external_id']  ?? '',
    'test_external_id2' => $vault['test_external_id2'] ?? '',
    'test_mailing_id'   => (string)($vault['test_mailing_id']   ?? ''),
    'test_cf_id'        => (string)($vault['test_cf_id']        ?? ''),
    'test_blacklist_id' => (string)($vault['test_blacklist_id'] ?? ''),
    'test_de_id'        => (string)($vault['test_de_id']        ?? ''),
    'test_tx_type_id'   => (string)($vault['test_tx_type_id']   ?? ''),
    'test_tx_id'        => $vault['test_tx_id']        ?? '',
    'test_webhook_id'   => (string)($vault['test_webhook_id']   ?? ''),
    'tg_name'          => 'php-ui-test-tg',
    'mail_name'        => 'php-ui-test-mailing',
    'mail_subject'     => 'UI Test Subject',
    'mail_type_filter' => 'regular',
    'mail_state_filter'=> 'draft',
    'mail_fields'      => '',
    'webhook_url'    => '',
    'cf_filter_name' => 'php-ui-test-filter',
    'pref_cat_name'  => 'php-ui-test-cat',
    'pref_name'      => 'php-ui-test-pref',
    'cf_field_name'  => 'PhpUiTestField',
    'mbl_name'       => 'php-ui-test-mbl',
    'tx_type_name'   => 'php_ui_test_type',
    'tx_type_name2'  => 'php_ui_test_type2',
    'page_index'     => '1',
    'page_size'      => '100',
    'de_create_body' => json_encode([
        'name'                => 'my_extension',
        'description'         => 'Description',
        'retention_policy'    => 'RECORDS_DURATION',
        'delete_interval'     => 7,
        'delete_interval_unit'=> 'DAYS',
        'fields'              => [
            ['name' => 'id',    'description' => 'A unique ID',                        'nullable' => false, 'unique_identifier' => true,  'data_type' => 'integer'],
            ['name' => 'email', 'description' => "The contact's email address",        'nullable' => false, 'unique_identifier' => true,  'data_type' => 'contact_email'],
            ['name' => 'text',  'description' => 'Some description for this field',    'nullable' => true,  'unique_identifier' => false, 'data_type' => 'string', 'default_value' => 'lorem ipsum'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    'de_update_body' => json_encode([
        'description' => 'Updated description',
        'fields'      => [
            ['name' => 'score', 'data_type' => 'integer', 'nullable' => true],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    'de_sync_body'   => json_encode([
        ['id' => 1, 'email' => 'alice@example.com', 'text' => 'First row'],
        ['id' => 2, 'email' => 'bob@example.com',   'text' => 'Second row'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    'contact_body'   => json_encode([
        'permission'      => 'doi',
        'standard_fields' => ['FIRSTNAME' => 'John', 'LASTNAME' => 'Doe'],
        'custom_fields'   => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    'rep_from_date'           => '',
    'rep_to_date'             => '',
    'rep_mailing_ids'         => '',
    'rep_contact_ids'         => '',
    'rep_contact_emails'      => '',
    'rep_contact_ext_ids'     => '',
    'rep_excl_anon'           => 'false',
    'rep_format_filter'       => '',
    'rep_social_filter'       => '',
    'rep_device_filter'       => '',
    'rep_link_id_filter'      => '',
    'rep_link_url_filter'     => '',
    'rep_link_tag_filter'     => '',
    'rep_bounce_status_filter'=> '',
    'rep_bounce_type'         => '',
    'rep_bounce_source'       => '',
    'rep_unsub_source'        => '',
    'rep_reasons'             => '',
    'rep_old_status'          => '',
    'rep_new_status'          => '',
    'rep_site_ids'            => '',
    'rep_goal_ids'            => '',
    'rep_link_ids'            => '',
    'rep_order'               => 'count',
    'rep_asc'                 => 'true',
    'rep_page_index'          => '1',
    'rep_page_size'           => '100',
], JSON_HEX_TAG|JSON_HEX_AMP) ?>;
const PARAM_LABELS = {
    test_email:        'Test email',
    test_email2:       'Test email 2',
    test_external_id:  'External ID',
    test_external_id2: 'External ID 2',
    test_mailing_id:   'Mailing ID',
    test_cf_id:        'Contact Filter ID',
    test_blacklist_id: 'Blacklist ID',
    test_de_id:        'Data Extension ID',
    test_tx_type_id:   'Transaction Type ID',
    test_tx_id:        'Transaction ID',
    test_webhook_id:   'Webhook ID',
    tg_name:            'Target group name',
    mail_name:          'Mailing name',
    mail_subject:       'Mailing subject',
    mail_type_filter:   'Mailing type',
    mail_state_filter:  'Mailing state',
    mail_fields:        'Fields to return',
    webhook_url:       'Webhook URL',
    cf_filter_name:    'Contact filter name',
    pref_cat_name:     'Pref. category name',
    pref_name:         'Preference name',
    cf_field_name:     'Custom field name',
    mbl_name:          'Mailing blacklist name',
    tx_type_name:      'Transaction type name',
    tx_type_name2:     'Transaction type name 2',
    page_index:        'Page index',
    page_size:         'Page size',
    de_create_body:    'Extension definition (JSON)',
    de_update_body:    'Update payload (JSON)',
    de_sync_body:      'Records (JSON array)',
    contact_body:      'Contact fields (JSON)',
    rep_from_date:            'From date (e.g. 2024-01-01)',
    rep_to_date:              'To date (e.g. 2024-12-31)',
    rep_mailing_ids:          'Mailing IDs (CSV, e.g. 123,456)',
    rep_contact_ids:          'Contact IDs (CSV ints)',
    rep_contact_emails:       'Contact emails (CSV)',
    rep_contact_ext_ids:      'External IDs (CSV)',
    rep_excl_anon:            'Exclude anonymous (true/false)',
    rep_format_filter:        'Format filter',
    rep_social_filter:        'Social network filter',
    rep_device_filter:        'Device type filter',
    rep_link_id_filter:       'Link ID filter',
    rep_link_url_filter:      'Link URL filter',
    rep_link_tag_filter:      'Link tag filter',
    rep_bounce_status_filter: 'Bounce status code filter',
    rep_bounce_type:          'Bounce type filter',
    rep_bounce_source:        'Bounce source filter',
    rep_unsub_source:         'Unsubscribe source filter',
    rep_reasons:              'Block reasons filter',
    rep_old_status:           'Old status filter',
    rep_new_status:           'New status filter',
    rep_site_ids:             'Site IDs (CSV ints)',
    rep_goal_ids:             'Goal IDs (CSV ints)',
    rep_link_ids:             'Link IDs (CSV ints)',
    rep_order:                'Order (count/date)',
    rep_asc:                  'Ascending (true/false)',
    rep_page_index:           'Page index',
    rep_page_size:            'Page size',
};
// Each entry: { r: [...required params], o: [...optional params] }
// Required = test skips/fails without it and has no session fallback.
// Optional = has a sensible default, a session carry-over, or a hardcoded fallback.
const TEST_PARAMS = {
    // ── Contacts ─────────────────────────────────────────────────────────────
    contact_get_by_email:          { r: ['test_email'],                               o: [] },
    contact_get_by_ext_id:         { r: ['test_external_id'],                         o: [] },
    contact_list:                  { r: [],  o: ['page_index', 'page_size'] },
    contact_list_update_after:     { r: [],  o: ['page_index', 'page_size'] },
    contact_blocked:               { r: [],  o: ['page_index', 'page_size'] },
    contact_create:                { r: ['test_email'],                               o: ['test_external_id', 'contact_body'] },
    contact_create_ext_id:         { r: ['test_email2', 'test_external_id2'],         o: ['contact_body'] },
    contact_update:                { r: ['test_email'],                               o: ['contact_body'] },
    contact_sync:                  { r: ['test_email'],                               o: ['contact_body'] },
    contact_unsubscribe_email:     { r: ['test_email'],                               o: [] },
    contact_delete:                { r: ['test_email'],                               o: [] },
    contact_delete_ext_id:         { r: ['test_external_id2'],                        o: [] },
    contact_create_custom_field:   { r: ['cf_field_name'],                            o: [] },
    contact_rename_custom_field:   { r: ['cf_field_name'],                            o: [] },
    contact_del_custom_field_vals: { r: ['cf_field_name'],                            o: [] },
    contact_del_custom_field:      { r: ['cf_field_name'],                            o: [] },
    // ── Preference categories ─────────────────────────────────────────────────
    pref_cat_create:               { r: ['pref_cat_name'],                            o: [] },
    pref_cat_get:                  { r: ['pref_cat_name'],                            o: [] },
    pref_cat_update:               { r: ['pref_cat_name'],                            o: [] },
    pref_cat_delete:               { r: ['pref_cat_name'],                            o: [] },
    pref_list:                     { r: ['pref_cat_name'],                            o: [] },
    pref_create:                   { r: ['pref_cat_name', 'pref_name'],               o: [] },
    pref_get:                      { r: ['pref_cat_name', 'pref_name'],               o: [] },
    pref_update:                   { r: ['pref_cat_name', 'pref_name'],               o: [] },
    pref_delete:                   { r: ['pref_cat_name', 'pref_name'],               o: [] },
    // ── Contact filters ───────────────────────────────────────────────────────
    cf_list:                       { r: [],                                            o: ['page_index', 'page_size'] },
    cf_get:                        { r: ['test_cf_id'],                                o: [] },
    cf_refresh:                    { r: ['test_cf_id'],                                o: [] },
    cf_create:                     { r: ['cf_filter_name'],                           o: [] },
    cf_update:                     { r: ['cf_filter_name'],                           o: [] },
    // ── Target groups ─────────────────────────────────────────────────────────
    tg_list:                       { r: [],                                            o: ['page_index', 'page_size'] },
    tg_create:                     { r: ['tg_name'],                                   o: [] },
    // ── Mailings – read (test_mailing_id optional: has $st session fallback) ──
    mail_list:                     { r: [],  o: ['mail_type_filter', 'mail_fields', 'page_index', 'page_size'] },
    mail_list_state:               { r: [],  o: ['mail_state_filter', 'mail_fields', 'page_index', 'page_size'] },
    mail_subject:                  { r: ['test_mailing_id'],                           o: [] },
    mail_sender:                   { r: ['test_mailing_id'],                           o: [] },
    mail_sender_alias:             { r: ['test_mailing_id'],                           o: [] },
    mail_replyto:                  { r: ['test_mailing_id'],                           o: [] },
    mail_preview:                  { r: ['test_mailing_id'],                           o: [] },
    mail_tags:                     { r: ['test_mailing_id'],                           o: [] },
    mail_locale:                   { r: ['test_mailing_id'],                           o: [] },
    mail_html:                     { r: ['test_mailing_id'],                           o: [] },
    mail_archive_url:              { r: ['test_mailing_id'],                           o: [] },
    mail_report_url:               { r: ['test_mailing_id'],                           o: [] },
    mail_domain:                   { r: ['test_mailing_id'],                           o: [] },
    mail_state:                    { r: ['test_mailing_id'],                           o: [] },
    mail_type:                     { r: ['test_mailing_id'],                           o: [] },
    mail_cf_restrictions:          { r: ['test_mailing_id'],                           o: [] },
    mail_custom_props:             { r: ['test_mailing_id'],                           o: [] },
    mail_copy:                     { r: ['test_mailing_id'],                           o: [] },
    mail_exists:                   { r: ['mail_name'],                                 o: [] },
    mail_create:                   { r: ['mail_name', 'mail_subject'],                 o: [] },
    mail_set_sender:               { r: [],                                            o: ['test_email'] },
    mail_set_replyto:              { r: [],                                            o: ['test_email'] },
    // ── Reports (all params optional; rep_mailing_ids falls back to test_mailing_id) ──
    rep_recipients:    { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon'] },
    rep_opens:         { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_format_filter','rep_social_filter','rep_device_filter','rep_excl_anon'] },
    rep_unique_opens:  { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon','rep_format_filter','rep_social_filter','rep_device_filter'] },
    rep_clicks:        { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_format_filter','rep_link_id_filter','rep_link_url_filter','rep_link_tag_filter','rep_social_filter','rep_device_filter','rep_excl_anon'] },
    rep_unique_clicks: { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon','rep_social_filter','rep_device_filter'] },
    rep_bounces:       { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_bounce_status_filter','rep_bounce_type','rep_bounce_source','rep_excl_anon'] },
    rep_unsubs:        { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_unsub_source','rep_excl_anon'] },
    rep_subscribers:   { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon'] },
    rep_blocks:        { r: [], o: ['rep_from_date','rep_to_date','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_reasons','rep_old_status','rep_new_status','rep_excl_anon'] },
    rep_conversions:   { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_site_ids','rep_goal_ids','rep_link_ids'] },
    rep_uniq_conv:     { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_site_ids','rep_goal_ids','rep_link_ids'] },
    rep_unsub_reasons: { r: [], o: ['rep_from_date','rep_to_date','rep_order','rep_asc','rep_page_index','rep_page_size'] },
    // ── Reports — list variants ───────────────────────────────────────────────
    rep_recipients_list:    { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon','rep_page_index','rep_page_size'] },
    rep_opens_list:         { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_format_filter','rep_social_filter','rep_device_filter','rep_excl_anon','rep_page_index','rep_page_size'] },
    rep_unique_opens_list:  { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon','rep_format_filter','rep_social_filter','rep_device_filter','rep_page_index','rep_page_size'] },
    rep_clicks_list:        { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_format_filter','rep_link_id_filter','rep_link_url_filter','rep_link_tag_filter','rep_social_filter','rep_device_filter','rep_excl_anon','rep_page_index','rep_page_size'] },
    rep_unique_clicks_list: { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon','rep_social_filter','rep_device_filter','rep_page_index','rep_page_size'] },
    rep_bounces_list:       { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_bounce_status_filter','rep_bounce_type','rep_bounce_source','rep_excl_anon','rep_page_index','rep_page_size'] },
    rep_unsubs_list:        { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_unsub_source','rep_excl_anon','rep_page_index','rep_page_size'] },
    rep_subscribers_list:   { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_excl_anon','rep_page_index','rep_page_size'] },
    rep_blocks_list:        { r: [], o: ['rep_from_date','rep_to_date','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_reasons','rep_old_status','rep_new_status','rep_excl_anon','rep_page_index','rep_page_size'] },
    rep_conversions_list:   { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_site_ids','rep_goal_ids','rep_link_ids','rep_page_index','rep_page_size'] },
    rep_uniq_conv_list:     { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_site_ids','rep_goal_ids','rep_link_ids','rep_page_index','rep_page_size'] },
    rep_revenue:            { r: [], o: ['rep_from_date','rep_to_date','rep_mailing_ids','rep_contact_ids','rep_contact_emails','rep_contact_ext_ids','rep_site_ids','rep_goal_ids','rep_link_ids'] },
    rep_mailing_summaries:  { r: [], o: ['rep_mailing_ids'] },
    // ── Transactions ──────────────────────────────────────────────────────────
    tx_type_list:                  { r: [],                                            o: ['page_index', 'page_size'] },
    tx_type_get:                   { r: ['test_tx_type_id'],                           o: [] },
    tx_send:                       { r: ['test_email', 'test_tx_type_id'],             o: [] },
    tx_send_multi:                 { r: ['test_email', 'test_tx_type_id'],             o: [] },
    tx_recent:                     { r: ['test_tx_type_id'],                           o: [] },
    tx_get:                        { r: ['test_tx_type_id', 'test_tx_id'],             o: [] },
    tx_delete:                     { r: ['test_tx_type_id', 'test_tx_id'],             o: [] },
    tx_delete_by_date:             { r: ['test_tx_type_id'],                           o: [] },
    tx_type_create:                { r: ['tx_type_name'],                              o: [] },
    tx_type_create2:               { r: ['tx_type_name2'],                             o: [] },
    // ── Blacklists ────────────────────────────────────────────────────────────
    bl_get:                        { r: ['test_blacklist_id'],                         o: [] },
    bl_entries:                    { r: ['test_blacklist_id'],                         o: [] },
    // ── Mailing blacklists ────────────────────────────────────────────────────
    mbl_create:                    { r: ['mbl_name'],                                  o: [] },
    // ── Webhooks ──────────────────────────────────────────────────────────────
    wh_get:                        { r: ['test_webhook_id'],                           o: [] },
    wh_create:                     { r: [],                                            o: ['webhook_url'] },
    // ── Data extensions ───────────────────────────────────────────────────────
    de_list:                       { r: [],                                            o: ['page_index', 'page_size'] },
    de_list_paged:                 { r: [],                                            o: ['page_index', 'page_size'] },
    de_create:                     { r: [],                                            o: ['de_create_body'] },
    de_get:                        { r: ['test_de_id'],                                o: [] },
    de_get_fields:                 { r: ['test_de_id'],                                o: [] },
    de_update:                     { r: ['test_de_id'],                                o: ['de_update_body'] },
    de_records:                    { r: ['test_de_id'],                                o: ['page_index', 'page_size'] },
    de_records_desc:               { r: ['test_de_id'],                                o: ['page_index', 'page_size'] },
    de_records_filtered:           { r: ['test_de_id'],                                o: ['page_index', 'page_size'] },
    de_sync_upsert:                { r: ['test_de_id'],                                o: ['de_sync_body'] },
    de_sync_insert_ign:            { r: ['test_de_id'],                                o: ['de_sync_body'] },
    de_sync_empty:                 { r: ['test_de_id'],                                o: [] },
    de_delete_records:             { r: ['test_de_id'],                                o: [] },
    de_delete:                     { r: ['test_de_id'],                                o: [] },
};

const PARAM_WIDGETS = {
    mail_type_filter:  { type: 'select', options: ['regular', 'trigger', 'doi'] },
    mail_state_filter: { type: 'select', options: ['draft', 'scheduled', 'queued', 'preparing', 'sending', 'paused', 'checks', 'blacklist', 'done', 'archiving', 'archived', 'canceled', 'failed', 'released'] },
    mail_fields:       { type: 'multiselect', options: ['type', 'state', 'name', 'scheduleTime'] },
};

function clearSelection() {
    document.querySelectorAll('#test-checks input').forEach(c => c.checked = false);
    updateParamsPanel();
}

function clearOutput() {
    document.getElementById('output').innerHTML = '<span style="color:var(--muted)">Output cleared.</span>';
}

async function runTests() {
    const checks = [...document.querySelectorAll('#test-checks input:checked')].map(c => c.value);
    if (!checks.length) { alert('No test selected.'); return; }

    const btn   = document.getElementById('run-btn');
    const label = document.getElementById('run-label');
    btn.classList.add('dim');
    label.innerHTML = '<span class="spinner"></span> Running…';

    document.getElementById('output').innerHTML = '';

    const allNeeded = new Set();
    checks.forEach(key => { const d = TEST_PARAMS[key] || {}; [...(d.r || []), ...(d.o || [])].forEach(p => allNeeded.add(p)); });
    const paramParts = [...allNeeded].map(p => {
        const el = document.getElementById('param-' + p);
        return el ? 'params%5B' + encodeURIComponent(p) + '%5D=' + encodeURIComponent(el.value) : '';
    }).filter(Boolean).join('&');

    const resp = await fetch(location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=run_tests&section=' + encodeURIComponent(section)
            + '&tests=' + checks.map(encodeURIComponent).join('&tests=')
            + (paramParts ? '&' + paramParts : ''),
    });

    const data = await resp.json().catch(() => ({ error: 'Invalid response from server.' }));

    btn.classList.remove('dim');
    label.textContent = 'Run';

    if (data.error) {
        document.getElementById('output').innerHTML = `<span style="color:var(--error)">${escHtml(data.error)}</span>`;
        return;
    }

    const out          = document.getElementById('output');
    const showDebugTab = !!(data.show_debug_tab);
    out.innerHTML = '';

    (data.results ?? []).forEach((result, idx) => {
        const div  = document.createElement('div');
        div.className = 'result-item';
        const si   = result.success ? 'result-ok' : 'result-fail';
        const icon = result.success ? '✓' : '✗';
        const sti  = statusInfo(result.status);
        const badge = result.status
            ? `<span class="status-badge" style="background:${sti.bg};color:${sti.fg}">${result.status} ${escHtml(sti.label)}</span>`
            : '';
        const methodBadge = result.req_method
            ? `<span class="method-badge method-${escHtml(result.req_method)}">${escHtml(result.req_method)}</span>`
            : '';
        const urlSpan = result.req_url
            ? `<span class="req-url" title="${escHtml(result.req_url)}">${escHtml(result.req_url)}</span>`
            : '';
        const timingBadge = (result.elapsed_ms != null)
            ? `<span class="timing-badge">${result.elapsed_ms}ms</span>`
            : '';

        let content = '';
        if (result.skipped) {
            content = `<div class="result-body" style="padding-top:6px">
                <span style="color:var(--muted)">${escHtml(result.message ?? 'Skipped.')}</span>
                <a href="?section=__config" style="color:var(--accent2);font-size:.78rem;margin-left:10px">Go to Configuration →</a>
            </div>`;
        } else {
            const pid = `r${idx}`;
            const reqDetails = `<details class="req-details">
                <summary><span class="req-arrow">▶</span> Request headers</summary>
                <div class="req-details-body">
                    <pre class="hdr-pre">${renderHeaderRows(result.req_headers)}</pre>
                </div>
            </details>`;

            const tabDefs = [
                ['Preview', `<div class="result-body">${escHtml(JSON.stringify(result.data, null, 2))}</div>`],
                ['Raw',     `<pre class="hdr-pre">${escHtml(result.body ?? '(empty)')}</pre>`],
                ['Headers', `<pre class="hdr-pre">${renderHeaderRows(result.res_headers)}</pre>`],
            ];
            if (result.toString_result != null) {
                tabDefs.push(['ToString', `<pre class="hdr-pre">${escHtml(result.toString_result)}</pre>`]);
            }
            if (result.debug_log) {
                tabDefs.push(['Debug', `<pre class="hdr-pre">${escHtml(result.debug_log)}</pre>`]);
            }
            const tabsHtml = tabDefs.map((t, i) =>
                `<div class="result-tab ${i===0?'active':''}" onclick="switchTab('${pid}',${i},this)">${escHtml(t[0])}</div>`
            ).join('');
            const panelsHtml = tabDefs.map((t, i) =>
                `<div id="${pid}-p${i}" class="result-tab-panel ${i===0?'active':''}">${t[1]}</div>`
            ).join('');

            content = `${reqDetails}
                <div class="result-tabs">${tabsHtml}</div>
                ${panelsHtml}`;
        }

        div.innerHTML = `
            <div class="result-header" style="flex-wrap:wrap">
                <span class="${si}">${icon}</span>
                <span class="label">${escHtml(result.label)}</span>
                ${methodBadge}${urlSpan}
                ${timingBadge}${badge}
                ${result.skipped ? '<span style="color:var(--muted);font-size:.78rem">skipped</span>' : ''}
            </div>
            ${content}`;
        out.appendChild(div);
    });
}

function saveParam(key, val) {
    try { localStorage.setItem('maileon_param_' + key, val); } catch(e) {}
}
function loadParam(key) {
    try { const v = localStorage.getItem('maileon_param_' + key); return v !== undefined ? v : null; } catch(e) { return null; }
}
function updateMultiParam(key, checkbox) {
    const hiddenEl = document.getElementById('param-' + key);
    if (!hiddenEl) return;
    const items = new Set(hiddenEl.value.split(',').map(s => s.trim()).filter(Boolean));
    if (checkbox.checked) items.add(checkbox.value);
    else items.delete(checkbox.value);
    hiddenEl.value = [...items].join(',');
    saveParam(key, hiddenEl.value);
}

function updateParamsPanel() {
    const ORDER = ['test_email','test_email2','test_external_id','test_external_id2',
                   'test_mailing_id','test_cf_id','test_blacklist_id','test_de_id',
                   'test_tx_type_id','test_tx_id','test_webhook_id',
                   'page_index','page_size',
                   'tg_name','mail_name','mail_subject','mail_type_filter','mail_state_filter','mail_fields','cf_filter_name',
                   'pref_cat_name','pref_name','cf_field_name','mbl_name',
                   'webhook_url','tx_type_name','tx_type_name2',
                   'de_create_body','de_update_body','de_sync_body','contact_body',
                   'rep_from_date','rep_to_date','rep_mailing_ids',
                   'rep_contact_ids','rep_contact_emails','rep_contact_ext_ids',
                   'rep_excl_anon',
                   'rep_format_filter','rep_social_filter','rep_device_filter',
                   'rep_link_id_filter','rep_link_url_filter','rep_link_tag_filter',
                   'rep_bounce_status_filter','rep_bounce_type','rep_bounce_source',
                   'rep_unsub_source',
                   'rep_reasons','rep_old_status','rep_new_status',
                   'rep_site_ids','rep_goal_ids','rep_link_ids',
                   'rep_order','rep_asc','rep_page_index','rep_page_size'];
    const checked  = [...document.querySelectorAll('#test-checks input:checked')].map(c => c.value);
    const seen     = new Set();
    const required = new Set();
    checked.forEach(key => {
        const d = TEST_PARAMS[key] || {};
        (d.r || []).forEach(p => { seen.add(p); required.add(p); });
        (d.o || []).forEach(p => seen.add(p));
    });
    const needed = ORDER.filter(p => seen.has(p));

    const panel = document.getElementById('params-panel');
    const body  = document.getElementById('params-body');
    if (!panel) return;
    if (!needed.length) { panel.style.display = 'none'; return; }

    // Prefer: in-page value > localStorage > vault default
    const current = {};
    needed.forEach(p => { const el = document.getElementById('param-' + p); if (el) current[p] = el.value; });

    panel.style.display = '';
    body.innerHTML = '<div class="form-row">' +
        needed.map(p => {
            const labelText = PARAM_LABELS[p] || p;
            const dflt      = VAULT_PARAMS[p] ?? '';
            const saved     = loadParam(p);
            const val       = current[p] !== undefined ? current[p] : (saved !== null ? saved : dflt);
            const isBody    = p.endsWith('_body');
            const req       = required.has(p);
            const isEmpty   = val === '' || val === '0';

            const reqMark = req
                ? `<span class="badge-req" title="Required — test skips without this">Required</span>`
                : `<span class="badge-opt">Optional</span>`;
            const configLink = (req && isEmpty)
                ? `<a href="?section=__config" style="color:var(--accent2);font-size:.7rem;margin-left:8px">→ Configure</a>`
                : '';
            const labelHtml = `${escHtml(labelText)}${reqMark}${configLink}`
                + `<span style="color:var(--muted);font-size:.7rem;margin-left:6px">${escHtml(p)}</span>`;

            const widget = PARAM_WIDGETS[p];
            if (widget) {
                if (widget.type === 'select') {
                    const safeVal = val || widget.options[0];
                    return `<div class="form-group">
                        <label>${labelHtml}</label>
                        <select id="param-${escHtml(p)}" class="param-sel"
                                onchange="saveParam('${escHtml(p)}', this.value)">
                            ${widget.options.map(opt =>
                                `<option value="${escHtml(opt)}"${safeVal === opt ? ' selected' : ''}>${escHtml(opt)}</option>`
                            ).join('')}
                        </select>
                    </div>`;
                }
                if (widget.type === 'multiselect') {
                    const selected = new Set(val.split(',').map(s => s.trim()).filter(Boolean));
                    return `<div class="form-group form-group-full">
                        <label>${labelHtml}</label>
                        <input type="hidden" id="param-${escHtml(p)}" value="${escHtml(val)}">
                        <div class="check-grid" style="margin-top:6px">
                            ${widget.options.map(opt =>
                                `<label class="check-item">
                                    <input type="checkbox" value="${escHtml(opt)}"${selected.has(opt) ? ' checked' : ''}
                                           onchange="updateMultiParam('${escHtml(p)}', this)">
                                    <span>${escHtml(opt)}</span>
                                </label>`
                            ).join('')}
                        </div>
                    </div>`;
                }
            }
            if (isBody) {
                return `<div class="form-group form-group-full">
                    <label>${labelHtml}</label>
                    <textarea id="param-${escHtml(p)}" class="body-ta" spellcheck="false"
                              oninput="saveParam('${escHtml(p)}', this.value)">${escHtml(val)}</textarea>
                </div>`;
            }
            const inputStyle = (req && isEmpty) ? ' style="border-color:rgba(255,107,107,.5)"' : '';
            return `<div class="form-group">
                <label>${labelHtml}</label>
                <input type="text" id="param-${escHtml(p)}" value="${escHtml(val)}"
                       placeholder="${escHtml(dflt || 'not set')}"${inputStyle}
                       oninput="saveParam('${escHtml(p)}', this.value)">
            </div>`;
        }).join('') + '</div>';
}

function filterTests() {
    const input    = document.getElementById('test-search');
    const clearBtn = document.getElementById('test-search-clear');
    const q        = input ? input.value.toLowerCase() : '';
    document.querySelectorAll('#test-checks .check-item').forEach(item => {
        item.style.display = (!q || item.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
    if (clearBtn) clearBtn.style.display = q ? '' : 'none';
    try { localStorage.setItem('maileon_test_filter_' + section, input ? input.value : ''); } catch(e) {}
}

function clearFilter() {
    const input = document.getElementById('test-search');
    if (input) { input.value = ''; filterTests(); input.focus(); }
}

function statusInfo(code) {
    const phrases = {
        200:'OK', 201:'Created', 202:'Accepted', 204:'No Content', 206:'Partial Content',
        301:'Moved Permanently', 302:'Found', 304:'Not Modified',
        400:'Bad Request', 401:'Unauthorized', 403:'Forbidden', 404:'Not Found',
        405:'Method Not Allowed', 409:'Conflict', 410:'Gone', 422:'Unprocessable Entity',
        429:'Too Many Requests',
        500:'Internal Server Error', 502:'Bad Gateway', 503:'Service Unavailable', 504:'Gateway Timeout'
    };
    const label = phrases[code] ?? '';
    if (!code) return { bg: 'rgba(136,146,176,.15)', fg: 'var(--muted)', label: '' };
    if (code >= 200 && code < 300) return { bg: 'rgba(81,207,102,.15)',  fg: 'var(--success)', label };
    if (code >= 300 && code < 400) return { bg: 'rgba(116,192,252,.15)', fg: '#74c0fc',        label };
    if (code >= 400 && code < 500) return { bg: 'rgba(252,196,25,.15)',  fg: 'var(--warning)', label };
    if (code >= 500 && code < 600) return { bg: 'rgba(255,107,107,.15)', fg: 'var(--error)',   label };
    return { bg: 'rgba(136,146,176,.15)', fg: 'var(--muted)', label };
}

function renderHeaderRows(obj) {
    if (!obj || typeof obj !== 'object') return '(none)';
    const entries = Object.entries(obj);
    if (!entries.length) return '(none)';
    return entries.map(([k, v]) => `${escHtml(k)}: ${escHtml(String(v ?? ''))}`).join('\n');
}

function switchTab(prefix, idx, el) {
    const item = el.closest('.result-item');
    item.querySelectorAll('.result-tab').forEach(t => t.classList.remove('active'));
    item.querySelectorAll('.result-tab-panel').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    const panel = item.querySelector('#' + prefix + '-p' + idx);
    if (panel) panel.classList.add('active');
}

// Restore saved filter and wire checkbox → params panel on page load
(function () {
    const input = document.getElementById('test-search');
    if (input) {
        try {
            const saved = localStorage.getItem('maileon_test_filter_' + section) || '';
            if (saved) { input.value = saved; filterTests(); }
        } catch(e) {}
    }
    const grid = document.getElementById('test-checks');
    if (grid) {
        grid.addEventListener('change', updateParamsPanel);
        updateParamsPanel();
    }
})();

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Code-snippet modal ─────────────────────────────────────────────────────

function closeCodeModal() {
    document.getElementById('code-overlay').style.display = 'none';
}

function copyCode() {
    const pre = document.getElementById('code-modal-pre');
    if (!pre) return;
    navigator.clipboard.writeText(pre.textContent).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = pre.textContent;
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    });
}

// Collect current param values (panel values override vault defaults)
function _collectParams() {
    const p = Object.assign({}, VAULT_PARAMS);
    document.querySelectorAll('[id^="param-"]').forEach(el => {
        p[el.id.replace(/^param-/, '')] = el.value;
    });
    return p;
}

// Helper: PHP var representation
function _phpVal(v) {
    if (v === '' || v === null || v === undefined) return 'null';
    if (!isNaN(v) && v !== '') return String(+v);
    return "'" + String(v).replace(/'/g, "\\'") + "'";
}
function _rDate(v)    { v=(v||'').trim(); return v?`strtotime(${_phpVal(v)}) * 1000`:'null'; }
function _rCsvI(v)    { if(!(v||'').trim()) return 'null'; const a=v.split(',').map(x=>+x.trim()).filter(n=>Number.isInteger(n)&&n>0); return a.length?`[${a.join(', ')}]`:'null'; }
function _rCsvS(v)    { if(!(v||'').trim()) return 'null'; const a=v.split(',').map(x=>x.trim()).filter(Boolean); return a.length?`[${a.map(s=>"'"+s.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+`'`).join(', ')}]`:'null'; }
function _rBool(v, d) { v=(v||'').trim().toLowerCase(); return v===''?(d?'true':'false'):(['true','1','yes'].includes(v)?'true':'false'); }
function _rStr(v)     { v=(v||'').trim(); return v?_phpVal(v):'null'; }

// Namespace shortcuts used in templates
const NS = {
    PING:        'de\\xqueue\\maileon\\api\\client\\utils\\PingService',
    CONTACT:     'de\\xqueue\\maileon\\api\\client\\contacts\\ContactsService',
    CONTACT_OBJ: 'de\\xqueue\\maileon\\api\\client\\contacts\\Contact',
    PERMISSION:  'de\\xqueue\\maileon\\api\\client\\contacts\\Permission',
    SYNC_MODE:   'de\\xqueue\\maileon\\api\\client\\contacts\\SynchronizationMode',
    STD_FIELD:   'de\\xqueue\\maileon\\api\\client\\contacts\\StandardContactField',
    PREF_CAT:    'de\\xqueue\\maileon\\api\\client\\contacts\\PreferenceCategory',
    PREF:        'de\\xqueue\\maileon\\api\\client\\contacts\\Preference',
    CF:          'de\\xqueue\\maileon\\api\\client\\contactfilters\\ContactfiltersService',
    CF_OBJ:      'de\\xqueue\\maileon\\api\\client\\contactfilters\\ContactFilter',
    TG:          'de\\xqueue\\maileon\\api\\client\\targetgroups\\TargetGroupsService',
    TG_OBJ:      'de\\xqueue\\maileon\\api\\client\\targetgroups\\TargetGroup',
    MAIL:        'de\\xqueue\\maileon\\api\\client\\mailings\\MailingsService',
    CUSTOM_PROP: 'de\\xqueue\\maileon\\api\\client\\mailings\\CustomProperty',
    MEDIA:       'de\\xqueue\\maileon\\api\\client\\media\\MediaService',
    REP:         'de\\xqueue\\maileon\\api\\client\\reports\\ReportsService',
    TX:          'de\\xqueue\\maileon\\api\\client\\transactions\\TransactionsService',
    TX_TYPE:     'de\\xqueue\\maileon\\api\\client\\transactions\\TransactionType',
    TX_OBJ:      'de\\xqueue\\maileon\\api\\client\\transactions\\Transaction',
    CONTACT_REF: 'de\\xqueue\\maileon\\api\\client\\transactions\\ContactReference',
    ATTR_TYPE:   'de\\xqueue\\maileon\\api\\client\\transactions\\AttributeType',
    DATA_TYPE:   'de\\xqueue\\maileon\\api\\client\\transactions\\DataType',
    BL:          'de\\xqueue\\maileon\\api\\client\\blacklists\\BlacklistsService',
    MBL:         'de\\xqueue\\maileon\\api\\client\\blacklists\\mailings\\MailingBlacklistsService',
    MBL_EXPR:    'de\\xqueue\\maileon\\api\\client\\blacklists\\mailings\\MailingBlacklistExpressions',
    ACC:         'de\\xqueue\\maileon\\api\\client\\account\\AccountService',
    ACC_PH:      'de\\xqueue\\maileon\\api\\client\\account\\AccountPlaceholder',
    WH:          'de\\xqueue\\maileon\\api\\client\\webhooks\\WebhooksService',
    WH_OBJ:      'de\\xqueue\\maileon\\api\\client\\webhooks\\Webhook',
    DE:          'de\\xqueue\\maileon\\api\\client\\dataextensions\\DataExtensionsService',
    DE_EXT:      'de\\xqueue\\maileon\\api\\client\\dataextensions\\DataExtension',
    DE_FLD:      'de\\xqueue\\maileon\\api\\client\\dataextensions\\DataExtensionField',
    DE_REC:      'de\\xqueue\\maileon\\api\\client\\dataextensions\\DataExtensionRecord',
};

function _tpl(uses, call) { return { uses, call }; }

// CODE_TEMPLATES: each entry is (params) => { uses: string[], call: string }
const CODE_TEMPLATES = {
    // Ping
    ping_get:    p => _tpl([NS.PING], '$svc = new PingService($config);\n$resp = $svc->pingGet();'),
    ping_put:    p => _tpl([NS.PING], '$svc = new PingService($config);\n$resp = $svc->pingPut();'),
    ping_post:   p => _tpl([NS.PING], '$svc = new PingService($config);\n$resp = $svc->pingPost();'),
    ping_delete: p => _tpl([NS.PING], '$svc = new PingService($config);\n$resp = $svc->pingDelete();'),
    // Contacts
    contact_count:           p => _tpl([NS.CONTACT], '$svc = new ContactsService($config);\n$resp = $svc->getContactsCount();'),
    contact_get_by_email:    p => _tpl([NS.CONTACT], `$svc = new ContactsService($config);\n$resp = $svc->getContactByEmail(${_phpVal(p.test_email)});`),
    contact_get_by_ext_id:   p => _tpl([NS.CONTACT], `$svc = new ContactsService($config);\n$resp = $svc->getContactsByExternalId(${_phpVal(p.test_external_id)});`),
    contact_list:            p => _tpl([NS.CONTACT], `$svc = new ContactsService($config);\n$resp = $svc->getContacts(${+p.page_index||1}, ${+p.page_size||100});`),
    contact_list_update_after: p => _tpl([NS.CONTACT], `$svc = new ContactsService($config);\n$ago30d = strtotime('-30 days') * 1000;\n$resp = $svc->getContacts(${+p.page_index||1}, ${+p.page_size||100}, [], [], $ago30d);`),
    contact_delete:          p => _tpl([NS.CONTACT], `$svc = new ContactsService($config);\n$resp = $svc->deleteContactByEmail(${_phpVal(p.test_email)});`),
    contact_unsubscribe_email: p => _tpl([NS.CONTACT], `$svc = new ContactsService($config);\n$resp = $svc->unsubscribeContactByEmail(${_phpVal(p.test_email)});`),
    // Reports
    rep_recipients:    p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getRecipientsCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rBool(p.rep_excl_anon,false)});`),
    rep_opens:         p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getOpensCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_format_filter)}, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)}, ${_rBool(p.rep_excl_anon,false)});`),
    rep_unique_opens:  p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUniqueOpensCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rBool(p.rep_excl_anon,false)}, ${_rStr(p.rep_format_filter)}, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)});`),
    rep_clicks:        p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getClicksCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_format_filter)}, ${_rStr(p.rep_link_id_filter)}, ${_rStr(p.rep_link_url_filter)}, ${_rStr(p.rep_link_tag_filter)}, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)}, ${_rBool(p.rep_excl_anon,false)});`),
    rep_unique_clicks: p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUniqueClicksCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rBool(p.rep_excl_anon,false)}, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)});`),
    rep_bounces:       p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getBouncesCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_bounce_status_filter)}, ${_rStr(p.rep_bounce_type)}, ${_rStr(p.rep_bounce_source)}, ${_rBool(p.rep_excl_anon,false)});`),
    rep_unsubs:        p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUnsubscribersCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_unsub_source)}, ${_rBool(p.rep_excl_anon,false)});`),
    rep_subscribers:   p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getSubscribersCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)||'[]'}, ${_rCsvI(p.rep_contact_ids)||'[]'}, ${_rCsvS(p.rep_contact_emails)||'[]'}, ${_rCsvS(p.rep_contact_ext_ids)||'[]'}, ${_rBool(p.rep_excl_anon,false)});`),
    rep_blocks:        p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getBlocksCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_reasons)}, ${_rStr(p.rep_old_status)}, ${_rStr(p.rep_new_status)}, ${_rBool(p.rep_excl_anon,false)});`),
    rep_conversions:   p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getConversionsCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)||'[]'}, ${_rCsvI(p.rep_contact_ids)||'[]'}, ${_rCsvS(p.rep_contact_emails)||'[]'}, ${_rCsvS(p.rep_contact_ext_ids)||'[]'}, ${_rCsvI(p.rep_site_ids)||'[]'}, ${_rCsvI(p.rep_goal_ids)||'[]'}, ${_rCsvI(p.rep_link_ids)||'[]'});`),
    rep_uniq_conv:     p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUniqueConversionsCount(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)||'[]'}, ${_rCsvI(p.rep_contact_ids)||'[]'}, ${_rCsvS(p.rep_contact_emails)||'[]'}, ${_rCsvS(p.rep_contact_ext_ids)||'[]'}, ${_rCsvI(p.rep_site_ids)||'[]'}, ${_rCsvI(p.rep_goal_ids)||'[]'}, ${_rCsvI(p.rep_link_ids)||'[]'});`),
    rep_unsub_reasons:      p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUnsubscriberReasons(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${(p.rep_order||'').trim()?_phpVal(p.rep_order):"'count'"}, ${_rBool(p.rep_asc,true)}, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    // Reports — list variants
    rep_recipients_list:    p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getRecipients(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rBool(p.rep_excl_anon,false)}, null, null, false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_opens_list:         p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getOpens(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_format_filter)}, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)}, false, ${_rBool(p.rep_excl_anon,false)}, null, null, false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_unique_opens_list:  p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUniqueOpens(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, false, ${_rBool(p.rep_excl_anon,false)}, null, null, false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100}, false, ${_rStr(p.rep_format_filter)}, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)});`),
    rep_clicks_list:        p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getClicks(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_format_filter)}, ${_rStr(p.rep_link_id_filter)}, ${_rStr(p.rep_link_url_filter)}, ${_rStr(p.rep_link_tag_filter)}, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)}, false, ${_rBool(p.rep_excl_anon,false)}, null, null, false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_unique_clicks_list: p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUniqueClicks(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, false, ${_rBool(p.rep_excl_anon,false)}, null, null, false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100}, false, ${_rStr(p.rep_social_filter)}, ${_rStr(p.rep_device_filter)});`),
    rep_bounces_list:       p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getBounces(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_bounce_status_filter)}, ${_rStr(p.rep_bounce_type)}, ${_rStr(p.rep_bounce_source)}, ${_rBool(p.rep_excl_anon,false)}, null, null, false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_unsubs_list:        p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUnsubscribers(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_unsub_source)}, false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100}, null, null, ${_rBool(p.rep_excl_anon,false)});`),
    rep_subscribers_list:   p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getSubscribers(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)||'[]'}, ${_rCsvI(p.rep_contact_ids)||'[]'}, ${_rCsvS(p.rep_contact_emails)||'[]'}, ${_rCsvS(p.rep_contact_ext_ids)||'[]'}, ${_rBool(p.rep_excl_anon,false)}, [], [], false, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_blocks_list:        p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getBlocks(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_contact_ids)}, ${_rCsvS(p.rep_contact_emails)}, ${_rCsvS(p.rep_contact_ext_ids)}, ${_rStr(p.rep_reasons)}, ${_rStr(p.rep_old_status)}, ${_rStr(p.rep_new_status)}, ${_rBool(p.rep_excl_anon,false)}, null, null, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_conversions_list:   p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getConversions(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)||'[]'}, ${_rCsvI(p.rep_contact_ids)||'[]'}, ${_rCsvS(p.rep_contact_emails)||'[]'}, ${_rCsvS(p.rep_contact_ext_ids)||'[]'}, ${_rCsvI(p.rep_site_ids)||'[]'}, ${_rCsvI(p.rep_goal_ids)||'[]'}, ${_rCsvI(p.rep_link_ids)||'[]'}, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_uniq_conv_list:     p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getUniqueConversions(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)||'[]'}, ${_rCsvI(p.rep_contact_ids)||'[]'}, ${_rCsvS(p.rep_contact_emails)||'[]'}, ${_rCsvS(p.rep_contact_ext_ids)||'[]'}, ${_rCsvI(p.rep_site_ids)||'[]'}, ${_rCsvI(p.rep_goal_ids)||'[]'}, ${_rCsvI(p.rep_link_ids)||'[]'}, ${+p.rep_page_index||1}, ${+p.rep_page_size||100});`),
    rep_revenue:            p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getRevenue(${_rDate(p.rep_from_date)}, ${_rDate(p.rep_to_date)}, ${_rCsvI(p.rep_mailing_ids)||'[]'}, ${_rCsvI(p.rep_contact_ids)||'[]'}, ${_rCsvS(p.rep_contact_emails)||'[]'}, ${_rCsvS(p.rep_contact_ext_ids)||'[]'}, ${_rCsvI(p.rep_site_ids)||'[]'}, ${_rCsvI(p.rep_goal_ids)||'[]'}, ${_rCsvI(p.rep_link_ids)||'[]'});`),
    rep_mailing_summaries:  p => _tpl([NS.REP], `$svc = new ReportsService($config);\n$resp = $svc->getMailingSummaries(${_rCsvI(p.rep_mailing_ids)||'[]'});`),
    // Mailings
    mail_list:       p => { const flds=(p.mail_fields||'').split(',').map(s=>s.trim()).filter(Boolean); return _tpl([NS.MAIL], `$types  = [${_phpVal(p.mail_type_filter||'regular')}]; // regular|trigger|doi\n$fields = ${flds.length?'['+flds.map(f=>"'"+f+"'").join(', ')+']':'[]'}; // type|state|name|scheduleTime\n$svc    = new MailingsService($config);\n$resp   = $svc->getMailingsByTypes($types, $fields, ${+p.page_index||1}, ${+p.page_size||100});`); },
    mail_list_state: p => { const flds=(p.mail_fields||'').split(',').map(s=>s.trim()).filter(Boolean); return _tpl([NS.MAIL], `$states = [${_phpVal(p.mail_state_filter||'draft')}]; // draft|done|sending|queued|...\n$fields = ${flds.length?'['+flds.map(f=>"'"+f+"'").join(', ')+']':'[]'}; // type|state|name|scheduleTime\n$svc    = new MailingsService($config);\n$resp   = $svc->getMailingsByStates($states, $fields, ${+p.page_index||1}, ${+p.page_size||100});`); },
    mail_subject:    p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getSubject(${+p.test_mailing_id||0});`),
    mail_html:       p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getHTMLContent(${+p.test_mailing_id||0});`),
    mail_copy:       p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->copyMailing(${+p.test_mailing_id||0});`),
    mail_exists:     p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getMailingIdByName(${_phpVal(p.mail_name)});`),
    mail_create:     p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->createMailing(${_phpVal(p.mail_name)}, ${_phpVal(p.mail_subject)});`),
    // Transactions
    tx_type_list: p => _tpl([NS.TX], `$svc = new TransactionsService($config);\n$resp = $svc->getTransactionTypes(${+p.page_index||1}, ${+p.page_size||100});`),
    tx_type_get:  p => _tpl([NS.TX], `$svc = new TransactionsService($config);\n$resp = $svc->getTransactionType(${+p.test_tx_type_id||0});`),
    tx_recent:    p => _tpl([NS.TX], `$svc = new TransactionsService($config);\n$resp = $svc->getRecentTransactions(${+p.test_tx_type_id||0}, 10);`),
    tx_get:       p => _tpl([NS.TX], `$svc = new TransactionsService($config);\n$resp = $svc->getTransaction(${+p.test_tx_type_id||0}, ${_phpVal(p.test_tx_id)});`),
    tx_delete:    p => _tpl([NS.TX], `$svc = new TransactionsService($config);\n$resp = $svc->deleteTransaction(${+p.test_tx_type_id||0}, ${_phpVal(p.test_tx_id)});`),
    // Blacklists
    bl_list:    p => _tpl([NS.BL], '$svc = new BlacklistsService($config);\n$resp = $svc->getBlacklists();'),
    bl_get:     p => _tpl([NS.BL], `$svc = new BlacklistsService($config);\n$resp = $svc->getBlacklist(${+p.test_blacklist_id||0});`),
    bl_entries: p => _tpl([NS.BL], `$svc = new BlacklistsService($config);\n$resp = $svc->addEntriesToBlacklist(${+p.test_blacklist_id||0}, ['user@example.com']);`),
    // Webhooks
    wh_list: p => _tpl([NS.WH], '$svc = new WebhooksService($config);\n$resp = $svc->getWebhooks();'),
    wh_get:  p => _tpl([NS.WH], `$svc = new WebhooksService($config);\n$resp = $svc->getWebhook(${+p.test_webhook_id||0});`),
    // Account
    acc_info:    p => _tpl([NS.ACC], '$svc = new AccountService($config);\n$resp = $svc->getAccountInfo();'),
    acc_ph_list: p => _tpl([NS.ACC], '$svc = new AccountService($config);\n$resp = $svc->getAccountPlaceholders();'),
    acc_domains: p => _tpl([NS.ACC], '$svc = new AccountService($config);\n$resp = $svc->getAccountMailingDomains();'),
    // Data extensions
    de_datatypes:    p => _tpl([NS.DE], '$svc = new DataExtensionsService($config);\n$resp = $svc->getDataTypes();'),
    de_list:         p => _tpl([NS.DE], `$svc = new DataExtensionsService($config);\n$resp = $svc->listDataExtensions(${+p.page_index||1}, ${+p.page_size||100});`),
    de_list_paged:   p => _tpl([NS.DE], `$svc = new DataExtensionsService($config);\n$resp = $svc->listDataExtensions(2, ${+p.page_size||100});`),
    de_get:          p => _tpl([NS.DE], `$svc = new DataExtensionsService($config);\n$resp = $svc->getDataExtension(${+p.test_de_id||0});`),
    de_get_fields:   p => _tpl([NS.DE], `$svc = new DataExtensionsService($config);\n$ext = $svc->getDataExtension(${+p.test_de_id||0})->getResult();\n$fields = array_column($ext->fields, 'name');`),
    de_create:       p => _tpl([NS.DE, NS.DE_EXT, NS.DE_FLD],
`$ext                   = new DataExtension();
$ext->name             = 'my_extension_' . date('His');
$ext->retention_policy = 'NONE';
$kf                    = new DataExtensionField();
$kf->name              = 'ref_id';
$kf->data_type         = 'string';
$kf->nullable          = false;
$kf->unique_identifier = true;
$ext->fields           = [$kf];
$svc  = new DataExtensionsService($config);
$resp = $svc->createDataExtension($ext); // returns new extension ID`),
    de_update:       p => _tpl([NS.DE, NS.DE_EXT, NS.DE_FLD],
`$id   = ${+p.test_de_id||0}; // test_de_id (or session-created ID)
$svc  = new DataExtensionsService($config);
$cur  = $svc->getDataExtension($id)->getResult();
$upd                       = new DataExtension();
$upd->name                 = $cur->name;
$upd->retention_policy     = $cur->retention_policy;
$upd->description          = 'Updated description';
$nf               = new DataExtensionField();
$nf->name         = 'score';
$nf->data_type    = 'integer';
$nf->nullable     = true;
$upd->fields      = [$nf];
$resp = $svc->updateDataExtension($id, $upd);`),
    de_delete:       p => _tpl([NS.DE], `$svc = new DataExtensionsService($config);\n$resp = $svc->deleteDataExtension(${+p.test_de_id||0}); // irreversible`),
    de_records:      p => _tpl([NS.DE], `$svc = new DataExtensionsService($config);\n$resp = $svc->getDataExtensionRecords(${+p.test_de_id||0}, ${+p.page_index||1}, ${+p.page_size||100}, true);`),
    de_records_desc: p => _tpl([NS.DE], `$svc = new DataExtensionsService($config);\n$resp = $svc->getDataExtensionRecords(${+p.test_de_id||0}, ${+p.page_index||1}, ${+p.page_size||100}, false);`),
    de_records_filtered: p => _tpl([NS.DE], `$svc    = new DataExtensionsService($config);\n$fields = ['ref_id', 'label']; // field names to return\n$resp   = $svc->getDataExtensionRecords(${+p.test_de_id||0}, ${+p.page_index||1}, ${+p.page_size||100}, true, $fields);`),
    de_sync_upsert:  p => _tpl([NS.DE, NS.DE_REC],
`$r1         = new DataExtensionRecord();
$r1->values = ['ref_id' => 'row-001', 'label' => 'First'];
$r2         = new DataExtensionRecord();
$r2->values = ['ref_id' => 'row-002', 'label' => 'Second'];
$svc  = new DataExtensionsService($config);
$resp = $svc->synchronizeRecords(${+p.test_de_id||0}, [$r1, $r2], 'UPSERT');`),
    de_sync_insert_ign: p => _tpl([NS.DE, NS.DE_REC],
`$rec         = new DataExtensionRecord();
$rec->values = ['ref_id' => 'row-001', 'label' => 'First'];
$svc  = new DataExtensionsService($config);
$resp = $svc->synchronizeRecords(${+p.test_de_id||0}, [$rec], 'INSERT_IGNORE_DUPLICATES');`),
    de_sync_empty:      p => _tpl([NS.DE], `$svc  = new DataExtensionsService($config);\n$resp = $svc->synchronizeRecords(${+p.test_de_id||0}, []); // must return null`),
    de_delete_records:  p => _tpl([NS.DE], `$svc  = new DataExtensionsService($config);\n$resp = $svc->deleteAllRecords(${+p.test_de_id||0}); // irreversible`),
    // Contacts – read (additional)
    contact_blocked:      p => _tpl([NS.CONTACT], `$svc = new ContactsService($config);\n$resp = $svc->getBlockedContacts([], [], ${+p.page_index||1}, ${+p.page_size||100});`),
    contact_custom_fields: p => _tpl([NS.CONTACT], '$svc = new ContactsService($config);\n$resp = $svc->getCustomFields();'),
    // Contacts – write
    contact_create: p => _tpl([NS.CONTACT, NS.CONTACT_OBJ, NS.PERMISSION, NS.SYNC_MODE],
`$contact              = new Contact();
$contact->email       = ${_phpVal(p.test_email)};
$contact->permission  = Permission::$DOI;
$svc  = new ContactsService($config);
$resp = $svc->createContact($contact, SynchronizationMode::$UPDATE);`),
    contact_create_ext_id: p => _tpl([NS.CONTACT, NS.CONTACT_OBJ, NS.PERMISSION, NS.SYNC_MODE],
`$contact              = new Contact();
$contact->email       = ${_phpVal(p.test_email2)};
$contact->external_id = ${_phpVal(p.test_external_id2)};
$contact->permission  = Permission::$SOI;
$svc  = new ContactsService($config);
$resp = $svc->createContactByExternalId($contact, SynchronizationMode::$UPDATE);`),
    contact_update: p => _tpl([NS.CONTACT, NS.CONTACT_OBJ, NS.PERMISSION, NS.STD_FIELD],
`$contact                 = new Contact();
$contact->email          = ${_phpVal(p.test_email)};
$contact->permission     = Permission::$DOI;
$contact->standard_fields = [StandardContactField::$FIRSTNAME => 'Updated'];
$svc  = new ContactsService($config);
$resp = $svc->updateContactByEmail(${_phpVal(p.test_email)}, $contact);`),
    contact_sync: p => _tpl([NS.CONTACT, NS.CONTACT_OBJ, NS.PERMISSION, NS.STD_FIELD, NS.SYNC_MODE],
`$contact                 = new Contact();
$contact->email          = ${_phpVal(p.test_email)};
$contact->permission     = Permission::$DOI;
$contact->standard_fields = [StandardContactField::$FIRSTNAME => 'Synced'];
$svc  = new ContactsService($config);
$resp = $svc->synchronizeContacts([$contact], null, SynchronizationMode::$UPDATE);`),
    contact_create_custom_field: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->createCustomField(${_phpVal(p.cf_field_name||'MyCustomField')}, 'String');`),
    contact_rename_custom_field: p => _tpl([NS.CONTACT],
`$oldName = ${_phpVal(p.cf_field_name||'MyCustomField')};
$newName = $oldName . 'Renamed';
$svc  = new ContactsService($config);
$resp = $svc->renameCustomField($oldName, $newName);`),
    contact_del_custom_field_vals: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->deleteCustomFieldValues(${_phpVal(p.cf_field_name||'MyCustomField')});`),
    contact_del_custom_field: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->deleteCustomField(${_phpVal(p.cf_field_name||'MyCustomField')});`),
    contact_del_std_field_vals: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->deleteStandardFieldValues('FIRSTNAME');`),
    contact_unsubscribe_id: p => _tpl([NS.CONTACT],
`$contactId = 0; // replace with contact ID (run contact_get_by_email first)
$svc  = new ContactsService($config);
$resp = $svc->unsubscribeContactById($contactId);`),
    contact_delete_ext_id: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->deleteContactsByExternalId(${_phpVal(p.test_external_id2)});`),
    // Preference categories
    pref_cat_list:   p => _tpl([NS.CONTACT], '$svc = new ContactsService($config);\n$resp = $svc->getContactPreferenceCategories();'),
    pref_cat_create: p => _tpl([NS.CONTACT, NS.PREF_CAT],
`$cat              = new PreferenceCategory();
$cat->name        = ${_phpVal(p.pref_cat_name||'my-category')};
$cat->description = 'Created via API';
$svc  = new ContactsService($config);
$resp = $svc->createContactPreferenceCategory($cat);`),
    pref_cat_get: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->getContactPreferenceCategoryByName(${_phpVal(p.pref_cat_name||'my-category')});`),
    pref_cat_update: p => _tpl([NS.CONTACT, NS.PREF_CAT],
`$cat              = new PreferenceCategory();
$cat->name        = ${_phpVal(p.pref_cat_name||'my-category')};
$cat->description = 'Updated description';
$svc  = new ContactsService($config);
$resp = $svc->updateContactPreferenceCategory(${_phpVal(p.pref_cat_name||'my-category')}, $cat);`),
    pref_cat_delete: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->deleteContactPreferenceCategory(${_phpVal(p.pref_cat_name||'my-category')});`),
    pref_list: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->getPreferencesOfContactPreferencesCategory(${_phpVal(p.pref_cat_name||'my-category')});`),
    pref_create: p => _tpl([NS.CONTACT, NS.PREF],
`$pref              = new Preference();
$pref->name        = ${_phpVal(p.pref_name||'my-preference')};
$pref->description = 'Created via API';
$svc  = new ContactsService($config);
$resp = $svc->createContactPreference(${_phpVal(p.pref_cat_name||'my-category')}, $pref);`),
    pref_get: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->getContactPreference(${_phpVal(p.pref_cat_name||'my-category')}, ${_phpVal(p.pref_name||'my-preference')});`),
    pref_update: p => _tpl([NS.CONTACT, NS.PREF],
`$pref              = new Preference();
$pref->name        = ${_phpVal(p.pref_name||'my-preference')};
$pref->description = 'Updated description';
$svc  = new ContactsService($config);
$resp = $svc->updateContactPreference(${_phpVal(p.pref_cat_name||'my-category')}, ${_phpVal(p.pref_name||'my-preference')}, $pref);`),
    pref_delete: p => _tpl([NS.CONTACT],
`$svc  = new ContactsService($config);
$resp = $svc->deleteContactPreference(${_phpVal(p.pref_cat_name||'my-category')}, ${_phpVal(p.pref_name||'my-preference')});`),
    // Contact filters
    cf_count: p => _tpl([NS.CF], '$svc = new ContactfiltersService($config);\n$resp = $svc->getContactFiltersCount();'),
    cf_list:  p => _tpl([NS.CF], `$svc = new ContactfiltersService($config);\n$resp = $svc->getContactFilters(${+p.page_index||1}, ${+p.page_size||100});`),
    cf_get:   p => _tpl([NS.CF], `$svc  = new ContactfiltersService($config);\n$resp = $svc->getContactFilter(${+p.test_cf_id||0});`),
    cf_create: p => _tpl([NS.CF, NS.CF_OBJ],
`$filter       = new ContactFilter();
$filter->name = ${_phpVal(p.cf_filter_name||'my-filter')};
$svc  = new ContactfiltersService($config);
$resp = $svc->createContactFilter($filter, false); // returns new filter ID`),
    cf_update: p => _tpl([NS.CF, NS.CF_OBJ],
`$id           = ${+p.test_cf_id||0}; // replace with filter ID from cf_create
$filter       = new ContactFilter();
$filter->name = ${_phpVal((p.cf_filter_name||'my-filter') + '-updated')};
$svc  = new ContactfiltersService($config);
$resp = $svc->updateContactFilter($id, $filter);`),
    cf_refresh: p => _tpl([NS.CF],
`$id   = ${+p.test_cf_id||0}; // replace with filter ID
$svc  = new ContactfiltersService($config);
$resp = $svc->refreshContactFilterContacts($id, null);`),
    cf_delete: p => _tpl([NS.CF],
`$id   = ${+p.test_cf_id||0}; // replace with filter ID from cf_create
$svc  = new ContactfiltersService($config);
$resp = $svc->deleteContactFilter($id);`),
    // Target groups
    tg_count:  p => _tpl([NS.TG], '$svc = new TargetGroupsService($config);\n$resp = $svc->getTargetGroupsCount();'),
    tg_list:   p => _tpl([NS.TG], `$svc = new TargetGroupsService($config);\n$resp = $svc->getTargetGroups(${+p.page_index||1}, ${+p.page_size||100});`),
    tg_create: p => _tpl([NS.TG, NS.TG_OBJ],
`$tg       = new TargetGroup();
$tg->name = ${_phpVal(p.tg_name||'my-target-group')};
$tg->type = 'contact_filter';
$svc  = new TargetGroupsService($config);
$resp = $svc->createTargetGroup($tg); // returns new target group ID`),
    tg_get: p => _tpl([NS.TG],
`$id   = 0; // replace with target group ID from tg_create
$svc  = new TargetGroupsService($config);
$resp = $svc->getTargetGroup($id);`),
    tg_delete: p => _tpl([NS.TG],
`$id   = 0; // replace with target group ID from tg_create
$svc  = new TargetGroupsService($config);
$resp = $svc->deleteTargetGroup($id);`),
    // Mailings – read (additional)
    mail_sender:       p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getSender(${+p.test_mailing_id||0});`),
    mail_sender_alias: p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getSenderAlias(${+p.test_mailing_id||0});`),
    mail_replyto:      p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getReplyToAddress(${+p.test_mailing_id||0});`),
    mail_preview:      p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getPreviewText(${+p.test_mailing_id||0});`),
    mail_tags:         p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getTags(${+p.test_mailing_id||0});`),
    mail_locale:       p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getLocale(${+p.test_mailing_id||0});`),
    mail_archive_url:  p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getArchiveUrl(${+p.test_mailing_id||0});`),
    mail_report_url:   p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getReportUrl(${+p.test_mailing_id||0});`),
    mail_domain:       p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getMailingDomain(${+p.test_mailing_id||0});`),
    mail_state:        p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getState(${+p.test_mailing_id||0});`),
    mail_type:         p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getType(${+p.test_mailing_id||0});`),
    mail_cf_restrictions: p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getContactFilterRestrictionsCount(${+p.test_mailing_id||0});`),
    mail_custom_props: p => _tpl([NS.MAIL], `$svc = new MailingsService($config);\n$resp = $svc->getCustomProperties(${+p.test_mailing_id||0});`),
    // Mailings – write
    mail_set_html: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0}; // mailing ID (run mail_create first)
$html = '<html><body><p>Content [unsubscribe]</p></body></html>';
$svc  = new MailingsService($config);
$resp = $svc->setHTMLContent($id, $html);`),
    mail_set_sender: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->setSender($id, ${_phpVal(p.test_email||'noreply@example.com')});`),
    mail_set_replyto: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->setReplyToAddress($id, false, ${_phpVal(p.test_email||'noreply@example.com')});`),
    mail_set_preview: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->setPreviewText($id, 'Your preview text here');`),
    mail_set_tags: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->setTags($id, ['tag1', 'tag2']);`),
    mail_set_locale: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->setLocale($id, 'de_DE');`),
    mail_add_custom_prop: p => _tpl([NS.MAIL, NS.CUSTOM_PROP],
`$prop        = new CustomProperty();
$prop->key   = 'my_prop';
$prop->value = 'my_value';
$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->addCustomProperties($id, [$prop]);`),
    mail_upd_custom_prop: p => _tpl([NS.MAIL, NS.CUSTOM_PROP],
`$prop        = new CustomProperty();
$prop->key   = 'my_prop';
$prop->value = 'updated_value';
$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->updateCustomProperty($id, $prop);`),
    mail_del_custom_prop: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->deleteCustomProperty($id, 'my_prop');`),
    mail_disable_qos: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0};
$svc  = new MailingsService($config);
$resp = $svc->disableQosChecks($id);`),
    mail_delete: p => _tpl([NS.MAIL],
`$id   = ${+p.test_mailing_id||0}; // mailing ID (run mail_create first)
$svc  = new MailingsService($config);
$resp = $svc->deleteMailing($id); // irreversible`),
    // Media
    media_templates:      p => _tpl([NS.MEDIA], '$svc = new MediaService($config);\n$resp = $svc->getMailingTemplates();'),
    media_cms2_templates: p => _tpl([NS.MEDIA], '$svc = new MediaService($config);\n$resp = $svc->getCms2MailingTemplates();'),
    // Transactions (additional)
    tx_type_count: p => _tpl([NS.TX], '$svc = new TransactionsService($config);\n$resp = $svc->getTransactionTypesCount();'),
    tx_type_create: p => _tpl([NS.TX, NS.TX_TYPE, NS.ATTR_TYPE, NS.DATA_TYPE],
`$a            = new AttributeType();
$a->name      = 'order_id';
$a->type      = DataType::$STRING;
$a->required  = false;
$trt             = new TransactionType();
$trt->name       = ${_phpVal(p.tx_type_name||'my_tx_type')};
$trt->attributes = [$a];
$svc  = new TransactionsService($config);
$resp = $svc->createTransactionType($trt); // returns new type ID`),
    tx_type_create2: p => _tpl([NS.TX, NS.TX_TYPE, NS.ATTR_TYPE, NS.DATA_TYPE],
`$a1            = new AttributeType();
$a1->name      = 'transaction_id';
$a1->type      = DataType::$STRING;
$a1->required  = false;
$a2            = new AttributeType();
$a2->name      = 'amount';
$a2->type      = DataType::$DOUBLE;
$a2->required  = false;
$trt             = new TransactionType();
$trt->name       = ${_phpVal(p.tx_type_name2||'my_tx_type2')};
$trt->attributes = [$a1, $a2];
$svc  = new TransactionsService($config);
$resp = $svc->createTransactionType($trt);`),
    tx_send: p => _tpl([NS.TX, NS.TX_OBJ, NS.CONTACT_REF],
`$contact        = new ContactReference();
$contact->email = ${_phpVal(p.test_email)};
$tx             = new Transaction();
$tx->contact    = $contact;
$tx->typeid     = ${+p.test_tx_type_id||0};
$tx->content    = ['order_id' => 'order-001'];
$svc  = new TransactionsService($config);
$resp = $svc->createTransactions([$tx], true, false);`),
    tx_send_multi: p => _tpl([NS.TX, NS.TX_OBJ, NS.CONTACT_REF],
`$typeId = ${+p.test_tx_type_id||0};
$email  = ${_phpVal(p.test_email)};
$txs    = [];
foreach (['order-002', 'order-003', 'order-004'] as $oid) {
    $contact        = new ContactReference();
    $contact->email = $email;
    $tx             = new Transaction();
    $tx->contact    = $contact;
    $tx->typeid     = $typeId;
    $tx->content    = ['order_id' => $oid];
    $txs[]          = $tx;
}
$svc  = new TransactionsService($config);
$resp = $svc->createTransactions($txs, true, false);`),
    tx_delete_by_date: p => _tpl([NS.TX],
`$typeId   = ${+p.test_tx_type_id||0};
$beforeMs = strtotime('1970-01-02') * 1000; // epoch ms – adjust date as needed
$svc  = new TransactionsService($config);
$resp = $svc->deleteTransactions($typeId, $beforeMs);`),
    tx_type_delete: p => _tpl([NS.TX],
`$id   = 0; // replace with type ID from tx_type_create
$svc  = new TransactionsService($config);
$resp = $svc->deleteTransactionType($id);`),
    // Mailing blacklists
    mbl_list: p => _tpl([NS.MBL], '$svc = new MailingBlacklistsService($config);\n$resp = $svc->getMailingBlacklists();'),
    mbl_create: p => _tpl([NS.MBL],
`$svc  = new MailingBlacklistsService($config);
$resp = $svc->createMailingBlacklist(${_phpVal(p.mbl_name||'my-mailing-blacklist')}); // returns new blacklist ID`),
    mbl_get: p => _tpl([NS.MBL],
`$id   = 0; // replace with ID from mbl_create
$svc  = new MailingBlacklistsService($config);
$resp = $svc->getMailingBlacklist($id);`),
    mbl_update: p => _tpl([NS.MBL],
`$id      = 0; // replace with ID from mbl_create
$newName = ${_phpVal((p.mbl_name||'my-mailing-blacklist') + '-updated')};
$svc  = new MailingBlacklistsService($config);
$resp = $svc->updateMailingBlacklist($id, $newName);`),
    mbl_entries: p => _tpl([NS.MBL, NS.MBL_EXPR],
`$expr              = new MailingBlacklistExpressions();
$expr->expressions = ['@bounce-test.invalid', 'spam@example.invalid'];
$id   = 0; // replace with ID from mbl_create
$svc  = new MailingBlacklistsService($config);
$resp = $svc->addEntriesToBlacklist($id, $expr);`),
    mbl_get_entries: p => _tpl([NS.MBL],
`$id   = 0; // replace with ID from mbl_create
$svc  = new MailingBlacklistsService($config);
$resp = $svc->getEntriesForBlacklist($id);`),
    mbl_delete: p => _tpl([NS.MBL],
`$id   = 0; // replace with ID from mbl_create
$svc  = new MailingBlacklistsService($config);
$resp = $svc->deleteMailingBlacklist($id); // irreversible`),
    // Account – write
    acc_ph_set: p => _tpl([NS.ACC, NS.ACC_PH],
`$ph        = new AccountPlaceholder();
$ph->key   = 'my_placeholder';
$ph->value = 'hello from API';
$svc  = new AccountService($config);
$resp = $svc->setAccountPlaceholders([$ph]);`),
    acc_ph_update: p => _tpl([NS.ACC, NS.ACC_PH],
`$ph        = new AccountPlaceholder();
$ph->key   = 'my_placeholder';
$ph->value = 'updated value';
$svc  = new AccountService($config);
$resp = $svc->updateAccountPlaceholders([$ph]);`),
    acc_ph_delete: p => _tpl([NS.ACC],
`$svc  = new AccountService($config);
$resp = $svc->deleteAccountPlaceholder('my_placeholder');`),
    // Webhooks – write
    wh_create: p => _tpl([NS.WH, NS.WH_OBJ],
`$wh        = new Webhook();
$wh->url   = ${_phpVal(p.webhook_url||'https://webhook.site/your-uuid')};
$wh->event = Webhook::$EVENT_UNSUBSCRIPTION;
$svc  = new WebhooksService($config);
$resp = $svc->createWebhook($wh); // returns new webhook ID`),
    wh_get_created: p => _tpl([NS.WH],
`$id   = 0; // replace with ID from wh_create
$svc  = new WebhooksService($config);
$resp = $svc->getWebhook($id);`),
    wh_update: p => _tpl([NS.WH, NS.WH_OBJ],
`$id        = 0; // replace with ID from wh_create
$wh        = new Webhook();
$wh->url   = 'https://webhook.site/updated-url';
$wh->event = Webhook::$EVENT_BOUNCE;
$svc  = new WebhooksService($config);
$resp = $svc->updateWebhook($id, $wh);`),
    wh_delete: p => _tpl([NS.WH],
`$id   = 0; // replace with ID from wh_create
$svc  = new WebhooksService($config);
$resp = $svc->deleteWebhook($id); // irreversible`),
};

// Minimal PHP syntax highlighter — no external dependencies
function highlightPhp(raw) {
    const KWS  = new Set(['new','use','function','class','return','if','else','foreach','as',
                          'echo','require_once','require','var_dump']);
    const LITS = new Set(['null','true','false','NULL','TRUE','FALSE']);
    function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function sp(cls, s) { return '<span class="php-' + cls + '">' + esc(s) + '</span>'; }

    let out = '', i = 0, afterNew = false;
    const n = raw.length;

    while (i < n) {
        // Line comment
        if (raw[i] === '/' && raw[i+1] === '/') {
            const end = raw.indexOf('\n', i);
            const tok = end === -1 ? raw.slice(i) : raw.slice(i, end);
            out += sp('cmt', tok); i += tok.length; afterNew = false; continue;
        }
        // Block comment
        if (raw[i] === '/' && raw[i+1] === '*') {
            const end = raw.indexOf('*/', i+2);
            const tok = end === -1 ? raw.slice(i) : raw.slice(i, end+2);
            out += sp('cmt', tok); i += tok.length; afterNew = false; continue;
        }
        // Single-quoted string
        if (raw[i] === "'") {
            let j = i+1;
            while (j < n && raw[j] !== "'") { if (raw[j] === '\\') j++; j++; }
            out += sp('str', raw.slice(i, j+1)); i = j+1; afterNew = false; continue;
        }
        // Double-quoted string
        if (raw[i] === '"') {
            let j = i+1;
            while (j < n && raw[j] !== '"') { if (raw[j] === '\\') j++; j++; }
            out += sp('str', raw.slice(i, j+1)); i = j+1; afterNew = false; continue;
        }
        // PHP open tag
        if (raw.slice(i, i+5) === '<?php') {
            out += sp('tag', '<?php'); i += 5; afterNew = false; continue;
        }
        // Variable ($name)
        if (raw[i] === '$' && i+1 < n && /[a-zA-Z_]/.test(raw[i+1])) {
            let j = i+1;
            while (j < n && /[a-zA-Z0-9_]/.test(raw[j])) j++;
            out += sp('var', raw.slice(i, j)); i = j; afterNew = false; continue;
        }
        // Arrow operator -> and scope ::
        if (raw[i] === '-' && raw[i+1] === '>') { out += sp('op', '->'); i += 2; continue; }
        if (raw[i] === ':' && raw[i+1] === ':') { out += sp('op', '::'); i += 2; continue; }
        // Number
        if (/[0-9]/.test(raw[i]) && (i === 0 || !/[a-zA-Z0-9_$]/.test(raw[i-1]))) {
            let j = i;
            while (j < n && /[0-9.]/.test(raw[j])) j++;
            out += sp('num', raw.slice(i, j)); i = j; afterNew = false; continue;
        }
        // Identifier: keyword / literal / class name / plain
        if (/[a-zA-Z_]/.test(raw[i])) {
            let j = i;
            while (j < n && /[a-zA-Z0-9_]/.test(raw[j])) j++;
            const word = raw.slice(i, j);
            if (KWS.has(word)) {
                out += sp('kw', word); afterNew = (word === 'new');
            } else if (LITS.has(word)) {
                out += sp('lit', word); afterNew = false;
            } else if (afterNew || raw.slice(j, j+2) === '::') {
                out += sp('cls', word); afterNew = false;
            } else {
                out += esc(word); afterNew = false;
            }
            i = j; continue;
        }
        // Whitespace — preserve afterNew so `new ClassName` works across the space
        if (raw[i] === ' ' || raw[i] === '\t') { out += raw[i++]; continue; }
        // Everything else
        out += esc(raw[i++]); afterNew = false;
    }
    return out;
}

function showCode(key) {
    const p = _collectParams();
    const overlay   = document.getElementById('code-overlay');
    const titleEl   = document.getElementById('code-modal-title');
    const preEl     = document.getElementById('code-modal-pre');

    titleEl.textContent = key;

    const tpl = CODE_TEMPLATES[key];
    let codeStr;
    if (!tpl) {
        codeStr = '// No PHP snippet defined for: ' + key + '\n// Check the service class directly.';
    } else {
        const result = tpl(p);
        const uses   = (result.uses || []).map(u => 'use ' + u + ';').join('\n');
        codeStr =
            '<?php\n\n'
            + "require_once __DIR__ . '/vendor/autoload.php'; // adjust path\n\n"
            + uses + '\n\n'
            + "$config = [\n    'API_KEY'  => 'YOUR_API_KEY',\n    'BASE_URI' => 'https://api.maileon.com/1.0',\n];\n\n"
            + result.call + '\n\n'
            + "if ($resp && $resp->isSuccess()) {\n    var_dump($resp->getResult());\n} else {\n    echo 'Error: HTTP ', $resp ? $resp->getStatusCode() : 'null';\n}";
    }
    preEl.innerHTML = highlightPhp(codeStr);

    overlay.style.display = '';
}
</script>

<?php endif; // authed ?>
</body>
</html>
