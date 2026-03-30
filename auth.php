<?php
session_start();
if (empty($_SESSION['imapfilter_logged_in'])) {
    header('Location: login.php');
    exit;
}
