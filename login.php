<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/atomic.php';

// Cookie-Flags vor session_start setzen
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// Noch kein Benutzer → Setup
if (empty(load_users())) {
    header('Location: setup.php');
    exit;
}

// Bereits eingeloggt
if (!empty($_SESSION['imapfilter_logged_in'])) {
    header('Location: index.php');
    exit;
}

// ─── Rate-Limiting ────────────────────────────────────────────────────────────
define('RL_MAX_ATTEMPTS', 5);    // Max. Fehlversuche
define('RL_WINDOW',       900);  // Sperrdauer in Sekunden (15 Min.)
define('RL_FILE', '/srv/imapfilter/.login_attempts.json');

function rl_get_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rl_load(): array {
    if (!file_exists(RL_FILE)) return [];
    $data = json_decode(file_get_contents(RL_FILE), true);
    return is_array($data) ? $data : [];
}

function rl_save(array $data): void {
    atomic_write_json(RL_FILE, $data, 0600);
}

function rl_is_blocked(string $ip): bool {
    $data  = rl_load();
    $entry = $data[$ip] ?? null;
    if (!$entry) return false;
    if ($entry['attempts'] >= RL_MAX_ATTEMPTS) {
        if (time() - $entry['last_attempt'] < RL_WINDOW) return true;
        // Sperre abgelaufen → zurücksetzen
        unset($data[$ip]);
        rl_save($data);
    }
    return false;
}

function rl_record_failure(string $ip): void {
    $data  = rl_load();
    $entry = $data[$ip] ?? ['attempts' => 0, 'last_attempt' => 0];
    // Fenster abgelaufen → neu starten
    if (time() - $entry['last_attempt'] >= RL_WINDOW) {
        $entry['attempts'] = 0;
    }
    $entry['attempts']++;
    $entry['last_attempt'] = time();
    $data[$ip] = $entry;
    rl_save($data);

    // Fehlversuch loggen
    $logDir = rtrim($GLOBALS['logDir'] ?? '/var/log/imapfilter', '/');
    $line   = '[' . date('Y-m-d H:i:s') . '] [login] Fehlversuch #' . $entry['attempts']
            . ' von ' . $ip . "\n";
    @file_put_contents($logDir . '/login.log', $line, FILE_APPEND | LOCK_EX);
}

function rl_reset(string $ip): void {
    $data = rl_load();
    unset($data[$ip]);
    rl_save($data);
}

function rl_remaining_seconds(string $ip): int {
    $data  = rl_load();
    $entry = $data[$ip] ?? null;
    if (!$entry) return 0;
    return max(0, RL_WINDOW - (time() - $entry['last_attempt']));
}

// ─── Login-Verarbeitung ───────────────────────────────────────────────────────
$ip    = rl_get_ip();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (rl_is_blocked($ip)) {
        $secs  = rl_remaining_seconds($ip);
        $mins  = ceil($secs / 60);
        $error = "Zu viele Fehlversuche. Bitte " . $mins . " Minute(n) warten.";
    } else {
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        $u    = verify_user($user, $pass);

        if ($u) {
            rl_reset($ip);
            session_regenerate_id(true);
            $_SESSION['imapfilter_logged_in'] = true;
            $_SESSION['username']             = $u['username'];
            $_SESSION['is_admin']             = !empty($u['is_admin']);
            $_SESSION['csrf_token']           = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        }

        rl_record_failure($ip);
        $data     = rl_load();
        $attempts = $data[$ip]['attempts'] ?? 1;
        $remaining = RL_MAX_ATTEMPTS - $attempts;

        if ($remaining <= 0) {
            $error = 'Zu viele Fehlversuche. Bitte ' . ceil(RL_WINDOW / 60) . ' Minuten warten.';
        } else {
            $error = 'Benutzername oder Passwort falsch.'
                   . ($remaining <= 2 ? ' Noch ' . $remaining . ' Versuch(e) vor Sperre.' : '');
        }
    }
}

$setupDone = isset($_GET['setup']);
$blocked   = rl_is_blocked($ip);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — IMAPFilter</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center">
<div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:32px;width:340px">
    <div style="font-size:1.1rem;font-weight:700;color:#60a5fa;margin-bottom:4px">📧 IMAPFilter</div>
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:20px">Web-UI</div>

    <?php if ($setupDone): ?>
        <div style="background:#052e16;border:1px solid #14532d;color:#86efac;border-radius:5px;padding:8px 10px;font-size:.85rem;margin-bottom:14px">
            ✅ Admin-Account angelegt. Bitte einloggen.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background:#450a0a;border:1px solid #7f1d1d;color:#fca5a5;border-radius:5px;padding:8px 10px;font-size:.85rem;margin-bottom:14px">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label class="form-label">Benutzername</label>
            <input type="text" name="user" class="form-input" autocomplete="username" autofocus
                   <?= $blocked ? 'disabled' : '' ?>>
        </div>
        <div class="form-group">
            <label class="form-label">Passwort</label>
            <input type="password" name="pass" class="form-input" autocomplete="current-password"
                   <?= $blocked ? 'disabled' : '' ?>>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px"
                <?= $blocked ? 'disabled' : '' ?>>Anmelden</button>
    </form>
</div>
</div>
</body>
</html>
