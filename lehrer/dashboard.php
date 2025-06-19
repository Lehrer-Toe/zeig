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
        
        // Berechtigung prüfen für Erstellen/Bearbeiten
        if (in_array($_POST['form_action'], ['create_group', 'update_group', 'delete_group'])) {
            $stmt = $db->prepare("SELECT can_create_groups FROM users WHERE id = ?");
            $stmt->execute([$teacher_id]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Sie haben keine Berechtigung, Gruppen zu verwalten.');
            }
        }
        
        switch ($_POST['form_action']) {
            case 'create_group':
                $topic = trim($_POST['topic'] ?? '');
                $class_id = (int)($_POST['class_id'] ?? 0);
                $student_ids = $_POST['students'] ?? [];
                $examiners = $_POST['examiner'] ?? [];
                $subjects = $_POST['subject'] ?? [];
                
                if (empty($topic)) {
                    throw new Exception('Thema ist erforderlich.');
                }
                
                if ($class_id <= 0) {
                    throw new Exception('Bitte wählen Sie eine Klasse aus.');
                }
                
                if (empty($student_ids)) {
                    throw new Exception('Bitte wählen Sie mindestens einen Schüler aus.');
                }
                
                if (count($student_ids) > 4) {
                    throw new Exception('Maximal 4 Schüler pro Gruppe erlaubt.');
                }
                
                $db->beginTransaction();
                
                // Prüfe ob Schüler bereits in Gruppen sind
                $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
                $stmt = $db->prepare("
                    SELECT s.id, s.first_name, s.last_name
                    FROM students s
                    JOIN group_students gs ON s.id = gs.student_id
                    WHERE s.id IN ($placeholders)
                ");
                $stmt->execute($student_ids);
                $already_in_group = $stmt->fetchAll();
                
                if (!empty($already_in_group)) {
                    $names = array_map(function($s) {
                        return $s['first_name'] . ' ' . $s['last_name'];
                    }, $already_in_group);
                    throw new Exception('Folgende Schüler sind bereits in Gruppen: ' . implode(', ', $names));
                }
                
                // Gruppe erstellen
                $stmt = $db->prepare("
                    INSERT INTO groups (school_id, class_id, name, teacher_id, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$school_id, $class_id, $topic, $teacher_id]);
                $group_id = $db->lastInsertId();
                
                // Schüler zuordnen
                $stmt = $db->prepare("
                    INSERT INTO group_students (group_id, student_id, assigned_by, examiner_teacher_id, subject_id, assigned_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($student_ids as $student_id) {
                    $examiner_id = isset($examiners[$student_id]) && $examiners[$student_id] ? $examiners[$student_id] : null;
                    $subject_id = isset($subjects[$student_id]) && $subjects[$student_id] ? $subjects[$student_id] : null;
                    $stmt->execute([$group_id, $student_id, $teacher_id, $examiner_id, $subject_id]);
                }
                
                // Letzte ausgewählte Klasse speichern
                $stmt = $db->prepare("UPDATE users SET last_selected_class_id = ? WHERE id = ?");
                $stmt->execute([$class_id, $teacher_id]);
                
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
                
                // Prüfen ob Gruppe dem Lehrer gehört
                $stmt = $db->prepare("SELECT id FROM groups WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1");
                $stmt->execute([$group_id, $teacher_id, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Gruppe nicht gefunden oder keine Berechtigung.');
                }
                
                $db->beginTransaction();
                
                // Gruppe aktualisieren
                $stmt = $db->prepare("UPDATE groups SET name = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$topic, $group_id]);
                
                // Schüler-Zuordnungen aktualisieren
                foreach ($examiners as $student_id => $examiner_id) {
                    $subject_id = isset($subjects[$student_id]) && $subjects[$student_id] ? $subjects[$student_id] : null;
                    $examiner_id = $examiner_id ? $examiner_id : null;
                    
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
                
            case 'remove_student':
                $group_id = (int)($_POST['group_id'] ?? 0);
                $student_id = (int)($_POST['student_id'] ?? 0);
                
                if ($group_id <= 0 || $student_id <= 0) {
                    throw new Exception('Ungültige IDs.');
                }
                
                // Prüfen ob Gruppe dem Lehrer gehört
                $stmt = $db->prepare("SELECT id FROM groups WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1");
                $stmt->execute([$group_id, $teacher_id, $school_id]);
                if (!$stmt->fetch()) {
                    throw new Exception('Gruppe nicht gefunden oder keine Berechtigung.');
                }
                
                // Schüler entfernen
                $stmt = $db->prepare("DELETE FROM group_students WHERE group_id = ? AND student_id = ?");
                $stmt->execute([$group_id, $student_id]);
                
                $_SESSION['flash_message'] = 'Schüler erfolgreich aus der Gruppe entfernt.';
                $_SESSION['flash_type'] = 'success';
                break;
                
            case 'delete_group':
                $group_id = (int)($_POST['group_id'] ?? 0);
                
                if ($group_id <= 0) {
                    throw new Exception('Ungültige Gruppen-ID.');
                }
                
                // Prüfen ob Gruppe dem Lehrer gehört
                $stmt = $db->prepare("SELECT name FROM groups WHERE id = ? AND teacher_id = ? AND school_id = ? AND is_active = 1");
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

// can_create_groups in Session speichern für Include-Dateien
$_SESSION['can_create_groups'] = $teacher['can_create_groups'];

// AJAX-Handler für Schüler-Abruf
if (isset($_GET['action']) && $_GET['action'] === 'get_students' && isset($_GET['class_id'])) {
    $class_id = (int)$_GET['class_id'];
    if ($class_id > 0) {
        $stmt = $db->prepare("
            SELECT s.*, 
                   CASE WHEN gs.id IS NOT NULL THEN 1 ELSE 0 END as has_group
            FROM students s
            LEFT JOIN group_students gs ON s.id = gs.student_id
            WHERE s.class_id = ? AND s.is_active = 1
            ORDER BY s.last_name, s.first_name
        ");
        $stmt->execute([$class_id]);
        $students = $stmt->fetchAll();
        
        foreach ($students as $student) {
            $isDisabled = $student['has_group'] ? 'disabled' : '';
            $name = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
            echo <<<HTML
                <label class="student-checkbox {$isDisabled}">
                    <input type="checkbox" name="students[]" value="{$student['id']}" 
                        onchange="toggleStudent({$student['id']}, '{$name}')" 
                        {$isDisabled}>
                    <span>{$name}</span>
HTML;
            if ($student['has_group']) {
                echo '<small style="color: #999;"> (bereits in Gruppe)</small>';
            }
            echo '</label>';
        }
    }
    exit();
}
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
            <a href="?page=gruppen" class="nav-tab <?= $page === 'gruppen' ? 'active' : '' ?>">👥 Gruppen verwalten</a>
            <a href="?page=bewerten" class="nav-tab <?= $page === 'bewerten' ? 'active' : '' ?>">⭐ Schüler bewerten</a>
            <a href="?page=vorlagen" class="nav-tab <?= $page === 'vorlagen' ? 'active' : '' ?>">📋 Bewertungsvorlagen</a>
            <a href="?page=uebersicht" class="nav-tab <?= $page === 'uebersicht' ? 'active' : '' ?>">📊 Übersicht</a>
            <a href="?page=einstellungen" class="nav-tab <?= $page === 'einstellungen' ? 'active' : '' ?>">⚙️ Einstellungen</a>
        </div>

        <div class="tab-content">
            <?php
            switch ($page) {
                case 'news':
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
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">⭐ Schüler bewerten</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Bewerten Sie Ihre Schüler anhand der definierten Kriterien und Themen.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">⭐</span>';
                    echo '<h3>Bewertungssystem in Vorbereitung</h3>';
                    echo '<p>Das Bewertungsmodul wird nach der Erstellung von Themen und Gruppen verfügbar sein.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'vorlagen':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">📋 Bewertungsvorlagen</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Erstellen und verwalten Sie Bewertungsvorlagen für wiederkehrende Aufgaben.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">📋</span>';
                    echo '<h3>Vorlagen-System in Entwicklung</h3>';
                    echo '<p>Erstellen Sie wiederverwendbare Bewertungsvorlagen für verschiedene Projekttypen.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'uebersicht':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">📊 Übersicht</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Statistische Auswertungen und Berichte zu Ihren Bewertungen.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">📊</span>';
                    echo '<h3>Statistiken werden erstellt</h3>';
                    echo '<p>Nach den ersten Bewertungen werden hier detaillierte Auswertungen angezeigt.</p>';
                    echo '</div>';
                    echo '</div>';
                    break;

                case 'einstellungen':
                    echo '<h2 style="text-align: center; color: #002b45; margin-bottom: 30px;">⚙️ Einstellungen</h2>';
                    echo '<p style="text-align: center; margin-bottom: 30px; color: #666;">Persönliche Einstellungen und Präferenzen anpassen.</p>';
                    
                    echo '<div class="content-section">';
                    echo '<div class="empty-state">';
                    echo '<span class="empty-state-icon">⚙️</span>';
                    echo '<h3>Einstellungen in Vorbereitung</h3>';
                    echo '<p>Hier können Sie bald Ihre persönlichen Präferenzen anpassen.</p>';
                    echo '</div>';
                    echo '</div>';
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