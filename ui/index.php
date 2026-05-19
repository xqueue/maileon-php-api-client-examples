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
    ['cf_count',   'Count',          'read',    'contactfilters'],
    ['cf_list',    'List',           'read',    'contactfilters'],
    ['cf_get',     'Get (config ID)','read',    'contactfilters'],
    ['cf_create',  'Create',         'write',   'contactfilters'],
    ['cf_update',  'Update name',    'write',   'contactfilters'],
    ['cf_refresh', 'Refresh',        'write',   'contactfilters'],
    ['cf_delete',  'Delete created', 'destroy', 'contactfilters'],

    // Target groups
    ['tg_count',  'Count',   'read',    'targetgroups'],
    ['tg_list',   'List',    'read',    'targetgroups'],
    ['tg_create', 'Create',  'write',   'targetgroups'],
    ['tg_get',    'Get',     'read',    'targetgroups'],
    ['tg_delete', 'Delete',  'destroy', 'targetgroups'],

    // Mailings – read
    ['mail_list',       'List (by type)',        'read',  'mailings'],
    ['mail_list_state', 'List (by state)',       'read',  'mailings'],
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

    // Reports (require mailing ID from config)
    ['rep_recipients',   'Recipients',         'read', 'reports'],
    ['rep_opens',        'Opens',              'read', 'reports'],
    ['rep_unique_opens', 'Unique opens',       'read', 'reports'],
    ['rep_clicks',       'Clicks',             'read', 'reports'],
    ['rep_unique_clicks','Unique clicks',      'read', 'reports'],
    ['rep_bounces',      'Bounces',            'read', 'reports'],
    ['rep_unique_bounces','Unique bounces',    'read', 'reports'],
    ['rep_unsubs',       'Unsubscribers',      'read', 'reports'],
    ['rep_unsub_reasons','Unsubscriber reasons','read','reports'],
    ['rep_subscribers',  'Subscribers',        'read', 'reports'],
    ['rep_blocks',       'Blocks',             'read', 'reports'],
    ['rep_conversions',  'Conversions',        'read', 'reports'],
    ['rep_uniq_conv',    'Unique conversions', 'read', 'reports'],

    // Transactions
    ['tx_type_count',  'Type count',          'read',    'transactions'],
    ['tx_type_list',   'Type list',           'read',    'transactions'],
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
    ['de_list',             'List extensions',        'read',  'dataextensions'],
    ['de_list_paged',       'List (page 2)',          'read',  'dataextensions'],
    ['de_get',              'Get extension',          'read',  'dataextensions'],
    ['de_get_fields',       'Verify fields',          'read',  'dataextensions'],
    ['de_records',          'Get records',            'read',  'dataextensions'],
    ['de_records_desc',     'Get records (desc)',     'read',  'dataextensions'],
    ['de_records_filtered', 'Get records (filtered)', 'read',  'dataextensions'],
    ['de_sync_upsert',      'Sync UPSERT',            'write', 'dataextensions'],
    ['de_sync_insert_ign',  'Sync INSERT_IGNORE',     'write', 'dataextensions'],
    ['de_sync_empty',       'Sync empty (guard)',      'read',  'dataextensions'],
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
        <button class="btn btn-outline btn-sm" onclick="selectAll(true)">Select all</button>
        <button class="btn btn-outline btn-sm" onclick="selectAll(false)">Clear</button>
        <button class="btn btn-primary" id="run-btn" onclick="runTests()">
            <span id="run-label">Run selected</span>
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
            <label class="check-item">
                <input type="checkbox" name="test" value="<?= htmlspecialchars($key) ?>">
                <span><?= htmlspecialchars($label) ?></span>
                <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
            </label>
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
        <span style="color:var(--muted);font-size:.8rem">Overrides apply to this run only — not saved to config</span>
    </div>
    <div class="panel-body" id="params-body"></div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Output</h3>
        <button class="btn btn-outline btn-sm" onclick="clearOutput()">Clear</button>
    </div>
    <div class="panel-body">
        <div class="output-panel" id="output">
            <span style="color:var(--muted)">Select tests and click "Run selected".</span>
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
    'tg_name'        => 'php-ui-test-tg',
    'mail_name'      => 'php-ui-test-mailing',
    'mail_subject'   => 'UI Test Subject',
    'webhook_url'    => '',
    'cf_filter_name' => 'php-ui-test-filter',
    'pref_cat_name'  => 'php-ui-test-cat',
    'pref_name'      => 'php-ui-test-pref',
    'cf_field_name'  => 'PhpUiTestField',
    'mbl_name'       => 'php-ui-test-mbl',
    'tx_type_name'   => 'php_ui_test_type',
    'tx_type_name2'  => 'php_ui_test_type2',
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
    tg_name:           'Target group name',
    mail_name:         'Mailing name',
    mail_subject:      'Mailing subject',
    webhook_url:       'Webhook URL',
    cf_filter_name:    'Contact filter name',
    pref_cat_name:     'Pref. category name',
    pref_name:         'Preference name',
    cf_field_name:     'Custom field name',
    mbl_name:          'Mailing blacklist name',
    tx_type_name:      'Transaction type name',
    tx_type_name2:     'Transaction type name 2',
};
const TEST_PARAMS = {
    contact_get_by_email:      ['test_email'],
    contact_get_by_ext_id:     ['test_external_id'],
    contact_create:            ['test_email', 'test_external_id'],
    contact_create_ext_id:     ['test_email2', 'test_external_id2'],
    contact_update:            ['test_email'],
    contact_sync:              ['test_email'],
    contact_unsubscribe_email: ['test_email'],
    contact_delete:            ['test_email'],
    contact_delete_ext_id:     ['test_external_id2'],
    cf_get:                    ['test_cf_id'],
    cf_refresh:                ['test_cf_id'],
    mail_subject:              ['test_mailing_id'],
    mail_sender:               ['test_mailing_id'],
    mail_sender_alias:         ['test_mailing_id'],
    mail_replyto:              ['test_mailing_id'],
    mail_preview:              ['test_mailing_id'],
    mail_tags:                 ['test_mailing_id'],
    mail_locale:               ['test_mailing_id'],
    mail_html:                 ['test_mailing_id'],
    mail_archive_url:          ['test_mailing_id'],
    mail_report_url:           ['test_mailing_id'],
    mail_domain:               ['test_mailing_id'],
    mail_state:                ['test_mailing_id'],
    mail_type:                 ['test_mailing_id'],
    mail_cf_restrictions:      ['test_mailing_id'],
    mail_custom_props:         ['test_mailing_id'],
    mail_copy:                 ['test_mailing_id'],
    mail_set_sender:           ['test_email'],
    mail_set_replyto:          ['test_email'],
    rep_recipients:            ['test_mailing_id'],
    rep_opens:                 ['test_mailing_id'],
    rep_unique_opens:          ['test_mailing_id'],
    rep_clicks:                ['test_mailing_id'],
    rep_unique_clicks:         ['test_mailing_id'],
    rep_bounces:               ['test_mailing_id'],
    rep_unique_bounces:        ['test_mailing_id'],
    rep_unsubs:                ['test_mailing_id'],
    rep_subscribers:           ['test_mailing_id'],
    rep_blocks:                ['test_mailing_id'],
    rep_conversions:           ['test_mailing_id'],
    rep_uniq_conv:             ['test_mailing_id'],
    tx_type_get:               ['test_tx_type_id'],
    tx_send:                   ['test_tx_type_id', 'test_email'],
    tx_send_multi:             ['test_tx_type_id', 'test_email'],
    tx_recent:                 ['test_tx_type_id'],
    tx_get:                    ['test_tx_type_id', 'test_tx_id'],
    tx_delete:                 ['test_tx_type_id', 'test_tx_id'],
    tx_delete_by_date:         ['test_tx_type_id'],
    bl_get:                    ['test_blacklist_id'],
    bl_entries:                ['test_blacklist_id'],
    de_get:                    ['test_de_id'],
    de_get_fields:             ['test_de_id'],
    de_records:                ['test_de_id'],
    de_records_desc:           ['test_de_id'],
    de_records_filtered:       ['test_de_id'],
    de_sync_upsert:            ['test_de_id'],
    de_sync_insert_ign:        ['test_de_id'],
    wh_get:                    ['test_webhook_id'],
    tg_create:                 ['tg_name'],
    cf_create:                 ['cf_filter_name'],
    cf_update:                 ['cf_filter_name'],
    mail_create:               ['mail_name', 'mail_subject'],
    mail_exists:               ['mail_name'],
    pref_cat_create:           ['pref_cat_name'],
    pref_cat_get:              ['pref_cat_name'],
    pref_cat_update:           ['pref_cat_name'],
    pref_cat_delete:           ['pref_cat_name'],
    pref_list:                 ['pref_cat_name'],
    pref_create:               ['pref_cat_name', 'pref_name'],
    pref_get:                  ['pref_cat_name', 'pref_name'],
    pref_update:               ['pref_cat_name', 'pref_name'],
    pref_delete:               ['pref_cat_name', 'pref_name'],
    contact_create_custom_field:   ['cf_field_name'],
    contact_rename_custom_field:   ['cf_field_name'],
    contact_del_custom_field_vals: ['cf_field_name'],
    contact_del_custom_field:      ['cf_field_name'],
    mbl_create:                ['mbl_name'],
    wh_create:                 ['webhook_url'],
    tx_type_create:            ['tx_type_name'],
    tx_type_create2:           ['tx_type_name2'],
};

