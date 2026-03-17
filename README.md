# imapfilter-ui

A simple PHP-based web interface for managing [imapfilter](https://github.com/lefcha/imapfilter) rules and folders directly in the browser.

Instead of editing Lua files manually via SSH, this UI lets you view and edit `filters.lua` and `folders.lua` through a password-protected web interface. Every save automatically creates a timestamped backup.

---

## Features

- Edit `filters.lua` and `folders.lua` directly in the browser
- Automatic backup on every save (stored in `/srv/imapfilter/backups/`)
- Password-protected login
- HTTPS via Let's Encrypt (Nginx)
- Access restricted to local network / VPN

---

## Requirements

- Debian/Ubuntu with Nginx and PHP 8.x (php-fpm)
- [imapfilter](https://github.com/lefcha/imapfilter) installed
- imapfilter config files under `/srv/imapfilter/` (configurable)

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/DasAoD/imapfilter-ui /var/www/imapfilter-ui
```

### 2. Create the configuration file

```bash
cp /var/www/imapfilter-ui/config.php.example /var/www/imapfilter-ui/config.php
nano /var/www/imapfilter-ui/config.php
```

Adjust the following values:

- `$luaBaseDir` — path to your imapfilter config files (default: `/srv/imapfilter`)
- `IMAPFILTER_UI_USER` — your desired username
- `IMAPFILTER_UI_PASSWORD_HASH` — generate with:

```bash
php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```

> **Note:** If you place `filters.lua` and `folders.lua` in a different directory,
> update `$luaBaseDir` in `config.php` accordingly. The paths `$filtersFile`,
> `$foldersFile` and `$backupDir` are all derived from `$luaBaseDir`.

### 3. Create imapfilter config files

```bash
mkdir -p /srv/imapfilter/backups
cp /var/www/imapfilter-ui/folders.lua.example /srv/imapfilter/folders.lua
cp /var/www/imapfilter-ui/filters.lua.example /srv/imapfilter/filters.lua
```

Edit both files to match your IMAP folder structure and filter rules.

### 4. Set permissions

```bash
chown -R www-data:www-data /var/www/imapfilter-ui
chown -R www-data:www-data /srv/imapfilter
```

### 5. Configure Nginx

Example vHost (`/etc/nginx/sites-available/imapfilter`):

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

    # Restrict access to local network and VPN only
    location / {
        allow 127.0.0.1;
        allow 10.8.0.0/24;       # WireGuard VPN
        allow 192.168.0.0/24;    # Local network
        deny  all;
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        allow 127.0.0.1;
        allow 10.8.0.0/24;
        allow 192.168.0.0/24;
        deny  all;
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Enable and reload:

```bash
ln -s /etc/nginx/sites-available/imapfilter /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

---

## File Structure

```
/var/www/imapfilter-ui/     ← Web root
├── auth.php                # Session / login logic
├── config.php              # Local config (not in git)
├── config.php.example      # Config template
├── filters.lua.example     # Example filter rules
├── folders.lua.example     # Example folder references
├── index.php               # Main UI
├── login.php
└── logout.php

/srv/imapfilter/            ← imapfilter config (path configurable)
├── config.lua              # imapfilter IMAP account config
├── filters.lua             # Your filter rules (not in git)
├── folders.lua             # Your folder references (not in git)
└── backups/                # Automatic backups on save
```

---

## License

[MIT](LICENSE)
