<?php
require_once __DIR__ . '/auth_check.php';
require_once dirname(__DIR__) . '/lib/generate.php';
require_once dirname(__DIR__) . '/lib/atomic.php';
header('Content-Type: application/json');

$validFiles = [
    'filters' => ['label' => 'filters.lua', 'path' => $userPaths['filters']],
    'folders' => ['label' => 'folders.lua', 'path' => $userPaths['folders']],
];

$method  = $_SERVER['REQUEST_METHOD'];
$fileKey = $_GET['file'] ?? 'filters';
if (!isset($validFiles[$fileKey])) { echo json_encode(['ok' => false, 'error' => 'Ungültiger Dateiname.']); exit; }
$filePath = $validFiles[$fileKey]['path'];

if ($method === 'GET') {
    if (!file_exists($filePath)) { echo json_encode(['ok' => true, 'content' => "-- Datei noch nicht vorhanden.\n", 'exists' => false]); exit; }
    $content = file_get_contents($filePath);
    echo json_encode(['ok' => true, 'content' => $content !== false ? $content : '', 'exists' => true]);
    exit;
}

if ($method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = $body['content'] ?? '';
    // Backup mit Rotation (max. 10)
    make_lua_backup($filePath, $userPaths['backups']);
    if (!atomic_write($filePath, $content, 0640)) {
        echo json_encode(['ok' => false, 'error' => 'Konnte Datei nicht schreiben.']); exit;
    }
    echo json_encode(['ok' => true, 'message' => "Datei '{$validFiles[$fileKey]['label']}' gespeichert."]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
