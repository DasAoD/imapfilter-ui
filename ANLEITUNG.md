# IMAPFilter Web-UI — Installations- und Einrichtungsanleitung

Diese Anleitung führt dich Schritt für Schritt durch die vollständige Einrichtung des IMAPFilter Web-UI.  
Sie richtet sich sowohl an erfahrene als auch an weniger erfahrene Anwender.

---

## Inhaltsverzeichnis

1. [Was ist IMAPFilter Web-UI?](#1-was-ist-imapfilter-web-ui)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation der Software](#3-installation-der-software)
4. [Verzeichnisse und Berechtigungen](#4-verzeichnisse-und-berechtigungen)
5. [Nginx konfigurieren (HTTPS)](#5-nginx-konfigurieren-https)
6. [Ersteinrichtung im Browser](#6-ersteinrichtung-im-browser)
7. [IMAP-Einstellungen hinterlegen](#7-imap-einstellungen-hinterlegen)
8. [Filterregeln anlegen](#8-filterregeln-anlegen)
9. [Lua-Dateien generieren](#9-lua-dateien-generieren)
10. [Dispatcher einrichten](#10-dispatcher-einrichten)
    - [Variante A: systemd (empfohlen)](#variante-a-systemd-empfohlen)
    - [Variante B: Cron über Konsole](#variante-b-cron-über-konsole)
    - [Variante C: Cron über Hoster-Panel (z. B. KAS)](#variante-c-cron-über-hoster-panel-z-b-kas)
11. [Weitere Benutzer anlegen](#11-weitere-benutzer-anlegen)
12. [Ordner anlegen](#12-ordner-anlegen)
13. [Lua-Editor (Fallback)](#13-lua-editor-fallback)
14. [Logdateien und Fehlersuche](#14-logdateien-und-fehlersuche)
15. [Dateistruktur (Übersicht)](#15-dateistruktur-übersicht)
16. [Sicherheitshinweise](#16-sicherheitshinweise)
17. [Häufige Fragen (FAQ)](#17-häufige-fragen-faq)

---

## 1. Was ist IMAPFilter Web-UI?

IMAPFilter ist ein Programm, das E-Mails auf einem IMAP-Server (z. B. bei all-inkl.com) automatisch
sortiert und in Ordner verschiebt — ohne dass eine E-Mail heruntergeladen werden muss.

Das **Web-UI** ist eine Benutzeroberfläche im Browser, mit der du:

- Filterregeln bequem per Formular verwaltest (kein manuelles Editieren von Konfigurationsdateien)
- IMAP-Ordner direkt im Browser anlegst, umbenennst und löschst
- Den Ausführungsintervall pro Benutzer einstellst
- Mehrere Benutzer (z. B. Familienmitglieder) mit jeweils eigenem Mailkonto verwaltest
- Dein eigenes Passwort jederzeit ändern kannst

**Wichtig:** Mails verbleiben physisch beim Mailhoster — IMAPFilter greift nur per IMAP darauf zu.

---

## 2. Voraussetzungen

### Server

| Anforderung | Details |
|---|---|
| Betriebssystem | Debian 11/12 oder Ubuntu 22.04/24.04 |
| Webserver | Nginx |
| PHP | 8.1 oder neuer (empfohlen: 8.3) |
| PHP-Extensions | `php-fpm`, `php-imap` |
| imapfilter | Version 2.7 oder neuer |
| Zugriff | Root-Zugriff (sudo) für die Installation |

### Domain / Erreichbarkeit

Das Web-UI ist **ausschließlich** für den Zugriff aus dem lokalen Netzwerk oder per VPN gedacht.
Es darf **nie** öffentlich im Internet erreichbar sein (Zugangsdaten für Mailkonten wären sonst gefährdet).

---

## 3. Installation der Software

### 3.1 Pakete installieren

```bash
apt update
apt install nginx php8.3-fpm php8.3-imap imapfilter unzip -y
```

### 3.2 PHP-Version prüfen

```bash
php --version
```

Die Ausgabe sollte `PHP 8.x.x` zeigen. Falls eine andere Version aktiv ist:

```bash
# Verfügbare Versionen anzeigen
update-alternatives --list php

# Auf 8.3 wechseln (Beispiel)
update-alternatives --set php /usr/bin/php8.3
```

### 3.3 imapfilter prüfen

```bash
imapfilter --version
which imapfilter
```

Der Pfad (`/usr/bin/imapfilter`) wird später in der Konfiguration benötigt.

### 3.4 Web-UI installieren

```bash
# ZIP hochladen und entpacken (Beispiel mit scp vom eigenen Rechner)
scp imapfilter-ui.zip root@dein-server:/tmp/

# Auf dem Server:
cd /tmp
unzip imapfilter-ui.zip
mv imapfilter-ui-new /var/www/imapfilter-ui
```

Oder direkt per Git:

```bash
git clone https://github.com/DasAoD/imapfilter-ui /var/www/imapfilter-ui
```

---

## 4. Verzeichnisse und Berechtigungen

Der Webserver (Benutzer `www-data`) muss in die Arbeitsverzeichnisse schreiben dürfen.

```bash
# Konfigurationsverzeichnis anlegen
mkdir -p /srv/imapfilter

# Logverzeichnis anlegen
mkdir -p /var/log/imapfilter

# Eigentümer setzen
chown -R www-data:www-data /srv/imapfilter
chown -R www-data:www-data /var/log/imapfilter
chown -R www-data:www-data /var/www/imapfilter-ui

# Berechtigungen setzen
chmod 750 /srv/imapfilter
chmod 750 /var/log/imapfilter
```

### Prüfen ob alles stimmt

```bash
ls -la /srv/
# Erwartete Ausgabe: drwxr-x--- www-data www-data ... imapfilter

ls -la /var/log/imapfilter/
# Erwartete Ausgabe: drwxr-x--- www-data www-data ...
```

### Konfigurationsdatei

Die Datei `config.php` liegt bereits im Repository und enthält die Standardpfade.
Nur anpassen, wenn du andere Verzeichnisse verwenden möchtest:

```bash
nano /var/www/imapfilter-ui/config.php
```

Inhalt (Standard — muss in den meisten Fällen nicht geändert werden):

```php
$luaBaseDir    = '/srv/imapfilter';       // Arbeitsverzeichnis
$usersJson     = '/srv/imapfilter/users.json'; // Benutzerdatenbank
$imapfilterBin = '/usr/bin/imapfilter';   // Pfad zum imapfilter-Binary
$logDir        = '/var/log/imapfilter';   // Logdateien
```

---

## 5. Nginx konfigurieren (HTTPS)

### 5.1 SSL-Zertifikat besorgen (Let's Encrypt)

```bash
apt install certbot python3-certbot-nginx -y
certbot --nginx -d imapfilter.beispiel.de
```

> Ersetze `imapfilter.beispiel.de` durch deine eigene Domain oder Subdomain.

### 5.2 Nginx vHost anlegen

Datei erstellen:

```bash
nano /etc/nginx/sites-available/imapfilter
```

Inhalt einfügen (Domain und LAN/VPN-Adressen anpassen):

```nginx
# HTTP → HTTPS Weiterleitung
server {
    listen 80;
    listen [::]:80;
    server_name imapfilter.beispiel.de;
    return 301 https://$host$request_uri;
}

# HTTPS
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name imapfilter.beispiel.de;

    # SSL-Zertifikate (von certbot automatisch eingetragen)
    ssl_certificate     /etc/letsencrypt/live/imapfilter.beispiel.de/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/imapfilter.beispiel.de/privkey.pem;

    root  /var/www/imapfilter-ui/public;
    index index.php;

    # ── Zugriff nur aus LAN / VPN ──────────────────────────────────────────────
    # Passe die IP-Adressen an dein Netzwerk an!
    # Beispiele:
    #   127.0.0.1        → Localhost (immer erlauben)
    #   10.8.0.0/24      → WireGuard VPN (typisch)
    #   192.168.0.0/24   → Heimnetzwerk
    #   192.168.4.0/24   → weiteres LAN-Segment

    location / {
        allow 127.0.0.1;
        allow 10.8.0.0/24;
        allow 192.168.0.0/24;
        allow 192.168.4.0/24;
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

    # Versteckte Dateien sperren
    location ~ /\. {
        deny all;
    }
}
```

### 5.3 vHost aktivieren und Nginx neu starten

```bash
# Symlink anlegen
ln -s /etc/nginx/sites-available/imapfilter /etc/nginx/sites-enabled/

# Konfiguration testen (darf keine Fehler zeigen)
nginx -t

# Nginx neu laden
systemctl reload nginx
```

### 5.4 IP-Adresse deines Netzwerks herausfinden

Falls du dir nicht sicher bist, welche IP-Adresse dein Heimnetzwerk hat:

```bash
ip route
# Beispielausgabe: 192.168.1.0/24 dev eth0 ...
```

Der Bereich vor dem `/24` ist dein Netzwerk. Trage ihn in den Nginx-Block ein.

---

## 6. Ersteinrichtung im Browser

Rufe die URL deines Web-UI im Browser auf (z. B. `https://imapfilter.beispiel.de`).

**Beim allerersten Aufruf** erscheint automatisch die Seite `setup.php`:

1. **Benutzername** eingeben (nur Buchstaben, Zahlen, `-`, `_`, `.` erlaubt — z. B. `admin`)
2. **Passwort** eingeben — es muss folgende Anforderungen erfüllen:
   - Mindestens **10 Zeichen**
   - Mindestens ein **Großbuchstabe** (A–Z)
   - Mindestens ein **Kleinbuchstabe** (a–z)
   - Mindestens eine **Zahl** (0–9)
   - Mindestens ein **Sonderzeichen** (`!@#$%^&*-_=+?`)
   - Ein **Stärke-Indikator** zeigt beim Tippen an, wie sicher das Passwort ist
3. **Passwort wiederholen**
4. Auf **„Admin-Account anlegen"** klicken

Danach wirst du zur Login-Seite weitergeleitet. Melde dich mit den soeben erstellten Zugangsdaten an.

> **Hinweis:** Dieser erste Account hat automatisch Admin-Rechte und kann weitere Benutzer anlegen.

---

## 7. IMAP-Einstellungen hinterlegen

Nach dem Login: Klicke in der linken Seitenleiste auf **🔌 IMAP-Einstellungen**.

| Feld | Erklärung | Beispiel |
|---|---|---|
| **IMAP-Server (Host)** | Hostname des Mailservers deines Hosters | `w010ea06.kasserver.com` |
| **Port** | Fast immer `993` für SSL | `993` |
| **SSL** | Aktiviert lassen (verschlüsselte Verbindung) | ✅ |
| **Benutzername** | Deine vollständige E-Mail-Adresse | `name@beispiel.de` |
| **Passwort** | Das Passwort deines Mailkontos | `••••••••` |

**Wo finde ich den IMAP-Server meines Hosters?**

- **all-inkl.com (KAS):** `w0XXeaXX.kasserver.com` — steht im KAS unter „E-Mail" → „Postfach" → „Servereinstellungen"
- **Andere Hoster:** Im Hoster-Panel unter „E-Mail" → „IMAP-Einstellungen" oder „Servereinstellungen"

Nach dem Ausfüllen:

1. Klicke **„💾 Speichern"** — die Zugangsdaten werden in `/srv/imapfilter/<benutzername>/imap_settings.json` gespeichert
2. Klicke **„🔌 Verbindung testen"** — bei Erfolg erscheint eine grüne Meldung

**Ausführungs-Intervall** (darunter auf der gleichen Seite):

Wähle, wie oft IMAPFilter deine Mails filtern soll:

| Intervall | Empfehlung |
|---|---|
| 1 Minute | Für sehr aktive Postfächer |
| 5 Minuten | Guter Standard für die meisten Nutzer |
| 15 / 30 Minuten | Für weniger aktive Postfächer |
| 1 / 6 Stunden | Für Newsletter-artige Nutzung |
| 24 Stunden | Einmal täglich |
| Deaktiviert | IMAPFilter läuft nicht automatisch |

Klicke **„💾 Intervall speichern"**.

---

## 8. Filterregeln anlegen

Klicke in der Seitenleiste auf **⚙️ Filterregeln**.

### 8.1 Spam-Filter konfigurieren

Der Spam-Filter-Block ist standardmäßig aktiviert. Er nutzt einen Header, den viele Hoster
automatisch an E-Mails anhängen, wenn sie als Spam erkannt wurden.

| Feld | Erklärung | Standard |
|---|---|---|
| **Header-Feld** | Name des Spam-Headers | `X-KasSpamfilter` |
| **Header-Wert** | Wert, der Spam kennzeichnet | `rSpamD` |
| **Zielordner** | Wohin Spam verschoben wird | `Spam` |

> **all-inkl.com:** Die Standardwerte `X-KasSpamfilter` / `rSpamD` sind korrekt und müssen nicht geändert werden.

**Whitelist** — Absender, die niemals als Spam behandelt werden sollen:

- Trage E-Mail-Adressen oder Domains ein, z. B. `@meinefirma.de` oder `newsletter@wichtig.de`
- Klicke **„+ Hinzufügen"** oder drücke Enter
- Mit `×` wieder entfernen

### 8.2 Eigene Filterregel anlegen

Klicke auf **„+ Regel hinzufügen"**. Es öffnet sich ein Formular:

| Feld | Erklärung | Beispiel |
|---|---|---|
| **Regelname** | Frei wählbare Bezeichnung | `Familie`, `Arbeit`, `Newsletter` |
| **Absender-Adressen** | Mails von diesen Adressen/Domains werden erfasst | `@firma.de`, `max@beispiel.de` |
| **Empfänger-Adressen** | Mails an diese Adressen werden erfasst (An:) | `helene@beispiel.de` |
| **Betreff-Schlüsselwörter** | Mails deren Betreff diesen Text enthält | `Rechnung`, `Newsletter` |
| **Zielordner** | Wohin die Mails verschoben werden | `Arbeit`, `Familie/Max` |
| **Logik** | ODER = eines muss zutreffen · UND = alles muss zutreffen | `ODER` |
| **Regel aktiv** | Deaktivierte Regeln werden ignoriert | ✅ |

**Hinweise:**

- Mindestens eines der drei Felder (Absender, Empfänger, Betreff) muss ausgefüllt sein
- **Mehrere Einträge auf einmal** möglich: kommagetrennt eingeben, z. B. `@firma1.de, @firma2.de`
- Domains mit `@` als Präfix erfassen alle Adressen dieser Domain: `@beispiel.de`
- Das **Empfänger-Feld** ist nützlich für Mails, die du als Kopie (CC) empfängst oder die an eine Sammeladresse gehen
- **Reihenfolge ist wichtig:** Regeln für Unterordner (z. B. `Familie/Max`) müssen **vor** der Regel für den übergeordneten Ordner (`Familie`) stehen
- Reihenfolge per **Drag & Drop** ändern (an den `⋮⋮`-Griffen ziehen)

---

## 9. Lua-Dateien generieren

Die Lua-Dateien werden **automatisch bei jeder Regeländerung** neu generiert — es ist kein
manueller Schritt nötig. Der Button **„⚡ Lua neu generieren"** steht als manueller Fallback
zur Verfügung, falls etwas nicht stimmt.

Dabei werden folgende Dateien automatisch erstellt oder überschrieben:

| Datei | Inhalt |
|---|---|
| `/srv/imapfilter/<benutzername>/config.lua` | IMAP-Verbindungsdaten + Einbindung der anderen Dateien |
| `/srv/imapfilter/<benutzername>/folders.lua` | Ordner-Referenzen |
| `/srv/imapfilter/<benutzername>/filters.lua` | Filterregeln |

Von jeder Datei wird automatisch ein Backup erstellt unter:
`/srv/imapfilter/<benutzername>/backups/`

> **Hinweis:** Die automatische Generierung setzt voraus, dass IMAP-Einstellungen bereits
> gespeichert sind. Bei einem ganz neuen Account zuerst die IMAP-Einstellungen hinterlegen.

### Ersten Testlauf durchführen

Klicke in der Seitenleiste auf **▶️ Ausführen**, dann auf **„▶ imapfilter starten"**.

Die Ausgabe erscheint im Log-Bereich darunter. Bei Erfolg sollte keine Fehlermeldung erscheinen.

---

## 10. Dispatcher einrichten

Der **Dispatcher** ist ein PHP-Skript, das einmal pro Minute aufgerufen wird und für jeden
Benutzer prüft, ob sein eingestelltes Intervall abgelaufen ist. Falls ja, wird IMAPFilter gestartet.

> **Einmalige Einrichtung durch den Admin** — danach läuft alles automatisch,
> auch wenn neue Benutzer hinzukommen.

Öffne im Admin-Bereich: **🕐 Dispatcher**

---

### Variante A: systemd (empfohlen)

Geeignet für: eigene Server (VPS, Heimserver) mit Root-Zugriff.

**Schritt 1:** Service- und Timer-Datei installieren

```bash
cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.service /etc/systemd/system/
cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.timer  /etc/systemd/system/
```

**Schritt 2:** systemd neu laden

```bash
systemctl daemon-reload
```

**Schritt 3:** Timer aktivieren und starten

```bash
systemctl enable --now imapfilter-dispatcher.timer
```

**Schritt 4:** Status prüfen

```bash
# Timer-Status anzeigen
systemctl status imapfilter-dispatcher.timer

# Erwartete Ausgabe (Ausschnitt):
# Active: active (waiting)
# Trigger: ...

# Letzten Lauf prüfen
journalctl -u imapfilter-dispatcher.service --no-pager -n 20
```

**Timer deaktivieren** (falls nötig):

```bash
systemctl disable --now imapfilter-dispatcher.timer
```

---

### Variante B: Cron über Konsole

Geeignet für: Server mit Konsolenzugriff, auf denen kein systemd verwendet wird.

**Option 1:** Über `/etc/cron.d/` (empfohlen, da als Root ohne crontab):

```bash
cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.cron /etc/cron.d/imapfilter-dispatcher
chmod 644 /etc/cron.d/imapfilter-dispatcher
```

Inhalt der Datei zur Kontrolle:

```
* * * * * www-data /usr/bin/php /var/www/imapfilter-ui/cron/dispatcher.php
```

**Option 2:** Über `crontab -e` (als root):

```bash
crontab -e
```

Folgende Zeile einfügen:

```
* * * * * /usr/bin/php /var/www/imapfilter-ui/cron/dispatcher.php
```

**PHP-Pfad prüfen** (falls der Cron-Job nicht startet):

```bash
which php
# Ausgabe z. B.: /usr/bin/php8.3

# Falls abweichend, Pfad in der Cron-Zeile anpassen:
* * * * * /usr/bin/php8.3 /var/www/imapfilter-ui/cron/dispatcher.php
```

---

### Variante C: Cron über Hoster-Panel (z. B. KAS)

Geeignet für: Shared Hosting ohne Konsolen-Cron-Zugriff (z. B. all-inkl.com).

**Schritt 1:** Im KAS-Panel anmelden → „Tools" → „Cronjobs" → „Neuen Cronjob anlegen"

**Schritt 2:** Folgende Werte eintragen:

| Feld | Wert |
|---|---|
| Minute | `*` |
| Stunde | `*` |
| Tag | `*` |
| Monat | `*` |
| Wochentag | `*` |
| Befehl | `/usr/bin/php /var/www/imapfilter-ui/cron/dispatcher.php` |

> **Hinweis:** Der PHP-Pfad kann je nach Hoster abweichen. Im KAS ist er in der Regel
> `/usr/bin/php` oder `/usr/local/bin/php`. Im Zweifel beim Hoster-Support nachfragen
> oder auf der Konsole `which php` ausführen.

**Schritt 3:** Cronjob speichern. Er ist sofort aktiv.

---

### Dispatcher-Status prüfen

Im Web-UI unter **🕐 Dispatcher** siehst du für jeden Benutzer:

| Spalte | Bedeutung |
|---|---|
| Benutzername | Wer ist konfiguriert |
| Intervall | Wie oft IMAPFilter läuft |
| Letzter Lauf | Wann zuletzt ausgeführt |
| Exit-Code | Grün = OK, Rot = Fehler |
| ⚠️ | config.lua fehlt → „Lua generieren" nötig |

---

## 11. Weitere Benutzer anlegen

Nur Admins können neue Benutzer anlegen.

Klicke in der Seitenleiste auf **👤 Benutzerverwaltung** → **„+ Benutzer anlegen"**:

| Feld | Erklärung |
|---|---|
| **Benutzername** | Eindeutiger Bezeichner, nur `a-z`, `0-9`, `-`, `_`, `.` |
| **Passwort** | Mindestens 8 Zeichen |
| **Passwort wiederholen** | Zur Bestätigung |
| **Admin-Rechte** | Nur aktivieren, wenn der Benutzer andere Nutzer verwalten soll |

Nach dem Anlegen kann sich der neue Benutzer unter der Web-UI-URL einloggen
und seine eigenen IMAP-Einstellungen und Regeln hinterlegen.

**Passwort zurücksetzen:**

In der Benutzerliste auf **„🔑 Passwort"** klicken → neues Passwort zweimal eingeben → speichern.

**Benutzer löschen:**

Auf **„🗑 Löschen"** klicken. Die Dateien des Benutzers unter `/srv/imapfilter/<benutzername>/`
bleiben erhalten und müssen manuell gelöscht werden:

```bash
rm -rf /srv/imapfilter/<benutzername>
rm /var/log/imapfilter/<benutzername>.log
```

---

## 12. Ordner verwalten

Klicke in der Seitenleiste auf **📁 Ordner**.

Die Ordner werden live vom IMAP-Server abgerufen und angezeigt.

**Neuen Ordner anlegen:**

Klicke auf **„+ Ordner anlegen"** → Namen eingeben → **„Speichern"**.

**Ordner umbenennen:**

Klicke auf das ✏️-Symbol neben dem Ordnernamen → neuen Namen eingeben → **„Speichern"**.

**Ordner löschen:**

Klicke auf das 🗑-Symbol neben dem Ordnernamen → Bestätigung im Modal.

> ⚠️ Alle Mails im gelöschten Ordner werden automatisch in die **INBOX** verschoben,
> bevor der Ordner gelöscht wird. Die INBOX selbst kann nicht gelöscht oder umbenannt werden.

Für Unterordner einen `/` als Trennzeichen verwenden:

| Eingabe | Ergebnis |
|---|---|
| `Familie` | Hauptordner „Familie" |
| `Familie/Max` | Unterordner „Max" unter „Familie" |
| `#servermails/Grafana` | Unterordner „Grafana" unter „#servermails" |

> **Hinweis:** Manche IMAP-Server erlauben keine Sonderzeichen oder bestimmte Ordnernamen.
> Falls eine Aktion fehlschlägt, einen einfacheren Namen versuchen.

---

## 13. Lua-Editor (Fallback)

Der Lua-Editor unter **📝 Lua-Editor** ermöglicht die direkte Bearbeitung von
`filters.lua` und `folders.lua` im Browser.

**Wann verwenden?**

- Für manuelle Anpassungen, die das UI nicht abbilden kann
- Für komplexe imapfilter-Funktionen (z. B. `mark_as_read`, `delete_messages`)

> ⚠️ **Achtung:** Beim nächsten Klick auf **„⚡ Lua generieren"** werden die manuell
> bearbeiteten Dateien **überschrieben**. Nur den Editor verwenden, wenn du die
> generierten Dateien danach nicht mehr überschreiben möchtest.

---

## 14. Logdateien und Fehlersuche

### Logdateien

| Datei | Inhalt |
|---|---|
| `/var/log/imapfilter/<benutzername>.log` | IMAPFilter-Ausgabe pro Benutzer |
| `/var/log/imapfilter/dispatcher.log` | Dispatcher-Protokoll (wann welcher Benutzer gestartet wurde) |
| `/var/log/imapfilter/login.log` | Fehlgeschlagene Login-Versuche (IP, Zeitstempel, Versuch-Nr.) |
| `/var/log/nginx/error.log` | Nginx-Fehler |
| `/var/log/php8.3-fpm.log` | PHP-Fehler |

Im Web-UI unter **▶️ Ausführen** sind die letzten 100 Zeilen des Benutzer-Logs sichtbar.

### Häufige Fehlermeldungen

**„Permission denied"** beim ersten Aufruf:

```bash
chown -R www-data:www-data /srv/imapfilter
chown -R www-data:www-data /var/log/imapfilter
```

**„config.lua nicht gefunden":**

→ Zuerst IMAP-Einstellungen speichern, dann **„⚡ Lua generieren"** klicken.

**„PHP-IMAP-Extension nicht installiert":**

```bash
apt install php8.3-imap
systemctl restart php8.3-fpm
```

**IMAPFilter meldet Verbindungsfehler:**

- IMAP-Zugangsdaten prüfen (Benutzername = vollständige E-Mail-Adresse)
- Servername und Port prüfen
- SSL aktiviert lassen (Port 993)
- Beim Hoster prüfen, ob IMAP aktiviert ist

**„Zu viele Fehlversuche" beim Login:**

Nach 5 falschen Login-Versuchen wird die IP für 15 Minuten gesperrt.
Die Sperre läuft automatisch ab. Admin kann die Datei manuell löschen:

```bash
rm /srv/imapfilter/.login_attempts.json
```

**Dispatcher läuft, aber IMAPFilter startet nicht:**

```bash
# Dispatcher-Log prüfen
tail -50 /var/log/imapfilter/dispatcher.log

# Timer-Status (bei systemd)
systemctl status imapfilter-dispatcher.timer
journalctl -u imapfilter-dispatcher.service -n 30
```

---

## 15. Dateistruktur (Übersicht)

```
/var/www/imapfilter-ui/          ← Projektverzeichnis
├── public/                      ← Nginx-Webroot
│   ├── api/                     ← Backend-API (PHP)
│   │   ├── auth_check.php       ← Session-Check + CSRF-Prüfung
│   │   ├── dispatcher.php
│   │   ├── editor.php
│   │   ├── folders.php          ← Ordner anzeigen/anlegen/umbenennen/löschen
│   │   ├── generate.php
│   │   ├── rules.php
│   │   ├── run.php
│   │   ├── settings.php
│   │   └── users.php
│   ├── assets/
│   │   ├── app.js               ← Frontend-Logik
│   │   └── style.css            ← Design
│   ├── auth.php
│   ├── index.php                ← Haupt-UI
│   ├── login.php                ← Login mit Rate-Limiting
│   ├── logout.php
│   ├── robots.txt               ← Crawler-Ausschluss
│   └── setup.php                ← Ersteinrichtung
├── cron/
│   ├── dispatcher.php           ← Dispatcher-Skript (nur CLI)
│   ├── imapfilter-dispatcher.service  ← systemd Service
│   ├── imapfilter-dispatcher.timer    ← systemd Timer
│   └── imapfilter-dispatcher.cron     ← Cron-Datei
├── lib/
│   ├── atomic.php               ← Atomare Schreiboperationen
│   ├── generate.php             ← Lua-Generierungslogik
│   └── users.php                ← Benutzerverwaltungs-Funktionen
└── config.php                   ← Konfiguration (Pfade) — außerhalb des Webroots

/srv/imapfilter/                 ← Arbeitsdaten (nicht im Repo)
├── users.json                   ← Benutzerdatenbank
├── dispatcher_state.json        ← Laufzeitzustand des Dispatchers
├── .login_attempts.json         ← Rate-Limiting-Daten
└── <benutzername>/
    ├── config.lua               ← Generiert: IMAP-Verbindung (0600)
    ├── filters.lua              ← Generiert: Filterregeln (0640)
    ├── folders.lua              ← Generiert: Ordner (0640)
    ├── rules.json               ← UI-Regeln (0640)
    ├── imap_settings.json       ← IMAP-Zugangsdaten + Intervall (0600)
    └── backups/                 ← Automatische Sicherungen (max. 10 pro Datei)

/var/log/imapfilter/             ← Logdateien
├── dispatcher.log
├── login.log                    ← Fehlgeschlagene Login-Versuche
└── <benutzername>.log
```

---

## 16. Sicherheitshinweise

- **Nie öffentlich zugänglich machen.** Der Nginx-Block erlaubt nur Zugriff aus LAN/VPN.
  Wer von unterwegs zugreifen möchte, verbindet sich zuerst per VPN.

- **Zugangsdaten.** IMAP-Passwörter werden in `imap_settings.json` gespeichert.
  Die Datei liegt außerhalb des Webroots und hat `0600`-Berechtigungen (nur `www-data` lesbar).
  `config.lua` enthält ebenfalls das Passwort und wird ebenfalls mit `0600` geschrieben.

- **Keine Secrets im Repo.** `users.json`, `imap_settings.json` und `rules.json`
  sind in `.gitignore` eingetragen und werden nie ins Repository hochgeladen.

- **Passwort-Anforderungen.** Das System erzwingt serverseitig: mindestens 10 Zeichen,
  Groß-/Kleinbuchstaben, Zahl und Sonderzeichen. Ein Stärke-Indikator hilft beim Wählen.

- **Login-Schutz.** Nach 5 falschen Versuchen wird die IP für 15 Minuten gesperrt.
  Fehlversuche werden in `/var/log/imapfilter/login.log` protokolliert.
  Manuelle Entsperrung: `rm /srv/imapfilter/.login_attempts.json`

- **CSRF-Schutz.** Alle schreibenden API-Endpunkte prüfen einen Session-gebundenen Token.

- **Session-Sicherheit.** Cookies werden mit `Secure`, `HttpOnly` und `SameSite=Strict` gesetzt.

- **Regelmäßige Updates.** Nginx, PHP und imapfilter aktuell halten:
  ```bash
  apt update && apt upgrade -y
  ```

---

## 17. Häufige Fragen (FAQ)

**Werden Mails heruntergeladen oder gelöscht?**

Nein. IMAPFilter arbeitet ausschließlich auf dem IMAP-Server. Mails werden nur verschoben,
nie heruntergeladen oder gelöscht (außer du richtest explizit eine Lösch-Regel ein).

**Kann ich IMAPFilter auch ohne dieses Web-UI verwenden?**

Ja. IMAPFilter ist ein eigenständiges Programm. Das Web-UI ist nur eine Oberfläche,
um die Konfiguration einfacher zu verwalten.

**Was passiert, wenn ich auf „Lua generieren" klicke?**

Die Dateien `config.lua`, `folders.lua` und `filters.lua` werden neu erzeugt.
Vorher wird automatisch ein Backup der alten Dateien erstellt.

**Kann ich die generierten Lua-Dateien manuell bearbeiten?**

Ja, über den Lua-Editor. Aber beim nächsten „Lua generieren" werden sie überschrieben.
Für dauerhafte manuelle Änderungen: einfach nicht mehr „Lua generieren" klicken.

**Wie füge ich ein weiteres Familienmitglied hinzu?**

Als Admin: **👤 Benutzerverwaltung** → **„+ Benutzer anlegen"**.
Das neue Mitglied meldet sich an, trägt sein eigenes Mailkonto ein und richtet seine Regeln ein.
Der Dispatcher kümmert sich automatisch darum, dass auch für diesen Benutzer IMAPFilter läuft.

**Wie ändere ich mein Passwort?**

Über **🔑 Passwort ändern** in der Seitenleiste. Aktuelles Passwort zur Bestätigung eingeben,
neues Passwort zweimal eingeben. Der Stärke-Indikator zeigt die Qualität des neuen Passworts.
Admin kann Passwörter anderer Benutzer über die Benutzerverwaltung zurücksetzen.

**Der Dispatcher läuft, aber meine Mails werden nicht sortiert.**

1. Prüfen ob `config.lua` existiert: **🕐 Dispatcher** → Status-Tabelle → ⚠️-Symbol?
2. Falls ja: **⚡ Lua generieren** ausführen
3. Manuellen Testlauf starten: **▶️ Ausführen** → **„▶ imapfilter starten"**
4. Fehlermeldungen im Log prüfen

---

## Mitwirkende

Dieses Projekt wurde in Zusammenarbeit mit [Claude](https://claude.ai) (Sonnet 4.5) von [Anthropic](https://anthropic.com) entwickelt.  
Der überwiegende Teil des Codes, der Architektur und der Dokumentation wurde durch KI generiert und iterativ verfeinert.

| Rolle | Person / Tool |
|---|---|
| Projektidee & Anforderungen | [DasAoD](https://github.com/DasAoD) |
| Code, Architektur, Dokumentation | Claude (Anthropic) |
