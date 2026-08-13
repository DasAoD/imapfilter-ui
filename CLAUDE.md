# imapfilter-ui – Projektkontext für Claude Code

Web-UI zur Verwaltung von [imapfilter](https://github.com/lefcha/imapfilter)-Regeln und IMAP-Ordnern. Unterstützt mehrere Benutzer – jeder mit eigenem Mailkonto, eigenen Regeln und eigenem Lua-Setup.

## Tech-Stack
- PHP, nginx, läuft auf Debian

## Struktur
- `cron/` – Cron-Skripte (Dispatcher)
- `lib/` – Shared PHP-Funktionen, Lua-Generierung
- `public/` – Document Root
  - `api/` – API-Endpunkte
  - `assets/` – CSS/JS

## Features
- Mehrbenutzerbetrieb – jeder Benutzer verwaltet sein eigenes IMAP-Konto
- Admin-Bereich – Benutzer anlegen/löschen
- Filterregeln per Formular (kein manuelles Lua-Editieren nötig)
- Lua-Generierung aus JSON-Regeln
- Dispatcher – zentrales Scheduling für alle Benutzer
- IMAP-Ordner live anzeigen, anlegen, umbenennen und löschen

## Wichtige Konvention
- Der Kern der Anwendung ist die **Lua-Code-Generierung aus JSON-Regeln** – bei Änderungen an der Regel-Logik immer sicherstellen, dass generiertes Lua weiterhin gültig ist und von imapfilter fehlerfrei geparst wird
- Mehrbenutzerbetrieb beachten: Änderungen an Rules/Ordnern eines Users dürfen niemals andere Benutzerkonten beeinflussen (striktes Scoping pro User-ID)
