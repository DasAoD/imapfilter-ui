<?php
/**
 * Zeichensatz-Konvertierung zwischen IMAP-Ordnernamen (modified UTF-7, RFC
 * 2060/3501) und UTF-8 — überall dort gebraucht, wo Ordnernamen angezeigt
 * oder in eine Mailbox-Pfadangabe eingebettet werden.
 *
 * mb_convert_encoding() mit dem eingebauten "UTF7-IMAP"-Encoding ist
 * zuverlässiger als die älteren imap_utf8()/imap_utf7_encode()-Funktionen,
 * die bei bestimmten Zeichen (z.B. "&", das in modified UTF-7 eine
 * Sonderbedeutung hat) fehlerhafte Ergebnisse liefern bzw. gar nicht erst
 * zur passenden Mailbox zurückfinden.
 */

/**
 * Roher IMAP-Ordnername (modified UTF-7) → UTF-8 für Anzeige/Verarbeitung.
 */
function imap_folder_decode(string $rawName): string {
    return mb_convert_encoding($rawName, 'UTF-8', 'UTF7-IMAP');
}

/**
 * UTF-8-Ordnername → modified UTF-7 für IMAP-Befehle (open/create/rename/…).
 */
function imap_folder_encode(string $name): string {
    return mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
}
