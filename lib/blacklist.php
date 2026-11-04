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
    $d = trim((string)@imap_utf8($s));
    // imap_utf8 lässt manche Encoded-Words unangetastet (z. B. "=?UTF-8?Q?Your Prime ...?=" mit Leerzeichen)
    if (str_contains($d, '=?')) $d = trim((string)@mb_decode_mimeheader($d));
    return $d;
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
 * Muster-Kandidaten eines normalisierten Betreffs: Wortfolgen aus 2 bis 4 Wörtern
 * und der ganze Betreff. Nur ASCII; Wörter mit "@" und alle anderen Wörter (Umlaute,
 * Emoji, Satzzeichen wie "–") trennen Folgen, weil das Muster ein zusammenhängender
 * Teilstring des Betreffs bleiben muss.
 *
 * @return string[]
 */
function blacklist_subject_phrases(string $norm): array {
    $out   = [];
    $trim  = " .,;:!?-\"'()[]{}<>*#|/\\_";
    $valid = fn($p) => strlen($p) >= 10 && str_contains($p, ' ') && strpbrk($p, "\"\\") === false;

    if ($valid($norm) && blacklist_pattern_is_ascii($norm) && !str_contains($norm, '@')) $out[$norm] = true;

    $run = [];
    $flush = function () use (&$run, &$out, $trim, $valid) {
        for ($n = 2; $n <= 4; $n++) {
            for ($i = 0; $i + $n <= count($run); $i++) {
                $p = trim(implode(' ', array_slice($run, $i, $n)), $trim);
                if ($valid($p)) $out[$p] = true;
            }
        }
        $run = [];
    };
    foreach (preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        if (preg_match('/^[\x21-\x7E]+$/', $w) && !str_contains($w, '@')) { $run[] = $w; continue; }
        $flush();
    }
    $flush();
    return array_map('strval', array_keys($out));
}

/**
 * Klammer-Etiketten wie "[adac]" oder "(dpd)" an beliebiger Stelle des Betreffs.
 * Spam setzt die vorgetäuschte Marke oft so voran; Zähler wie "(1)" haben zu wenige Buchstaben.
 *
 * @param string $raw dekodierter, kleingeschriebener Betreff
 * @return string[]
 */
