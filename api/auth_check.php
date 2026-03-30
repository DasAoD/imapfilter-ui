<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/users.php';
session_start();

if (empty($_SESSION['imapfilter_logged_in'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

// Aktiven Benutzer und seine Pfade bereitstellen
$currentUser  = $_SESSION['username'];
$currentAdmin = !empty($_SESSION['is_admin']);
$userPaths    = user_paths($currentUser);
