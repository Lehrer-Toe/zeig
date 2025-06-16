<?php
session_start();

// Session-Daten löschen
$_SESSION = array();

// Session-Cookie löschen
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Session beenden
session_destroy();

// Zur Login-Seite weiterleiten
header('Location: index.php');
exit();
?>