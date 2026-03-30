#!/usr/bin/env php
<?php
/**
 * IMAPFilter Dispatcher — nur als CLI-Skript ausführbar.
 */

// Sicherheit: Aufruf nur per CLI erlaubt
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// Bootstrap
define('DISPATCHER', true);
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/lib/users.php';
require_once $baseDir . '/lib/atomic.php';

// ─── Hilfsfunktionen ─────────────────────────────────────────────────────────

function dispatcher_log(string $msg): void {
    global $logDir;
    $line = '[' . date('Y-m-d H:i:s') . '] [dispatcher] ' . $msg . "\n";
    $file = rtrim($logDir, '/') . '/dispatcher.log';
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function read_user_settings(string $settingsFile): array {
    if (!file_exists($settingsFile)) return [];
    $data = json_decode(file_get_contents($settingsFile), true);
    return is_array($data) ? $data : [];
}

function read_state(string $stateFile): array {
    if (!file_exists($stateFile)) return [];
    $data = json_decode(file_get_contents($stateFile), true);
    return is_array($data) ? $data : [];
}

function write_state(string $stateFile, array $state): void {
    atomic_write_json($stateFile, $state);
}

// ─── Hauptlogik ───────────────────────────────────────────────────────────────

$users     = load_users();
$stateFile = rtrim($luaBaseDir, '/') . '/dispatcher_state.json';
$state     = read_state($stateFile);
$now       = time();
$changed   = false;

if (empty($users)) {
    dispatcher_log('Keine Benutzer gefunden.');
    exit(0);
}

foreach ($users as $user) {
    $username = $user['username'];
    $paths    = user_paths($username);
    $settings = read_user_settings($paths['settings']);

    // Benutzer ohne Einstellungen oder deaktivierten Intervall überspringen
    $intervalMin = (int)($settings['run_interval'] ?? 5);
    if ($intervalMin <= 0) {
        continue;
    }

    // config.lua muss existieren (wurde durch "Lua generieren" erstellt)
    if (!file_exists($paths['config'])) {
        dispatcher_log("[$username] config.lua nicht gefunden, überspringe.");
        continue;
    }

    $intervalSec = $intervalMin * 60;
    $lastRun     = $state[$username]['last_run'] ?? 0;
    $nextRun     = $lastRun + $intervalSec;

    if ($now < $nextRun) {
        // Noch nicht fällig
        continue;
    }

    // imapfilter starten
    $logFile = rtrim($logDir, '/') . '/' . $username . '.log';
    $cmd     = 'HOME=/tmp '
             . escapeshellarg($imapfilterBin)
             . ' -c ' . escapeshellarg($paths['config'])
             . ' >> ' . escapeshellarg($logFile)
             . ' 2>&1';

    dispatcher_log("[$username] Starte imapfilter (Intervall: {$intervalMin} Min.)…");
    $start = microtime(true);
    exec($cmd, $output, $code);
    $duration = round(microtime(true) - $start, 1);

    if ($code === 0) {
        dispatcher_log("[$username] Fertig in {$duration}s (Exit: 0).");
    } else {
        dispatcher_log("[$username] Fehler! Exit-Code: $code (nach {$duration}s).");
    }

    $state[$username] = [
        'last_run'      => $now,
        'last_exit'     => $code,
        'last_duration' => $duration,
        'interval'      => $intervalMin,
    ];
    $changed = true;
}

if ($changed) {
    write_state($stateFile, $state);
}

exit(0);
