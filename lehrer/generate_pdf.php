<?php
// generate_pdf.php - Vereinfachte Version basierend auf funktionierendem Code
require_once '../config.php';

// Lehrer-Zugriff prüfen
if (!isLoggedIn() || $_SESSION['user_type'] !== 'lehrer') {
    die('Zugriff verweigert');
}

$rating_id = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];
$db = getDB();

if (!$rating_id) {
    die('Keine Bewertungs-ID angegeben');
}

try {
    // Erweiterte Abfrage mit Prüfungsfach
    $stmt = $db->prepare("
        SELECT 
            r.*,
            s.first_name as student_firstname,
            s.last_name as student_lastname,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            c.name as class_name,
            u.name as teacher_name,
            sch.name as school_name,
            g.name as project_name,
            g.description as project_description,
            subj.full_name as exam_subject,
            subj.short_name as exam_subject_short,
            GROUP_CONCAT(si.name SEPARATOR '\n') as strengths_list
        FROM ratings r
        JOIN students s ON r.student_id = s.id
        JOIN users u ON r.teacher_id = u.id
        JOIN schools sch ON s.school_id = sch.id
        JOIN groups g ON r.group_id = g.id
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN group_students gs ON gs.group_id = r.group_id AND gs.student_id = r.student_id
        LEFT JOIN subjects subj ON gs.subject_id = subj.id
        LEFT JOIN rating_strengths rs ON r.id = rs.rating_id
        LEFT JOIN strength_items si ON rs.strength_item_id = si.id
        WHERE r.id = ?
        GROUP BY r.id
    ");
    
    $stmt->execute([$rating_id]);
    $rating = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rating) {
        die('Bewertung nicht gefunden');
    }
    
    // Berechtigungsprüfung nach dem Abrufen
    if ($rating['teacher_id'] != $teacher_id) {
        die('Keine Berechtigung - Sie sind nicht der bewertende Lehrer');
    }

    // Dokumentvorlage abrufen
    $stmt = $db->prepare("SELECT * FROM dokumentvorlagen WHERE school_id = ? LIMIT 1");
    $stmt->execute([$school_id]);
    $vorlage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vorlage || !file_exists($vorlage['dateipfad'])) {
        die('Keine Dokumentvorlage gefunden. Bitte laden Sie zuerst eine Vorlage hoch.');
    }

    // Temporäre Kopie der Vorlage erstellen
    $tempFile = tempnam(sys_get_temp_dir(), 'doc_') . '.docx';
    if (!copy($vorlage['dateipfad'], $tempFile)) {
        die('Fehler beim Kopieren der Vorlage');
    }

    // DOCX als ZIP öffnen
    $zip = new ZipArchive();
    if ($zip->open($tempFile) !== TRUE) {
        @unlink($tempFile);
        die('Fehler beim Öffnen der Dokumentvorlage');
    }
    
    // Alle relevanten XML-Dateien (dynamisch suchen)
    $xmlFiles = ['word/document.xml'];
    
    // Header und Footer dynamisch finden
    for ($i = 1; $i <= 10; $i++) {
        if ($zip->locateName("word/header$i.xml") !== false) {
            $xmlFiles[] = "word/header$i.xml";
        }
        if ($zip->locateName("word/footer$i.xml") !== false) {
            $xmlFiles[] = "word/footer$i.xml";
        }
    }
    
    // Schuljahr berechnen
    $currentMonth = (int)date('n');
    $currentYear = (int)date('Y');
    if ($currentMonth >= 8) {
        $schoolYear = $currentYear . '/' . ($currentYear + 1);
    } else {
        $schoolYear = ($currentYear - 1) . '/' . $currentYear;
    }
    
    // Note formatieren
    $gradeText = '';
    $gradeTextLong = '';
    if (!empty($rating['final_grade'])) {
        $grade = (float)$rating['final_grade'];
        $gradeText = number_format($grade, 1, ',', '');
        
        // Note in Textform
        if ($grade < 1.5) {
            $gradeTextLong = 'sehr gut';
        } elseif ($grade < 2.5) {
            $gradeTextLong = 'gut';
        } elseif ($grade < 3.5) {
            $gradeTextLong = 'befriedigend';
        } elseif ($grade < 4.5) {
            $gradeTextLong = 'ausreichend';
        } elseif ($grade < 5.5) {
            $gradeTextLong = 'mangelhaft';
        } else {
            $gradeTextLong = 'ungenügend';
        }
    }
    
    // Datum formatieren
    $monate = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
    ];
    $datumLang = date('j') . '. ' . $monate[(int)date('n')] . ' ' . date('Y');
    
    // Platzhalter vorbereiten (mit sicherem XML-Escape)
    $replacements = [
        // Schülerdaten
        '[Name]' => htmlspecialchars($rating['student_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Schülername]' => htmlspecialchars($rating['student_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Vorname]' => htmlspecialchars($rating['student_firstname'] ?? '', ENT_XML1, 'UTF-8'),
        '[Nachname]' => htmlspecialchars($rating['student_lastname'] ?? '', ENT_XML1, 'UTF-8'),
        
        // Projekt und Prüfung
        '[Projektname]' => htmlspecialchars($rating['project_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Projektbeschreibung]' => htmlspecialchars($rating['project_description'] ?? '', ENT_XML1, 'UTF-8'),
        '[Prüfungsfach]' => htmlspecialchars($rating['exam_subject'] ?? '', ENT_XML1, 'UTF-8'),
        '[Fach]' => htmlspecialchars($rating['exam_subject'] ?? '', ENT_XML1, 'UTF-8'),
        '[Fach Kürzel]' => htmlspecialchars($rating['exam_subject_short'] ?? '', ENT_XML1, 'UTF-8'),
        
        // Bewertungen
        '[Note]' => $gradeText,
        '[Gesamtnote]' => $gradeText,
        '[Note in Textform]' => $gradeTextLong,
        
        // Organisation
        '[Klasse]' => htmlspecialchars($rating['class_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Schule]' => htmlspecialchars($rating['school_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Schulname]' => htmlspecialchars($rating['school_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Schuljahr]' => $schoolYear,
        
        // Personen
        '[Lehrkraft]' => htmlspecialchars($rating['teacher_name'] ?? '', ENT_XML1, 'UTF-8'),
        
        // Datum
        '[Datum]' => date('d.m.Y'),
        '[Datum lang]' => $datumLang,
        
        // Kommentar
        '[Kommentar]' => htmlspecialchars($rating['comment'] ?? '', ENT_XML1, 'UTF-8'),
        '[Bemerkung]' => htmlspecialchars($rating['comment'] ?? '', ENT_XML1, 'UTF-8'),
    ];
    
    // Stärken formatieren mit Aufzählungszeichen
    if (!empty($rating['strengths_list'])) {
        $strengths = explode("\n", $rating['strengths_list']);
        $formattedStrengths = [];
        $isFirst = true;
        foreach ($strengths as $strength) {
            if (trim($strength)) {
                if ($isFirst) {
                    // Erster Eintrag - direkt mit Aufzählungszeichen
                    $formattedStrengths[] = '• ' . htmlspecialchars(trim($strength), ENT_XML1, 'UTF-8');
                    $isFirst = false;
                } else {
                    // Weitere Einträge mit Line Break davor
                    $formattedStrengths[] = '</w:t></w:r><w:r><w:br/></w:r><w:r><w:t>• ' . htmlspecialchars(trim($strength), ENT_XML1, 'UTF-8');
                }
            }
        }
        // Verbinde alle Einträge
        $replacements['[Stärken]'] = implode('', $formattedStrengths);
    } else {
        $replacements['[Stärken]'] = 'Keine besonderen Stärken ausgewählt';
    }
    
    // Custom Mappings aus der Datenbank laden
    $stmt = $db->prepare("
        SELECT platzhalter, datenbank_feld 
        FROM platzhalter_mappings 
        WHERE school_id = ?
    ");
    $stmt->execute([$school_id]);
    $customMappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Custom Mappings anwenden
    foreach ($customMappings as $mapping) {
        $field = $mapping['datenbank_feld'];
        $placeholder = $mapping['platzhalter'];
        
        // Mapping auf die verfügbaren Felder
        $fieldMapping = [
            'student_name' => $rating['student_name'] ?? '',
            'student_firstname' => $rating['student_firstname'] ?? '',
            'student_lastname' => $rating['student_lastname'] ?? '',
            'project_name' => $rating['project_name'] ?? '',
            'exam_subject' => $rating['exam_subject'] ?? '',
            'exam_subject_short' => $rating['exam_subject_short'] ?? '',
            'final_grade' => $gradeText,
            'comment' => $rating['comment'] ?? '',
            'strengths_list' => $replacements['[Stärken]'] ?? '',
            'class_name' => $rating['class_name'] ?? '',
            'school_name' => $rating['school_name'] ?? '',
            'teacher_name' => $rating['teacher_name'] ?? '',
            'current_date' => date('d.m.Y'),
            'current_date_long' => $datumLang,
            'school_year' => $schoolYear
        ];
        
        if (isset($fieldMapping[$field])) {
            $replacements[$placeholder] = htmlspecialchars($fieldMapping[$field], ENT_XML1, 'UTF-8');
        }
    }
    
    // Platzhalter ersetzen
    foreach ($xmlFiles as $xmlFile) {
        if ($zip->locateName($xmlFile) !== false) {
            $content = $zip->getFromName($xmlFile);
            if ($content !== false) {
                
                foreach ($replacements as $placeholder => $value) {
                    // Einfache Ersetzung
                    $content = str_replace($placeholder, $value, $content);
                    
                    // Regex für aufgeteilte Platzhalter (vereinfachte Version)
                    $pattern = '/' . preg_quote($placeholder[0], '/');
                    for ($i = 1; $i < strlen($placeholder) - 1; $i++) {
                        $pattern .= '(?:<[^>]+>)*' . preg_quote($placeholder[$i], '/');
                    }
                    $pattern .= '(?:<[^>]+>)*' . preg_quote($placeholder[strlen($placeholder)-1], '/') . '/';
                    
                    // Nur wenn Muster gefunden wird, ersetzen
                    if (preg_match($pattern, $content)) {
                        $content = preg_replace($pattern, $value, $content);
                    }
                }
                
                // Inhalt zurückschreiben
                $zip->deleteName($xmlFile);
                $zip->addFromString($xmlFile, $content);
            }
        }
    }
    
    $zip->close();
    
    // Ausgabe
    $outputFilename = 'Bewertung_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $rating['student_name'] ?? 'Dokument') . '_' . date('Y-m-d') . '.docx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $outputFilename . '"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    readfile($tempFile);
    @unlink($tempFile);
    
} catch (Exception $e) {
    die('Fehler: ' . $e->getMessage());
}
?>