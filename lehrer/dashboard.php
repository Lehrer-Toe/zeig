<?php
require_once '../config.php';

// Lehrer-Zugriff prüfen
if (!isLoggedIn() || $_SESSION['user_type'] !== 'lehrer') {
    header('Location: ../index.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? $_GET['page'] : 'news';

// Schul-Lizenz prüfen
if (isset($_SESSION['school_id'])) {
    requireValidSchoolLicense($_SESSION['school_id']);
}

// CSRF-Token generieren falls nicht vorhanden

$db = getDB();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// === THEMEN-FORMULARVERARBEITUNG (VOR HTML-AUSGABE!) ===
if ($page === 'themen' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $db = getDB();
    $school_id = $_SESSION['school_id'];
    
    try {
        // CSRF-Token prüfen
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Ungültiger CSRF-Token');
        }
        
        switch ($_POST['form_action']) {
            case 'create_topic':
                $title = trim($_POST['title'] ?? '');
                $short_description = trim($_POST['short_description'] ?? '');
                $is_global = isset($_POST['is_global']) ? 1 : 0;
                $subject_ids = $_POST['subjects'] ?? [];
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich.');
                }
                
                if (mb_strlen($short_description) > 240) {
                    throw new Exception('Kurzbeschreibung darf maximal 240 Zeichen haben.');
                }
                
                $db->beginTransaction();
                
                // Thema erstellen
                $stmt = $db->prepare("INSERT INTO topics (school_id, teacher_id, title, description, short_description, is_global, created_at, updated_at) VALUES (?, ?, ?, '', ?, ?, NOW(), NOW())");
                $stmt->execute([$school_id, $teacher_id, $title, $short_description, $is_global]);
                $new_topic_id = $db->lastInsertId();
                
                // Fächer zuordnen
                if (!empty($subject_ids) && is_array($subject_ids)) {
                    $stmt = $db->prepare("INSERT INTO topic_subjects (topic_id, subject_id) VALUES (?, ?)");
                    foreach ($subject_ids as $subject_id) {
                        $stmt->execute([$new_topic_id, (int)$subject_id]);
                    }
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Thema erfolgreich erstellt.';
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'update_topic':
                $topic_id = (int)($_POST['topic_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $short_description = trim($_POST['short_description'] ?? '');
                $is_global = isset($_POST['is_global']) ? 1 : 0;
                $subject_ids = $_POST['subjects'] ?? [];
                
                if ($topic_id <= 0) {
                    throw new Exception('Ungültige Thema-ID.');
                }
                
                if (empty($title)) {
                    throw new Exception('Titel ist erforderlich.');
                }
                
                // Prüfen ob Thema dem Lehrer gehört
                $stmt = $db->prepare("SELECT id FROM topics WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1");
                $stmt->execute([$topic_id, $teacher_id, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Thema nicht gefunden oder keine Berechtigung.');
                }
                
                $db->beginTransaction();
                
                // Thema aktualisieren
                $stmt = $db->prepare("UPDATE topics SET title = ?, short_description = ?, is_global = ?, updated_at = NOW() WHERE id = ? AND teacher_id = ? AND school_id = ?");
                $stmt->execute([$title, $short_description, $is_global, $topic_id, $teacher_id, $school_id]);
                
                // Alte Fachzuordnungen löschen
                $stmt = $db->prepare("DELETE FROM topic_subjects WHERE topic_id = ?");
                $stmt->execute([$topic_id]);
                
                // Neue Fächer zuordnen
                if (!empty($subject_ids) && is_array($subject_ids)) {
                    $stmt = $db->prepare("INSERT INTO topic_subjects (topic_id, subject_id) VALUES (?, ?)");
                    foreach ($subject_ids as $subject_id) {
                        $stmt->execute([$topic_id, (int)$subject_id]);
                    }
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Thema erfolgreich aktualisiert.';
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'delete_topic':
                $topic_id = (int)($_POST['topic_id'] ?? 0);
                
                if ($topic_id <= 0) {
                    throw new Exception('Ungültige Thema-ID.');
                }
                
                // Prüfen ob Thema dem Lehrer gehört
                $stmt = $db->prepare("SELECT title FROM topics WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1");
                $stmt->execute([$topic_id, $teacher_id, $school_id]);
                $topic = $stmt->fetch();
                if (!$topic) {
                    throw new Exception('Thema nicht gefunden oder keine Berechtigung.');
                }
                
                // Soft delete
                $stmt = $db->prepare("UPDATE topics SET is_active = 0, updated_at = NOW() WHERE id = ? AND teacher_id = ? AND school_id = ?");
                $stmt->execute([$topic_id, $teacher_id, $school_id]);
                
                $_SESSION['flash_message'] = "Thema '{$topic['title']}' erfolgreich gelöscht.";
                $_SESSION['flash_type'] = 'success';
                break;
        }
        
        // Redirect zur gleichen Seite mit allen Parametern
        $redirect_url = $_SERVER['PHP_SELF'] . '?page=themen';
        if (isset($_GET['filter'])) $redirect_url .= '&filter=' . $_GET['filter'];
        if (isset($_GET['sort'])) $redirect_url .= '&sort=' . $_GET['sort'];
        if (isset($_GET['subject'])) $redirect_url .= '&subject=' . $_GET['subject'];
        
        header('Location: ' . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        
        // Bei Fehler auch redirect mit allen Parametern
        $redirect_url = $_SERVER['PHP_SELF'] . '?page=themen';
        if (isset($_GET['filter'])) $redirect_url .= '&filter=' . $_GET['filter'];
        if (isset($_GET['sort'])) $redirect_url .= '&sort=' . $_GET['sort'];
        if (isset($_GET['subject'])) $redirect_url .= '&subject=' . $_GET['subject'];
        
        header('Location: ' . $redirect_url);
        exit();
    }
}

// === GRUPPEN-FORMULARVERARBEITUNG (VOR HTML-AUSGABE!) ===
if ($page === 'gruppen' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $db = getDB();
    $school_id = $_SESSION['school_id'];
    
    try {
        // CSRF-Token prüfen
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Ungültiger CSRF-Token');
        }
        
        switch ($_POST['form_action']) {
            case 'create_group':
                // Berechtigung prüfen
                if (!$_SESSION['can_create_groups']) {
                    throw new Exception('Sie haben keine Berechtigung, Gruppen zu erstellen.');
                }
                
                $topic = trim($_POST['topic'] ?? '');
                $class_id = (int)($_POST['class_id'] ?? 0);
                $students = $_POST['students'] ?? [];
                $examiners = $_POST['examiner'] ?? [];
                $subjects = $_POST['subject'] ?? [];
                
                if (empty($topic)) {
                    throw new Exception('Thema ist erforderlich.');
                }
                
                if ($class_id <= 0) {
                    throw new Exception('Bitte wählen Sie eine Klasse aus.');
                }
                
                if (empty($students)) {
                    throw new Exception('Bitte wählen Sie mindestens einen Schüler aus.');
                }
                
                if (count($students) > 4) {
                    throw new Exception('Maximal 4 Schüler pro Gruppe erlaubt.');
                }
                
                $db->beginTransaction();
                
                // Gruppe erstellen
                $stmt = $db->prepare("
                    INSERT INTO groups (school_id, class_id, name, teacher_id, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$school_id, $class_id, $topic, $teacher_id]);
                $group_id = $db->lastInsertId();
                
                // Schüler zur Gruppe hinzufügen
                $stmt = $db->prepare("
                    INSERT INTO group_students (group_id, student_id, assigned_by, examiner_teacher_id, subject_id) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($students as $student_id) {
                    $examiner_id = isset($examiners[$student_id]) ? (int)$examiners[$student_id] : null;
                    $subject_id = isset($subjects[$student_id]) ? (int)$subjects[$student_id] : null;
                    
                    $stmt->execute([$group_id, $student_id, $teacher_id, $examiner_id, $subject_id]);
                }
                
                // Letzte ausgewählte Klasse speichern
                try {
                    $stmt = $db->prepare("UPDATE users SET last_selected_class_id = ? WHERE id = ?");
                    $stmt->execute([$class_id, $teacher_id]);
                } catch (PDOException $e) {
                    // Spalte existiert möglicherweise nicht - ignorieren
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Gruppe erfolgreich erstellt.';
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'update_group':
                $group_id = (int)($_POST['group_id'] ?? 0);
                $topic = trim($_POST['topic'] ?? '');
                $examiners = $_POST['examiner'] ?? [];
                $subjects = $_POST['subject'] ?? [];
                
                if ($group_id <= 0) {
                    throw new Exception('Ungültige Gruppen-ID.');
                }
                
                if (empty($topic)) {
                    throw new Exception('Thema ist erforderlich.');
                }
                
                // Prüfen ob Gruppe bearbeitet werden darf
                $stmt = $db->prepare("
                    SELECT id FROM groups 
                    WHERE id = ? AND school_id = ? AND is_active = 1 
                    AND (teacher_id = ? OR EXISTS (
                        SELECT 1 FROM group_students gs 
                        WHERE gs.group_id = groups.id AND gs.examiner_teacher_id = ?
                    ))
                ");
                $stmt->execute([$group_id, $school_id, $teacher_id, $teacher_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Gruppe nicht gefunden oder keine Berechtigung.');
                }
                
                $db->beginTransaction();
                
                // Gruppenname aktualisieren
                $stmt = $db->prepare("UPDATE groups SET name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$topic, $group_id]);
                
                // Schülerzuweisungen aktualisieren
                foreach ($examiners as $student_id => $examiner_id) {
                    $subject_id = isset($subjects[$student_id]) ? (int)$subjects[$student_id] : null;
                    $examiner_id = $examiner_id ? (int)$examiner_id : null;
                    
                    $stmt = $db->prepare("
                        UPDATE group_students 
                        SET examiner_teacher_id = ?, subject_id = ?
                        WHERE group_id = ? AND student_id = ?
                    ");
                    $stmt->execute([$examiner_id, $subject_id, $group_id, $student_id]);
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Gruppe erfolgreich aktualisiert.';
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'delete_group':
                // Berechtigung prüfen
                if (!$_SESSION['can_create_groups']) {
                    throw new Exception('Sie haben keine Berechtigung, Gruppen zu löschen.');
                }
                
                $group_id = (int)($_POST['group_id'] ?? 0);
                
                if ($group_id <= 0) {
                    throw new Exception('Ungültige Gruppen-ID.');
                }
                
                // Prüfen ob Gruppe gelöscht werden darf
                $stmt = $db->prepare("
                    SELECT name FROM groups 
                    WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1
                ");
                $stmt->execute([$group_id, $teacher_id, $school_id]);
                $group = $stmt->fetch();
                if (!$group) {
                    throw new Exception('Gruppe nicht gefunden oder keine Berechtigung.');
                }
                
                // Soft delete
                $stmt = $db->prepare("UPDATE groups SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$group_id]);
                
                $_SESSION['flash_message'] = "Gruppe '{$group['name']}' erfolgreich gelöscht.";
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'remove_student':
                $group_id = (int)($_POST['group_id'] ?? 0);
                $student_id = (int)($_POST['student_id'] ?? 0);
                
                if ($group_id <= 0 || $student_id <= 0) {
                    throw new Exception('Ungültige Daten.');
                }
                
                // Prüfen ob Berechtigung vorhanden
                $stmt = $db->prepare("
                    SELECT id FROM groups 
                    WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1
                ");
                $stmt->execute([$group_id, $teacher_id, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Keine Berechtigung für diese Aktion.');
                }
                
                // Schüler aus Gruppe entfernen
                $stmt = $db->prepare("DELETE FROM group_students WHERE group_id = ? AND student_id = ?");
                $stmt->execute([$group_id, $student_id]);
                
                $_SESSION['flash_message'] = 'Schüler erfolgreich aus der Gruppe entfernt.';
                $_SESSION['flash_type'] = 'success';
                break;
        }
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=gruppen');
        exit();
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=gruppen');
        exit();
    }
}

// === VORLAGEN-FORMULARVERARBEITUNG (VOR HTML-AUSGABE!) ===
if ($page === 'vorlagen' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $db = getDB();
    
    try {
        // CSRF-Token prüfen
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Ungültiger CSRF-Token');
        }
        
        switch ($_POST['form_action']) {
            case 'create_template':
                $template_name = trim($_POST['template_name'] ?? '');
                
                if (empty($template_name)) {
                    throw new Exception('Vorlagenname ist erforderlich.');
                }
                
                // Prüfen ob maximale Anzahl erreicht
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM rating_templates 
                    WHERE teacher_id = ? AND is_standard = 0 AND is_active = 1
                ");
                $stmt->execute([$teacher_id]);
                $count = $stmt->fetchColumn();
                
                if ($count >= 10) {
                    throw new Exception('Sie haben bereits die maximale Anzahl von 10 benutzerdefinierten Vorlagen erreicht.');
                }
                
                $db->beginTransaction();
                
                // Vorlage erstellen
                $stmt = $db->prepare("
                    INSERT INTO rating_templates (teacher_id, name, is_standard, is_active, created_at) 
                    VALUES (?, ?, 0, 1, NOW())
                ");
                $stmt->execute([$teacher_id, $template_name]);
                $template_id = $db->lastInsertId();
                
                // Reflexion als Standardkategorie hinzufügen
                $stmt = $db->prepare("
                    INSERT INTO rating_template_categories (template_id, name, weight, display_order) 
                    VALUES (?, 'Reflexion', 30, 1)
                ");
                $stmt->execute([$template_id]);
                
                $db->commit();
                $_SESSION['flash_message'] = 'Vorlage erfolgreich erstellt.';
                $_SESSION['flash_type'] = 'success';
                
                // Direkt zur Bearbeitung weiterleiten
                header('Location: ' . $_SERVER['PHP_SELF'] . '?page=vorlagen&edit=' . $template_id);
                exit();
                break;
                
            case 'add_category':
                $template_id = (int)($_POST['template_id'] ?? 0);
                $category_name = trim($_POST['category_name'] ?? '');
                $weight = (int)($_POST['weight'] ?? 0);
                
                if ($template_id <= 0) {
                    throw new Exception('Ungültige Vorlagen-ID.');
                }
                
                if (empty($category_name)) {
                    throw new Exception('Kategoriename ist erforderlich.');
                }
                
                if ($weight < 1 || $weight > 70) {
                    throw new Exception('Gewichtung muss zwischen 1 und 70 liegen.');
                }
                
                // Prüfen ob Vorlage dem Lehrer gehört
                $stmt = $db->prepare("
                    SELECT id FROM rating_templates 
                    WHERE id = ? AND teacher_id = ? AND is_standard = 0 AND is_active = 1
                ");
                $stmt->execute([$template_id, $teacher_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Vorlage nicht gefunden oder keine Berechtigung.');
                }
                
                // Aktuelle Gesamtgewichtung prüfen
                $stmt = $db->prepare("
                    SELECT SUM(weight) FROM rating_template_categories 
                    WHERE template_id = ?
                ");
                $stmt->execute([$template_id]);
                $current_weight = (int)$stmt->fetchColumn();
                
                if ($current_weight + $weight > 100) {
                    throw new Exception('Die Gesamtgewichtung würde 100% überschreiten.');
                }
                
                // Nächste display_order bestimmen
                $stmt = $db->prepare("
                    SELECT MAX(display_order) FROM rating_template_categories 
                    WHERE template_id = ?
                ");
                $stmt->execute([$template_id]);
                $max_order = (int)$stmt->fetchColumn();
                
                // Kategorie hinzufügen
                $stmt = $db->prepare("
                    INSERT INTO rating_template_categories (template_id, name, weight, display_order) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$template_id, $category_name, $weight, $max_order + 1]);
                
                $_SESSION['flash_message'] = 'Kategorie erfolgreich hinzugefügt.';
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'delete_category':
                $category_id = (int)($_POST['category_id'] ?? 0);
                $template_id = (int)($_POST['template_id'] ?? 0);
                
                if ($category_id <= 0 || $template_id <= 0) {
                    throw new Exception('Ungültige Daten.');
                }
                
                // Prüfen ob Kategorie zur Vorlage des Lehrers gehört
                $stmt = $db->prepare("
                    SELECT c.name FROM rating_template_categories c
                    JOIN rating_templates t ON c.template_id = t.id
                    WHERE c.id = ? AND t.id = ? AND t.teacher_id = ? 
                    AND t.is_standard = 0 AND t.is_active = 1
                    AND c.name != 'Reflexion'
                ");
                $stmt->execute([$category_id, $template_id, $teacher_id]);
                $category = $stmt->fetch();
                
                if (!$category) {
                    throw new Exception('Kategorie nicht gefunden oder kann nicht gelöscht werden.');
                }
                
                // Kategorie löschen
                $stmt = $db->prepare("DELETE FROM rating_template_categories WHERE id = ?");
                $stmt->execute([$category_id]);
                
                $_SESSION['flash_message'] = "Kategorie '{$category['name']}' erfolgreich entfernt.";
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'delete_template':
                $template_id = (int)($_POST['template_id'] ?? 0);
                
                if ($template_id <= 0) {
                    throw new Exception('Ungültige Vorlagen-ID.');
                }
                
                // Prüfen ob Vorlage dem Lehrer gehört
                $stmt = $db->prepare("
                    SELECT name FROM rating_templates 
                    WHERE id = ? AND teacher_id = ? AND is_standard = 0 AND is_active = 1
                ");
                $stmt->execute([$template_id, $teacher_id]);
                $template = $stmt->fetch();
                
                if (!$template) {
                    throw new Exception('Vorlage nicht gefunden oder keine Berechtigung.');
                }
                
                // Soft delete
                $stmt = $db->prepare("UPDATE rating_templates SET is_active = 0 WHERE id = ?");
                $stmt->execute([$template_id]);
                
                $_SESSION['flash_message'] = "Vorlage '{$template['name']}' erfolgreich gelöscht.";
                $_SESSION['flash_type'] = 'success';
                break;
        }
        
        // Redirect mit edit-Parameter falls vorhanden
        $redirect_url = $_SERVER['PHP_SELF'] . '?page=vorlagen';
        if (isset($_POST['template_id']) && $_POST['form_action'] !== 'delete_template') {
            $redirect_url .= '&edit=' . $_POST['template_id'];
        }
        
        header('Location: ' . $redirect_url);
        exit();
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        
        $redirect_url = $_SERVER['PHP_SELF'] . '?page=vorlagen';
        if (isset($_POST['template_id'])) {
            $redirect_url .= '&edit=' . $_POST['template_id'];
        }
        
        header('Location: ' . $redirect_url);
        exit();
    }
}

// === BEWERTUNGS-FORMULARVERARBEITUNG (VOR HTML-AUSGABE!) ===
if ($page === 'bewerten' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $db = getDB();
    
    try {
        // CSRF-Token prüfen
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Ungültiger CSRF-Token');
        }
        
        switch ($_POST['action']) {
            case 'save_rating':
                $student_id = (int)$_POST['student_id'];
                $group_id = (int)$_POST['group_id'];
                $template_id = (int)$_POST['template_id'];
                $final_grade = isset($_POST['final_grade']) && $_POST['final_grade'] !== '' ? 
                               (float)$_POST['final_grade'] : null;
                $category_ratings = $_POST['category_rating'] ?? [];
                
                // Berechtigung prüfen
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM group_students 
                    WHERE student_id = ? AND group_id = ? AND examiner_teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Keine Berechtigung für diese Aktion.');
                }
                
                $db->beginTransaction();
                
                // Prüfen ob bereits eine Bewertung existiert
                $stmt = $db->prepare("
                    SELECT id FROM ratings 
                    WHERE student_id = ? AND group_id = ? AND teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                $rating_id = $stmt->fetchColumn();
                
                // criteria_id wird nicht mehr verwendet, setze auf 1 als Platzhalter
                $criteria_id = 1; // Dummy-Wert für Legacy-Spalte
                $points = 0; // Dummy-Wert für Legacy-Spalte
                
                if ($rating_id) {
                    // Update bestehende Bewertung
                    $stmt = $db->prepare("
                        UPDATE ratings 
                        SET template_id = ?, criteria_id = ?, points = ?, final_grade = ?, 
                            is_complete = ?, rating_date = CURDATE(), updated_at = NOW()
                        WHERE id = ?
                    ");
                    $is_complete = !empty($category_ratings) && $final_grade !== null ? 1 : 0;
                    $stmt->execute([$template_id, $criteria_id, $points, $final_grade, 
                                   $is_complete, $rating_id]);
                } else {
                    // Neue Bewertung erstellen
                    $stmt = $db->prepare("
                        INSERT INTO ratings (student_id, teacher_id, group_id, template_id, 
                                           criteria_id, points, final_grade, is_complete, 
                                           rating_date, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())
                    ");
                    $is_complete = !empty($category_ratings) && $final_grade !== null ? 1 : 0;
                    $stmt->execute([$student_id, $teacher_id, $group_id, $template_id, 
                                  $criteria_id, $points, $final_grade, $is_complete]);
                    $rating_id = $db->lastInsertId();
                }
                
                // Alte Kategorie-Bewertungen löschen
                $stmt = $db->prepare("DELETE FROM rating_categories WHERE rating_id = ?");
                $stmt->execute([$rating_id]);
                
                // Neue Kategorie-Bewertungen speichern
                if (!empty($category_ratings)) {
                    $stmt = $db->prepare("
                        INSERT INTO rating_categories (rating_id, category_id, points)
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($category_ratings as $category_id => $points) {
                        if ($points !== '') {
                            $stmt->execute([$rating_id, (int)$category_id, (float)$points]);
                        }
                    }
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Bewertung erfolgreich gespeichert.';
                $_SESSION['flash_type'] = 'success';
                
                // Redirect je nach Button
                if (isset($_POST['save_and_close'])) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=bewerten');
                } else {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?page=bewerten&rate=' . 
                           $student_id . '&group=' . $group_id);
                }
                exit();
                
                break;
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        
        header('Location: ' . $_SERVER['PHP_SELF'] . '?page=bewerten');
        exit();
    }
}


// === BEWERTEN-FORMULARVERARBEITUNG (VOR HTML-AUSGABE!) ===
// Dieser Code muss in dashboard.php VOR der HTML-Ausgabe eingefügt werden
// Am besten nach den anderen Formularverarbeitungen (z.B. nach Themen/Gruppen)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && isset($_GET['page']) && $_GET['page'] === 'bewerten') {
    try {
        // CSRF-Token prüfen
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Ungültiger CSRF-Token');
        }
        
        switch ($_POST['form_action']) {
            case 'save_rating':
                $student_id = (int)$_POST['student_id'];
                $group_id = (int)$_POST['group_id'];
                $template_id = (int)$_POST['template_id'];
                $final_grade = isset($_POST['final_grade']) && $_POST['final_grade'] !== '' ? (float)$_POST['final_grade'] : null;
                $category_ratings = $_POST['category_rating'] ?? [];
                
                // Berechtigung prüfen
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM group_students 
                    WHERE student_id = ? AND group_id = ? AND examiner_teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Keine Berechtigung für diese Aktion.');
                }
                
                $db->beginTransaction();
                
                // Prüfen ob bereits eine Bewertung existiert
                $stmt = $db->prepare("
                    SELECT id FROM ratings 
                    WHERE student_id = ? AND group_id = ? AND teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                $rating_id = $stmt->fetchColumn();
                
                if ($rating_id) {
                    // Update bestehende Bewertung
                    $stmt = $db->prepare("
                        UPDATE ratings 
                        SET template_id = ?, final_grade = ?, is_complete = ?, 
                            rating_date = CURDATE(), updated_at = NOW()
                        WHERE id = ?
                    ");
                    $is_complete = !empty($category_ratings) && $final_grade !== null ? 1 : 0;
                    $stmt->execute([$template_id, $final_grade, $is_complete, $rating_id]);
                } else {
                    // Neue Bewertung erstellen - WICHTIG: criteria_id mit Standardwert setzen
                    $stmt = $db->prepare("
                        INSERT INTO ratings (student_id, teacher_id, group_id, template_id, 
                                           final_grade, is_complete, rating_date, created_at, criteria_id, points)
                        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), NOW(), 1, 0)
                    ");
                    $is_complete = !empty($category_ratings) && $final_grade !== null ? 1 : 0;
                    $stmt->execute([$student_id, $teacher_id, $group_id, $template_id, 
                                  $final_grade, $is_complete]);
                    $rating_id = $db->lastInsertId();
                }
                
                // Alte Kategorie-Bewertungen löschen
                $stmt = $db->prepare("DELETE FROM rating_categories WHERE rating_id = ?");
                $stmt->execute([$rating_id]);
                
                // Neue Kategorie-Bewertungen speichern
                if (!empty($category_ratings)) {
                    $stmt = $db->prepare("
                        INSERT INTO rating_categories (rating_id, category_id, points)
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($category_ratings as $category_id => $points) {
                        if ($points !== '') {
                            $stmt->execute([$rating_id, (int)$category_id, (float)$points]);
                        }
                    }
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Bewertung erfolgreich gespeichert.';
                $_SESSION['flash_type'] = 'success';
                
                // Redirect je nach Button
                $redirect_url = $_SERVER['PHP_SELF'] . '?page=bewerten';
                if (isset($_POST['save_and_close'])) {
                    // Zurück zur Übersicht
                } else {
                    // Auf der Bewertungsseite bleiben
                    $redirect_url .= '&rate=' . $student_id . '&group=' . $group_id . '&tab=rating';
                }
                
                header('Location: ' . $redirect_url);
                exit();
                
                break;
                
            case 'save_strengths':
                $student_id = (int)$_POST['student_id'];
                $group_id = (int)$_POST['group_id'];
                $strength_items = $_POST['strength_items'] ?? [];
                
                // Berechtigung prüfen
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM group_students 
                    WHERE student_id = ? AND group_id = ? AND examiner_teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Keine Berechtigung für diese Aktion.');
                }
                
                $db->beginTransaction();
                
                // Prüfen ob bereits eine Bewertung existiert
                $stmt = $db->prepare("
                    SELECT id FROM ratings 
                    WHERE student_id = ? AND group_id = ? AND teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                $rating_id = $stmt->fetchColumn();
                
                if (!$rating_id) {
                    // Bewertung muss existieren bevor Stärken gespeichert werden können
                    throw new Exception('Bitte speichern Sie zuerst die Bewertung.');
                }
                
                // Alte Stärken löschen
                $stmt = $db->prepare("DELETE FROM rating_strengths WHERE rating_id = ?");
                $stmt->execute([$rating_id]);
                
                // Neue Stärken speichern
                if (!empty($strength_items)) {
                    $stmt = $db->prepare("
                        INSERT INTO rating_strengths (rating_id, strength_item_id)
                        VALUES (?, ?)
                    ");
                    
                    foreach ($strength_items as $item_id) {
                        $stmt->execute([$rating_id, (int)$item_id]);
                    }
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Stärken erfolgreich gespeichert.';
                $_SESSION['flash_type'] = 'success';
                
                // Redirect je nach Button
                $redirect_url = $_SERVER['PHP_SELF'] . '?page=bewerten';
                if (isset($_POST['save_and_close'])) {
                    // Zurück zur Übersicht - Filter und Sort beibehalten
                    if (isset($_GET['status'])) $redirect_url .= '&status=' . $_GET['status'];
                    if (isset($_GET['sort'])) $redirect_url .= '&sort=' . $_GET['sort'];
                } else {
                    // Auf der Stärken-Seite bleiben
                    $redirect_url .= '&rate=' . $student_id . '&group=' . $group_id . '&tab=strengths';
                }
                
                header('Location: ' . $redirect_url);
                exit();
                
                break;
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        
        // Bei Fehler auch redirect mit allen Parametern
        $redirect_url = $_SERVER['PHP_SELF'] . '?page=bewerten';
        if (isset($student_id) && isset($group_id)) {
            $redirect_url .= '&rate=' . $student_id . '&group=' . $group_id;
            if (isset($_POST['form_action']) && $_POST['form_action'] === 'save_strengths') {
                $redirect_url .= '&tab=strengths';
            }
        }
        
        header('Location: ' . $redirect_url);
        exit();
    }
}


// === BEWERTEN-FORMULARVERARBEITUNG (VOR HTML-AUSGABE!) ===
// Dieser Code muss in dashboard.php VOR der HTML-Ausgabe eingefügt werden
// Am besten nach den anderen Formularverarbeitungen (z.B. nach Themen/Gruppen)

// WICHTIG: Stelle sicher, dass $db verfügbar ist
if (!isset($db)) {
    $db = getDB();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && isset($_GET['page']) && $_GET['page'] === 'bewerten') {
    try {
        // CSRF-Token prüfen
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception('Ungültiger CSRF-Token');
        }
        
        switch ($_POST['form_action']) {
            case 'save_rating':
                $student_id = (int)$_POST['student_id'];
                $group_id = (int)$_POST['group_id'];
                $template_id = (int)$_POST['template_id'];
                $final_grade = isset($_POST['final_grade']) && $_POST['final_grade'] !== '' ? (float)$_POST['final_grade'] : null;
                $category_ratings = $_POST['category_rating'] ?? [];
                
                // Berechtigung prüfen
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM group_students 
                    WHERE student_id = ? AND group_id = ? AND examiner_teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Keine Berechtigung für diese Aktion.');
                }
                
                $db->beginTransaction();
                
                // Prüfen ob bereits eine Bewertung existiert
                $stmt = $db->prepare("
                    SELECT id FROM ratings 
                    WHERE student_id = ? AND group_id = ? AND teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                $rating_id = $stmt->fetchColumn();
                
                if ($rating_id) {
                    // Update bestehende Bewertung
                    $stmt = $db->prepare("
                        UPDATE ratings 
                        SET template_id = ?, final_grade = ?, is_complete = ?, 
                            rating_date = CURDATE(), updated_at = NOW()
                        WHERE id = ?
                    ");
                    $is_complete = !empty($category_ratings) && $final_grade !== null ? 1 : 0;
                    $stmt->execute([$template_id, $final_grade, $is_complete, $rating_id]);
                } else {
                    // Neue Bewertung erstellen - WICHTIG: criteria_id mit Standardwert setzen
                    $stmt = $db->prepare("
                        INSERT INTO ratings (student_id, teacher_id, group_id, template_id, 
                                           final_grade, is_complete, rating_date, created_at, criteria_id, points)
                        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), NOW(), 1, 0)
                    ");
                    $is_complete = !empty($category_ratings) && $final_grade !== null ? 1 : 0;
                    $stmt->execute([$student_id, $teacher_id, $group_id, $template_id, 
                                  $final_grade, $is_complete]);
                    $rating_id = $db->lastInsertId();
                }
                
                // Alte Kategorie-Bewertungen löschen
                $stmt = $db->prepare("DELETE FROM rating_categories WHERE rating_id = ?");
                $stmt->execute([$rating_id]);
                
                // Neue Kategorie-Bewertungen speichern
                if (!empty($category_ratings)) {
                    $stmt = $db->prepare("
                        INSERT INTO rating_categories (rating_id, category_id, points)
                        VALUES (?, ?, ?)
                    ");
                    
                    foreach ($category_ratings as $category_id => $points) {
                        if ($points !== '') {
                            $stmt->execute([$rating_id, (int)$category_id, (float)$points]);
                        }
                    }
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Bewertung erfolgreich gespeichert.';
                $_SESSION['flash_type'] = 'success';
                
                // Redirect je nach Button
                $redirect_url = $_SERVER['PHP_SELF'] . '?page=bewerten';
                if (isset($_POST['save_and_close'])) {
                    // Zurück zur Übersicht
                } else {
                    // Auf der Bewertungsseite bleiben
                    $redirect_url .= '&rate=' . $student_id . '&group=' . $group_id . '&tab=rating';
                }
                
                header('Location: ' . $redirect_url);
                exit();
                
                break;
                
            case 'save_strengths':
                $student_id = (int)$_POST['student_id'];
                $group_id = (int)$_POST['group_id'];
                $strength_items = $_POST['strength_items'] ?? [];
                
                // Berechtigung prüfen
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM group_students 
                    WHERE student_id = ? AND group_id = ? AND examiner_teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Keine Berechtigung für diese Aktion.');
                }
                
                $db->beginTransaction();
                
                // Prüfen ob bereits eine Bewertung existiert
                $stmt = $db->prepare("
                    SELECT id FROM ratings 
                    WHERE student_id = ? AND group_id = ? AND teacher_id = ?
                ");
                $stmt->execute([$student_id, $group_id, $teacher_id]);
                $rating_id = $stmt->fetchColumn();
                
                if (!$rating_id) {
                    // Bewertung muss existieren bevor Stärken gespeichert werden können
                    throw new Exception('Bitte speichern Sie zuerst die Bewertung.');
                }
                
                // Alte Stärken löschen
                $stmt = $db->prepare("DELETE FROM rating_strengths WHERE rating_id = ?");
                $stmt->execute([$rating_id]);
                
                // Neue Stärken speichern
                if (!empty($strength_items)) {
                    $stmt = $db->prepare("
                        INSERT INTO rating_strengths (rating_id, strength_item_id)
                        VALUES (?, ?)
                    ");
                    
                    foreach ($strength_items as $item_id) {
                        $stmt->execute([$rating_id, (int)$item_id]);
                    }
                }
                
                $db->commit();
                $_SESSION['flash_message'] = 'Stärken erfolgreich gespeichert.';
                $_SESSION['flash_type'] = 'success';
                
                // Redirect je nach Button
                $redirect_url = $_SERVER['PHP_SELF'] . '?page=bewerten';
                if (isset($_POST['save_and_close'])) {
                    // Zurück zur Übersicht
                } else {
                    // Auf der Stärken-Seite bleiben
                    $redirect_url .= '&rate=' . $student_id . '&group=' . $group_id . '&tab=strengths';
                }
                
                header('Location: ' . $redirect_url);
                exit();
                
                break;
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_message'] = $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        
        // Bei Fehler auch redirect mit allen Parametern
        $redirect_url = $_SERVER['PHP_SELF'] . '?page=bewerten';
        if (isset($student_id) && isset($group_id)) {
            $redirect_url .= '&rate=' . $student_id . '&group=' . $group_id;
            if (isset($_POST['form_action']) && $_POST['form_action'] === 'save_strengths') {
                $redirect_url .= '&tab=strengths';
            }
        }
        
        header('Location: ' . $redirect_url);
        exit();
    }
}

// === EINSTELLUNGEN-FORMULARVERARBEITUNG (VOR HTML-AUSGABE!) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password' && $page === 'einstellungen') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validierung
    $errors = [];
    
    if (strlen($new_password) < 8) {
        $errors[] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
    }
    
    if (!preg_match('/[A-Z]/', $new_password)) {
        $errors[] = "Das Passwort muss mindestens einen Großbuchstaben enthalten.";
    }
    
    if (!preg_match('/[^a-zA-Z0-9]/', $new_password)) {
        $errors[] = "Das Passwort muss mindestens ein Sonderzeichen enthalten.";
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = "Die Passwörter stimmen nicht überein.";
    }
    
    if (empty($errors)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, first_login = 0, password_set_by_admin = 0, admin_password = NULL WHERE id = ?");
        if ($stmt->execute([$hashed_password, $teacher_id])) {
            $_SESSION['flash_message'] = 'Passwort erfolgreich geändert!';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = "Fehler beim Speichern des Passworts.";
            $_SESSION['flash_type'] = 'error';
        }
    } else {
        $_SESSION['flash_message'] = implode("<br>", $errors);
        $_SESSION['flash_type'] = 'error';
    }
    
    header('Location: ?page=einstellungen');
    exit;
}

// Lehrerdaten und Schulinfo abrufen
$db = getDB();
$stmt = $db->prepare("
    SELECT u.*, s.name as school_name, s.school_type
    FROM users u
    JOIN schools s ON u.school_id = s.id
    WHERE u.id = ? AND u.user_type = 'lehrer'
");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    die('Lehrerdaten konnten nicht geladen werden.');
}

// Datenbankverbindung für PDO anpassen (falls deine DB-Klasse anders heißt)
$pdo = $db;

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lehrer Dashboard - <?= htmlspecialchars($teacher['school_name']) ?></title>
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(to bottom, #999999 0%, #ff9900 100%);
            color: #001133;
            line-height: 1.6;
            min-height: 100vh;
        }

        .header {
            height: 180px;
            background: linear-gradient(135deg, #002b45 0%, #063b52 100%);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: #ff9900;
        }

        .header-content {
            text-align: center;
            z-index: 2;
        }

        .header h1 {
            color: white;
            font-size: 36px;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .header-info {
            color: rgba(255,255,255,0.9);
            margin-top: 10px;
            font-size: 16px;
        }

        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 60px 40px;
            position: relative;
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 40px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .nav-tab {
            padding: 15px 30px;
            background: rgba(255, 255, 255, 0.9);
            color: #001133;
            text-decoration: none;
            border-radius: 999px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .nav-tab:hover {
            background: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .nav-tab.active {
            background: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .tab-content {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            min-height: 500px;
        }

        .welcome-message {
            background: linear-gradient(135deg, #002b45, #063b52);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
        }

        .welcome-message h2 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .welcome-message p {
            font-size: 16px;
            opacity: 0.9;
        }

        .content-section {
            background: rgba(255, 255, 255, 0.9);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .content-section h3 {
            margin-bottom: 20px;
            color: #002b45;
            font-size: 24px;
        }

        .news-item {
            border-left: 4px solid #ff9900;
            padding-left: 20px;
            margin-bottom: 20px;
        }

        .news-item h4 {
            color: #002b45;
            margin-bottom: 8px;
            font-size: 18px;
        }

        .news-item p {
            color: #666;
            margin-bottom: 5px;
        }

        .news-item small {
            color: #999;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #666;
        }

        .empty-state-icon {
            font-size: 72px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin: 20px 0;
            color: #002b45;
        }

        .action-button {
            background: #ffffff;
            color: #001133;
            border: 2px solid #ff9900;
            padding: 15px 30px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin: 20px auto;
            text-align: center;
        }

        .action-button:hover {
            background: #ff9900;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 153, 0, 0.3);
        }

        .logout-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(231, 76, 60, 0.9);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 999px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 30px 20px;
            }
            
            .header h1 {
                font-size: 28px;
            }
            
            .nav-tabs {
                flex-direction: column;
                align-items: center;
            }

            .tab-content {
                padding: 20px;
            }

            .logout-btn {
                position: relative;
                top: auto;
                right: auto;
                margin: 10px auto;
                display: block;
                width: fit-content;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1><?= htmlspecialchars($teacher['school_name']) ?></h1>
            <div class="header-info">
                <div>👨‍🏫 <?= htmlspecialchars($teacher['name']) ?></div>
            </div>
        </div>
        <a href="../logout.php" class="logout-btn">Abmelden</a>
    </div>

    <div class="main-content">
        <div class="nav-tabs">
            <a href="?page=news" class="nav-tab <?= $page === 'news' ? 'active' : '' ?>">📰 News</a>
            <a href="?page=themen" class="nav-tab <?= $page === 'themen' ? 'active' : '' ?>">📚 Themen</a>
            <a href="?page=gruppen" class="nav-tab <?= $page === 'gruppen' ? 'active' : '' ?>">👥 Gruppen</a>
            <a href="?page=bewerten" class="nav-tab <?= $page === 'bewerten' ? 'active' : '' ?>">⭐ Schüler bewerten</a>
            <a href="?page=vorlagen" class="nav-tab <?= $page === 'vorlagen' ? 'active' : '' ?>">📋 Bewertungsvorlagen</a>
            <a href="?page=einstellungen" class="nav-tab <?= $page === 'einstellungen' ? 'active' : '' ?>">⚙️ Einstellungen</a>
        </div>

        <div class="tab-content">
            <?php
            switch ($page) {
                case 'news':
                    // News-Modul einbinden
                    if (file_exists('lehrer_news_include.php')) {
                        include 'lehrer_news_include.php';
                    } else {
                        // Fallback wenn das News-Modul noch nicht existiert
                        echo '<div class="welcome-message">';
                        echo '<h2>Willkommen, ' . htmlspecialchars($teacher['name']) . '!</h2>';
                        echo '<p>Hier finden Sie aktuelle Informationen und Neuigkeiten rund um das Bewertungssystem.</p>';
                        echo '</div>';
                        
                        echo '<div class="content-section">';
                        echo '<h3>📰 Aktuelle News</h3>';
                        echo '<div class="news-item">';
                        echo '<h4>System erfolgreich eingerichtet</h4>';
                        echo '<p>Das Bewertungssystem wurde erfolgreich für Ihre Schule konfiguriert.</p>';
                        echo '<small>Heute</small>';
                        echo '</div>';
                        echo '<div class="news-item">';
                        echo '<h4>Erste Schritte</h4>';
                        echo '<p>Beginnen Sie mit der Erstellung von Themen und Bewertungsvorlagen.</p>';
                        echo '<small>Heute</small>';
                        echo '</div>';
                        echo '</div>';
                        echo '<div style="text-align: center;">';
                        echo '<a href="?page=themen" class="action-button">Jetzt starten →</a>';
                        echo '</div>';
                    }
                    break;
                    
                case 'themen':
                    // Themen-Modul einbinden
                    if (file_exists('lehrer_themen_include.php')) {
                        include 'lehrer_themen_include.php';
                    } else {
                        echo '<div class="content-section">';
                        echo '<div class="empty-state">';
                        echo '<span class="empty-state-icon">⚠️</span>';
                        echo '<h3>Themen-Modul nicht gefunden</h3>';
                        echo '<p>Die Datei lehrer_themen_include.php konnte nicht geladen werden.</p>';
                        echo '</div>';
                        echo '</div>';
                    }
                    break;
                    
                case 'gruppen':
                    // Gruppen-Modul einbinden
                    if (file_exists('lehrer_gruppen_include.php')) {
                        include 'lehrer_gruppen_include.php';
                    } else {
                        echo '<div class="content-section">';
                        echo '<div class="empty-state">';
                        echo '<span class="empty-state-icon">⚠️</span>';
                        echo '<h3>Gruppen-Modul nicht gefunden</h3>';
                        echo '<p>Die Datei lehrer_gruppen_include.php konnte nicht geladen werden.</p>';
                        echo '</div>';
                        echo '</div>';
                    }
                    break;
                    
                case 'bewerten':
                    // Bewerten-Modul einbinden
                    if (file_exists('lehrer_bewerten_include.php')) {
                        include 'lehrer_bewerten_include.php';
                    } else {
                        echo '<div class="content-section">';
                        echo '<div class="empty-state">';
                        echo '<span class="empty-state-icon">⚠️</span>';
                        echo '<h3>Bewerten-Modul nicht gefunden</h3>';
                        echo '<p>Die Datei lehrer_bewerten_include.php konnte nicht geladen werden.</p>';
                        echo '</div>';
                        echo '</div>';
                    }
                    break;

                case 'vorlagen':
                    // Bewertungsvorlagen-Modul einbinden
                    if (file_exists('lehrer_vorlagen_include.php')) {
                        include 'lehrer_vorlagen_include.php';
                    } else {
                        echo '<div class="content-section">';
                        echo '<div class="empty-state">';
                        echo '<span class="empty-state-icon">⚠️</span>';
                        echo '<h3>Vorlagen-Modul nicht gefunden</h3>';
                        echo '<p>Die Datei lehrer_vorlagen_include.php konnte nicht geladen werden.</p>';
                        echo '</div>';
                        echo '</div>';
                    }
                    break;

                case 'einstellungen':
                    // Einstellungen-Modul einbinden
                    if (file_exists('lehrer_einstellungen_include.php')) {
                        include 'lehrer_einstellungen_include.php';
                    } else {
                        echo '<div class="content-section">';
                        echo '<div class="empty-state">';
                        echo '<span class="empty-state-icon">⚠️</span>';
                        echo '<h3>Einstellungen-Modul nicht gefunden</h3>';
                        echo '<p>Die Datei lehrer_einstellungen_include.php konnte nicht geladen werden.</p>';
                        echo '</div>';
                        echo '</div>';
                    }
                    break;

                default:
                    echo '<div class="content-section">';
                    echo '<h2>Seite nicht gefunden</h2>';
                    echo '<p>Die angeforderte Seite existiert nicht.</p>';
                    echo '</div>';
                    break;
            }
            ?>
        </div>
    </div>
</body>
</html>