function blacklist_subject_tags(string $raw): array {
    if (!preg_match_all('/\[[^\[\]()]{2,40}\]|\([^\[\]()]{2,40}\)/', $raw, $m)) return [];
    $out = [];
    foreach ($m[0] as $t) {
        if (strlen($t) < 5 || !blacklist_pattern_is_ascii($t) || strpbrk($t, "@\"\\") !== false) continue;
        if (preg_match_all('/[a-z]/', $t) < 3) continue;
        $out[$t] = true;
    }
    return array_keys($out);
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
 * Kandidaten sind zusammenhängende Wortfolgen (2 bis 4 Wörter) und der ganze
 * normalisierte Betreff. Ein Kandidat braucht mindestens $minMails Mails von
 * mindestens $minSenders verschiedenen Absendern. Nur ASCII, weil Umlaute und
 * Sonderzeichen bei IMAP-SEARCH unzuverlässig sind; Wörter mit "@" (eigene
 * Adresse) unterbrechen eine Folge, solche Muster träfen auch gewünschte Mails.
 * Mit $inboxOverviews werden Kandidaten verworfen, die eine Mail der INBOX treffen.
 * Aus den übrigen wählt eine gierige Mengenüberdeckung höchstens 20 Muster, die
 * jeweils mindestens $minMails noch nicht erfasste Mails abdecken.
 *
 * @param object[]      $overviews      Ergebnis von imap_fetch_overview() für den Spam-Ordner
 * @param object[]|null $inboxOverviews INBOX-Mails für die Trefferprüfung; null = nicht geprüft
 * @return array{patterns: array<int, array{pattern: string, mails: int, senders: int, inbox_hits: ?int}>, redundant: string[]}
 */
function blacklist_suggest(array $overviews, array $bl, ?array $inboxOverviews = null, array $whitelist = [], int $minMails = 4, int $minSenders = 3): array {
    $subjects   = blacklist_normalize_list($bl['subjects'] ?? []);
    $recipients = blacklist_normalize_list($bl['recipients'] ?? []);
    $known      = blacklist_normalize_list($bl['senders'] ?? []);

    $groups  = [];
    $covered = []; // Absender => [Mail erfasst?, ...]
    foreach ($overviews as $i => $ov) {
        $addr = blacklist_from_address($ov);
        if ($addr === null) continue;
        $isCovered = blacklist_matches($ov, $subjects, $recipients);
        $covered[$addr][] = $isCovered;
        if ($isCovered) continue;

        // imapfilter sucht im rohen Betreff; die Normalisierung (Leerzeichen, Re:) darf kein Muster erzeugen, das die Quellmail nicht trifft.
        $raw = mb_strtolower(blacklist_decode_header((string)($ov->subject ?? '')));
        $phrases = array_merge(blacklist_subject_phrases(blacklist_subject_normalize((string)($ov->subject ?? ''))), blacklist_subject_tags($raw));
        foreach (array_unique($phrases) as $c) {
            if (!str_contains($raw, $c)) continue;
            $groups[$c]['mails'][$i] = true;
            $groups[$c]['senders'][$addr] = true;
        }
    }

    $groups = array_filter($groups, fn($g) => count($g['mails']) >= $minMails && count($g['senders']) >= $minSenders);
    $hits = null;
    if ($inboxOverviews !== null && $groups) {
        // Muster enthalten ein Leerzeichen oder beginnen mit einer Klammer, sind also nie numerische (Integer-)Array-Schlüssel.
        $hits = blacklist_inbox_hits($inboxOverviews, array_keys($groups), 'subject', $whitelist);
        $groups = array_filter($groups, fn($g, $p) => ($hits[$p] ?? 0) === 0, ARRAY_FILTER_USE_BOTH);
    }

    $accepted = [];
    $done     = [];
    while (count($accepted) < 20 && $groups) {
        $best = null; $bestNew = 0;
        foreach ($groups as $pattern => $g) {
            $new = count(array_diff_key($g['mails'], $done));
            if ($new < $minMails) continue;
            // Gleich viele neue Mails: das längere, spezifischere Muster
            if ($new > $bestNew || ($new === $bestNew && strlen((string)$pattern) > strlen((string)$best))) {
                $best = (string)$pattern; $bestNew = $new;
            }
        }
        if ($best === null) break;
        $g = $groups[$best];
        $accepted[] = ['pattern' => $best, 'mails' => count($g['mails']), 'senders' => count($g['senders']),
                       'inbox_hits' => $hits === null ? null : 0];
        $done += $g['mails'];
        unset($groups[$best]);
    }

    $redundant = [];
    foreach ($known as $sender) {
        $flags = $covered[$sender] ?? [];
        if ($flags && !in_array(false, $flags, true)) $redundant[] = $sender;
    }

    return ['patterns' => array_slice($accepted, 0, 20), 'redundant' => $redundant];
}

/**
 * Zählt, wie viele Mails der INBOX ein Muster treffen würde, damit sich ein zu
 * allgemeiner Vorschlag vor dem Übernehmen erkennen lässt. Mails von Absendern der
 * Whitelist zählen nicht, weil die Whitelist in der Sperrliste Vorrang hat.
 *
 * @param object[] $overviews Ergebnis von imap_fetch_overview() für die INBOX
 * @param string[] $patterns  kleingeschriebene Muster
 * @param string   $field     'subject' oder 'from' (ganzer Header, wie contain_from)
 * @return array<string, int> Muster => Anzahl Treffer
 */
function blacklist_inbox_hits(array $overviews, array $patterns, string $field = 'subject', array $whitelist = []): array {
    $hits = array_fill_keys($patterns, 0);
    foreach ($overviews as $ov) {
        if ($whitelist) {
            $addr = blacklist_from_address($ov);
            if ($addr !== null && blacklist_is_whitelisted($addr, $whitelist)) continue;
        }
        $text = mb_strtolower(blacklist_decode_header((string)($ov->$field ?? '')));
        foreach ($patterns as $p) {
            if (str_contains($text, $p)) $hits[$p]++;
        }
    }
    return $hits;
}

/**
 * Vorschläge für Absendernamen: derselbe ASCII-Anzeigename bei mindestens
 * $minAddresses verschiedenen Adressen im Spam-Ordner und ohne Treffer in der INBOX.
 * contain_from durchsucht den ganzen From-Header, ein Name trifft also alle seine Adressen.
 * Wechselnde Unicode-Schreibweisen (Lookalike-Zeichen) sind kein ASCII und entfallen.
 *
 * @param object[]      $overviews      Spam-Ordner
 * @param object[]|null $inboxOverviews INBOX für die Trefferprüfung; null = nicht geprüft
 * @return array<int, array{pattern: string, mails: int, senders: int, inbox_hits: ?int}>
 */
function blacklist_suggest_names(array $overviews, array $bl, ?array $inboxOverviews = null, array $whitelist = [], int $minAddresses = 3): array {
    $known  = blacklist_normalize_list($bl['senders'] ?? []);
    $groups = [];
    foreach ($overviews as $ov) {
        $addr = blacklist_from_address($ov);
        if ($addr === null) continue;
        $header = mb_strtolower(blacklist_decode_header((string)($ov->from ?? '')));
        foreach ($known as $k) {
            if (str_contains($header, $k)) continue 2; // Mail ist schon durch die Absenderliste erfasst
        }
        if ($whitelist && blacklist_is_whitelisted($addr, $whitelist)) continue;
        $name = trim(preg_replace('/\s*<[^>]*>\s*$/', '', $header) ?? '', " \t\"'");
        if (strlen($name) < 4 || !blacklist_pattern_is_ascii($name) || strpbrk($name, "@\\\"") !== false) continue;
        if (preg_match_all('/[a-z]/', $name) < 3 || !str_contains($header, $name)) continue;
        $groups[$name]['mails'] = ($groups[$name]['mails'] ?? 0) + 1;
        $groups[$name]['addrs'][$addr] = true;
    }
    // contain_from durchsucht auch die Adresse: ein Name, der Teil einer Adresse ist ("info"), träfe zu viel
    foreach ($groups as $name => $g) {
        foreach (array_keys($g['addrs']) as $a) {
            if (str_contains($a, (string)$name)) { unset($groups[$name]); continue 2; }
        }
    }
    $groups = array_filter($groups, fn($g) => count($g['addrs']) >= $minAddresses);

    $hits = null;
    if ($inboxOverviews !== null && $groups) {
        $hits = blacklist_inbox_hits($inboxOverviews, array_map('strval', array_keys($groups)), 'from', $whitelist);
        $groups = array_filter($groups, fn($g, $n) => ($hits[$n] ?? 0) === 0, ARRAY_FILTER_USE_BOTH);
    }

    uksort($groups, fn($a, $b) => strlen($a) <=> strlen($b));
    $accepted = [];
    foreach ($groups as $name => $g) {
        foreach ($accepted as $other) {
            if (str_contains($name, $other['pattern'])) continue 2; // kürzerer Name erfasst ihn schon
        }
        $accepted[] = ['pattern' => (string)$name, 'mails' => $g['mails'], 'senders' => count($g['addrs']),
                       'inbox_hits' => $hits === null ? null : 0];
    }
    usort($accepted, fn($a, $b) => [$b['senders'], $b['mails']] <=> [$a['senders'], $a['mails']]);
    return array_slice($accepted, 0, 20);
}
