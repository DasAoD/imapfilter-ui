# imapfilter-ui

Web-UI zur Verwaltung von [imapfilter](https://github.com/lefcha/imapfilter)-Regeln und IMAP-Ordnern.  
Unterstützt mehrere Benutzer — jeder mit eigenem Mailkonto, eigenen Regeln und eigenem Lua-Setup.

---

## Features

- **Mehrbenutzerbetrieb** — jeder Benutzer verwaltet sein eigenes IMAP-Konto
- **Admin-Bereich** — Benutzer anlegen/löschen, Passwörter zurücksetzen
- **Ersteinrichtung** per Browser-Formular (`setup.php`)
- **Filterregeln** per Formular (kein manuelles Lua-Editieren)
  - Absender (Von:), Empfänger (An:) und Betreff-Schlüsselwörter pro Regel
  - Kommagetrennte Mehrfacheingabe in allen Feldern
  - Zielordner per Dropdown (live vom IMAP-Server)
  - Reihenfolge per Drag & Drop
  - Regeln aktivieren / deaktivieren
  - Lua-Dateien werden **automatisch bei jeder Regeländerung** neu generiert
- **Spam-Konfiguration** mit Whitelist (kommagetrennte Eingabe)
- **Lua-Generierung** aus JSON-Regeln inkl. `config.lua` (mit automatischem Backup, max. 10 pro Datei)
- **Dispatcher** — zentrales Scheduling für alle Benutzer (systemd / cron / Hoster)
  - Intervall pro Benutzer frei in Minuten einstellbar
  - Einrichtungsanleitung und Status-Übersicht im Admin-Bereich
- **IMAP-Ordner** live anzeigen, anlegen, umbenennen und löschen (`php-imap`)
  - Beim Löschen werden Mails automatisch in die INBOX verschoben
- **Lua-Editor** als Fallback für direkte Anpassungen
- **imapfilter ausführen** mit Live-Logausgabe
- **Passwort ändern** für alle Benutzer (mit Stärke-Indikator)

### Sicherheit

- CSRF-Schutz auf allen schreibenden API-Endpunkten
- Session-Cookies mit `Secure`, `HttpOnly`, `SameSite=Strict`
- Login Rate-Limiting: 5 Fehlversuche → 15 Minuten Sperre pro IP
- Atomare Schreiboperationen für alle JSON- und Lua-Dateien
- Strikte Dateiberechtigungen (`config.lua` und `imap_settings.json` mit `0600`)
- TLS-Zertifikatsprüfung standardmäßig aktiv
- `cron/` und `lib/` per Nginx gesperrt

---

## Anforderungen

- Debian/Ubuntu mit Nginx und PHP 8.3 (php8.3-fpm)
- PHP IMAP-Extension: `apt install php8.3-imap`
- [imapfilter](https://github.com/lefcha/imapfilter) installiert
- Konfigurationsdateien unter `/srv/imapfilter/` (konfigurierbar)

---

## Installation

### 1. Repository klonen

```bash
git clone https://github.com/DasAoD/imapfilter-ui /var/www/imapfilter-ui
```

### 2. PHP IMAP-Extension installieren

```bash
apt install php8.3-imap
systemctl restart php8.3-fpm
```

### 3. Konfiguration prüfen

`config.php` liegt bereits im Repository und ist direkt einsatzbereit.  
Standardpfade: `/srv/imapfilter/` und `/var/log/imapfilter/`.  
Nur anpassen, wenn du andere Pfade verwenden möchtest:

```bash
nano /var/www/imapfilter-ui/config.php
```

### 4. Verzeichnisse vorbereiten

```bash
mkdir -p /srv/imapfilter
mkdir -p /var/log/imapfilter
chown -R www-data:www-data /srv/imapfilter
chown -R www-data:www-data /var/log/imapfilter
chown -R www-data:www-data /var/www/imapfilter-ui
```

### 5. Nginx konfigurieren

Beispiel-vHost (`/etc/nginx/sites-available/imapfilter`):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name imapfilter.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name imapfilter.example.com;

    ssl_certificate     /etc/letsencrypt/live/imapfilter.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/imapfilter.example.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    root  /var/www/imapfilter-ui;
    index index.php;

    access_log /var/log/nginx/imapfilter.example.com.access.log;
    error_log  /var/log/nginx/imapfilter.example.com.error.log;

    # Security-Header
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    # Versteckte Dateien sperren
    location ~ /\.ht { deny all; }

    # CLI-Verzeichnisse sperren
    location ^~ /cron/ { deny all; }
    location ^~ /lib/  { deny all; }
}
```

> **Hinweis:** Falls das UI nur aus dem LAN/VPN erreichbar sein soll, können in den
> `location`-Blöcken `allow`/`deny`-Regeln ergänzt werden.

Aktivieren und neu laden:

```bash
ln -s /etc/nginx/sites-available/imapfilter /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### 6. Ersteinrichtung im Browser

Beim ersten Aufruf erscheint automatisch `setup.php`.  
Dort wird der erste Admin-Account angelegt. Passwort-Anforderungen: min. 10 Zeichen,
Groß-/Kleinbuchstaben, Zahl, Sonderzeichen. Danach:

1. **IMAP-Einstellungen** → Zugangsdaten hinterlegen, Verbindung testen, Intervall setzen
2. **Filterregeln** → Spam-Filter konfigurieren, Regeln anlegen
3. **Dispatcher einrichten** → Admin-Bereich → 🕐 Dispatcher → Anleitung für systemd / cron / Hoster

Weitere Benutzer werden im Admin-Bereich (👤 Benutzerverwaltung) angelegt.

---

## Dispatcher

Der Dispatcher ist ein zentrales PHP-Skript, das einmal pro Minute aufgerufen wird und
für jeden Benutzer prüft, ob sein eingestelltes Intervall abgelaufen ist.

**Nur einmalig einrichten** — neue Benutzer werden automatisch berücksichtigt.

### systemd (empfohlen)

```bash
cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.service /etc/systemd/system/
cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.timer  /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now imapfilter-dispatcher.timer
```

### Cron (/etc/cron.d/)

```bash
cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.cron /etc/cron.d/imapfilter-dispatcher
chmod 644 /etc/cron.d/imapfilter-dispatcher
```

### Hoster-Panel (KAS, Plesk, cPanel …)

Cron-Job anlegen: `* * * * *` → `/usr/bin/php /var/www/imapfilter-ui/cron/dispatcher.php`

> ⚠️ Niemals systemd-Timer **und** Cron gleichzeitig betreiben — das führt zu doppelten Ausführungen.

---

## Dateistruktur

```
/var/www/imapfilter-ui/
├── api/
│   ├── auth_check.php    # API-Authentifizierung + CSRF-Prüfung
│   ├── dispatcher.php    # Dispatcher-Status + Intervall-API
│   ├── editor.php        # Lua-Dateien lesen/schreiben
│   ├── folders.php       # IMAP-Ordner anzeigen / anlegen / umbenennen / löschen
│   ├── generate.php      # Lua + config.lua aus JSON generieren
│   ├── rules.php         # Regeln CRUD + Auto-Generate
│   ├── run.php           # imapfilter ausführen / Log lesen
│   ├── settings.php      # IMAP-Einstellungen
│   └── users.php         # Benutzerverwaltung (Admin + eigenes Passwort)
├── assets/
│   ├── app.js            # Frontend-Anwendungslogik
│   └── style.css         # Dark-Theme CSS
├── cron/
│   ├── dispatcher.php                 # Zentrales Dispatcher-Skript (nur CLI)
│   ├── imapfilter-dispatcher.service  # systemd Service
│   ├── imapfilter-dispatcher.timer    # systemd Timer
│   └── imapfilter-dispatcher.cron     # Cron-Datei für /etc/cron.d/
├── lib/
│   ├── atomic.php        # Atomare Schreiboperationen
│   ├── generate.php      # Lua-Generierungslogik (geteilt)
│   └── users.php         # Benutzerverwaltungs-Funktionen
├── auth.php              # Session-Check (Redirect)
├── config.php            # Konfiguration (Pfade)
├── index.php             # Haupt-UI-Shell
├── login.php             # Loginseite (mit Rate-Limiting)
├── logout.php            # Logout
├── robots.txt            # Crawler-Ausschluss
└── setup.php             # Ersteinrichtung (Admin-Account)

/srv/imapfilter/
├── users.json                 # Benutzerdatenbank (nicht im Repo)
├── dispatcher_state.json      # Laufzeitzustand des Dispatchers
├── .login_attempts.json       # Rate-Limiting-Daten (automatisch)
└── <username>/
    ├── config.lua             # Generiert: IMAP-Verbindung + dofile-Includes (0600)
    ├── filters.lua            # Generiert: Filterregeln (0640)
    ├── folders.lua            # Generiert: Ordner-Referenzen (0640)
    ├── rules.json             # UI-Regeln (nicht im Repo, 0640)
    ├── imap_settings.json     # IMAP-Zugangsdaten + Intervall (nicht im Repo, 0600)
    └── backups/               # Automatische Backups (max. 10 pro Datei)

/var/log/imapfilter/
├── dispatcher.log        # Dispatcher-Protokoll
├── login.log             # Login-Fehlversuche
└── <username>.log        # imapfilter-Ausgabe pro Benutzer
```

---

## Sicherheitshinweise

- `users.json`, `rules.json` und `imap_settings.json` sind im `.gitignore`
- `imap_settings.json` und `config.lua` enthalten das IMAP-Passwort — werden mit `0600` geschrieben
- Login Rate-Limiting: 5 Fehlversuche → 15 Min. Sperre; Fehlversuche werden in `login.log` protokolliert
- CSRF-Token wird bei jedem Login neu generiert und bei allen schreibenden API-Aufrufen geprüft
- Passwort-Anforderungen werden client- und serverseitig erzwungen
- Niemals systemd-Timer und Cron gleichzeitig für den Dispatcher betreiben

---

## Mitwirkende

Dieses Projekt wurde in Zusammenarbeit mit [Claude](https://claude.ai) (Sonnet 4.5) von [Anthropic](https://anthropic.com) entwickelt.  
Der überwiegende Teil des Codes, der Architektur und der Dokumentation wurde durch KI generiert und iterativ verfeinert.

| Rolle | Person / Tool |
|---|---|
| Projektidee & Anforderungen | [DasAoD](https://github.com/DasAoD) |
| Code, Architektur, Dokumentation | Claude (Anthropic) |

---

## License

[MIT](LICENSE)
