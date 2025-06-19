<?php
/**
 * Schüler-Funktionen für CSV-Upload
 */

// Nur definieren wenn die Funktion noch nicht existiert
if (!function_exists('parseCSVFile')) {
    function parseCSVFile($filePath, $nameFormat = 'firstname_lastname') {
        $students = [];
        
        // Datei öffnen
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            // BOM entfernen falls vorhanden
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            while (($data = fgetcsv($handle, 1000)) !== FALSE) {
                if (empty($data[0])) continue;
                
                // Eine Spalte = vollständiger Name
                if (count($data) == 1) {
                    $fullName = trim($data[0]);
                    $nameParts = parseStudentName($fullName, $nameFormat);
                    if ($nameParts) {
                        $students[] = $nameParts;
                    }
                }
                // Zwei Spalten = Vor- und Nachname getrennt
                elseif (count($data) >= 2) {
                    $firstName = trim($data[0]);
                    $lastName = trim($data[1]);
                    
                    if ($nameFormat === 'lastname_firstname') {
                        $temp = $firstName;
                        $firstName = $lastName;
                        $lastName = $temp;
                    }
                    
                    if (!empty($firstName) && !empty($lastName)) {
                        $students[] = [
                            'first_name' => $firstName,
                            'last_name' => $lastName
                        ];
                    }
                }
            }
            fclose($handle);
        }
        
        return $students;
    }
}

if (!function_exists('parseStudentName')) {
    function parseStudentName($fullName, $format = 'firstname_lastname') {
        $fullName = trim($fullName);
        if (empty($fullName)) return null;
        
        // Komma-getrennt
        if (strpos($fullName, ',') !== false) {
            $parts = array_map('trim', explode(',', $fullName, 2));
            return [
                'first_name' => $parts[1] ?? '',
                'last_name' => $parts[0]
            ];
        }
        
        // Leerzeichen-getrennt
        $parts = preg_split('/\s+/', $fullName, 2);
        
        if (count($parts) == 2) {
            if ($format === 'firstname_lastname') {
                return [
                    'first_name' => $parts[0],
                    'last_name' => $parts[1]
                ];
            } else {
                return [
                    'first_name' => $parts[1],
                    'last_name' => $parts[0]
                ];
            }
        }
        
        // Nur ein Teil - als Nachname verwenden
        return [
            'first_name' => '',
            'last_name' => $fullName
        ];
    }
}

// Diese Funktionen sind bereits in db.php definiert, daher nicht nochmal definieren
// getClassStudentCount() - bereits in db.php
// canAddStudentToClass() - bereits in db.php

// Zusätzliche spezifische Funktionen für Schüler-Upload
if (!function_exists('parseCSVFileSimple')) {
    function parseCSVFileSimple($filePath, $nameFormat = 'firstname_lastname') {
        $students = [];
        
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception('Datei konnte nicht gelesen werden.');
        }
        
        $content = file_get_contents($filePath);
        if ($content === false || empty($content)) {
            throw new Exception('Datei ist leer oder konnte nicht gelesen werden.');
        }
        
        // BOM entfernen falls vorhanden
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        
        // Zeilen aufteilen
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Wenn die Zeile keine Trennzeichen enthält, nehmen wir die ganze Zeile als Namen
            if (!strpos($line, ',') && !strpos($line, ';') && !strpos($line, "\t")) {
                // Direkt als Name verwenden
                $nameData = parseStudentName($line, $nameFormat);
                if ($nameData) {
                    $students[] = $nameData;
                }
            } else {
                // Mit Trennzeichen parsen
                $possibleDelimiters = [',', ';', "\t"];
                $delimiter = null;
                $maxParts = 0;
                
                // Besten Delimiter finden
                foreach ($possibleDelimiters as $delim) {
                    $parts = explode($delim, $line);
                    if (count($parts) > $maxParts) {
                        $maxParts = count($parts);
                        $delimiter = $delim;
                    }
                }
                
                if ($delimiter) {
                    $parts = array_map('trim', explode($delimiter, $line));
                    
                    if (count($parts) >= 2) {
                        $firstName = $parts[0];
                        $lastName = $parts[1];
                        
                        if ($nameFormat === 'lastname_firstname') {
                            $temp = $firstName;
                            $firstName = $lastName;
                            $lastName = $temp;
                        }
                        
                        if (!empty($firstName) && !empty($lastName)) {
                            $students[] = [
                                'first_name' => $firstName,
                                'last_name' => $lastName
                            ];
                        }
                    } elseif (count($parts) === 1 && !empty($parts[0])) {
                        $nameData = parseStudentName($parts[0], $nameFormat);
                        if ($nameData) {
                            $students[] = $nameData;
                        }
                    }
                }
            }
        }
        
        return $students;
    }
}

if (!function_exists('getCurrentStudentCount')) {
    function getCurrentStudentCount($classId) {
        // Wrapper für getClassStudentCount aus db.php
        return getClassStudentCount($classId);
    }
}

if (!function_exists('ensureStudentsTable')) {
    function ensureStudentsTable() {
        $db = getDB();
        
        try {
            // Prüfen ob Students-Tabelle existiert
            $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                // Minimale Tabelle erstellen
                $sql = "
                    CREATE TABLE students (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        class_id INT NOT NULL,
                        school_id INT NOT NULL,
                        first_name VARCHAR(100) NOT NULL,
                        last_name VARCHAR(100) NOT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_class (class_id),
                        INDEX idx_school (school_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ";
                $db->exec($sql);
            }
        } catch (Exception $e) {
            error_log("Error creating students table: " . $e->getMessage());
            throw new Exception("Fehler beim Erstellen der Schüler-Tabelle");
        }
    }
}

if (!function_exists('insertStudentsSimple')) {
    function insertStudentsSimple($classId, $schoolId, $students) {
        $db = getDB();
        $insertedCount = 0;
        
        try {
            $stmt = $db->prepare("
                INSERT INTO students (class_id, school_id, first_name, last_name) 
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($students as $student) {
                // Bei leerem Vornamen einen Standard setzen
                $firstName = !empty($student['first_name']) ? $student['first_name'] : '-';
                $lastName = $student['last_name'];
                
                if (empty($lastName)) {
                    continue;
                }
                
                if ($stmt->execute([$classId, $schoolId, $firstName, $lastName])) {
                    $insertedCount++;
                }
            }
            
            return $insertedCount;
            
        } catch (Exception $e) {
            error_log("Error inserting students: " . $e->getMessage());
            throw new Exception("Fehler beim Speichern der Schüler");
        }
    }
}
?>