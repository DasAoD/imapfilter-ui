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
  - **Lua-Dateien werden automatisch bei jeder Regeländerung neu generiert**
- **Spam-Konfiguration** mit Whitelist (kommagetrennte Eingabe)
- **Lua-Generierung** aus JSON-Regeln inkl. `config.lua` (mit automatischem Backup)
- **Dispatcher** — zentrales Scheduling für alle Benutzer (systemd / cron / Hoster)
  - Intervall pro Benutzer frei in Minuten einstellbar
  - Einrichtungsanleitung und Status-Übersicht im Admin-Bereich
- **IMAP-Ordner** live anzeigen und anlegen (`php-imap`)
- **Lua-Editor** als Fallback für direkte Anpassungen
- **imapfilter ausführen** mit Live-Logausgabe

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

    root  /var/www/imapfilter-ui;
    index index.php;

    # Zugriff nur aus LAN / VPN
    location / {
        allow 127.0.0.1;
        allow 10.8.0.0/24;        # WireGuard VPN
        allow 192.168.0.0/24;     # LAN
        allow 192.168.4.0/24;     # weiteres LAN-Segment
        deny  all;
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        allow 127.0.0.1;
        allow 10.8.0.0/24;
        allow 192.168.0.0/24;
        allow 192.168.4.0/24;
        deny  all;
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht { deny all; }
}
```

Aktivieren und neu laden:

```bash
ln -s /etc/nginx/sites-available/imapfilter /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### 6. Ersteinrichtung im Browser

Beim ersten Aufruf erscheint automatisch `setup.php`.  
Dort wird der erste Admin-Account angelegt. Danach:

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
│   ├── auth_check.php    # API-Authentifizierung + User-Kontext
│   ├── dispatcher.php    # Dispatcher-Status + Intervall-API
│   ├── editor.php        # Lua-Dateien lesen/schreiben
│   ├── folders.php       # IMAP-Ordner anzeigen / anlegen
│   ├── generate.php      # Lua + config.lua aus JSON generieren
│   ├── rules.php         # Regeln CRUD + Auto-Generate
│   ├── run.php           # imapfilter ausführen / Log lesen
│   ├── settings.php      # IMAP-Einstellungen
│   └── users.php         # Admin: Benutzerverwaltung
├── assets/
│   ├── app.js            # Frontend-Anwendungslogik
│   └── style.css         # Dark-Theme CSS
├── cron/
│   ├── dispatcher.php                 # Zentrales Dispatcher-Skript
│   ├── imapfilter-dispatcher.service  # systemd Service
│   ├── imapfilter-dispatcher.timer    # systemd Timer
│   └── imapfilter-dispatcher.cron     # Cron-Datei für /etc/cron.d/
├── lib/
│   ├── generate.php      # Lua-Generierungslogik (geteilt)
│   └── users.php         # Benutzerverwaltungs-Funktionen
├── auth.php              # Session-Check (Redirect)
├── config.php            # Konfiguration (Pfade)
├── index.php             # Haupt-UI-Shell
├── login.php             # Loginseite
├── logout.php            # Logout
└── setup.php             # Ersteinrichtung (Admin-Account)

/srv/imapfilter/
├── users.json                 # Benutzerdatenbank (nicht im Repo)
├── dispatcher_state.json      # Laufzeitzustand des Dispatchers
└── <username>/
    ├── config.lua             # Generiert: IMAP-Verbindung + dofile-Includes
    ├── filters.lua            # Generiert: Filterregeln
    ├── folders.lua            # Generiert: Ordner-Referenzen
    ├── rules.json             # UI-Regeln (nicht im Repo)
    ├── imap_settings.json     # IMAP-Zugangsdaten + Intervall (nicht im Repo)
    └── backups/               # Automatische Backups bei jeder Generierung

/var/log/imapfilter/
├── dispatcher.log        # Dispatcher-Protokoll
└── <username>.log        # Log pro Benutzer
```

---

## Sicherheitshinweise

- Web-UI ist ausschließlich aus VPN/LAN erreichbar — niemals öffentlich freigeben
- `users.json`, `rules.json` und `imap_settings.json` sind im `.gitignore`
- `imap_settings.json` enthält das IMAP-Passwort im Klartext → Dateiberechtigungen prüfen:
  ```bash
  chmod 640 /srv/imapfilter/*/imap_settings.json
  chown www-data:www-data /srv/imapfilter/*/imap_settings.json
  ```
- Niemals systemd-Timer und Cron gleichzeitig für den Dispatcher betreiben

---

## License

[MIT](LICENSE)
