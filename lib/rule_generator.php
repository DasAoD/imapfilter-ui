<?php
/**
 * Automatische Generierung von Filterregeln aus dem Inhalt von IMAP-Ordnern.
 * Siehe Issue #3: "Filterregeln automatisch aus Ordnerinhalten generieren".
 *
 * Einmalige, manuell angestoßene Aktion (kein Dauer-Automatismus wie bei der
 * Sperrliste) — der Nutzer bekommt vor dem Speichern eine Vorschau
 * (public/api/rule_generator.php) und entscheidet pro Ordner, ob die
 * gefundenen Domains übernommen werden.
 */

require_once __DIR__ . '/atomic.php';

/**
 * Öffnet eine IMAP-Verbindung auf Kontoebene (INBOX), damit anschließend mit
 * imap_reopen() zwischen beliebigen Ordnern gewechselt werden kann.
 *
 * @return array{imap: \IMAP\Connection|resource, mbox: string}|array{error: string}
 */
function rulegen_imap_open(array $settings): array {
    if (!function_exists('imap_open')) return ['error' => 'PHP-IMAP-Extension nicht installiert.'];
    if (empty($settings['host']) || empty($settings['user']) || empty($settings['pass'])) {
        return ['error' => 'IMAP-Einstellungen unvollständig.'];
    }
    $ssl = ($settings['ssl'] ?? true) ? '/ssl' : '';
    if (!empty($settings['ssl_novalidate'])) $ssl .= '/novalidate-cert';
    $mbox = '{' . $settings['host'] . ':' . ($settings['port'] ?? 993) . $ssl . '}';
    $imap = @imap_open($mbox . 'INBOX', $settings['user'], $settings['pass'], 0, 1);
    if (!$imap) return ['error' => imap_last_error() ?: 'IMAP-Verbindung fehlgeschlagen.'];
    return ['imap' => $imap, 'mbox' => $mbox];
}

/**
 * Listet alle Ordner des Kontos (wie public/api/folders.php GET).
 */
function rulegen_list_folders($imap, string $mbox): array {
    $raw = @imap_list($imap, $mbox, '*');
    if ($raw === false) return [];
    $folders = [];
    foreach ($raw as $f) {
        $folders[] = imap_utf8(substr($f, strlen($mbox)));
    }
    sort($folders);
    return $folders;
}

/**
 * Heuristik: soll dieser Ordner in der Vorschau standardmäßig NICHT
 * vorausgewählt sein? (INBOX, Spam-Zielordner, sowie Sent/Trash/Drafts-artige
 * Systemordner — auf Deutsch und Englisch, per Namens-Teilstring erkannt).
 * Der Ordner wird trotzdem gescannt und in der Vorschau angezeigt — der
 * Nutzer kann die Auswahl bewusst umschalten ("konfigurierbar").
 */
function rulegen_default_excluded(string $folder, string $spamTarget): bool {
    if (strcasecmp($folder, 'INBOX') === 0) return true;
    if ($spamTarget !== '' && strcasecmp($folder, $spamTarget) === 0) return true;

    $leaf = $folder;
    if (($pos = strrpos($folder, '/')) !== false) $leaf = substr($folder, $pos + 1);
    $leaf = mb_strtolower($leaf, 'UTF-8');

    $needles = ['sent', 'gesendet', 'trash', 'papierkorb', 'deleted', 'gelöscht', 'draft', 'entw', 'junk', 'spam'];
    foreach ($needles as $n) {
        if (str_contains($leaf, $n)) return true;
    }
    return false;
}

/**
 * Extrahiert die reine Absenderadresse aus einem IMAP-Overview-From-Header.
 */
function rulegen_from_address(object $overview): ?string {
    $from = trim((string)($overview->from ?? ''));
    if ($from === '') return null;
    if (preg_match('/<([^<>]+)>/', $from, $m)) $from = $m[1];
    $from = mb_strtolower(trim($from));
    return $from !== '' ? $from : null;
}

/**
 * Liest alle Absenderdomains (inkl. führendem "@") aus einem Ordner.
 */
