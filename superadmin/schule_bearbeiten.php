<?php
/**
 * Redirect-Datei für Schule bearbeiten
 * Leitet zur schule_anlegen.php mit Edit-Parameter weiter
 */

require_once '../config.php';

// Superadmin-Zugriff prüfen
requireSuperadmin();

// ID Parameter prüfen
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    redirectWithMessage('dashboard.php', 'Ungültige Schul-ID.', 'error');
}

// Zur Bearbeitungsseite weiterleiten
header('Location: schule_anlegen.php?id=' . (int)$_GET['id']);
exit;
?>