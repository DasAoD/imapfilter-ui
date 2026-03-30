<?php
/**
 * Atomares Schreiben von Dateien.
 * Schreibt in eine Temp-Datei und benennt diese dann atomar um,
 * um korrupte Dateien bei gleichzeitigen Zugriffen zu verhindern.
 *
 * @param string $path    Zielpfad der Datei
 * @param string $content Inhalt
 * @param int    $mode    Dateiberechtigungen (octal)
 * @return bool
 */
function atomic_write(string $path, string $content, int $mode = 0640): bool {
    $dir  = dirname($path);
    $tmp  = $dir . '/' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';

    $fh = @fopen($tmp, 'w');
    if ($fh === false) return false;

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        @unlink($tmp);
        return false;
    }

    $written = fwrite($fh, $content);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    if ($written === false) {
        @unlink($tmp);
        return false;
    }

    chmod($tmp, $mode);

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * JSON atomar schreiben.
 */
function atomic_write_json(string $path, mixed $data, int $mode = 0640): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    return atomic_write($path, $json, $mode);
}
