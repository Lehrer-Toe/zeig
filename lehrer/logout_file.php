<?php
require_once 'config.php';

// User ausloggen
logoutUser();

// Weiterleitung zum Login mit Nachricht
$_SESSION['flash_message'] = 'Sie wurden erfolgreich abgemeldet.';
$_SESSION['flash_type'] = 'success';

header('Location: index.php');
exit;
?>
