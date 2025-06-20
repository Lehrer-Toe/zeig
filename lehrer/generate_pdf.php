<?php
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
    // Flexiblere Abfrage - ohne strikte Berechtigungsprüfung in der SQL
    $stmt = $db->prepare("
        SELECT 
            r.*,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            c.name as class_name,
            u.name as teacher_name,
            sch.name as school_name,
            g.name as project_name,
            GROUP_CONCAT(si.name SEPARATOR '\n') as strengths_list
        FROM ratings r
        JOIN students s ON r.student_id = s.id
        JOIN users u ON r.teacher_id = u.id
        JOIN schools sch ON s.school_id = sch.id
        JOIN groups g ON r.group_id = g.id
        JOIN classes c ON s.class_id = c.id
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

    // Dokumentvorlage abrufen - erst für die Schule des Lehrers, dann allgemein
    $stmt = $db->prepare("SELECT * FROM dokumentvorlagen WHERE school_id = ? LIMIT 1");
    $stmt->execute([$school_id]);
    $vorlage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Falls keine schulspezifische Vorlage, versuche allgemeine Vorlage
    if (!$vorlage) {
        $stmt = $db->prepare("SELECT * FROM dokumentvorlagen ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $vorlage = $stmt->fetch(PDO::FETCH_ASSOC);
    }

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
    
    // Alle relevanten XML-Dateien
    $xmlFiles = [
        'word/document.xml',
        'word/header1.xml',
        'word/header2.xml', 
        'word/header3.xml',
        'word/footer1.xml',
        'word/footer2.xml',
        'word/footer3.xml'
    ];
    
    // Platzhalter vorbereiten
    $replacements = [
        '[Name]' => htmlspecialchars($rating['student_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Projektname]' => htmlspecialchars($rating['project_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Note]' => isset($rating['final_grade']) ? number_format((float)$rating['final_grade'], 1, ',', '') : '',
        '[Lehrkraft]' => htmlspecialchars($rating['teacher_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Datum]' => date('d.m.Y'),
        '[Klasse]' => htmlspecialchars($rating['class_name'] ?? '', ENT_XML1, 'UTF-8'),
        '[Schule]' => htmlspecialchars($rating['school_name'] ?? '', ENT_XML1, 'UTF-8')
    ];
    
    // Stärken formatieren
    if (!empty($rating['strengths_list'])) {
        $strengths = explode("\n", $rating['strengths_list']);
        $formattedStrengths = [];
        foreach ($strengths as $strength) {
            if (trim($strength)) {
                $formattedStrengths[] = '• ' . htmlspecialchars(trim($strength), ENT_XML1, 'UTF-8');
            }
        }
        $replacements['[Stärken]'] = implode('</w:t><w:br/></w:r><w:r><w:t>', $formattedStrengths);
    } else {
        $replacements['[Stärken]'] = 'Keine besonderen Stärken ausgewählt';
    }
    
    // Kommentar
    $replacements['[Kommentar]'] = htmlspecialchars($rating['comment'] ?? '', ENT_XML1, 'UTF-8');
    
    // Platzhalter ersetzen
    foreach ($xmlFiles as $xmlFile) {
        if ($zip->locateName($xmlFile) !== false) {
            $content = $zip->getFromName($xmlFile);
            if ($content !== false) {
                foreach ($replacements as $placeholder => $value) {
                    // Einfache Ersetzung
                    $content = str_replace($placeholder, $value, $content);
                    
                    // Regex für aufgeteilte Platzhalter
                    $pattern = '/' . preg_quote($placeholder[0], '/');
                    for ($i = 1; $i < strlen($placeholder) - 1; $i++) {
                        $pattern .= '(<[^>]+>)*' . preg_quote($placeholder[$i], '/');
                    }
                    $pattern .= '(<[^>]+>)*' . preg_quote($placeholder[strlen($placeholder)-1], '/') . '/';
                    $content = preg_replace($pattern, $value, $content);
                }
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