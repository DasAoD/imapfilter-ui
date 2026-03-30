<?php
/**
 * Benutzerverwaltung — liest/schreibt users.json
 * Format: [{ "username": "…", "password_hash": "…", "is_admin": bool }, …]
 */

function users_file(): string {
    global $usersJson;
    return $usersJson;
}

function load_users(): array {
    $f = users_file();
    if (!file_exists($f)) return [];
    $data = json_decode(file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

function save_users(array $users): bool {
    return file_put_contents(users_file(), json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function find_user(string $username): ?array {
    foreach (load_users() as $u) {
        if ($u['username'] === $username) return $u;
    }
    return null;
}

function user_exists(string $username): bool {
    return find_user($username) !== null;
}

function verify_user(string $username, string $password): ?array {
    $u = find_user($username);
    if (!$u) return null;
    if (!password_verify($password, $u['password_hash'])) return null;
    return $u;
}

function add_user(string $username, string $password, bool $is_admin = false): bool {
    if (user_exists($username)) return false;
    $users   = load_users();
    $users[] = [
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'is_admin'      => $is_admin,
    ];
    return save_users($users);
}

function delete_user(string $username): bool {
    $users = array_filter(load_users(), fn($u) => $u['username'] !== $username);
    return save_users(array_values($users));
}

function update_password(string $username, string $new_password): bool {
    $users = load_users();
    $found = false;
    foreach ($users as &$u) {
        if ($u['username'] === $username) {
            $u['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            $found = true;
            break;
        }
    }
    unset($u);
    if (!$found) return false;
    return save_users($users);
}

/**
 * Verzeichnis für einen Benutzer zurückgeben.
 * Erstellt es inklusive backups-Unterordner, falls nötig.
 */
function user_dir(string $username): string {
    global $luaBaseDir;
    $dir = rtrim($luaBaseDir, '/') . '/' . $username;
    if (!is_dir($dir)) {
        mkdir($dir . '/backups', 0770, true);
    }
    return $dir;
}

function user_paths(string $username): array {
    $dir = user_dir($username);
    return [
        'dir'          => $dir,
        'filters'      => $dir . '/filters.lua',
        'folders'      => $dir . '/folders.lua',
        'config'       => $dir . '/config.lua',
        'rules'        => $dir . '/rules.json',
        'settings'     => $dir . '/imap_settings.json',
        'backups'      => $dir . '/backups',
    ];
}
