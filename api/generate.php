<?php
require_once __DIR__ . '/auth_check.php';
require_once dirname(__DIR__) . '/lib/generate.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Nur POST erlaubt.']);
    exit;
}

$result = generate_lua($userPaths, $currentUser, $imapfilterBin);
echo json_encode($result);
