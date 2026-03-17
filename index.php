<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/**
 * Kleines WebUI zum Bearbeiten von filters.lua und folders.lua
 *
 * Funktionen:
 *  - Auswahl: filters.lua oder folders.lua
 *  - Inhalt anzeigen
 *  - Änderungen speichern
 *  - vor dem Speichern ein Backup anlegen
 */

/////////////////////////////
// Helferfunktionen
/////////////////////////////

function ensure_backup_dir($backupDir) {
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
            throw new RuntimeException("Backup-Verzeichnis '$backupDir' konnte nicht erstellt werden.");
        }
    }
}

function make_backup($filePath, $backupDir) {
    if (!file_exists($filePath)) {
        // Nichts zu sichern
        return null;
    }

    ensure_backup_dir($backupDir);

    $baseName   = basename($filePath);
    $timestamp  = date('Ymd-His');
    $backupFile = rtrim($backupDir, '/') . '/' . $baseName . '.' . $timestamp . '.bak';

    if (!copy($filePath, $backupFile)) {
        throw new RuntimeException("Backup nach '$backupFile' fehlgeschlagen.");
    }

    return $backupFile;
}

/////////////////////////////
// Auswahl: filters.lua / folders.lua
/////////////////////////////

$validFiles = [
    'filters' => [
        'label' => 'filters.lua',
        'path'  => $filtersFile,
    ],
    'folders' => [
        'label' => 'folders.lua',
        'path'  => $foldersFile,
    ],
];

$currentKey = $_GET['file'] ?? 'filters';
if (!isset($validFiles[$currentKey])) {
    $currentKey = 'filters';
}

$currentFileLabel = $validFiles[$currentKey]['label'];
$currentFilePath  = $validFiles[$currentKey]['path'];

/////////////////////////////
// POST: Änderungen speichern
/////////////////////////////

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedFileKey = $_POST['file_key'] ?? '';
    if (isset($validFiles[$postedFileKey])) {
        $filePath = $validFiles[$postedFileKey]['path'];
        $content  = $_POST['content'] ?? '';

        try {
            // Backup anlegen
            $backupFile = make_backup($filePath, $backupDir);

            // Datei schreiben
            if (file_put_contents($filePath, $content) === false) {
                throw new RuntimeException("Konnte '$filePath' nicht schreiben.");
            }

            $message = "Datei '{$validFiles[$postedFileKey]['label']}' wurde gespeichert."
                     . ($backupFile ? " Backup: " . htmlspecialchars(basename($backupFile)) : '');

            // Nach dem Speichern neu laden, um F5-Dubletten zu vermeiden
            header('Location: ?file=' . urlencode($postedFileKey) . '&saved=1');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Ungültiger Datei-Parameter.';
    }
}

// Inhalt der aktuellen Datei laden
$currentContent = '';
if (file_exists($currentFilePath)) {
    $currentContent = file_get_contents($currentFilePath);
    if ($currentContent === false) {
        $error = "Konnte Datei '$currentFilePath' nicht lesen.";
    }
} else {
    $currentContent = "-- Datei existiert noch nicht: $currentFileLabel\n";
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>IMAPFilter WebUI – <?= htmlspecialchars($currentFileLabel) ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #1e1e1e;
            color: #ddd;
            margin: 0;
            padding: 0;
        }
        .wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px;
        }
        h1 {
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }
		        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        .logout {
            font-size: 0.85rem;
            color: #ddd;
            text-decoration: none;
            background: #3c3c3c;
            padding: 4px 10px;
            border-radius: 4px;
        }
        .logout:hover {
            background: #555;
        }
        .tabs {
            margin-bottom: 1rem;
        }
        .tabs a {
            display: inline-block;
            padding: 6px 12px;
            margin-right: 4px;
            text-decoration: none;
            border-radius: 4px 4px 0 0;
            background: #333;
            color: #ccc;
        }
        .tabs a.active {
            background: #007acc;
            color: #fff;
        }
        .card {
            background: #252526;
            border-radius: 6px;
            padding: 12px;
            box-shadow: 0 0 0 1px #333;
        }
        textarea {
            width: 100%;
            min-height: 500px;
            font-family: monospace;
            font-size: 13px;
            background: #1e1e1e;
            color: #dcdcdc;
            border: 1px solid #3c3c3c;
            border-radius: 4px;
            padding: 8px;
            box-sizing: border-box;
            resize: vertical;
            white-space: pre;
        }
        .buttons {
            margin-top: 10px;
            display: flex;
            gap: 8px;
        }
        button {
            padding: 6px 14px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .btn-primary {
            background: #007acc;
            color: #fff;
        }
        .btn-secondary {
            background: #3c3c3c;
            color: #ddd;
        }
        .message {
            margin-bottom: 8px;
            padding: 8px;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .message.ok {
            background: #1e3a1e;
            color: #b4f0b4;
            border: 1px solid #2f6b2f;
        }
        .message.err {
            background: #3a1e1e;
            color: #f0b4b4;
            border: 1px solid #6b2f2f;
        }
        .file-path {
            font-size: 0.8rem;
            color: #999;
            margin-bottom: 6px;
        }
        .hint {
            font-size: 0.85rem;
            color: #aaa;
            margin-top: 4px;
        }
        .hint code {
            background: #333;
            padding: 0 4px;
            border-radius: 3px;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>IMAPFilter WebUI</h1>
        <a class="logout" href="logout.php">Logout</a>
    </div>

    <div class="tabs">
        <a href="?file=filters" class="<?= $currentKey === 'filters' ? 'active' : '' ?>">filters.lua</a>
        <a href="?file=folders" class="<?= $currentKey === 'folders' ? 'active' : '' ?>">folders.lua</a>
    </div>

    <?php if (isset($_GET['saved']) && !$error): ?>
        <div class="message ok">
            Änderungen an <?= htmlspecialchars($currentFileLabel) ?> wurden gespeichert.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message err">
            Fehler: <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="file-path">
            Datei: <code><?= htmlspecialchars($currentFilePath) ?></code>
        </div>

        <form method="post">
            <input type="hidden" name="file_key" value="<?= htmlspecialchars($currentKey) ?>">

            <textarea name="content"><?= htmlspecialchars($currentContent) ?></textarea>

            <div class="buttons">
                <button type="submit" class="btn-primary">Speichern &amp; Backup anlegen</button>
                <button type="button" class="btn-secondary"
                        onclick="if(confirm('Änderungen verwerfen und Seite neu laden?')) location.reload();">
                    Änderungen verwerfen
                </button>
            </div>

            <div class="hint">
                Hinweis: Vor dem Speichern wird automatisch ein Backup unter
                <code><?= htmlspecialchars($backupDir) ?></code> angelegt (falls möglich).
            </div>
        </form>
    </div>
</div>
</body>
</html>
