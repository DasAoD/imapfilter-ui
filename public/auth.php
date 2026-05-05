<?php
session_start();
if (empty($_SESSION['imapfilter_logged_in'])) {
    header('Location: login.php');
    exit;
}
// CSRF-Token sicherstellen
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
