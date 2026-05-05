<?php
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function validate_password(string $pwd): ?string {
    if (strlen($pwd) < 10) return 'Passwort muss mindestens 10 Zeichen lang sein.';
    if (!preg_match('/[A-Z]/', $pwd)) return 'Passwort muss einen Großbuchstaben enthalten.';
    if (!preg_match('/[a-z]/', $pwd)) return 'Passwort muss einen Kleinbuchstaben enthalten.';
    if (!preg_match('/[0-9]/', $pwd)) return 'Passwort muss eine Zahl enthalten.';
    if (!preg_match('/[!@#$%^&*\-_=+?]/', $pwd)) return 'Passwort muss ein Sonderzeichen enthalten (!@#$%^&*-_=+?).';
    return null;
}

// ─── POST: Eigenes Passwort ändern (alle Benutzer) ───────────────────────────
if ($method === 'POST' && $action === 'change_password') {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $currentPwd  = $body['current_password'] ?? '';
    $newPwd      = $body['new_password']     ?? '';

    // Aktuelles Passwort prüfen
    $user = find_user($currentUser);
    if (!$user || !password_verify($currentPwd, $user['password_hash'])) {
        echo json_encode(['ok' => false, 'error' => 'Aktuelles Passwort ist falsch.']);
        exit;
    }

    // Passwort-Anforderungen serverseitig prüfen
    if (strlen($newPwd) < 10) {
        echo json_encode(['ok' => false, 'error' => 'Neues Passwort muss mindestens 10 Zeichen lang sein.']);
        exit;
    }
    if (!preg_match('/[A-Z]/', $newPwd) || !preg_match('/[a-z]/', $newPwd) ||
        !preg_match('/[0-9]/', $newPwd) || !preg_match('/[!@#$%^&*\-_=+?]/', $newPwd)) {
        echo json_encode(['ok' => false, 'error' => 'Passwort erfüllt nicht die Anforderungen (Groß-/Kleinbuchstaben, Zahl, Sonderzeichen).']);
        exit;
    }
    if ($newPwd === $currentPwd) {
        echo json_encode(['ok' => false, 'error' => 'Neues Passwort muss sich vom aktuellen unterscheiden.']);
        exit;
    }

    if (!update_password($currentUser, $newPwd)) {
        echo json_encode(['ok' => false, 'error' => 'Fehler beim Speichern des Passworts.']);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Passwort erfolgreich geändert.']);
    exit;
}

// ─── Nur Admins ab hier ───────────────────────────────────────────────────────
if (!$currentAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Kein Zugriff.']);
    exit;
}

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
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\-]{1,30}$/', $username)) {
        echo json_encode(['ok' => false, 'error' => 'Benutzername ungültig (2–31 Zeichen, nur Buchstaben/Zahlen/Bindestrich/Unterstrich, muss mit Buchstabe/Zahl beginnen).']);
        exit;
    }
    $pwdErr = validate_password($password);
    if ($pwdErr) { echo json_encode(['ok' => false, 'error' => $pwdErr]); exit; }
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
    $pwdErr = validate_password($password);
    if ($pwdErr) { echo json_encode(['ok' => false, 'error' => $pwdErr]); exit; }

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
