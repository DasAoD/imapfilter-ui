<?php
require_once __DIR__ . '/auth_check.php';
require_once dirname(__DIR__, 2) . '/lib/blacklist.php';
header('Content-Type: application/json');

// Siehe rule_generator.php: IMAP-Alerts würden sonst die JSON-Antwort verunreinigen.
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']); exit;
}

$maxScan = 5000; // Obergrenze wie im Abgleich (lib/blacklist.php), damit große Ordner die Anfrage nicht blockieren

$rules    = file_exists($userPaths['rules'])    ? json_decode(file_get_contents($userPaths['rules']), true)    : null;
$settings = file_exists($userPaths['settings']) ? json_decode(file_get_contents($userPaths['settings']), true) : null;
if (!is_array($rules) || !is_array($settings)) {
    echo json_encode(['ok' => false, 'error' => 'Regeln oder IMAP-Einstellungen fehlen.']); exit;
}
$target = trim((string)($rules['spam']['target'] ?? ''));
if ($target === '' || $target === 'INBOX') {
    echo json_encode(['ok' => false, 'error' => 'Kein gültiger Spam-Zielordner konfiguriert.']); exit;
}

// Sonst hält die Session-Sperre andere Requests desselben Benutzers während des IMAP-Scans auf.
session_write_close();
@set_time_limit(60);
$conn = blacklist_imap_open($settings, $target);
if (isset($conn['error'])) {
    echo json_encode(['ok' => false, 'error' => $conn['error']]); exit;
}
$imap  = $conn['imap'];
$count = @imap_num_msg($imap) ?: 0;
$spamOverviews = $count > 0 ? (imap_fetch_overview($imap, max(1, $count - $maxScan + 1) . ':' . $count) ?: []) : [];

// Muster, die Mails der INBOX treffen würden, werden nicht vorgeschlagen. Bei zu großer
// INBOX (oder wenn sie sich nicht öffnen lässt) entfällt die Prüfung (inbox_hits = null).
$inboxOverviews = null;
if (@imap_reopen($imap, $conn['mbox'] . 'INBOX')) {
    $inboxCount = @imap_num_msg($imap) ?: 0;
    if ($inboxCount <= $maxScan) {
        $inboxOverviews = $inboxCount > 0 ? (imap_fetch_overview($imap, '1:' . $inboxCount) ?: []) : [];
    }
}
imap_errors();
imap_alerts();
imap_close($imap);

$blacklist = $rules['blacklist'] ?? [];
$whitelist = array_values(array_filter((array)($rules['spam']['whitelist'] ?? []), 'is_string'));
$result = blacklist_suggest($spamOverviews, $blacklist, $inboxOverviews, $whitelist);
$names  = blacklist_suggest_names($spamOverviews, $blacklist, $inboxOverviews, $whitelist);
// Bei abgeschnittenem Scan kennen wir nicht alle Mails eines Absenders: nichts zum Aufräumen vorschlagen.
if ($count > $maxScan) $result['redundant'] = [];

echo json_encode(['ok' => true, 'patterns' => $result['patterns'], 'names' => $names, 'redundant' => $result['redundant']]);
