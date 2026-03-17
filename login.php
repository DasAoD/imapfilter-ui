<?php
require_once __DIR__ . '/config.php';
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';

    if ($user === IMAPFILTER_UI_USER && password_verify($pass, IMAPFILTER_UI_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['imapfilter_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Benutzername oder Passwort falsch.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Login – IMAPFilter WebUI</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #1e1e1e;
            color: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-box {
            background: #252526;
            padding: 16px 20px;
            border-radius: 6px;
            box-shadow: 0 0 0 1px #333;
            width: 320px;
        }
        .login-box h1 {
            font-size: 1.2rem;
            margin-top: 0;
        }
        label {
            display: block;
            margin-top: 8px;
            font-size: 0.9rem;
        }
        input {
            width: 100%;
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #3c3c3c;
            background: #1e1e1e;
            color: #ddd;
            box-sizing: border-box;
        }
        button {
            margin-top: 12px;
            width: 100%;
            padding: 7px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            background: #007acc;
            color: #fff;
        }
        .error {
            margin-top: 8px;
            background: #3a1e1e;
            color: #f0b4b4;
            padding: 6px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
<div class="login-box">
    <h1>IMAPFilter Login</h1>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <label>Benutzername
            <input type="text" name="user" autocomplete="username">
        </label>
        <label>Passwort
            <input type="password" name="pass" autocomplete="current-password">
        </label>
        <button type="submit">Anmelden</button>
    </form>
</div>
</body>
</html>
