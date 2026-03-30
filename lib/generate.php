<?php
/**
 * Lua-Generierung als wiederverwendbare Funktion.
 * Wird von api/generate.php und api/rules.php (Auto-Generate) genutzt.
 */

function lua_str(string $s): string {
    $s = str_replace("\\", "\\\\", $s); // erst Backslash
    $s = str_replace("'",  "\\'",  $s); // dann Quote
    return "'" . $s . "'";
}

function lua_folder_var(string $name): string {
    $v = mb_strtolower($name, 'UTF-8');
    $v = preg_replace('/[^a-z0-9]+/', '_', $v);
    return 'f_' . trim($v, '_');
}

function lua_rule_var(string $name): string {
    $v = mb_strtolower($name, 'UTF-8');
    $v = preg_replace('/[^a-z0-9]+/', '_', $v);
    return 'r_' . trim($v, '_');
}

function make_lua_backup(string $file, string $backupDir): void {
    if (!file_exists($file)) return;
    if (!is_dir($backupDir)) mkdir($backupDir, 0770, true);
    copy($file, rtrim($backupDir, '/') . '/' . basename($file) . '.' . date('Ymd-His') . '.bak');
}

/**
 * Generiert config.lua, folders.lua und filters.lua für einen Benutzer.
 *
 * @param array  $paths        Ergebnis von user_paths($username)
 * @param string $username     Benutzername
 * @param string $imapfilterBin Pfad zum imapfilter-Binary (nur für Kommentar)
 * @return array ['ok' => bool, 'error' => string|null, 'message' => string|null]
 */
