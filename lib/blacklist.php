<?php
/**
 * Automatisches Lernen der Spam-Sperrliste aus dem Spam-Zielordner.
 * Siehe Issue #1: "Automatisches Hinzufügen von Spam-Absendern zur Sperrliste".
 *
 * Option A (immer aktiv, sobald "enabled"): neue Absender im Spam-Zielordner
 *   werden zur Sperrliste hinzugefügt, Duplikate ignoriert.
 * Option B: wird eine zuvor gesehene Spam-Mail nicht mehr im Zielordner
 *   gefunden, aber mit identischer Message-ID in INBOX, wird ihr Absender
 *   wieder entsperrt — außer der Absender hat noch andere Mails im
 *   Spam-Zielordner (dann bleibt er gesperrt). Löschen oder Verschieben in
 *   einen anderen Ordner führt nie zum Entsperren.
 */

require_once __DIR__ . '/atomic.php';
require_once __DIR__ . '/imap_helpers.php';

/**
 * Extrahiert die reine E-Mail-Adresse aus einem "Name <adresse>"-From-Header.
 */
function blacklist_from_address(object $overview): ?string {
    $from = trim((string)($overview->from ?? ''));
    if ($from === '') return null;
    if (preg_match('/<([^<>]+)>/', $from, $m)) {
        $from = $m[1];
    }
    $from = mb_strtolower(trim($from));
    return $from !== '' ? $from : null;
}

/**
 * Prüft, ob eine Adresse durch die Spam-Whitelist gedeckt ist
 * (exakte Adresse oder Domain-Eintrag wie "@domain.de").
 */
function blacklist_is_whitelisted(string $address, array $whitelist): bool {
    $address = mb_strtolower($address);
    foreach ($whitelist as $w) {
        $w = mb_strtolower(trim((string)$w));
        if ($w === '') continue;
        if ($w[0] === '@') {
            if (str_ends_with($address, $w)) return true;
        } elseif ($address === $w) {
            return true;
        }
    }
    return false;
}

/**
 * Öffnet eine IMAP-Verbindung direkt im angegebenen Ordner.
 *
 * @return array{imap: \IMAP\Connection|resource, mbox: string}|array{error: string}
 */
function blacklist_imap_open(array $settings, string $folder): array {
    if (!function_exists('imap_open')) return ['error' => 'PHP-IMAP-Extension nicht installiert.'];
    if (empty($settings['host']) || empty($settings['user']) || empty($settings['pass'])) {
        return ['error' => 'IMAP-Einstellungen unvollständig.'];
    }
    $ssl = ($settings['ssl'] ?? true) ? '/ssl' : '';
    if (!empty($settings['ssl_novalidate'])) $ssl .= '/novalidate-cert';
    $mbox = '{' . $settings['host'] . ':' . ($settings['port'] ?? 993) . $ssl . '}';
    $imap = @imap_open($mbox . imap_folder_encode($folder), $settings['user'], $settings['pass'], 0, 1);
    if (!$imap) return ['error' => imap_last_error() ?: 'IMAP-Verbindung fehlgeschlagen.'];
    return ['imap' => $imap, 'mbox' => $mbox];
}

/**
 * Gleicht die Sperrliste eines Benutzers mit dem aktuellen Inhalt seines
 * Spam-Zielordners ab. Schreibt rules.json + den internen Zustand
 * (blacklist_state.json) bei Bedarf neu.
 *
 * @param array $paths Ergebnis von user_paths($username)
 * @return array{changed: bool, added: string[], removed: string[], error: ?string}
 */
