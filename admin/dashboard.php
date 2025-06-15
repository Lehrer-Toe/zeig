<?php
require_once '../config.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

// Aktiver Tab
$activeTab = $_GET['tab'] ?? 'klassen';

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        sendErrorResponse('Sicherheitsfehler.');
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_class':
            $className = trim($_POST['class_name'] ?? '');
            $schoolYear = $_POST['school_year'] ?? '';
            
            if (empty($className)) {
                sendErrorResponse('Klassenname ist erforderlich.');
            }
            
            // Prüfen ob Klassenlimit erreicht
            if (!canCreateClass($user['school_id'])) {
                sendErrorResponse('Maximale Anzahl an Klassen erreicht.');
            }
            
            $db = getDB();
            
            // Prüfen welche Spalten existieren
            $stmt = $db->prepare("SHOW COLUMNS FROM classes");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $insertColumns = ['name', 'school_id'];
            $insertValues = ['?', '?'];
            $insertParams = [$className, $user['school_id']];
            
            if (in_array('school_year', $columns) && !empty($schoolYear)) {
                $insertColumns[] = 'school_year';
                $insertValues[] = '?';
                $insertParams[] = $schoolYear;
            }
            
            if (in_array('created_by', $columns)) {
                $insertColumns[] = 'created_by';
                $insertValues[] = '?';
                $insertParams[] = $user['id'];
            }
            
            if (in_array('is_active', $columns)) {
                $insertColumns[] = 'is_active';
                $insertValues[] = '1';
            }
            
            if (in_array('created_at', $columns)) {
                $insertColumns[] = 'created_at';
                $insertValues[] = 'CURRENT_TIMESTAMP';
            }
            
            $sql = "INSERT INTO classes (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")";
            $stmt = $db->prepare($sql);
            
            if ($stmt->execute($insertParams)) {
                sendSuccessResponse('Klasse erfolgreich erstellt.');
            } else {
                sendErrorResponse('Fehler beim Erstellen der Klasse.');
            }
            break;
            
        case 'delete_class':
            $classId = (int)($_POST['class_id'] ?? 0);
            
            $db = getDB();
            
            // Prüfen ob is_active Spalte existiert
            $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'is_active'");
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                // Soft delete mit is_active
                $stmt = $db->prepare("
                    UPDATE classes 
                    SET is_active = 0, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ? AND school_id = ?
                ");
            } else {
                // Hard delete falls is_active nicht existiert
                $stmt = $db->prepare("
                    DELETE FROM classes 
                    WHERE id = ? AND school_id = ?
                ");
            }
            
            if ($stmt->execute([$classId, $user['school_id']])) {
                sendSuccessResponse('Klasse erfolgreich gelöscht.');
            } else {
                sendErrorResponse('Fehler beim Löschen der Klasse.');
            }
            break;
    }
}

