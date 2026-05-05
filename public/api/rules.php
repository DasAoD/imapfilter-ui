<?php
require_once __DIR__ . '/auth_check.php';
require_once dirname(__DIR__, 2) . '/lib/generate.php';
require_once dirname(__DIR__, 2) . '/lib/atomic.php';
header('Content-Type: application/json');

$rulesJson = $userPaths['rules'];

function default_rules(): array {
    return ['version' => 1, 'spam' => ['enabled' => true, 'header_field' => 'X-KasSpamfilter', 'header_value' => 'rSpamD', 'whitelist' => [], 'target' => 'Spam'], 'rules' => []];
}

function read_rules(string $file): array {
    if (file_exists($file)) { $d = json_decode(file_get_contents($file), true); if (is_array($d)) return $d; }
    return default_rules();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(['ok' => true, 'data' => read_rules($rulesJson)]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['rules'])) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige Daten.']); exit;
    }
    foreach ($body['rules'] as &$rule) { if (empty($rule['id'])) $rule['id'] = bin2hex(random_bytes(6)); }
    unset($rule);
    $body['version'] = 1;

    if (atomic_write_json($rulesJson, $body, 0640) === false) {
        echo json_encode(['ok' => false, 'error' => 'Konnte rules.json nicht speichern.']); exit;
    }

    // Lua-Dateien sofort automatisch neu generieren (nur wenn IMAP-Einstellungen vorhanden)
    if (file_exists($userPaths['settings'])) {
        $gen = generate_lua($userPaths, $currentUser, $imapfilterBin);
        if (!$gen['ok']) {
            // Regeln gespeichert, aber Lua-Generierung fehlgeschlagen → als Warning zurückgeben
            echo json_encode(['ok' => true, 'warning' => $gen['error']]);
            exit;
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