function rulegen_scan_folder($imap, string $mbox, string $folder): array {
    if (!@imap_reopen($imap, $mbox . imap_utf7_encode($folder))) return [];
    $count = @imap_num_msg($imap) ?: 0;
    if ($count === 0) return [];

    $overviews = imap_fetch_overview($imap, '1:' . $count) ?: [];
    $domains   = [];
    foreach ($overviews as $ov) {
        $addr = rulegen_from_address($ov);
        if ($addr === null) continue;
        $at = strrpos($addr, '@');
        if ($at === false) continue;
        $domain = substr($addr, $at);
        if (preg_match('/^@[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) $domains[$domain] = true;
    }
    return array_keys($domains);
}

/**
 * Leitet den Regelnamen aus dem Ordnerpfad ab (führendes "INBOX/" entfällt,
 * da es nur das implizite Wurzelverzeichnis markiert).
 */
function rulegen_derive_name(string $folder): string {
    return preg_replace('#^INBOX/#i', '', $folder);
}

/**
 * Baut die Vorschau: für jeden gescannten Ordner mit gefundenen Domains wird
 * ermittelt, ob eine neue Regel entstehen oder eine bestehende Regel ergänzt
 * werden würde, plus eventuelle Konflikte mit anderen Regeln/Sperrliste/
 * Whitelist. Ordner ohne neue Domains werden weggelassen.
 *
 * @param array $rulesData Kompletter Inhalt von rules.json
 * @param array $scanned   [folder => string[] domains] (nur Ordner mit Treffern)
 * @return array Liste von Vorschlägen
 */
function rulegen_build_suggestions(array $rulesData, array $scanned): array {
    $existingRules = $rulesData['rules'] ?? [];

    // Alle bereits an anderer Stelle verwendeten Adressen/Domains sammeln,
    // um Konflikte erkennen zu können.
    $usedElsewhere = []; // lowercase Adresse/Domain => [Fundstellen]
    foreach ($existingRules as $r) {
        foreach ($r['from_addresses'] ?? [] as $a) {
            $usedElsewhere[mb_strtolower(trim($a))][] = 'Regel „' . ($r['name'] ?? '?') . '"';
        }
    }
    foreach ($rulesData['blacklist']['senders'] ?? [] as $a) {
        $usedElsewhere[mb_strtolower(trim($a))][] = 'Sperrliste';
    }
    foreach ($rulesData['spam']['whitelist'] ?? [] as $a) {
        $usedElsewhere[mb_strtolower(trim($a))][] = 'Whitelist';
    }

    $suggestions = [];
    foreach ($scanned as $folder => $domains) {
        $existing = null;
        foreach ($existingRules as $r) {
            if (($r['target'] ?? '') === $folder) { $existing = $r; break; }
        }

        $currentAddrs = array_map(fn($a) => mb_strtolower(trim($a)), $existing['from_addresses'] ?? []);
        $newDomains   = array_values(array_diff($domains, $currentAddrs));
        if (empty($newDomains)) continue; // nichts Neues für diesen Ordner

        $conflicts = [];
        foreach ($newDomains as $d) {
            if (isset($usedElsewhere[$d])) {
                $conflicts[$d] = implode(', ', array_unique($usedElsewhere[$d]));
            }
        }

        $suggestions[] = [
            'target'    => $folder,
            'name'      => $existing['name'] ?? rulegen_derive_name($folder),
            'kind'      => $existing ? 'update' : 'new',
            'domains'   => $newDomains,
            'conflicts' => $conflicts,
        ];
    }
    return $suggestions;
}

/**
 * Prüft, ob $ancestor ein Vorfahr-Ordner von $target ist
 * (z.B. ist "Newsletter" Vorfahr von "Newsletter/WWF").
 */
function rulegen_is_ancestor(string $ancestor, string $target): bool {
    return $ancestor !== '' && $target !== $ancestor && str_starts_with($target, $ancestor . '/');
}

/**
 * Fügt eine neue Regel so ein, dass sie vor jeder bestehenden Regel für einen
 * übergeordneten Ordner steht (Unterordner-Regeln müssen vor Root-Regeln
 * stehen — bestehende Anforderung der App). Unabhängig von der Verarbeitungs-
 * reihenfolge mehrerer neuer Regeln bleibt die relative Ordnung dadurch
 * korrekt (jede neu eingefügte Regel wird vor ihren bereits vorhandenen
 * Vorfahren einsortiert).
 */
function rulegen_insert_ordered(array &$rules, array $newRule): void {
    $insertAt = count($rules);
    foreach ($rules as $i => $r) {
        if (rulegen_is_ancestor($r['target'] ?? '', $newRule['target'])) {
            $insertAt = $i;
            break;
        }
    }
    array_splice($rules, $insertAt, 0, [$newRule]);
}

/**
 * Wendet die vom Nutzer bestätigte Auswahl auf rules.json an: legt neue
 * Regeln an bzw. ergänzt bestehende um die gefundenen Domains. Bestehende
 * Regeln werden nicht umsortiert, nur neu angelegte werden korrekt platziert.
 *
 * @param array $rulesData wird per Referenz verändert
 * @param array $selections [['target' => string, 'domains' => string[]], …]
 * @return array{added: int, updated: int}
 */
function rulegen_apply(array &$rulesData, array $selections): array {
    if (!isset($rulesData['rules']) || !is_array($rulesData['rules'])) $rulesData['rules'] = [];
    $added   = 0;
    $updated = 0;

    foreach ($selections as $sel) {
        $target  = trim((string)($sel['target'] ?? ''));
        $domains = array_values(array_unique(array_filter(array_map(
            fn($d) => mb_strtolower(trim((string)$d)), $sel['domains'] ?? []
        ))));
        if ($target === '' || empty($domains)) continue;

        $idx = null;
        foreach ($rulesData['rules'] as $i => $r) {
            if (($r['target'] ?? '') === $target) { $idx = $i; break; }
        }

        if ($idx !== null) {
            $current = array_map(fn($a) => mb_strtolower(trim($a)), $rulesData['rules'][$idx]['from_addresses'] ?? []);
            $rulesData['rules'][$idx]['from_addresses'] = array_values(array_unique(array_merge($current, $domains)));
            $updated++;
        } else {
            $newRule = [
                'id'             => bin2hex(random_bytes(6)),
                'name'           => rulegen_derive_name($target),
                'enabled'        => true,
                'from_addresses' => $domains,
                'to_addresses'   => [],
                'subjects'       => [],
                'logic'          => 'OR',
                'target'         => $target,
            ];
            rulegen_insert_ordered($rulesData['rules'], $newRule);
            $added++;
        }
    }

    return ['added' => $added, 'updated' => $updated];
}