function generate_lua(array $paths, string $username, string $imapfilterBin): array {

    // ── Einstellungen laden ───────────────────────────────────────────────────
    if (!file_exists($paths['settings'])) {
        return ['ok' => false, 'error' => 'IMAP-Einstellungen fehlen. Bitte zuerst konfigurieren.'];
    }
    $settings = json_decode(file_get_contents($paths['settings']), true);
    if (!$settings || empty($settings['host'])) {
        return ['ok' => false, 'error' => 'IMAP-Einstellungen unvollständig.'];
    }

    // ── Regeln laden ──────────────────────────────────────────────────────────
    if (!file_exists($paths['rules'])) {
        return ['ok' => false, 'error' => 'Keine Regeln gefunden. Bitte erst Regeln anlegen.'];
    }
    $data = json_decode(file_get_contents($paths['rules']), true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'rules.json ist ungültig.'];
    }

    $spam  = $data['spam']  ?? ['enabled' => false];
    $rules = $data['rules'] ?? [];
    $ts    = date('Y-m-d H:i:s');
    $header = "-- Automatisch generiert von IMAPFilter Web-UI am $ts\n"
            . "-- Benutzer: $username\n"
            . "-- NICHT manuell bearbeiten (wird bei Regeländerungen überschrieben).\n";

    // ── Zielordner sammeln ────────────────────────────────────────────────────
    $targetFolders = [];
    if (!empty($spam['enabled']) && !empty($spam['target']) && $spam['target'] !== 'INBOX') {
        $targetFolders[$spam['target']] = lua_folder_var($spam['target']);
    }
    foreach ($rules as $rule) {
        if (empty($rule['enabled'])) continue;
        $tf = $rule['target'] ?? '';
        if ($tf !== '' && $tf !== 'INBOX') {
            $targetFolders[$tf] = lua_folder_var($tf);
        }
    }

    // ── config.lua ────────────────────────────────────────────────────────────
    $sslMode   = ($settings['ssl'] ?? true) ? 'ssl' : 'none';
    $configLua = $header . "\n"
        . "options.timeout   = 120\n"
        . "options.subscribe = true\n\n"
        . "account = IMAP {\n"
        . "    server   = " . lua_str($settings['host'])                   . ",\n"
        . "    port     = " . (int)($settings['port'] ?? 993)              . ",\n"
        . "    ssl      = " . lua_str($sslMode)                            . ",\n"
        . "    username = " . lua_str($settings['user'])                   . ",\n"
        . "    password = " . lua_str($settings['pass'])                   . ",\n"
        . "}\n\n"
        . "dofile(" . lua_str($paths['folders']) . ")\n"
        . "dofile(" . lua_str($paths['filters']) . ")\n";

    // ── folders.lua ───────────────────────────────────────────────────────────
    $fLines   = [$header, "inbox = account['INBOX']"];
    foreach ($targetFolders as $name => $var) {
        $fLines[] = "$var = account[" . lua_str($name) . "]";
    }
    $foldersLua = implode("\n", $fLines) . "\n";

    // ── filters.lua ───────────────────────────────────────────────────────────
    $lines = [$header];

    if (!empty($spam['enabled'])) {
        $lines[] = "\n-- ─── Spam ───────────────────────────────────────────────────────────────────";
        $lines[] = "spam_candidates = inbox:contain_field("
            . lua_str($spam['header_field'] ?? 'X-KasSpamfilter') . ", "
            . lua_str($spam['header_value'] ?? 'rSpamD') . ")";
        $wl = array_filter(array_map('trim', $spam['whitelist'] ?? []));
        if (!empty($wl)) {
            $parts   = array_map(fn($w) => "    inbox:contain_from(" . lua_str($w) . ")", $wl);
            $lines[] = "false_positive =\n" . implode(" +\n", $parts);
            $lines[] = "real_spam = spam_candidates - false_positive";
        } else {
            $lines[] = "real_spam = spam_candidates";
        }
        $spamVar = $targetFolders[$spam['target']] ?? lua_folder_var($spam['target'] ?? 'Spam');
        $lines[] = "inbox:move_messages($spamVar, real_spam)";
    }

    $lines[]  = "\n-- ─── Filterregeln ───────────────────────────────────────────────────────────";
    $varCount = [];

    foreach ($rules as $rule) {
        if (empty($rule['enabled'])) continue;
        $name   = $rule['name']   ?? 'Regel';
        $target = $rule['target'] ?? '';
        $from   = array_filter(array_map('trim', array_merge(...array_map(
            fn($a) => preg_split('/[,;]+/', $a),
            $rule['from_addresses'] ?? []
        ))));
        $to     = array_filter(array_map('trim', array_merge(...array_map(
            fn($a) => preg_split('/[,;]+/', $a),
            $rule['to_addresses'] ?? []
        ))));
        $subj   = array_filter(array_map('trim', array_merge(...array_map(
            fn($s) => preg_split('/[,;]+/', $s),
            $rule['subjects'] ?? []
        ))));
        $logic  = $rule['logic']  ?? 'OR';
        if ((empty($from) && empty($to) && empty($subj)) || empty($target)) continue;

        $lines[] = "\n-- $name";
        $base    = lua_rule_var($name);
        if (!isset($varCount[$base])) { $varCount[$base] = 0; }
        else { $base .= '_' . (++$varCount[$base]); }
        $tVar = $targetFolders[$target] ?? lua_folder_var($target);

        $fromVar = null;
        if (!empty($from)) {
            $fromVar = $base . '_from';
            $lines[] = $fromVar . " = " . implode("\n    + ", array_map(
                fn($a) => "inbox:contain_from(" . lua_str($a) . ")", $from
            ));
        }
        $toVar = null;
        if (!empty($to)) {
            $toVar   = $base . '_to';
            $lines[] = $toVar . " = " . implode("\n    + ", array_map(
                fn($a) => "inbox:contain_to(" . lua_str($a) . ")", $to
            ));
        }
        $subjVar = null;
        if (!empty($subj)) {
            $subjVar = $base . '_subj';
            $lines[] = $subjVar . " = " . implode("\n    + ", array_map(
                fn($s) => "inbox:contain_subject(" . lua_str($s) . ")", $subj
            ));
        }

        // Alle gesetzten Teile mit OR oder AND verknüpfen
        $parts = array_filter([$fromVar, $toVar, $subjVar]);
        if (count($parts) === 1) {
            $lines[] = $base . " = " . reset($parts);
        } else {
            $op      = ($logic === 'AND') ? ' * ' : ' + ';
            $lines[] = $base . " = " . implode($op, $parts);
        }

        $lines[] = "inbox:move_messages($tVar, $base)";
    }

    $filtersLua = implode("\n", $lines) . "\n";

    // ── Backups + Schreiben ───────────────────────────────────────────────────
    try {
        make_lua_backup($paths['config'],  $paths['backups']);
        make_lua_backup($paths['folders'], $paths['backups']);
        make_lua_backup($paths['filters'], $paths['backups']);

        foreach ([
            $paths['config']  => $configLua,
            $paths['folders'] => $foldersLua,
            $paths['filters'] => $filtersLua,
        ] as $file => $content) {
            if (file_put_contents($file, $content) === false) {
                throw new RuntimeException("Konnte $file nicht schreiben.");
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'message' => 'Lua-Dateien erfolgreich generiert.'];
}
