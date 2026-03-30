<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/users.php';
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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    $u    = verify_user($user, $pass);

    if ($u) {
        session_regenerate_id(true);
        $_SESSION['imapfilter_logged_in'] = true;
        $_SESSION['username']             = $u['username'];
        $_SESSION['is_admin']             = !empty($u['is_admin']);
        header('Location: index.php');
        exit;
    }
    $error = 'Benutzername oder Passwort falsch.';
}

$setupDone = isset($_GET['setup']);
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
            <input type="text" name="user" class="form-input" autocomplete="username" autofocus>
        </div>
        <div class="form-group">
            <label class="form-label">Passwort</label>
            <input type="password" name="pass" class="form-input" autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">Anmelden</button>
    </form>
</div>
</div>
</body>
</html>
