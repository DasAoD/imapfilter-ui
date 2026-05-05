<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/users.php';
session_start();

if (empty($_SESSION['imapfilter_logged_in'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

// CSRF-Token sicherstellen
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF-Prüfung bei schreibenden Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Ungültiger CSRF-Token.']);
        exit;
    }
}

// Aktiven Benutzer und seine Pfade bereitstellen
$currentUser  = $_SESSION['username'];
$currentAdmin = !empty($_SESSION['is_admin']);
$userPaths    = user_paths($currentUser);
