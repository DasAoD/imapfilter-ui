<?php
session_start();

if (empty($_SESSION['imapfilter_logged_in']) || $_SESSION['imapfilter_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
