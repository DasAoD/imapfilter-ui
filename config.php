<?php
/**
 * IMAPFilter Web-UI — Konfiguration
 * Kopiere diese Datei als config.php und passe die Werte an.
 * WICHTIG: config.php niemals ins Git-Repository committen!
 */

// ─── Pfade ────────────────────────────────────────────────────────────────────
// Basisverzeichnis für imapfilter-Konfiguration
// Unterhalb davon: /srv/imapfilter/<username>/
$luaBaseDir = '/srv/imapfilter';

// Benutzerdatenbank (außerhalb des Webroots, vom Webserver beschreibbar)
$usersJson  = '/srv/imapfilter/users.json';

// imapfilter-Binary
$imapfilterBin = '/usr/bin/imapfilter';

// Logverzeichnis — pro Benutzer: /var/log/imapfilter/<username>.log
$logDir = '/var/log/imapfilter';

// ─── Erster Start ─────────────────────────────────────────────────────────────
// Beim allerersten Aufruf (users.json leer / nicht vorhanden) erscheint ein
// Setup-Formular zum Anlegen des ersten Admin-Accounts.
