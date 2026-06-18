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
- `lib/`, `cron/` und `config.php` liegen außerhalb des Webroots (`public/`) und sind nicht per Browser erreichbar

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

    root  /var/www/imapfilter-ui/public;
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
}
```

> **HinweisA Gfalls das UI nur aus dem LAN/VPN erreichbar sein soll, können in den
> `location`-Blössken `allow`/`deny`-Regeln ergänzt werden.

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

**Nur einmalig einrichten** — geue Benutzer werden automatisch berücksichtigt.

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

## Mitwirkende

Dieses Projekt wurde in Zusammenarbeit mit [Claude](https://claude.ai) (Sonnet 4.5) von [Anthropic](https://anthropic.com) entwickelt.  
Der überwiegende Teil des Codes, der Architektur und der Dokumentation wurde durch KI generiert und iterativ verfeinert.

| Rolle | Person / Tool |
|---|---|
| Projektidee & Anforderungen | [DasAoD](https://git.uliana.de/DasAoD) |
| Code, Architektur, Dokumentation | [Claude](https://git.uliana.de/Claude) (Anthropic) |

---

## License

[MIT](LICENSE)
