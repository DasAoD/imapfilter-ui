<?php
require_once __DIR__ . '/auth_check.php';
require_once dirname(__DIR__) . '/lib/atomic.php';
header('Content-Type: application/json');

$stateFile = rtrim($luaBaseDir, '/') . '/dispatcher_state.json';

function interval_label(int $minutes): string {
    if ($minutes === 0)  return 'Deaktiviert';
    if ($minutes < 60)   return $minutes . ' Min.';
    if ($minutes < 1440) return round($minutes / 60, 1) . ' Std.';
    return round($minutes / 1440, 1) . ' Tage';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $state = file_exists($stateFile)
        ? (json_decode(file_get_contents($stateFile), true) ?? [])
        : [];

    if ($currentAdmin && isset($_GET['all'])) {
        $users  = load_users();
        $result = [];
        foreach ($users as $u) {
            $name  = $u['username'];
            $paths = user_paths($name);
            $s     = file_exists($paths['settings'])
                ? (json_decode(file_get_contents($paths['settings']), true) ?? [])
                : [];
            $minutes   = (int)($s['run_interval'] ?? 5);
            $userState = $state[$name] ?? null;
            $result[]  = [
                'username'      => $name,
                'interval'      => $minutes,
                'interval_label'=> interval_label($minutes),
                'config_exists' => file_exists($paths['config']),
                'last_run'      => $userState['last_run']      ?? null,
                'last_exit'     => $userState['last_exit']     ?? null,
                'last_duration' => $userState['last_duration'] ?? null,
            ];
        }
        echo json_encode(['ok' => true, 'users' => $result]);
    } else {
        $paths     = user_paths($currentUser);
        $s         = file_exists($paths['settings'])
            ? (json_decode(file_get_contents($paths['settings']), true) ?? [])
            : [];
        $minutes   = (int)($s['run_interval'] ?? 5);
        $userState = $state[$currentUser] ?? null;
        echo json_encode([
            'ok'             => true,
            'interval'       => $minutes,
            'interval_label' => interval_label($minutes),
            'config_exists'  => file_exists($paths['config']),
            'last_run'       => $userState['last_run']      ?? null,
            'last_exit'      => $userState['last_exit']     ?? null,
            'last_duration'  => $userState['last_duration'] ?? null,
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $minutes = (int)($body['interval'] ?? 5);
    if ($minutes < 0 || $minutes > 10080) {
        echo json_encode(['ok' => false, 'error' => 'Intervall muss zwischen 0 und 10080 Minuten liegen.']);
        exit;
    }
    $paths    = user_paths($currentUser);
    $settings = file_exists($paths['settings'])
        ? (json_decode(file_get_contents($paths['settings']), true) ?? [])
        : [];
    $settings['run_interval'] = $minutes;
    if (!atomic_write_json($paths['settings'], $settings, 0600)) {
        echo json_encode(['ok' => false, 'error' => 'Konnte Einstellungen nicht speichern.']);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => $minutes === 0 ? 'Dispatcher deaktiviert.' : "Intervall: $minutes Minute(n)."]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
