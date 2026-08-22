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
require_once $baseDir . '/lib/generate.php';
require_once $baseDir . '/lib/blacklist.php';

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
    $logFile  = rtrim($logDir, '/') . '/' . $username . '.log';
    $lockFile = rtrim($logDir, '/') . '/' . $username . '.lock';

    // Lockfile prüfen — läuft noch ein Prozess?
    if (file_exists($lockFile)) {
        $pid = (int)file_get_contents($lockFile);
        if ($pid > 0 && file_exists('/proc/' . $pid)) {
            dispatcher_log("[$username] Überspringe — Lauf #$pid noch aktiv.");
            continue;
        }
        // Veraltetes Lockfile entfernen
        @unlink($lockFile);
    }

    // Lockfile anlegen
    file_put_contents($lockFile, getmypid());

    $cmd     = 'HOME=/tmp timeout 120 '
             . escapeshellarg($imapfilterBin)
             . ' -c ' . escapeshellarg($paths['config'])
             . ' 2>&1';

    dispatcher_log("[$username] Starte imapfilter (Intervall: {$intervalMin} Min.)…");
    $start            = microtime(true);
    $imapfilterOutput = [];
    exec($cmd, $imapfilterOutput, $code);
    $duration = round(microtime(true) - $start, 1);

    // Ausgabe mit Zeitstempel ins Benutzer-Log schreiben (statt per Shell-Redirect
    // ohne jeden Zeitbezug) — ein Zeitstempel pro Lauf, damit im "Ausführen"-Log
    // erkennbar ist, wann zuletzt Mails verschoben wurden.
    $imapfilterOutput = array_filter($imapfilterOutput, fn($line) => trim($line) !== '');
    if (!empty($imapfilterOutput)) {
        $ts       = date('Y-m-d H:i:s');
        $logLines = array_map(fn($line) => "[$ts] $line", $imapfilterOutput);
        file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND | LOCK_EX);
    }

    if ($code === 0) {
        dispatcher_log("[$username] Fertig in {$duration}s (Exit: 0).");

        // Sperrliste mit dem aktuellen Spam-Ordner abgleichen (Issue #1).
        // Läuft noch unter demselben Lockfile wie imapfilter selbst, damit ein
        // überlappender Dispatcher-Lauf (Cron alle 60s) nicht gleichzeitig auf
        // blacklist_state.json desselben Benutzers zugreift.
        $sync = sync_spam_blacklist($paths);
        if ($sync['error']) {
            $errForLog = str_replace(["\r", "\n"], ' ', $sync['error']);
            dispatcher_log("[$username] Sperrlisten-Abgleich übersprungen: $errForLog");
        } elseif ($sync['changed']) {
            // Absenderadressen stammen aus Spam-Mails — vor dem Loggen von Steuerzeichen befreien
            $forLog  = fn(array $a) => implode(', ', array_map(fn($s) => str_replace(["\r", "\n"], ' ', $s), $a));
            $parts   = [];
            if ($sync['added'])   $parts[] = 'neu gesperrt: ' . $forLog($sync['added']);
            if ($sync['removed']) $parts[] = 'entsperrt: ' . $forLog($sync['removed']);
            dispatcher_log("[$username] Sperrliste aktualisiert (" . implode(' · ', $parts) . ').');

            $gen = generate_lua($paths, $username, $imapfilterBin);
            if (!$gen['ok']) {
                dispatcher_log("[$username] Lua-Neugenerierung nach Sperrlisten-Update fehlgeschlagen: {$gen['error']}");
            }
        }
    } else {
        dispatcher_log("[$username] Fehler! Exit-Code: $code (nach {$duration}s).");
    }

    @unlink($lockFile);

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