function selectAll(v) {
    document.querySelectorAll('#test-checks input').forEach(c => c.checked = v);
}

function clearOutput() {
    document.getElementById('output').innerHTML = '<span style="color:var(--muted)">Output cleared.</span>';
}

async function runTests() {
    const checks = [...document.querySelectorAll('#test-checks input:checked')].map(c => c.value);
    if (!checks.length) { alert('No tests selected.'); return; }

    const btn   = document.getElementById('run-btn');
    const label = document.getElementById('run-label');
    btn.classList.add('dim');
    label.innerHTML = '<span class="spinner"></span> Running…';

    document.getElementById('output').innerHTML = '';

    const allNeeded = new Set();
    checks.forEach(key => (TEST_PARAMS[key] || []).forEach(p => allNeeded.add(p)));
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
    label.textContent = 'Run selected';

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

function updateParamsPanel() {
    const ORDER   = ['test_email','test_email2','test_external_id','test_external_id2',
                     'test_mailing_id','test_cf_id','test_blacklist_id','test_de_id',
                     'test_tx_type_id','test_tx_id','test_webhook_id',
                     'tg_name','mail_name','mail_subject','cf_filter_name',
                     'pref_cat_name','pref_name','cf_field_name','mbl_name',
                     'webhook_url','tx_type_name','tx_type_name2'];
    const checked = [...document.querySelectorAll('#test-checks input:checked')].map(c => c.value);
    const seen    = new Set();
    checked.forEach(key => (TEST_PARAMS[key] || []).forEach(p => seen.add(p)));
    const needed  = ORDER.filter(p => seen.has(p));

    const panel = document.getElementById('params-panel');
    const body  = document.getElementById('params-body');
    if (!panel) return;
    if (!needed.length) { panel.style.display = 'none'; return; }

    // Preserve values already typed
    const current = {};
    needed.forEach(p => { const el = document.getElementById('param-' + p); if (el) current[p] = el.value; });

    panel.style.display = '';
    body.innerHTML = '<div class="form-row">' +
        needed.map(p => {
            const label = PARAM_LABELS[p] || p;
            const dflt  = VAULT_PARAMS[p] ?? '';
            const val   = current[p] !== undefined ? current[p] : dflt;
            return `<div class="form-group">
                <label>${escHtml(label)}<span style="color:var(--muted);font-size:.72rem;margin-left:5px">${escHtml(p)}</span></label>
                <input type="text" id="param-${escHtml(p)}" value="${escHtml(val)}" placeholder="${escHtml(dflt || 'not set')}">
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
</script>

<?php endif; // authed ?>
</body>
</html>
