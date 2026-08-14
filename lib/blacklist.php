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
    $imap = @imap_open($mbox . imap_utf7_encode($folder), $settings['user'], $settings['pass'], 0, 1);
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
    $current = [];
    $count   = @imap_num_msg($imap) ?: 0;
    if ($count > 0) {
        $overviews = imap_fetch_overview($imap, '1:' . $count) ?: [];
        foreach ($overviews as $ov) {
            $mid = trim((string)($ov->message_id ?? ''));
            if ($mid === '') continue; // ohne Message-ID kein zuverlässiger Abgleich möglich
            $addr = blacklist_from_address($ov);
            if ($addr !== null) $current[$mid] = $addr;
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
    $toAdd = [];
    foreach ($current as $mid => $addr) {
        if (array_key_exists($mid, $previous)) continue; // war beim letzten Lauf schon da
        if (blacklist_is_whitelisted($addr, $wl)) continue;
        if (!in_array($addr, $toAdd, true)) $toAdd[] = $addr;
    }

    // ── Verschwundene Nachrichten → ggf. Kandidaten zum Entsperren (Option B) ─
    $toRemove    = [];
    $carryOver   = []; // konnten nicht geprüft werden → nächsten Lauf erneut versuchen
    $disappeared = array_diff_key($previous, $current);
    if (!empty($disappeared)) {
        if (@imap_reopen($imap, $conn['mbox'] . 'INBOX')) {
            $stillPresent = array_values($current);
            foreach ($disappeared as $mid => $addr) {
                // Absender hat noch eine andere Mail im Spam-Ordner → nicht entsperren
                if (in_array($addr, $stillPresent, true)) continue;
                // Message-ID stammt vom Absender selbst — Anführungszeichen/Zeilenumbrüche/
                // Backslashes entfernen, bevor sie in die IMAP-SEARCH-Query eingebettet wird
                $needle = preg_replace('/[\r\n"\\\\]/', '', $mid);
                $found  = @imap_search($imap, 'HEADER Message-ID "' . $needle . '"', SE_UID);
                if ($found) {
                    if (!in_array($addr, $toRemove, true)) $toRemove[] = $addr;
                }
                // sonst: Nachricht endgültig verschwunden (gelöscht/anderer Ordner) → nicht weiter tracken
            }
        } else {
            // INBOX konnte nicht geprüft werden (z. B. Verbindungsproblem) — betroffene
            // Nachrichten im Zustand behalten, statt den Absender fälschlich dauerhaft
            // gesperrt zu lassen; im nächsten Lauf wird erneut geprüft.
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
        if (!is_array($fresh)) return;

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
