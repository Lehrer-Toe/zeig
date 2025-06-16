<?php
require_once '../config.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

// Schuldaten laden
$school = getSchoolById($user['school_id']);
if (!$school) {
    die('Schule nicht gefunden.');
}

// Debug-Informationen sammeln
$db = getDB();

echo "<h2>Erweiterte Debug-Informationen für Schüler-Statistik</h2>";
echo "<pre style='background: #1e293b; padding: 1rem; border-radius: 0.5rem;'>";

// 1. Klassen-Übersicht
echo "1. KLASSEN-ÜBERSICHT für Ihre Schule (ID: {$user['school_id']}):\n";

// Prüfen ob classes Tabelle is_active hat
$stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'is_active'");
$stmt->execute();
$classHasIsActive = $stmt->rowCount() > 0;
echo "   - classes Tabelle hat is_active Spalte: " . ($classHasIsActive ? "JA" : "NEIN") . "\n\n";

// Alle Klassen anzeigen
$query = "SELECT c.id, c.name, 
          (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.is_active = 1) as active_students,
          (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) as total_students";
if ($classHasIsActive) {
    $query .= ", c.is_active as class_is_active";
}
$query .= " FROM classes c WHERE c.school_id = ? ORDER BY c.name";

$stmt = $db->prepare($query);
$stmt->execute([$user['school_id']]);
$classes = $stmt->fetchAll();

echo "   Klassen mit Schülerzahlen:\n";
$totalActiveInActiveClasses = 0;
$totalActiveInAllClasses = 0;

foreach ($classes as $class) {
    $isClassActive = !$classHasIsActive || $class['class_is_active'] == 1;
    echo "   - Klasse '{$class['name']}' (ID: {$class['id']}): ";
    echo "{$class['active_students']} aktive Schüler von {$class['total_students']} gesamt";
    if ($classHasIsActive) {
        echo ", Klasse ist " . ($isClassActive ? "AKTIV" : "INAKTIV");
    }
    echo "\n";
    
    $totalActiveInAllClasses += $class['active_students'];
    if ($isClassActive) {
        $totalActiveInActiveClasses += $class['active_students'];
    }
}

echo "\n   SUMMEN:\n";
echo "   - Aktive Schüler in ALLEN Klassen: $totalActiveInAllClasses\n";
if ($classHasIsActive) {
    echo "   - Aktive Schüler nur in AKTIVEN Klassen: $totalActiveInActiveClasses\n";
}

// 2. Dashboard-Statistiken nachstellen
echo "\n2. DASHBOARD-STATISTIKEN (wie sie berechnet werden sollten):\n";

// Direkte Schülerzählung (wie im Dashboard)
$stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE school_id = ? AND is_active = 1");
$stmt->execute([$user['school_id']]);
$directCount = $stmt->fetch()['count'];
echo "   - Direkte Zählung (students WHERE school_id = ? AND is_active = 1): $directCount\n";

// Zählung mit JOIN auf aktive Klassen
if ($classHasIsActive) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM students s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.school_id = ? 
        AND s.is_active = 1 
        AND c.is_active = 1
    ");
    $stmt->execute([$user['school_id']]);
    $joinCount = $stmt->fetch()['count'];
    echo "   - Zählung nur in aktiven Klassen: $joinCount\n";
}

// 3. Mögliche Probleme prüfen
echo "\n3. MÖGLICHE PROBLEME:\n";

// Schüler ohne Klassenzuordnung
$stmt = $db->prepare("
    SELECT COUNT(*) as count 
    FROM students s 
    WHERE s.school_id = ? 
    AND s.is_active = 1 
    AND NOT EXISTS (SELECT 1 FROM classes c WHERE c.id = s.class_id)
");
$stmt->execute([$user['school_id']]);
$orphanStudents = $stmt->fetch()['count'];
echo "   - Schüler ohne gültige Klassenzuordnung: $orphanStudents\n";

// Schüler in Klassen anderer Schulen
$stmt = $db->prepare("
    SELECT COUNT(*) as count 
    FROM students s 
    JOIN classes c ON s.class_id = c.id 
    WHERE s.school_id = ? 
    AND c.school_id != ?
");
$stmt->execute([$user['school_id'], $user['school_id']]);
$wrongSchoolStudents = $stmt->fetch()['count'];
echo "   - Schüler in Klassen anderer Schulen: $wrongSchoolStudents\n";

// 4. Empfehlung
echo "\n4. EMPFEHLUNG:\n";
if ($classHasIsActive && $totalActiveInActiveClasses < $totalActiveInAllClasses) {
    echo "   ⚠️ Es gibt " . ($totalActiveInAllClasses - $totalActiveInActiveClasses) . " aktive Schüler in inaktiven Klassen!\n";
    echo "   → Das Dashboard sollte nur Schüler in aktiven Klassen zählen.\n";
} else {
    echo "   ✅ Alle aktiven Schüler sind in aktiven Klassen.\n";
    echo "   → Die Statistik zeigt die korrekte Anzahl aktiver Schüler.\n";
}

echo "</pre>";

// Link zurück zum Dashboard
echo '<p><a href="dashboard.php" style="color: #3b82f6;">← Zurück zum Dashboard</a></p>';
?>