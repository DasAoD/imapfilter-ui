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
    } elseif (strlen($pass) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen lang sein.';
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
            <label class="form-label">Passwort (min. 8 Zeichen)</label>
            <input type="password" name="pass" class="form-input" autocomplete="new-password">
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
</body>
</html>
