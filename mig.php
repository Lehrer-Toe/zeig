<?php
require_once 'config.php';

// Diese Datei kann ausgeführt werden, um die Fächer-Tabelle zu erstellen
// Führen Sie sie einmalig aus: php migrate_subjects.php

try {
    // Prüfen, ob die Tabelle bereits existiert
    $check_table = "SHOW TABLES LIKE 'subjects'";
    $result = $conn->query($check_table);
    
    if ($result->num_rows == 0) {
        // Tabelle für Fächer erstellen
        $create_subjects = "CREATE TABLE `subjects` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `school_id` int(11) NOT NULL,
            `short_name` varchar(10) NOT NULL,
            `full_name` varchar(100) NOT NULL,
            `color` varchar(7) NOT NULL DEFAULT '#000000',
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school_id` (`school_id`),
            KEY `idx_active` (`is_active`),
            UNIQUE KEY `unique_school_subject` (`school_id`, `short_name`),
            CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        if ($conn->query($create_subjects) === TRUE) {
            echo "Tabelle 'subjects' erfolgreich erstellt.\n";
        } else {
            throw new Exception("Fehler beim Erstellen der Tabelle 'subjects': " . $conn->error);
        }
    } else {
        echo "Tabelle 'subjects' existiert bereits.\n";
    }
    
    // Prüfen, ob die Tabelle group_subjects bereits existiert
    $check_table2 = "SHOW TABLES LIKE 'group_subjects'";
    $result2 = $conn->query($check_table2);
    
    if ($result2->num_rows == 0) {
        // Tabelle für Fach-Zuweisungen erstellen
        $create_group_subjects = "CREATE TABLE `group_subjects` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `group_id` int(11) NOT NULL,
            `subject_id` int(11) NOT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_group_subject` (`group_id`, `subject_id`),
            KEY `idx_group_id` (`group_id`),
            KEY `idx_subject_id` (`subject_id`),
            CONSTRAINT `group_subjects_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
            CONSTRAINT `group_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        if ($conn->query($create_group_subjects) === TRUE) {
            echo "Tabelle 'group_subjects' erfolgreich erstellt.\n";
        } else {
            throw new Exception("Fehler beim Erstellen der Tabelle 'group_subjects': " . $conn->error);
        }
    } else {
        echo "Tabelle 'group_subjects' existiert bereits.\n";
    }
    
    // Standard-Fächer für alle existierenden Schulen einfügen
    $schools_query = "SELECT id FROM schools WHERE is_active = 1";
    $schools_result = $conn->query($schools_query);
    
    $default_subjects = [
        ['AES', 'AES', '#FF6B6B'],
        ['BIO', 'Biologie', '#4ECDC4'],
        ['BK', 'Bildende Kunst', '#45B7D1'],
        ['CH', 'Chemie', '#96CEB4'],
        ['D', 'Deutsch', '#DDA0DD'],
        ['E', 'Englisch', '#FFD93D'],
        ['ETH', 'Ethik', '#6C88C4'],
        ['FR', 'Französisch', '#FF8C42'],
        ['G', 'Geschichte', '#A0522D'],
        ['GK', 'Gemeinschaftskunde', '#20B2AA'],
        ['IT', 'Informatik', '#5D3FD3'],
        ['M', 'Mathematik', '#FF1744'],
        ['PH', 'Physik', '#00BCD4'],
        ['REL', 'Religion', '#FFB6C1'],
        ['SP', 'Sport', '#32CD32'],
        ['T', 'Technik', '#708090']
    ];
    
    while ($school = $schools_result->fetch_assoc()) {
        $school_id = $school['id'];
        
        // Prüfen, ob bereits Fächer für diese Schule existieren
        $check_subjects = "SELECT COUNT(*) as count FROM subjects WHERE school_id = ?";
        $stmt = $conn->prepare($check_subjects);
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['count'] == 0) {
            // Standard-Fächer einfügen
            $insert_sql = "INSERT INTO subjects (school_id, short_name, full_name, color) VALUES (?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            
            foreach ($default_subjects as $subject) {
                $insert_stmt->bind_param("isss", $school_id, $subject[0], $subject[1], $subject[2]);
                $insert_stmt->execute();
            }
            
            echo "Standard-Fächer für Schule ID $school_id eingefügt.\n";
        } else {
            echo "Schule ID $school_id hat bereits Fächer.\n";
        }
    }
    
    echo "\nMigration erfolgreich abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
}

$conn->close();
?>