function sync_spam_blacklist(array $paths): array {
    $result = ['changed' => false, 'added' => [], 'removed' => [], 'error' => null];

    if (!file_exists($paths['rules']) || !file_exists($paths['settings'])) {
        return $result;
    }
    $rules = json_decode(file_get_contents($paths['rules']), true);
    if (!is_array($rules)) {
        $result['error'] = 'rules.json ist ungültig oder beschädigt.';
        return $result;
    }

    $bl = $rules['blacklist'] ?? null;
    if (empty($bl['enabled'])) return $result; // Auto-Lernen für diesen Account deaktiviert

    $spam         = $rules['spam'] ?? [];
    $targetFolder = trim((string)($spam['target'] ?? ''));
    if ($targetFolder === '' || $targetFolder === 'INBOX') {
        $result['error'] = 'Kein gültiger Spam-Zielordner konfiguriert.';
        return $result;
    }

    $settings = json_decode(file_get_contents($paths['settings']), true);
    if (!is_array($settings)) {
        $result['error'] = 'imap_settings.json ist ungültig oder beschädigt.';
        return $result;
    }

    $conn = blacklist_imap_open($settings, $targetFolder);
    if (isset($conn['error'])) {
        $result['error'] = $conn['error'];
        return $result;
    }
    $imap = $conn['imap'];

    // ── Aktuellen Ordnerinhalt einlesen: Message-ID → Absender ───────────────
    $current    = [];
    $viaPattern = []; // Mails, die schon durch ein Betreff-/Empfänger-Muster im Spam-Ordner liegen
    $patSubjects   = blacklist_normalize_list($bl['subjects'] ?? []);
    $patRecipients = blacklist_normalize_list($bl['recipients'] ?? []);
    $count   = @imap_num_msg($imap) ?: 0;
    if ($count > 0) {
        $overviews = imap_fetch_overview($imap, '1:' . $count) ?: [];
        foreach ($overviews as $ov) {
            $mid = trim((string)($ov->message_id ?? ''));
            if ($mid === '') continue; // ohne Message-ID kein zuverlässiger Abgleich möglich
            $addr = blacklist_from_address($ov);
            if ($addr === null) continue;
            $current[$mid] = $addr;
            if (blacklist_matches($ov, $patSubjects, $patRecipients)) $viaPattern[$mid] = true;
        }
    }

    // ── Vorherigen Stand laden ────────────────────────────────────────────────
    $stateFile = rtrim($paths['dir'], '/') . '/blacklist_state.json';
    $previous  = [];
    if (file_exists($stateFile)) {
        $d = json_decode(file_get_contents($stateFile), true);
        if (is_array($d)) $previous = $d;
    }

    $wl = $spam['whitelist'] ?? [];

    // ── Neue Nachrichten → Kandidaten zum Sperren sammeln (Option A) ─────────
    // Absenderadressen aus Spam-Mails sind nicht vertrauenswürdig — nur echte
    // E-Mail-Adressen übernehmen, bevor sie später in generiertes Lua wandern.
    $toAdd = [];
    foreach ($current as $mid => $addr) {
        if (array_key_exists($mid, $previous)) continue; // war beim letzten Lauf schon da
        if (isset($viaPattern[$mid])) continue; // Muster reicht, Absenderliste klein halten
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) continue;
        if (blacklist_is_whitelisted($addr, $wl)) continue;
        if (!in_array($addr, $toAdd, true)) $toAdd[] = $addr;
    }

    // ── Verschwundene Nachrichten → ggf. Kandidaten zum Entsperren (Option B) ─
    // Abgleich ausschließlich über imap_fetch_overview() + String-Vergleich in
    // PHP, ohne Absender-/Message-ID-Daten in eine IMAP-SEARCH-Query einzubetten.
    $toRemove    = [];
    $carryOver   = []; // konnten nicht geprüft werden → nächsten Lauf erneut versuchen
    $disappeared  = array_diff_key($previous, $current);
    $maxInboxScan = 5000; // Obergrenze, um bei sehr großen Postfächern nicht jeden Lauf zu blockieren
    if (!empty($disappeared)) {
        $inboxOk = @imap_reopen($imap, $conn['mbox'] . 'INBOX');
        $inboxCount = $inboxOk ? (@imap_num_msg($imap) ?: 0) : 0;
        if ($inboxOk && $inboxCount <= $maxInboxScan) {
            $inboxIds = [];
            if ($inboxCount > 0) {
                $inboxOverviews = imap_fetch_overview($imap, '1:' . $inboxCount) ?: [];
                foreach ($inboxOverviews as $iov) {
                    $imid = trim((string)($iov->message_id ?? ''));
                    if ($imid !== '') $inboxIds[$imid] = true;
                }
            }
            $stillPresent = array_values($current);
            foreach ($disappeared as $mid => $addr) {
                // Absender hat noch eine andere Mail im Spam-Ordner → nicht entsperren
                if (in_array($addr, $stillPresent, true)) continue;
                if (isset($inboxIds[$mid])) {
                    if (!in_array($addr, $toRemove, true)) $toRemove[] = $addr;
                }
                // sonst: Nachricht endgültig verschwunden (gelöscht/anderer Ordner) → nicht weiter tracken
            }
        } else {
            // INBOX konnte nicht geprüft werden (z. B. Verbindungsproblem) oder ist für einen
            // vollständigen Abgleich in diesem Lauf zu groß — betroffene Nachrichten im Zustand
            // behalten, statt den Absender fälschlich dauerhaft gesperrt zu lassen; im nächsten
            // Lauf wird erneut geprüft.
            $carryOver = $disappeared;
        }
    }

    imap_close($imap);

    // ── Zustand speichern (inkl. nicht geprüfter, zurückgestellter Einträge) ──
    atomic_write_json($stateFile, $current + $carryOver, 0640);

    if (empty($toAdd) && empty($toRemove)) {
        return $result;
    }

    // ── Sperrliste aktualisieren ───────────────────────────────────────────────
    // rules.json wird unter Lock frisch eingelesen und nur um die ermittelten
    // Änderungen ergänzt/bereinigt (statt komplett überschrieben), damit ein
    // zeitgleiches Speichern über die Web-UI nicht verloren geht.
    $lockFile = rtrim($paths['dir'], '/') . '/rules.json.lock';
    with_file_lock($lockFile, function () use ($paths, $toAdd, $toRemove, &$result) {
        $fresh = json_decode(file_get_contents($paths['rules']), true);
        if (!is_array($fresh)) {
            $result['error'] = 'rules.json ist ungültig oder beschädigt (beim Schreiben der Sperrliste).';
            return;
        }

        $senders = array_values(array_unique(array_map('mb_strtolower', $fresh['blacklist']['senders'] ?? [])));
        foreach ($toAdd as $addr) {
            if (!in_array($addr, $senders, true)) {
                $senders[]          = $addr;
                $result['added'][]  = $addr;
            }
        }
        foreach ($toRemove as $addr) {
            if (in_array($addr, $senders, true)) {
                $senders                = array_values(array_diff($senders, [$addr]));
                $result['removed'][]    = $addr;
            }
        }
        if (empty($result['added']) && empty($result['removed'])) return;

        sort($senders);
        $fresh['blacklist']['senders'] = array_values($senders);
        if (atomic_write_json($paths['rules'], $fresh, 0640)) {
            $result['changed'] = true;
        }
    });

    return $result;
}

