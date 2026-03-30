<?php
/**
 * setup.php — Wird automatisch aufgerufen wenn noch kein Benutzer existiert.
 * Legt den ersten Admin-Account an und leitet danach zu login.php weiter.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/users.php';

// Wenn bereits Benutzer vorhanden → direkt zu Login
if (!empty(load_users())) {
    header('Location: login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user  = trim($_POST['user']  ?? '');
    $pass  = $_POST['pass']  ?? '';
    $pass2 = $_POST['pass2'] ?? '';

    if ($user === '') {
        $error = 'Bitte einen Benutzernamen eingeben.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $user)) {
        $error = 'Benutzername darf nur Buchstaben, Zahlen, Bindestrich, Punkt und Unterstrich enthalten.';
    } elseif (strlen($pass) < 10) {
        $error = 'Passwort muss mindestens 10 Zeichen lang sein.';
    } elseif (!preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) ||
              !preg_match('/[0-9]/', $pass) || !preg_match('/[!@#$%^&*\-_=+?]/', $pass)) {
        $error = 'Passwort muss Groß- und Kleinbuchstaben, eine Zahl und ein Sonderzeichen enthalten.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwörter stimmen nicht überein.';
    } else {
        // Verzeichnis anlegen
        $dir = rtrim($luaBaseDir, '/') . '/' . $user . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0770, true);

        // Logverzeichnis anlegen
        if (!is_dir($logDir)) mkdir($logDir, 0775, true);

        if (add_user($user, $pass, true)) {
            header('Location: login.php?setup=1');
            exit;
        }
        $error = 'Fehler beim Anlegen des Accounts. Prüfe die Schreibrechte auf ' . htmlspecialchars(dirname($usersJson)) . '.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ersteinrichtung — IMAPFilter</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px">
<div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:32px;width:380px">
    <div style="font-size:1.1rem;font-weight:700;color:#60a5fa;margin-bottom:4px">📧 IMAPFilter</div>
    <div style="font-size:.8rem;color:var(--muted);margin-bottom:6px">Ersteinrichtung</div>
    <p style="font-size:.85rem;color:var(--muted);margin-bottom:20px;line-height:1.5">
        Willkommen! Lege jetzt den ersten Admin-Account an.
        Danach kannst du weitere Benutzer im Admin-Bereich hinzufügen.
    </p>

    <?php if ($error): ?>
        <div style="background:#450a0a;border:1px solid #7f1d1d;color:#fca5a5;border-radius:5px;padding:8px 10px;font-size:.85rem;margin-bottom:14px">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label class="form-label">Benutzername (Admin)</label>
            <input type="text" name="user" class="form-input" autocomplete="username"
                   pattern="[a-zA-Z0-9_\-\.]+" title="Nur Buchstaben, Zahlen, - _ ." autofocus
                   value="<?= htmlspecialchars($_POST['user'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Passwort (min. 10 Zeichen, Groß-/Kleinbuchstaben, Zahl, Sonderzeichen)</label>
            <input type="password" name="pass" class="form-input" autocomplete="new-password"
                   id="setup-pass" oninput="pwdCheck()">
            <div style="margin-top:8px">
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="flex:1;height:6px;background:#374151;border-radius:3px;overflow:hidden">
                        <div id="setup-bar" style="height:100%;width:0%;border-radius:3px;transition:width .3s,background .3s"></div>
                    </div>
                    <span id="setup-label" style="font-size:.78rem;min-width:80px"></span>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Passwort wiederholen</label>
            <input type="password" name="pass2" class="form-input" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">
            Admin-Account anlegen
        </button>
    </form>
</div>
</div>
<script>
function pwdCheck() {
    const val   = document.getElementById('setup-pass').value;
    const bar   = document.getElementById('setup-bar');
    const label = document.getElementById('setup-label');
    let score = 0;
    if (val.length >= 10)              score++;
    if (val.length >= 14)              score++;
    if (/[A-Z]/.test(val))            score++;
    if (/[a-z]/.test(val))            score++;
    if (/[0-9]/.test(val))            score++;
    if (/[!@#$%^&*\-_=+?]/.test(val)) score++;
    const levels = [
        { pct:'0%',   bg:'transparent', text:'' },
        { pct:'20%',  bg:'#ef4444',     text:'Sehr schwach' },
        { pct:'35%',  bg:'#f97316',     text:'Schwach' },
        { pct:'55%',  bg:'#eab308',     text:'Mittel' },
        { pct:'75%',  bg:'#3b82f6',     text:'Stark' },
        { pct:'90%',  bg:'#10b981',     text:'Sehr stark' },
        { pct:'100%', bg:'#10b981',     text:'Ausgezeichnet' },
    ];
    const lvl = levels[Math.min(score, levels.length - 1)];
    bar.style.width      = lvl.pct;
    bar.style.background = lvl.bg;
    label.textContent    = lvl.text;
    label.style.color    = lvl.bg;
}
</script>
</body>
</html>
