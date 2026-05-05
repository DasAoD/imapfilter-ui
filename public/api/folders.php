<?php
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');

$settingsJson = $userPaths['settings'];

function get_imap_conn(string $settingsJson): array {
    if (!function_exists('imap_open'))   return ['error' => 'PHP-IMAP-Extension nicht installiert.'];
    if (!file_exists($settingsJson))      return ['error' => 'IMAP-Einstellungen noch nicht konfiguriert.'];
    $s = json_decode(file_get_contents($settingsJson), true);
    if (empty($s['host']) || empty($s['user']) || empty($s['pass'])) return ['error' => 'IMAP-Einstellungen unvollständig.'];
    $ssl  = ($s['ssl'] ?? true) ? '/ssl' : '';
    if (!empty($s['ssl_novalidate'])) $ssl .= '/novalidate-cert';
    $mbox = '{' . $s['host'] . ':' . $s['port'] . $ssl . '}';
    $imap = @imap_open($mbox, $s['user'], $s['pass'], 0, 1);
    if (!$imap) return ['error' => imap_last_error() ?: 'Verbindung fehlgeschlagen.'];
    return ['imap' => $imap, 'mbox' => $mbox];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── GET: Ordner auflisten ────────────────────────────────────────────────────
if ($method === 'GET') {
    $conn = get_imap_conn($settingsJson);
    if (isset($conn['error'])) { echo json_encode(['ok' => false, 'error' => $conn['error']]); exit; }
    $raw = imap_list($conn['imap'], $conn['mbox'], '*');
    imap_close($conn['imap']);
    if ($raw === false) { echo json_encode(['ok' => false, 'error' => 'Ordnerliste konnte nicht abgerufen werden.']); exit; }
    $folders = [];
    foreach ($raw as $f) {
        $name = imap_utf8(substr($f, strlen($conn['mbox'])));
        $folders[] = $name;
    }
    sort($folders);
    echo json_encode(['ok' => true, 'folders' => $folders]);
    exit;
}

// ─── POST: Ordner erstellen ───────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? '');
    if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Kein Ordnername angegeben.']); exit; }
    $conn = get_imap_conn($settingsJson);
    if (isset($conn['error'])) { echo json_encode(['ok' => false, 'error' => $conn['error']]); exit; }
    $result = imap_createmailbox($conn['imap'], imap_utf7_encode($conn['mbox'] . $name));
    imap_close($conn['imap']);
    if (!$result) { echo json_encode(['ok' => false, 'error' => imap_last_error() ?: 'Ordner konnte nicht erstellt werden.']); exit; }
    echo json_encode(['ok' => true, 'message' => "Ordner '$name' erstellt."]);
    exit;
}

// ─── POST: Ordner umbenennen ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'rename') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $oldName = trim($body['old_name'] ?? '');
    $newName = trim($body['new_name'] ?? '');
    if ($oldName === '' || $newName === '') { echo json_encode(['ok' => false, 'error' => 'Alter und neuer Name erforderlich.']); exit; }
    if ($oldName === 'INBOX') { echo json_encode(['ok' => false, 'error' => 'INBOX kann nicht umbenannt werden.']); exit; }
    $conn = get_imap_conn($settingsJson);
    if (isset($conn['error'])) { echo json_encode(['ok' => false, 'error' => $conn['error']]); exit; }
    $result = imap_renamemailbox(
        $conn['imap'],
        imap_utf7_encode($conn['mbox'] . $oldName),
        imap_utf7_encode($conn['mbox'] . $newName)
    );
    imap_close($conn['imap']);
    if (!$result) { echo json_encode(['ok' => false, 'error' => imap_last_error() ?: 'Umbenennen fehlgeschlagen.']); exit; }
    echo json_encode(['ok' => true, 'message' => "Ordner umbenannt: '$oldName' → '$newName'."]);
    exit;
}

// ─── DELETE: Ordner löschen (Mails vorher in INBOX) ──────────────────────────
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? '');
    if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Kein Ordnername angegeben.']); exit; }
    if ($name === 'INBOX') { echo json_encode(['ok' => false, 'error' => 'INBOX kann nicht gelöscht werden.']); exit; }

    $conn = get_imap_conn($settingsJson);
    if (isset($conn['error'])) { echo json_encode(['ok' => false, 'error' => $conn['error']]); exit; }

    $imap = $conn['imap'];
    $mbox = $conn['mbox'];

    // Mails in INBOX verschieben
    $folder = imap_utf7_encode($mbox . $name);
    $src = @imap_reopen($imap, $folder);
    if ($src) {
        $count = imap_num_msg($imap);
        if ($count > 0) {
            $msgs = implode(',', range(1, $count));
            @imap_mail_move($imap, $msgs, 'INBOX');
            @imap_expunge($imap);
        }
        @imap_reopen($imap, $mbox . 'INBOX');
    }

    // Ordner löschen
    $result = imap_deletemailbox($imap, $folder);
    imap_close($imap);

    if (!$result) { echo json_encode(['ok' => false, 'error' => imap_last_error() ?: 'Löschen fehlgeschlagen.']); exit; }
    echo json_encode(['ok' => true, 'message' => "Ordner '$name' gelöscht. Mails wurden in INBOX verschoben."]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