function blacklist_normalize_list($items): array {
    if (!is_array($items)) return [];
    return array_values(array_unique(array_filter(
        array_map(fn($s) => mb_strtolower(trim($s)), array_filter($items, 'is_string')),
        fn($s) => $s !== ''
    )));
}

/**
 * Betreff- und Empfänger-Muster gehen per IMAP SEARCH an den Server, der
 * Umlaute und Sonderzeichen unzuverlässig vergleicht (Issue #7) — nur ASCII zulassen.
 */
function blacklist_pattern_is_ascii(string $s): bool {
    return (bool)preg_match('/^[\x20-\x7E]+$/', $s);
}

function blacklist_decode_header(string $s): string {
    return trim((string)@imap_utf8($s));
}

// Überschriften wie "(1)", "[ 2 ]", "[Marke]" am Anfang und "Re:"/"Fwd:" wechseln
// von Mail zu Mail; ohne sie zu entfernen gäbe es nie Treffer über mehrere Mails.
function blacklist_subject_normalize(string $subject): string {
    $s = mb_strtolower(blacklist_decode_header($subject));
    do {
        $prev = $s;
        $s = preg_replace('/^\s*(?:re|aw|fwd?|wg)\s*:\s*/u', '', $s) ?? $prev;
        $s = preg_replace('/^\s*[\(\[\{][^\)\]\}]{0,40}[\)\]\}]\s*/u', '', $s) ?? $prev;
    } while ($s !== $prev);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * Prüft wie imapfilter (contain_subject/contain_to: Teilstring ohne Groß-/Kleinschreibung),
 * ob eine Mail von einem Betreff- oder Empfänger-Muster erfasst wird.
 */
function blacklist_matches(object $overview, array $subjects, array $recipients): bool {
    $subject = mb_strtolower(blacklist_decode_header((string)($overview->subject ?? '')));
    $to      = mb_strtolower(blacklist_decode_header((string)($overview->to ?? '')));
    foreach ($subjects as $p) {
        if ($p !== '' && str_contains($subject, $p)) return true;
    }
    foreach ($recipients as $p) {
        if ($p !== '' && str_contains($to, $p)) return true;
    }
    return false;
}

/**
 * Leitet aus den Mails des Spam-Ordners Vorschläge für Betreff-Muster ab und findet
 * Absender, die schon von einem Muster abgedeckt sind.
 *
 * Ein Vorschlag braucht mindestens $minMails Mails von mindestens $minSenders
 * verschiedenen Absendern. Kandidaten sind die Empfängeradresse am Betreffende
 * (Spam nennt oft "– <eigene Adresse>") und der normalisierte Betreff als Ganzes;
 * nur ASCII, weil Umlaute und Sonderzeichen bei IMAP-SEARCH unzuverlässig sind.
 *
 * @param object[] $overviews Ergebnis von imap_fetch_overview() für den Spam-Ordner
 * @return array{patterns: array<int, array{pattern: string, mails: int, senders: int}>, redundant: string[]}
 */
function blacklist_suggest(array $overviews, array $bl, int $minMails = 3, int $minSenders = 2): array {
    $subjects   = blacklist_normalize_list($bl['subjects'] ?? []);
    $recipients = blacklist_normalize_list($bl['recipients'] ?? []);
    $known      = blacklist_normalize_list($bl['senders'] ?? []);

    $groups  = [];
    $covered = []; // Absender => [Mail erfasst?, ...]
    foreach ($overviews as $ov) {
        $addr = blacklist_from_address($ov);
        if ($addr === null) continue;
        $isCovered = blacklist_matches($ov, $subjects, $recipients);
        $covered[$addr][] = $isCovered;
        if ($isCovered) continue;

        $norm       = blacklist_subject_normalize((string)($ov->subject ?? ''));
        $candidates = [];
        if (preg_match('/([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})\s*$/', $norm, $m)) $candidates[] = $m[1];
        $candidates[] = $norm;
        foreach (array_unique($candidates) as $c) {
            if (strlen($c) < 8 || !blacklist_pattern_is_ascii($c) || strpbrk($c, "\"\\") !== false) continue;
            $groups[$c]['mails'] = ($groups[$c]['mails'] ?? 0) + 1;
            $groups[$c]['senders'][$addr] = true;
        }
    }

    $accepted = [];
    uksort($groups, fn($a, $b) => strlen($a) <=> strlen($b));
    foreach ($groups as $pattern => $g) {
        if ($g['mails'] < $minMails || count($g['senders']) < $minSenders) continue;
        foreach ($accepted as $other) {
            if (str_contains($pattern, $other['pattern'])) continue 2; // kürzeres Muster erfasst es schon
        }
        $accepted[] = ['pattern' => (string)$pattern, 'mails' => $g['mails'], 'senders' => count($g['senders'])];
    }
    usort($accepted, fn($a, $b) => $b['mails'] <=> $a['mails']);

    $redundant = [];
    foreach ($known as $sender) {
        $flags = $covered[$sender] ?? [];
        if ($flags && !in_array(false, $flags, true)) $redundant[] = $sender;
    }

    return ['patterns' => array_slice($accepted, 0, 20), 'redundant' => $redundant];
}

/**
 * Zählt, wie viele Mails der INBOX ein Betreff-Muster treffen würde, damit sich
 * ein zu allgemeiner Vorschlag vor dem Übernehmen erkennen lässt.
 *
 * @param object[] $overviews Ergebnis von imap_fetch_overview() für die INBOX
 * @param string[] $patterns  normalisierte Betreff-Muster
 * @return array<string, int> Muster => Anzahl Treffer
 */
function blacklist_inbox_hits(array $overviews, array $patterns): array {
    $hits = array_fill_keys($patterns, 0);
    foreach ($overviews as $ov) {
        $subject = mb_strtolower(blacklist_decode_header((string)($ov->subject ?? '')));
        foreach ($patterns as $p) {
            if (str_contains($subject, $p)) $hits[$p]++;
        }
    }
    return $hits;
}