// Klassen laden
function getSchoolClasses($schoolId, $classFilter = 'all', $yearFilter = 'all') {
    $db = getDB();
    
    // Prüfen welche Spalten in classes existieren
    $stmt = $db->prepare("SHOW COLUMNS FROM classes");
    $stmt->execute();
    $availableColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $selectColumns = ['c.id', 'c.name', 'c.school_id'];
    $whereConditions = ['c.school_id = ?'];
    $params = [$schoolId];
    
    // Optional verfügbare Spalten hinzufügen
    if (in_array('school_year', $availableColumns)) {
        $selectColumns[] = 'c.school_year';
    }
    if (in_array('created_at', $availableColumns)) {
        $selectColumns[] = 'c.created_at';
    }
    if (in_array('created_by', $availableColumns)) {
        $selectColumns[] = 'c.created_by';
    }
    
    // WHERE Bedingungen basierend auf verfügbaren Spalten
    if (in_array('is_active', $availableColumns)) {
        $whereConditions[] = 'c.is_active = 1';
    }
    
    if ($classFilter !== 'all') {
        $whereConditions[] = 'c.id = ?';
        $params[] = $classFilter;
    }
    
    if ($yearFilter !== 'all' && in_array('school_year', $availableColumns)) {
        $whereConditions[] = 'c.school_year = ?';
        $params[] = $yearFilter;
    }
    
    // Schüleranzahl nur wenn students Tabelle existiert
    $studentCountSelect = "0 as student_count";
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $studentCountSelect = "COALESCE(COUNT(s.id), 0) as student_count";
        }
    } catch (Exception $e) {
        // Tabelle existiert nicht
    }
    
    $sql = "
        SELECT " . implode(', ', $selectColumns) . ", 
               " . $studentCountSelect . "
        FROM classes c";
    
    // JOIN nur wenn students Tabelle existiert
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $sql .= " LEFT JOIN students s ON c.id = s.class_id";
        }
    } catch (Exception $e) {
        // Tabelle existiert nicht
    }
    
    $sql .= " WHERE " . implode(' AND ', $whereConditions);
    $sql .= " GROUP BY c.id";
    
    // ORDER BY nur mit verfügbaren Spalten
    $orderBy = [];
    if (in_array('school_year', $availableColumns)) {
        $orderBy[] = 'c.school_year DESC';
    }
    $orderBy[] = 'c.name ASC';
    $sql .= " ORDER BY " . implode(', ', $orderBy);
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getSchoolYears($schoolId) {
    $db = getDB();
    
    // Prüfen ob school_year Spalte existiert
    $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'school_year'");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Spalte existiert nicht, leeres Array zurückgeben
        return [];
    }
    
    // Prüfen ob is_active Spalte existiert
    $whereClause = "school_id = ?";
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'is_active'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $whereClause .= " AND is_active = 1";
        }
    } catch (Exception $e) {
        // Spalte existiert nicht, ignorieren
    }
    
    $stmt = $db->prepare("
        SELECT DISTINCT school_year 
        FROM classes 
        WHERE " . $whereClause . "
        ORDER BY school_year DESC
    ");
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Filter
$classFilter = $_GET['class_filter'] ?? 'all';
$yearFilter = $_GET['year_filter'] ?? 'all';

$classes = getSchoolClasses($user['school_id'], $classFilter, $yearFilter);
$schoolYears = getSchoolYears($user['school_id']);
$school = getSchoolById($user['school_id']);
$flashMessage = getFlashMessage();

// Aktuelle Schuljahre für Dropdown
$currentYear = date('Y');
$nextYear = $currentYear + 1;
$availableYears = [
    ($currentYear - 1) . '/' . substr($currentYear, 2),
    $currentYear . '/' . substr($nextYear, 2),
    $nextYear . '/' . substr($nextYear + 1, 2)
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schuladmin - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #e2e8f0;
            min-height: 100vh;
        }

        .header {
            background: rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
        }

        .header h1 {
            color: #3b82f6;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info span {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .school-info {
            font-size: 0.8rem;
            opacity: 0.6;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.5rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
        }

        .btn-secondary {
            background: rgba(100, 116, 139, 0.2);
            color: #cbd5e1;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .tab-navigation {
            display: flex;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 1rem;
            padding: 0.5rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            overflow-x: auto;
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            background: transparent;
            border: none;
            color: #cbd5e1;
            cursor: pointer;
            border-radius: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            white-space: nowrap;
            text-decoration: none;
        }

        .tab-btn:hover {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .content-title {
            color: #3b82f6;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .filters {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-select {
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
            font-size: 0.9rem;
        }

        .filter-select option {
            background: #1e293b;
            color: white;
        }

        .create-section {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }

        .create-section h3 {
            color: #3b82f6;
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .create-form {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }

        .form-group label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #cbd5e1;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group input::placeholder {
            color: #64748b;
        }

        .form-group select option {
            background: #1e293b;
            color: white;
        }

        .upload-section {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }

        .upload-section h3 {
            color: #3b82f6;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .format-info {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .format-info h4 {
            color: #fbbf24;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .format-info ul {
            color: #fbbf24;
            margin-left: 1.5rem;
            font-size: 0.9rem;
        }

        .format-info .examples {
            margin-top: 0.5rem;
            font-style: italic;
            opacity: 0.8;
        }

        .upload-form {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }

        .file-input {
            position: absolute;
            left: -9999px;
        }

        .file-input-label {
            padding: 0.75rem 1.5rem;
            background: rgba(100, 116, 139, 0.2);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-input-label:hover {
            background: rgba(100, 116, 139, 0.3);
        }

        .file-name {
            color: #94a3b8;
            font-size: 0.9rem;
            font-style: italic;
        }

        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .class-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .class-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .class-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 0.25rem;
        }

        .class-year {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            padding: 0.25rem 0.75rem;
            border-radius: 0.75rem;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .class-stats {
            display: flex;
            justify-content: space-between;
            margin: 1rem 0;
            font-size: 0.9rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            opacity: 0.8;
        }

        .class-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .capacity-bar {
            background: rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            height: 0.5rem;
            margin: 0.5rem 0;
            overflow: hidden;
        }

        .capacity-fill {
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #16a34a);
            transition: width 0.3s ease;
        }

        .capacity-fill.warning {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }

        .capacity-fill.danger {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }

        .flash-message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .flash-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .flash-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            opacity: 0.7;
        }

        .no-data .icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #64748b;
        }

        .school-limits {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }

        .school-limits h4 {
            color: #93c5fd;
            margin-bottom: 0.5rem;
        }

        .limits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .limit-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .limit-current {
            font-weight: bold;
            color: #3b82f6;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .tab-navigation {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .content-header {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }
            
            .filters {
                justify-content: space-between;
            }
            
            .create-form,
            .upload-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .classes-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>🏫 Schuladmin Dashboard</h1>
            <div class="school-info">
                <?php echo escape($school['name']); ?> - <?php echo escape($school['location']); ?>
            </div>
        </div>
        <div class="user-info">
            <span>👋 <?php echo escape($user['name']); ?></span>
            <a href="../logout.php" class="btn btn-secondary btn-sm">Abmelden</a>
        </div>
    </div>

    <div class="container">
        <?php if ($flashMessage): ?>
            <div class="flash-message flash-<?php echo $flashMessage['type']; ?>">
                <?php echo escape($flashMessage['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <a href="?tab=news" class="tab-btn <?php echo $activeTab === 'news' ? 'active' : ''; ?>">
                📢 News
            </a>
            <a href="?tab=klassen" class="tab-btn <?php echo $activeTab === 'klassen' ? 'active' : ''; ?>">
                🏫 Klassen verwalten
            </a>
            <a href="?tab=lehrer" class="tab-btn <?php echo $activeTab === 'lehrer' ? 'active' : ''; ?>">
                👨‍🏫 Lehrer verwalten
            </a>
            <a href="?tab=faecher" class="tab-btn <?php echo $activeTab === 'faecher' ? 'active' : ''; ?>">
                📚 Fächer verwalten
            </a>
            <a href="?tab=staerken" class="tab-btn <?php echo $activeTab === 'staerken' ? 'active' : ''; ?>">
                💪 Stärken verwalten
            </a>
            <a href="?tab=dokumente" class="tab-btn <?php echo $activeTab === 'dokumente' ? 'active' : ''; ?>">
                📄 Schreiben verwalten
            </a>
            <a href="?tab=uebersicht" class="tab-btn <?php echo $activeTab === 'uebersicht' ? 'active' : ''; ?>">
                📊 Übersicht
            </a>
        </div>

        <!-- News Tab -->
        <div id="news" class="tab-content <?php echo $activeTab === 'news' ? 'active' : ''; ?>">
            <div class="content-header">
                <h2 class="content-title">📢 Nachrichten vom Superadmin</h2>
            </div>
            <div class="no-data">
                <div class="icon">📬</div>
                <h3>Keine neuen Nachrichten</h3>
                <p>Hier werden Nachrichten vom Superadmin angezeigt.</p>
            </div>
        </div>

        <!-- Klassen verwalten Tab -->
        <div id="klassen" class="tab-content <?php echo $activeTab === 'klassen' ? 'active' : ''; ?>">
            <div class="content-header">
                <h2 class="content-title">🏫 Klassen verwalten</h2>
            </div>

            <!-- Schullimits anzeigen -->
            <div class="school-limits">
                <h4>📋 Schullimits</h4>
                <div class="limits-grid">
                    <div class="limit-item">
                        <span>Klassen:</span>
                        <span class="limit-current"><?php echo count($classes); ?> / <?php echo $school['max_classes']; ?></span>
                    </div>
                    <div class="limit-item">
                        <span>Max. Schüler/Klasse:</span>
                        <span class="limit-current"><?php echo $school['max_students_per_class']; ?></span>
                    </div>
                </div>
            </div>

            <!-- Filter -->
            <div class="filters">
                <span style="font-weight: 500;">Filter:</span>
                <div class="filter-group">
                    <label>nach Klasse:</label>
                    <select class="filter-select" onchange="applyFilters()">
                        <option value="all">Alle Klassen</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                    <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo escape($class['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($schoolYears)): ?>
                <div class="filter-group">
                    <label>nach Schuljahr:</label>
                    <select class="filter-select" onchange="applyFilters()">
                        <option value="all">Alle Jahre</option>
                        <?php foreach ($schoolYears as $year): ?>
                            <option value="<?php echo escape($year); ?>" 
                                    <?php echo $yearFilter === $year ? 'selected' : ''; ?>>
                                <?php echo escape($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- Neue Klasse anlegen -->
            <div class="create-section">
                <h3>➕ Neue Klasse anlegen</h3>
                <form class="create-form" onsubmit="createClass(event)">
                    <div class="form-group">
                        <label for="className">Klassenname</label>
                        <input type="text" id="className" name="class_name" 
                               placeholder="z.B. 9a, 10b" required>
                    </div>
                    <?php 
                    // Schuljahr-Feld nur anzeigen wenn Spalte existiert
                    $db = getDB();
                    $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'school_year'");
                    $stmt->execute();
                    $hasSchoolYearColumn = $stmt->rowCount() > 0;
                    
                    if ($hasSchoolYearColumn): 
                    ?>
                    <div class="form-group">
                        <label for="schoolYear">Schuljahr</label>
                        <select id="schoolYear" name="school_year">
                            <option value="">Optional wählen...</option>
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?php echo $year; ?>" 
                                        <?php echo $year === $currentYear . '/' . substr($nextYear, 2) ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" 
                            <?php echo count($classes) >= $school['max_classes'] ? 'disabled' : ''; ?>>
                        ➕ Klasse anlegen
                    </button>
                </form>
            </div>

            <!-- Schüler aus Datei hochladen -->
            <div class="upload-section">
                <h3>📁 Schüler aus Datei hochladen</h3>
                
                <div class="format-info">
                    <h4>Unterstützte Formate:</h4>
                    <ul>
                        <li><strong>CSV/TXT:</strong> Ein Schüler pro Zeile</li>
                        <li><strong>Excel (XLSX):</strong> Erste Spalte mit Schülernamen</li>
                    </ul>
                    <div class="examples">
                        <strong>Beispiele:</strong><br>
                        Vorname Nachname: "Max Mustermann"<br>
                        Nachname, Vorname: "Mustermann, Max"
                    </div>
                </div>

                <form class="upload-form" onsubmit="uploadStudents(event)">
                    <div class="form-group">
                        <label for="uploadClass">Klasse auswählen</label>
                        <select id="uploadClass" name="class_id" required>
                            <option value="">Klasse auswählen...</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php 
                                    echo escape($class['name']);
                                    if (isset($class['school_year']) && !empty($class['school_year'])) {
                                        echo ' (' . escape($class['school_year']) . ')';
                                    }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nameFormat">Format</label>
                        <select id="nameFormat" name="name_format" required>
                            <option value="vorname_nachname">Vorname Nachname</option>
                            <option value="nachname_vorname">Nachname, Vorname</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="file-input-wrapper">
                            <input type="file" id="studentFile" name="student_file" 
                                   class="file-input" accept=".csv,.txt,.xlsx" 
                                   onchange="updateFileName(this)">
                            <label for="studentFile" class="file-input-label">
                                📁 Datei auswählen
                            </label>
                        </div>
                        <div class="file-name" id="fileName">Keine ausgewählt</div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        📤 Schüler hochladen
                    </button>
                </form>
            </div>

            <!-- Klassenliste -->
            <?php if (empty($classes)): ?>
                <div class="no-data">
                    <div class="icon">🏫</div>
                    <h3>Keine Klassen vorhanden</h3>
                    <p>Erstellen Sie Ihre erste Klasse über das Formular oben.</p>
                </div>
            <?php else: ?>
                <div class="classes-grid">
                    <?php foreach ($classes as $class): ?>
                        <div class="class-card">
                            <div class="class-header">
                                <div>
                                    <div class="class-name"><?php echo escape($class['name']); ?></div>
                                    <?php if (isset($class['created_at'])): ?>
                                    <div style="font-size: 0.8rem; opacity: 0.7;">
                                        Erstellt: <?php echo formatDate($class['created_at'], 'd.m.Y'); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if (isset($class['school_year']) && !empty($class['school_year'])): ?>
                                        <div class="class-year"><?php echo escape($class['school_year']); ?></div>
                                    <?php else: ?>
                                        <div class="class-year">Klasse</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="class-stats">
                                <div class="stat-item">
                                    <span>🎓</span>
                                    <span><?php echo $class['student_count']; ?> Schüler</span>
                                </div>
                                <div class="stat-item">
                                    <span>📊</span>
                                    <span><?php echo round(($class['student_count'] / $school['max_students_per_class']) * 100); ?>% belegt</span>
                                </div>
                            </div>

                            <!-- Kapazitätsbalken -->
                            <?php 
                            $capacity = ($class['student_count'] / $school['max_students_per_class']) * 100;
                            $capacityClass = $capacity >= 90 ? 'danger' : ($capacity >= 75 ? 'warning' : '');
                            ?>
                            <div class="capacity-bar">
                                <div class="capacity-fill <?php echo $capacityClass; ?>" 
                                     style="width: <?php echo min(100, $capacity); ?>%"></div>
                            </div>
                            
                            <div style="font-size: 0.8rem; text-align: center; opacity: 0.7;">
                                Beispiel: <?php 
                                // Zeige ersten Schüler als Beispiel (würde aus der Datenbank kommen)
                                echo "Maja Agatic, Lillian Joelin Blasy, Alessia Calo..."; 
                                ?>
                            </div>

                            <div class="class-actions">
                                <button class="btn btn-secondary btn-sm" onclick="editClass(<?php echo $class['id']; ?>)">
                                    ✏️ Bearbeiten
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteClass(<?php echo $class['id']; ?>)">
                                    🗑️ Löschen
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Placeholder für andere Tabs -->
        <div id="lehrer" class="tab-content <?php echo $activeTab === 'lehrer' ? 'active' : ''; ?>">
            <div class="content-header">
                <h2 class="content-title">👨‍🏫 Lehrer verwalten</h2>
            </div>
            <div class="no-data">
                <div class="icon">👨‍🏫</div>
                <h3>Lehrer verwalten</h3>
                <p>Hier können Sie Lehrer verwalten, hinzufügen und Berechtigungen vergeben.</p>
            </div>
        </div>

        <div id="faecher" class="tab-content <?php echo $activeTab === 'faecher' ? 'active' : ''; ?>">
            <div class="content-header">
                <h2 class="content-title">📚 Fächer verwalten</h2>
            </div>
            <div class="no-data">
                <div class="icon">📚</div>
                <h3>Fächer verwalten</h3>
                <p>Hier können Sie Fächer erstellen und verwalten.</p>
            </div>
        </div>

        <div id="staerken" class="tab-content <?php echo $activeTab === 'staerken' ? 'active' : ''; ?>">
            <div class="content-header">
                <h2 class="content-title">💪 Stärken verwalten</h2>
            </div>
            <div class="no-data">
                <div class="icon">💪</div>
                <h3>Stärken verwalten</h3>
                <p>Hier können Sie Stärken definieren und Bewertungskriterien festlegen.</p>
            </div>
        </div>

        <div id="dokumente" class="tab-content <?php echo $activeTab === 'dokumente' ? 'active' : ''; ?>">
            <div class="content-header">
                <h2 class="content-title">📄 Schreiben verwalten</h2>
            </div>
            <div class="no-data">
                <div class="icon">📄</div>
                <h3>Schreiben verwalten</h3>
                <p>Hier können Sie Word-Vorlagen hochladen und verwalten.</p>
            </div>
        </div>

        <div id="uebersicht" class="tab-content <?php echo $activeTab === 'uebersicht' ? 'active' : ''; ?>">
            <div class="content-header">
                <h2 class="content-title">📊 Übersicht</h2>
            </div>
            <div class="no-data">
                <div class="icon">📊</div>
                <h3>Projektübersicht</h3>
                <p>Hier erhalten Sie eine Übersicht über alle Aktivitäten in Ihrer Schule.</p>
            </div>
        </div>
    </div>

    <script>
        function applyFilters() {
            const classFilter = document.querySelector('.filter-select').value;
            const yearFilter = document.querySelectorAll('.filter-select')[1].value;
            
            const url = new URL(window.location);
            url.searchParams.set('tab', 'klassen');
            url.searchParams.set('class_filter', classFilter);
            url.searchParams.set('year_filter', yearFilter);
            window.location.href = url.toString();
        }

        function createClass(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('action', 'create_class');
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Fehler: ' + data.message);
                }
            })
            .catch(error => {
                alert('Ein Fehler ist aufgetreten.');
                console.error(error);
            });
        }

        function deleteClass(classId) {
            if (confirm('Klasse wirklich löschen? Alle Schülerdaten gehen verloren!')) {
                const formData = new FormData();
                formData.append('action', 'delete_class');
                formData.append('class_id', classId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Fehler: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Ein Fehler ist aufgetreten.');
                    console.error(error);
                });
            }
        }

        function editClass(classId) {
            // Placeholder für Klassen-Bearbeitung
            alert('Klassen-Bearbeitung wird in einem separaten Dialog implementiert.');
        }

        function uploadStudents(event) {
            event.preventDefault();
            
            const fileInput = document.getElementById('studentFile');
            if (!fileInput.files[0]) {
                alert('Bitte wählen Sie eine Datei aus.');
                return;
            }

            const formData = new FormData(event.target);
            formData.append('action', 'upload_students');
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            // Hier würde der Upload implementiert werden
            alert('Schüler-Upload wird implementiert.');
        }

        function updateFileName(input) {
            const fileName = document.getElementById('fileName');
            if (input.files[0]) {
                fileName.textContent = input.files[0].name;
            } else {
                fileName.textContent = 'Keine ausgewählt';
            }
        }
    </script>
</body>
</html>