<?php
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');

// Nur Admins dürfen diesen Endpunkt nutzen
if (!$currentAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Kein Zugriff.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── GET: Benutzerliste ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $users = array_map(fn($u) => [
        'username' => $u['username'],
        'is_admin' => !empty($u['is_admin']),
    ], load_users());
    echo json_encode(['ok' => true, 'users' => $users]);
    exit;
}

// ─── POST: Benutzer anlegen ───────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $is_admin = !empty($body['is_admin']);

    if ($username === '') { echo json_encode(['ok' => false, 'error' => 'Kein Benutzername angegeben.']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) { echo json_encode(['ok' => false, 'error' => 'Benutzername enthält ungültige Zeichen.']); exit; }
    if (strlen($password) < 8) { echo json_encode(['ok' => false, 'error' => 'Passwort muss mindestens 8 Zeichen lang sein.']); exit; }
    if (user_exists($username)) { echo json_encode(['ok' => false, 'error' => "Benutzer '$username' existiert bereits."]); exit; }

    // Benutzerverzeichnis anlegen
    $paths = user_paths($username);
    if (!is_dir($paths['backups'])) mkdir($paths['backups'], 0770, true);

    // Logverzeichnis sicherstellen
    if (!is_dir($logDir)) mkdir($logDir, 0775, true);

    if (!add_user($username, $password, $is_admin)) {
        echo json_encode(['ok' => false, 'error' => 'Fehler beim Anlegen des Benutzers.']);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => "Benutzer '$username' angelegt."]);
    exit;
}

// ─── POST: Passwort zurücksetzen ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'reset_password') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!user_exists($username)) { echo json_encode(['ok' => false, 'error' => "Benutzer '$username' nicht gefunden."]); exit; }
    if (strlen($password) < 8)   { echo json_encode(['ok' => false, 'error' => 'Neues Passwort muss mindestens 8 Zeichen lang sein.']); exit; }

    if (!update_password($username, $password)) {
        echo json_encode(['ok' => false, 'error' => 'Fehler beim Aktualisieren des Passworts.']);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => "Passwort für '$username' zurückgesetzt."]);
    exit;
}

// ─── DELETE: Benutzer löschen ─────────────────────────────────────────────────
if ($method === 'DELETE') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($body['username'] ?? '');

    if ($username === $currentUser) { echo json_encode(['ok' => false, 'error' => 'Du kannst deinen eigenen Account nicht löschen.']); exit; }
    if (!user_exists($username))    { echo json_encode(['ok' => false, 'error' => "Benutzer '$username' nicht gefunden."]); exit; }

    if (!delete_user($username)) {
        echo json_encode(['ok' => false, 'error' => 'Fehler beim Löschen.']);
        exit;
    }
    // Hinweis: Benutzerdaten (Lua-Dateien, rules.json) bleiben erhalten
    // und müssen manuell gelöscht werden, um Datenverlust zu vermeiden.
    echo json_encode(['ok' => true, 'message' => "Benutzer '$username' gelöscht. Dateien unter /srv/imapfilter/$username/ bleiben erhalten."]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
