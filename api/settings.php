<?php
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');

$settingsJson = $userPaths['settings'];

function read_settings(string $file): array {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    return ['host' => 'w010ea06.kasserver.com', 'port' => 993, 'ssl' => true, 'user' => '', 'pass' => ''];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    $s = read_settings($settingsJson);
    $s['pass_set'] = !empty($s['pass']);
    $s['pass']     = '';
    echo json_encode(['ok' => true, 'settings' => $s]);
    exit;
}

if ($method === 'POST' && $action === 'save') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $existing = read_settings($settingsJson);
    $s = [
        'host' => trim($body['host'] ?? $existing['host']),
        'port' => (int)($body['port'] ?? $existing['port']),
        'ssl'  => (bool)($body['ssl']  ?? $existing['ssl']),
        'user' => trim($body['user'] ?? $existing['user']),
        'pass' => trim($body['pass'] ?? ''),
    ];
    if ($s['pass'] === '' || $s['pass'] === '••••••••') {
        $s['pass'] = $existing['pass'];
    }
    if (file_put_contents($settingsJson, json_encode($s, JSON_PRETTY_PRINT)) === false) {
        echo json_encode(['ok' => false, 'error' => 'Konnte Einstellungen nicht speichern.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'POST' && $action === 'test') {
    if (!function_exists('imap_open')) {
        echo json_encode(['ok' => false, 'error' => 'PHP-IMAP-Extension nicht installiert.']);
        exit;
    }
    $s   = read_settings($settingsJson);
    $ssl = ($s['ssl'] ?? true) ? '/ssl/novalidate-cert' : '';
    $imap = @imap_open('{' . $s['host'] . ':' . $s['port'] . $ssl . '}', $s['user'], $s['pass'], 0, 1);
    if (!$imap) {
        echo json_encode(['ok' => false, 'error' => imap_last_error() ?: 'Verbindung fehlgeschlagen.']);
        exit;
    }
    imap_close($imap);
    echo json_encode(['ok' => true, 'message' => 'Verbindung erfolgreich.']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
