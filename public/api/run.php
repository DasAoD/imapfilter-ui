<?php
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');

$imapfilterConfig = $userPaths['config'];
$logFile          = rtrim($logDir, '/') . '/' . $currentUser . '.log';
$lockFile         = rtrim($logDir, '/') . '/' . $currentUser . '.lock';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $lines = max(10, min(500, (int)($_GET['lines'] ?? 100)));
    if (!file_exists($logFile)) { echo json_encode(['ok' => true, 'log' => '(Logdatei noch nicht vorhanden)']); exit; }
    $output = shell_exec('tail -n ' . $lines . ' ' . escapeshellarg($logFile) . ' 2>&1');
    echo json_encode(['ok' => true, 'log' => $output ?? '']);
    exit;
}

if ($method === 'POST') {
    if (!file_exists($imapfilterBin))    { echo json_encode(['ok' => false, 'error' => "imapfilter-Binary nicht gefunden: $imapfilterBin"]); exit; }
    if (!file_exists($imapfilterConfig)) { echo json_encode(['ok' => false, 'error' => "config.lua nicht gefunden. Bitte erst Lua generieren."]); exit; }

    // Lockfile prüfen
    if (file_exists($lockFile)) {
        $pid = (int)file_get_contents($lockFile);
        if ($pid > 0 && file_exists('/proc/' . $pid)) {
            echo json_encode(['ok' => false, 'error' => 'imapfilter läuft bereits (PID ' . $pid . '). Bitte warten.']);
            exit;
        }
        @unlink($lockFile);
    }

    file_put_contents($lockFile, getmypid());
    $cmd    = 'HOME=/tmp timeout 120 ' . escapeshellarg($imapfilterBin) . ' -c ' . escapeshellarg($imapfilterConfig) . ' 2>&1';
    $output = []; $code = 0;
    exec($cmd, $output, $code);
    @unlink($lockFile);

    echo json_encode(['ok' => ($code === 0), 'exit_code' => $code, 'output' => implode("\n", $output), 'message' => $code === 0 ? 'imapfilter erfolgreich ausgeführt.' : "imapfilter beendet mit Code $code."]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
