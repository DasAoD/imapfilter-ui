<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config.php';
$username = $_SESSION['username'];
$isAdmin  = !empty($_SESSION['is_admin']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IMAPFilter Web-UI</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div id="app">

    <nav id="sidebar">
        <div class="sidebar-logo">
            <div class="sidebar-logo-title">📧 IMAPFilter</div>
            <div class="sidebar-logo-sub">Web-UI</div>
        </div>

        <div class="sidebar-section">Verwaltung</div>
        <a class="nav-item" data-view="rules"    href="#rules">   <i class="icon">⚙️</i> Filterregeln</a>
        <a class="nav-item" data-view="folders"  href="#folders"> <i class="icon">📁</i> Ordner</a>

        <div class="sidebar-section">Werkzeuge</div>
        <a class="nav-item" data-view="run"      href="#run">     <i class="icon">▶️</i> Ausführen</a>
        <a class="nav-item" data-view="editor"   href="#editor">  <i class="icon">📝</i> Lua-Editor</a>

        <div class="sidebar-section">System</div>
        <a class="nav-item" data-view="settings" href="#settings"><i class="icon">🔌</i> IMAP-Einstellungen</a>
        <a class="nav-item" data-view="password" href="#password"><i class="icon">🔑</i> Passwort ändern</a>
        <?php if ($isAdmin): ?>
        <a class="nav-item" data-view="admin"      href="#admin">   <i class="icon">👤</i> Benutzerverwaltung</a>
        <a class="nav-item" data-view="dispatcher" href="#dispatcher"><i class="icon">🕐</i> Dispatcher</a>
        <?php endif; ?>

        <div class="sidebar-spacer"></div>
        <div class="sidebar-bottom">
            <div class="nav-item" style="cursor:default;color:var(--muted);font-size:.8rem;padding-bottom:4px">
                <i class="icon">👤</i>
                <?= htmlspecialchars($username) ?>
                <?php if ($isAdmin): ?><span style="font-size:.7rem;background:rgba(59,130,246,.2);color:#93c5fd;padding:1px 5px;border-radius:3px;margin-left:4px">Admin</span><?php endif; ?>
            </div>
            <a class="nav-item" href="logout.php"><i class="icon">🚪</i> Logout</a>
        </div>
    </nav>

    <main id="content">

        <!-- Rules view -->
        <div id="view-rules" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">Filterregeln</h1>
                <div class="view-actions">
                    <button class="btn btn-secondary" onclick="App.generateLua()" title="Lua-Dateien manuell neu generieren">⚡ Lua neu generieren</button>
                    <button class="btn btn-primary"   onclick="App.openRuleModal()">+ Regel hinzufügen</button>
                </div>
            </div>
            <div id="rules-content"></div>
        </div>

        <!-- Folders view -->
        <div id="view-folders" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">IMAP-Ordner</h1>
                <div class="view-actions">
                    <button class="btn btn-secondary" onclick="App.loadFolders(true)">🔄 Aktualisieren</button>
                    <button class="btn btn-primary"   onclick="App.openCreateFolderModal()">+ Ordner anlegen</button>
                </div>
            </div>
            <div id="folders-content"></div>
        </div>

        <!-- Run view -->
        <div id="view-run" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">Ausführen</h1>
                <div class="view-actions">
                    <button class="btn btn-secondary" onclick="App.loadLog()">🔄 Log aktualisieren</button>
                    <button class="btn btn-success"   onclick="App.runImapfilter()" id="btn-run">▶ imapfilter starten</button>
                </div>
            </div>
            <div class="card" style="margin-bottom:16px">
                <div class="card-title">⚡ Workflow</div>
                <p class="text-muted text-sm" style="margin-bottom:12px">Erst Lua-Dateien generieren, dann imapfilter starten.</p>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-secondary" onclick="App.generateLua()" title="Lua-Dateien manuell neu generieren">⚡ Lua neu generieren</button>
                    <button class="btn btn-success"   onclick="App.runImapfilter()">▶ imapfilter starten</button>
                </div>
            </div>
            <div class="card">
                <div class="card-title" style="justify-content:space-between">
                    <span>📋 Logdatei (letzte 100 Zeilen)</span>
                    <button class="btn btn-sm btn-secondary" onclick="App.loadLog()">Aktualisieren</button>
                </div>
                <div id="log-output" class="log-output"><span class="log-empty">Log wird geladen…</span></div>
            </div>
        </div>

        <!-- Editor view -->
        <div id="view-editor" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">Lua-Editor</h1>
                <div class="view-actions">
                    <button class="btn btn-primary" onclick="App.saveEditorFile()">💾 Speichern</button>
                </div>
            </div>
            <div class="card">
                <p class="text-muted text-sm" style="margin-bottom:14px">
                    ⚠️ Manuell gespeicherte Dateien werden beim nächsten <em>Lua generieren</em> überschrieben.
                </p>
                <div class="tabs">
                    <button class="tab active" onclick="App.switchEditorTab('filters',this)">filters.lua</button>
                    <button class="tab"        onclick="App.switchEditorTab('folders',this)">folders.lua</button>
                </div>
                <input type="hidden" id="editor-current-file" value="filters">
                <textarea id="editor-textarea" class="form-textarea mono" style="min-height:500px;width:100%"></textarea>
            </div>
        </div>

        <!-- Settings view -->
        <div id="view-settings" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">IMAP-Einstellungen</h1>
                <div class="view-actions">
                    <button class="btn btn-secondary" onclick="App.testConnection()" id="btn-test">🔌 Verbindung testen</button>
                    <button class="btn btn-primary"   onclick="App.saveSettings()">💾 Speichern</button>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Zugangsdaten</div>
                <div class="form-row">
                    <div class="form-group" style="flex:2">
                        <label class="form-label">IMAP-Server (Host)</label>
                        <input type="text" class="form-input" id="s-host" placeholder="w010ea06.kasserver.com">
                    </div>
                    <div class="form-group" style="flex:0 0 100px">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-input" id="s-port" value="993">
                    </div>
                    <div class="form-group" style="flex:0 0 80px">
                        <label class="form-label">SSL</label>
                        <div style="padding-top:6px"><label class="toggle"><input type="checkbox" id="s-ssl" checked><span class="toggle-slider"></span></label></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="toggle-row" style="cursor:pointer">
                        <div>
                            <div class="toggle-label">SSL-Zertifikat nicht prüfen</div>
                            <div class="text-sm text-muted">Nur aktivieren wenn der Mailserver ein selbst-signiertes Zertifikat verwendet</div>
                        </div>
                        <label class="toggle"><input type="checkbox" id="s-ssl-novalidate"><span class="toggle-slider"></span></label>
                    </label>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Benutzername (E-Mail)</label>
                        <input type="text" class="form-input" id="s-user" placeholder="user@example.com" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Passwort</label>
                        <input type="password" class="form-input" id="s-pass" placeholder="Leer lassen = nicht ändern" autocomplete="new-password">
                    </div>
                </div>
                <div id="settings-status" style="margin-top:8px"></div>
            </div>
            <div class="card" style="margin-top:16px">
                <div class="card-title">🕐 Ausführungs-Intervall</div>
                <p class="text-muted text-sm" style="margin-bottom:12px">
                    Wie oft soll imapfilter deine Mails filtern?
                    Der Dispatcher muss einmalig vom Admin eingerichtet sein.
                </p>
                <div class="form-row" style="align-items:flex-end">
                    <div class="form-group" style="flex:0 0 180px">
                        <label class="form-label">Intervall (in Minuten)</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="number" class="form-input" id="s-interval" min="1" max="10080" value="5" style="width:90px">
                            <span class="text-muted text-sm">Min.</span>
                        </div>
                        <div class="text-muted text-sm mt-2">0 = deaktiviert</div>
                    </div>
                    <div class="form-group" style="flex:0 0 auto">
                        <button class="btn btn-secondary" onclick="App.saveInterval()">💾 Intervall speichern</button>
                    </div>
                </div>
                <div id="dispatcher-status-user" style="margin-top:8px"></div>
            </div>
        </div>

        <!-- Dispatcher view (Admin) -->
        <?php if ($isAdmin): ?>
        <div id="view-dispatcher" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">Dispatcher</h1>
                <div class="view-actions">
                    <button class="btn btn-secondary" onclick="App.loadDispatcherStatus()">🔄 Aktualisieren</button>
                </div>
            </div>

            <!-- Einrichtung -->
            <div class="card" style="margin-bottom:16px">
                <div class="card-title">⚙️ Einrichtung</div>
                <p class="text-muted text-sm" style="margin-bottom:14px">
                    Der Dispatcher muss einmalig eingerichtet werden. Er läuft jede Minute und
                    startet imapfilter für jeden Benutzer gemäß seinem eingestellten Intervall.
                    Neue Benutzer werden automatisch berücksichtigt.
                </p>
                <div class="tabs" id="dispatcher-setup-tabs">
                    <button class="tab active" onclick="App.switchDispatcherTab('systemd', this)">systemd</button>
                    <button class="tab"        onclick="App.switchDispatcherTab('crond',   this)">Cron (/etc/cron.d/)</button>
                    <button class="tab"        onclick="App.switchDispatcherTab('hoster',  this)">Cron (Hoster/KAS)</button>
                </div>

                <div id="dt-systemd">
                    <p class="text-muted text-sm" style="margin-bottom:10px">Service- und Timer-Datei installieren:</p>
                    <pre class="log-output" style="max-height:none;white-space:pre-wrap">cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.service /etc/systemd/system/
cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.timer  /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now imapfilter-dispatcher.timer</pre>
                    <p class="text-muted text-sm" style="margin-top:10px">Status prüfen:</p>
                    <pre class="log-output" style="max-height:none;white-space:pre-wrap">systemctl status imapfilter-dispatcher.timer
journalctl -u imapfilter-dispatcher.service -f</pre>
                </div>

                <div id="dt-crond" hidden>
                    <p class="text-muted text-sm" style="margin-bottom:10px">Cron-Datei nach <code>/etc/cron.d/</code> kopieren:</p>
                    <pre class="log-output" style="max-height:none;white-space:pre-wrap">cp /var/www/imapfilter-ui/cron/imapfilter-dispatcher.cron /etc/cron.d/imapfilter-dispatcher
chmod 644 /etc/cron.d/imapfilter-dispatcher</pre>
                    <p class="text-muted text-sm" style="margin-top:10px">Inhalt der Datei:</p>
                    <pre class="log-output" style="max-height:none;white-space:pre-wrap">* * * * * www-data /usr/bin/php /var/www/imapfilter-ui/cron/dispatcher.php</pre>
                </div>

                <div id="dt-hoster" hidden>
                    <p class="text-muted text-sm" style="margin-bottom:10px">
                        Im Hoster-Panel (KAS, Plesk, cPanel, …) einen neuen Cron-Job anlegen:
                    </p>
                    <div class="card" style="background:var(--bg);margin-bottom:0">
                        <div class="form-row" style="gap:8px;flex-wrap:wrap">
                            <div class="form-group" style="flex:0 0 80px"><label class="form-label">Minute</label><input class="form-input" value="*" readonly></div>
                            <div class="form-group" style="flex:0 0 80px"><label class="form-label">Stunde</label><input class="form-input" value="*" readonly></div>
                            <div class="form-group" style="flex:0 0 80px"><label class="form-label">Tag</label><input class="form-input" value="*" readonly></div>
                            <div class="form-group" style="flex:0 0 80px"><label class="form-label">Monat</label><input class="form-input" value="*" readonly></div>
                            <div class="form-group" style="flex:0 0 80px"><label class="form-label">Wochentag</label><input class="form-input" value="*" readonly></div>
                            <div class="form-group" style="flex:1;min-width:200px"><label class="form-label">Befehl</label><input class="form-input" value="/usr/bin/php /var/www/imapfilter-ui/cron/dispatcher.php" readonly></div>
                        </div>
                    </div>
                    <p class="text-muted text-sm" style="margin-top:10px">
                        ⚠️ PHP-Pfad ggf. anpassen (<code>which php</code> auf dem Server ausführen).
                    </p>
                </div>
            </div>

            <!-- Laufzeit-Status -->
            <div class="card">
                <div class="card-title">📊 Status aller Benutzer</div>
                <div id="dispatcher-status-table"><div class="empty-state">Lädt…</div></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Password change view (all users) -->
        <div id="view-password" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">Passwort ändern</h1>
            </div>
            <div class="card" style="max-width:480px">
                <div class="card-title">🔑 Neues Passwort setzen</div>
                <div class="form-group">
                    <label class="form-label">Aktuelles Passwort</label>
                    <input type="password" class="form-input" id="cp-current" autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label class="form-label">Neues Passwort</label>
                    <input type="password" class="form-input" id="cp-new" autocomplete="new-password"
                           oninput="App.pwdCheck('cp-new')">
                    <div id="cp-strength"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Neues Passwort wiederholen</label>
                    <input type="password" class="form-input" id="cp-new2" autocomplete="new-password">
                </div>
                <div id="cp-status" style="margin-bottom:12px"></div>
                <button class="btn btn-primary" onclick="App.changePassword()">💾 Passwort ändern</button>
            </div>
        </div>
        <?php if ($isAdmin): ?>
        <div id="view-admin" class="view" hidden>
            <div class="view-header">
                <h1 class="view-title">Benutzerverwaltung</h1>
                <div class="view-actions">
                    <button class="btn btn-primary" onclick="App.openCreateUserModal()">+ Benutzer anlegen</button>
                </div>
            </div>
            <div class="card">
                <div class="card-title">👤 Benutzer</div>
                <div id="users-list"></div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<div id="toast-container"></div>

<div id="modal-overlay" hidden>
    <div class="modal" id="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="modal-title"></span>
            <button class="modal-close" onclick="App.closeModal()">✕</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer" id="modal-footer">
            <button class="btn btn-secondary" onclick="App.closeModal()">Abbrechen</button>
            <button class="btn btn-primary" id="modal-save-btn">Speichern</button>
        </div>
    </div>
</div>

<script>
    window.CURRENT_USER  = <?= json_encode($username) ?>;
    window.IS_ADMIN      = <?= json_encode($isAdmin) ?>;
    window.CSRF_TOKEN    = <?= json_encode($_SESSION['csrf_token']) ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>
