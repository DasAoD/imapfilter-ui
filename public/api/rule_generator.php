<?php
require_once __DIR__ . '/auth_check.php';
require_once dirname(__DIR__, 2) . '/lib/rule_generator.php';
require_once dirname(__DIR__, 2) . '/lib/generate.php';
require_once dirname(__DIR__, 2) . '/lib/atomic.php';
header('Content-Type: application/json');

$rulesJson    = $userPaths['rules'];
$settingsJson = $userPaths['settings'];

function rg_read_json(string $file, array $default = []): array {
    if (!file_exists($file)) return $default;
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── GET ?action=preview: Ordner scannen, Vorschläge berechnen ──────────────
if ($method === 'GET' && $action === 'preview') {
    @set_time_limit(120); // Scan läuft über alle Ordner des Kontos, kann etwas dauern
    $settings = rg_read_json($settingsJson);
    if (empty($settings['host'])) {
        echo json_encode(['ok' => false, 'error' => 'IMAP-Einstellungen noch nicht konfiguriert.']); exit;
    }

    $conn = rulegen_imap_open($settings);
    if (isset($conn['error'])) {
        echo json_encode(['ok' => false, 'error' => $conn['error']]); exit;
    }
    $imap = $conn['imap'];

    $rulesData  = rg_read_json($rulesJson, ['spam' => [], 'blacklist' => [], 'rules' => []]);
    $spamTarget = trim((string)($rulesData['spam']['target'] ?? ''));

    $folders    = rulegen_list_folders($imap, $conn['mbox']);
    $scanned    = [];
    $excludedBy = [];
    foreach ($folders as $f) {
        // INBOX kann nie Regel-Ziel sein (siehe rulegen_apply()) — erst gar
        // nicht scannen, damit es auch nie als Vorschlag auftauchen kann.
        if (strcasecmp($f, 'INBOX') === 0) continue;
        if (rulegen_default_excluded($f, $spamTarget)) $excludedBy[$f] = true;
        $domains = rulegen_scan_folder($imap, $conn['mbox'], $f);
        if (!empty($domains)) $scanned[$f] = $domains;
    }
    imap_close($imap);

    $suggestions = rulegen_build_suggestions($rulesData, $scanned);
    foreach ($suggestions as &$s) {
        $s['preselected'] = !isset($excludedBy[$s['target']]);
    }
    unset($s);

    echo json_encode(['ok' => true, 'suggestions' => $suggestions]);
    exit;
}

// ─── POST ?action=apply: bestätigte Auswahl übernehmen ──────────────────────
if ($method === 'POST' && $action === 'apply') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['selections']) || !is_array($body['selections'])) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige Daten.']); exit;
    }

    // Gleicher Lock wie rules.php/blacklist.php, damit sich Web-UI-Speichern,
    // Sperrlisten-Sync und Regel-Generierung nicht gegenseitig überschreiben.
    $lockFile = rtrim($userPaths['dir'], '/') . '/rules.json.lock';
    $result   = ['ok' => false, 'error' => 'Konnte rules.json nicht speichern.'];

    with_file_lock($lockFile, function () use ($rulesJson, $body, &$result) {
        // Noch nie gespeicherte rules.json (Account, an dem der reguläre
        // Regel-Editor noch nie "Speichern" ausgelöst hat) ist kein Fehler —
        // dann fangen wir eben mit einer leeren Grundstruktur an.
        $default = ['version' => 1, 'spam' => ['enabled' => true, 'whitelist' => [], 'target' => 'Spam'], 'blacklist' => ['enabled' => false, 'senders' => []], 'rules' => []];
        $rulesData = file_exists($rulesJson) ? rg_read_json($rulesJson, []) : $default;
        if (empty($rulesData)) {
            $result = ['ok' => false, 'error' => 'rules.json ist ungültig oder beschädigt.'];
            return;
        }
        $stats = rulegen_apply($rulesData, $body['selections']);
        if (atomic_write_json($rulesJson, $rulesData, 0640)) {
            $result = ['ok' => true, 'added' => $stats['added'], 'updated' => $stats['updated']];
        }
    });

    if (!empty($result['ok']) && file_exists($settingsJson)) {
        $gen = generate_lua($userPaths, $currentUser, $imapfilterBin);
        if (!$gen['ok']) $result['warning'] = $gen['error'];
    }

    echo json_encode($result);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
