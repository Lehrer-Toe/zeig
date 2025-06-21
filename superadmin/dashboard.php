<?php
require_once '../config.php';

// Null-sichere escape() Funktion
if (!function_exists('escape')) {
    function escape($string) {
        if ($string === null || $string === '') {
            return '';
        }
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

// Fehlende Funktionen hinzufügen
if (!function_exists('getAllSchools')) {
    function getAllSchools() {
        $db = getDB();
        
        $sql = "SELECT s.*, 
                       COUNT(DISTINCT c.id) as class_count,
                       COUNT(DISTINCT u.id) as teacher_count,
                       COALESCE(student_counts.student_count, 0) as student_count
                FROM schools s 
                LEFT JOIN classes c ON s.id = c.school_id AND c.is_active = 1
                LEFT JOIN users u ON s.id = u.school_id AND u.user_type = 'lehrer' AND u.is_active = 1
                LEFT JOIN (
                    SELECT c.school_id, COUNT(st.id) as student_count
                    FROM classes c
                    LEFT JOIN students st ON c.id = st.class_id AND st.is_active = 1
                    WHERE c.is_active = 1
                    GROUP BY c.school_id
                ) student_counts ON s.id = student_counts.school_id
                GROUP BY s.id
                ORDER BY s.name";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

if (!function_exists('getDashboardStats')) {
    function getDashboardStats() {
        $db = getDB();
        
        $stats = [];
        
        // Total schools
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM schools");
        $stmt->execute();
        $stats['total_schools'] = $stmt->fetch()['count'];
        
        // Active schools
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM schools WHERE is_active = 1 AND license_until >= CURDATE()");
        $stmt->execute();
        $stats['active_schools'] = $stmt->fetch()['count'];
        
        // Total classes (nur aktive)
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE is_active = 1");
            $stmt->execute();
            $stats['total_classes'] = $stmt->fetch()['count'];
        } catch (Exception $e) {
            $stats['total_classes'] = 0;
        }
        
        // Total students (nur aktive Schüler aus aktiven Klassen)
        try {
            $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                // Prüfen ob classes Tabelle is_active hat
                $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'is_active'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as count 
                        FROM students s 
                        JOIN classes c ON s.class_id = c.id 
                        WHERE s.is_active = 1 
                        AND c.is_active = 1
                    ");
                } else {
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE is_active = 1");
                }
                $stmt->execute();
                $stats['total_students'] = $stmt->fetch()['count'];
            } else {
                $stats['total_students'] = 0;
            }
        } catch (Exception $e) {
            $stats['total_students'] = 0;
        }
        
        // Total users
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
        $stmt->execute();
        $stats['total_users'] = $stmt->fetch()['count'];
        
        // Expiring licenses (within 30 days)
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM schools WHERE is_active = 1 AND license_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        $stmt->execute();
        $stats['expiring_licenses'] = $stmt->fetch()['count'];
        
        return $stats;
    }
}

if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('sendErrorResponse')) {
    function sendErrorResponse($message = 'Ein Fehler ist aufgetreten', $status = 400) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('sendSuccessResponse')) {
    function sendSuccessResponse($message = 'Erfolgreich', $data = null) {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('deleteSchool')) {
    function deleteSchool($schoolId) {
        $db = getDB();
        
        try {
            $db->beginTransaction();
            
            // Delete related data first
            $stmt = $db->prepare("DELETE FROM students WHERE school_id = ?");
            $stmt->execute([$schoolId]);
            
            $stmt = $db->prepare("DELETE FROM classes WHERE school_id = ?");
            $stmt->execute([$schoolId]);
            
            $stmt = $db->prepare("DELETE FROM users WHERE school_id = ?");
            $stmt->execute([$schoolId]);
            
            // Delete school
            $stmt = $db->prepare("DELETE FROM schools WHERE id = ?");
            $stmt->execute([$schoolId]);
            
            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error deleting school: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status, $text = null) {
        $classes = [
            'active' => 'badge-success',
            'inactive' => 'badge-danger',
            'expired' => 'badge-danger',
            'expiring' => 'badge-warning',
        ];
        
        $texts = [
            'active' => 'Aktiv',
            'inactive' => 'Inaktiv',
            'expired' => 'Abgelaufen',
            'expiring' => 'Läuft ab',
        ];
        
        $class = $classes[$status] ?? 'badge-secondary';
        $displayText = $text ?? $texts[$status] ?? ucfirst($status);
        
        return '<span class="badge ' . $class . '">' . escape($displayText) . '</span>';
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'd.m.Y') {
        if (empty($date) || $date === '0000-00-00') {
            return '';
        }
        
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            $dateObj = new DateTime($date);
        }
        
        return $dateObj->format($format);
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage() {
        if (isset($_SESSION['flash_message'])) {
            $message = [
                'message' => $_SESSION['flash_message'],
                'type' => $_SESSION['flash_type'] ?? 'success'
            ];
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            return $message;
        }
        return null;
    }
}

// Superadmin-Zugriff prüfen
$user = requireSuperadmin();

// Sicherstellen, dass 'name' existiert
if (!isset($user['name']) || $user['name'] === null) {
    $user['name'] = $user['email'] ?? 'Super Administrator';
}

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        sendErrorResponse('Sicherheitsfehler.');
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'toggle_status':
            $schoolId = (int)($_POST['school_id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 0);
            
            $db = getDB();
            $stmt = $db->prepare("UPDATE schools SET is_active = ? WHERE id = ?");
            if ($stmt->execute([$isActive, $schoolId])) {
                sendSuccessResponse('Status erfolgreich geändert.');
            } else {
                sendErrorResponse('Fehler beim Ändern des Status.');
            }
            break;
            
        case 'delete_school':
            $schoolId = (int)($_POST['school_id'] ?? 0);
            
            if (deleteSchool($schoolId)) {
                sendSuccessResponse('Schule erfolgreich gelöscht.');
            } else {
                sendErrorResponse('Fehler beim Löschen der Schule.');
            }
            break;
    }
}

// Schulen laden
$schools = getAllSchools();
$stats = getDashboardStats();
$flashMessage = getFlashMessage();

// Filterung
$viewMode = $_GET['view'] ?? 'list';
$statusFilter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

if ($statusFilter !== 'all') {
    $schools = array_filter($schools, function($school) use ($statusFilter) {
        switch ($statusFilter) {
            case 'active':
                return $school['is_active'] && $school['license_until'] >= date('Y-m-d');
            case 'inactive':
                return !$school['is_active'];
            case 'expired':
                return $school['license_until'] < date('Y-m-d');
            case 'expiring':
                return $school['license_until'] >= date('Y-m-d') && 
                       $school['license_until'] <= date('Y-m-d', strtotime('+30 days'));
            default:
                return true;
        }
    });
}

if ($search) {
    $schools = array_filter($schools, function($school) use ($search) {
        return stripos($school['name'], $search) !== false ||
               stripos($school['location'], $search) !== false ||
               stripos($school['contact_person'], $search) !== false;
    });
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Dashboard - <?php echo APP_NAME; ?></title>
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
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #3b82f6;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .controls-left {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .view-toggle {
            display: flex;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 0.5rem;
            overflow: hidden;
        }

        .view-toggle button {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-toggle button.active {
            background: #3b82f6;
            color: white;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 0.5rem 2.5rem 0.5rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
            width: 250px;
        }

        .search-box input::placeholder {
            color: #64748b;
        }

        .search-box .search-icon {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
        }

        .filter-select {
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(100, 116, 139, 0.3);
            border-radius: 0.5rem;
            color: white;
        }

        .filter-select option {
            background: #1e293b;
            color: white;
        }

        .schools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .school-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(100, 116, 139, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .school-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .school-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .school-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 0.25rem;
        }

        .school-location {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .school-details {
            margin: 1rem 0;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .school-details div {
            margin-bottom: 0.5rem;
        }

        .school-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 0.75rem;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .schools-table {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 1rem;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .schools-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .schools-table th,
        .schools-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(100, 116, 139, 0.2);
        }

        .schools-table th {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            font-weight: 600;
        }

        .schools-table tr:hover {
            background: rgba(59, 130, 246, 0.05);
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

        .flash-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            opacity: 0.7;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .controls-left {
                justify-content: space-between;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .schools-grid {
                grid-template-columns: 1fr;
            }
            
            /* Button-Gruppe auf Mobilgeräten */
            .controls > div:last-child {
                width: 100%;
            }
            
            .controls > div:last-child > * {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎓 Superadmin Dashboard</h1>
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

        <!-- Statistiken -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_schools']; ?></div>
                <div class="stat-label">📚 Schulen gesamt</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_schools']; ?></div>
                <div class="stat-label">✅ Aktive Schulen</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_classes']; ?></div>
                <div class="stat-label">🏫 Klassen gesamt</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label">🎓 Schüler gesamt</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">👥 Benutzer gesamt</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f59e0b;"><?php echo $stats['expiring_licenses']; ?></div>
                <div class="stat-label">⚠️ Lizenzen laufen ab</div>
            </div>
        </div>

        <!-- Steuerungselemente -->
        <div class="controls">
            <div class="controls-left">
                <div class="view-toggle">
                    <button class="<?php echo $viewMode === 'cards' ? 'active' : ''; ?>" 
                            onclick="setViewMode('cards')">🔳 Kacheln</button>
                    <button class="<?php echo $viewMode === 'list' ? 'active' : ''; ?>" 
                            onclick="setViewMode('list')">📋 Liste</button>
                </div>
                
                <select class="filter-select" onchange="setStatusFilter(this.value)">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Alle Status</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Aktiv</option>
                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inaktiv</option>
                    <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Abgelaufen</option>
                    <option value="expiring" <?php echo $statusFilter === 'expiring' ? 'selected' : ''; ?>>Läuft ab</option>
                </select>
                
                <div class="search-box">
                    <input type="text" placeholder="Schulen suchen..." 
                           value="<?php echo escape($search); ?>" 
                           onkeyup="searchSchools(this.value)">
                    <span class="search-icon">🔍</span>
                </div>
            </div>
            
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="schule_anlegen.php" class="btn btn-primary">
                    ➕ Neue Schule
                </a>
                <a href="security_monitoring.php" class="btn btn-danger">
                    🛡️ Sicherheits-Monitoring
                </a>
                <a href="schule_schuljahr.php" class="btn btn-danger" 
                   onclick="return confirm('⚠️ Möchten Sie wirklich zum Schuljahreswechsel? Dies löscht alle Klassen und Schüler!')">
                    🎓 Neues Schuljahr
                </a>
            </div>
        </div>

        <!-- Schulenliste -->
        <?php if (empty($schools)): ?>
            <div class="no-data">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🏫</div>
                <h3>Keine Schulen gefunden</h3>
                <p>Es wurden keine Schulen gefunden, die Ihren Suchkriterien entsprechen.</p>
            </div>
        <?php elseif ($viewMode === 'cards'): ?>
            <div class="schools-grid">
                <?php foreach ($schools as $school): ?>
                    <div class="school-card">
                        <div class="school-header">
                            <div>
                                <div class="school-name"><?php echo escape($school['name']); ?></div>
                                <div class="school-location">📍 <?php echo escape($school['location']); ?></div>
                            </div>
                            <div>
                                <?php
                                if (!$school['is_active']) {
                                    echo getStatusBadge('inactive');
                                } elseif ($school['license_until'] < date('Y-m-d')) {
                                    echo getStatusBadge('expired');
                                } elseif ($school['license_until'] <= date('Y-m-d', strtotime('+30 days'))) {
                                    echo getStatusBadge('expiring');
                                } else {
                                    echo getStatusBadge('active');
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="school-details">
                            <div><strong>Schulart:</strong> <?php echo escape($school['school_type_custom'] ?: $school['school_type']); ?></div>
                            <div><strong>Kontakt:</strong> <?php echo escape($school['contact_person']); ?></div>
                            <div><strong>E-Mail:</strong> <?php echo escape($school['contact_email']); ?></div>
                            <div><strong>Lizenz bis:</strong> <?php echo formatDate($school['license_until']); ?></div>
                            <div><strong>Kapazitäten:</strong> 
                                <?php echo $school['class_count']; ?>/<?php echo $school['max_classes']; ?> Klassen | 
                                Max. <?php echo $school['max_students_per_class']; ?> Schüler/Klasse
                            </div>
                            <div><strong>Aktuelle Zahlen:</strong> 
                                <?php echo $school['teacher_count']; ?> Lehrer | 
                                <?php echo $school['student_count']; ?> Schüler
                            </div>
                        </div>
                        
                        <div class="school-actions">
                            <a href="schule_bearbeiten.php?id=<?php echo $school['id']; ?>" class="btn btn-secondary btn-sm">
                                ✏️ Bearbeiten
                            </a>
                            <button onclick="toggleSchoolStatus(<?php echo $school['id']; ?>, <?php echo $school['is_active'] ? 0 : 1; ?>)" 
                                    class="btn <?php echo $school['is_active'] ? 'btn-danger' : 'btn-primary'; ?> btn-sm">
                                <?php echo $school['is_active'] ? '❌ Deaktivieren' : '✅ Aktivieren'; ?>
                            </button>
                            <button onclick="deleteSchool(<?php echo $school['id']; ?>)" class="btn btn-danger btn-sm">
                                🗑️ Löschen
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="schools-table">
                <table>
                    <thead>
                        <tr>
                            <th>Schule</th>
                            <th>Ort</th>
                            <th>Kontakt</th>
                            <th>Lizenz</th>
                            <th>Kapazitäten</th>
                            <th>Status</th>
                            <th>Benutzer</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schools as $school): ?>
                            <tr>
                                <td>
                                    <div class="school-name"><?php echo escape($school['name']); ?></div>
                                    <div style="font-size: 0.8rem; opacity: 0.7;">
                                        <?php echo escape($school['school_type_custom'] ?: $school['school_type']); ?>
                                    </div>
                                </td>
                                <td><?php echo escape($school['location']); ?></td>
                                <td>
                                    <div><?php echo escape($school['contact_person']); ?></div>
                                    <div style="font-size: 0.8rem; opacity: 0.7;">
                                        <?php echo escape($school['contact_email']); ?>
                                    </div>
                                </td>
                                <td><?php echo formatDate($school['license_until']); ?></td>
                                <td>
                                    <div style="font-size: 0.8rem;">
                                        🏫 <?php echo $school['class_count']; ?>/<?php echo $school['max_classes']; ?> Klassen
                                    </div>
                                    <div style="font-size: 0.8rem; opacity: 0.7;">
                                        Max. <?php echo $school['max_students_per_class']; ?> Schüler/Klasse
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    if (!$school['is_active']) {
                                        echo getStatusBadge('inactive');
                                    } elseif ($school['license_until'] < date('Y-m-d')) {
                                        echo getStatusBadge('expired');
                                    } elseif ($school['license_until'] <= date('Y-m-d', strtotime('+30 days'))) {
                                        echo getStatusBadge('expiring');
                                    } else {
                                        echo getStatusBadge('active');
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div>👨‍🏫 <?php echo $school['teacher_count']; ?></div>
                                    <div>🎓 <?php echo $school['student_count']; ?></div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                        <a href="schule_bearbeiten.php?id=<?php echo $school['id']; ?>" 
                                           class="btn btn-secondary btn-sm">✏️</a>
                                        <button onclick="toggleSchoolStatus(<?php echo $school['id']; ?>, <?php echo $school['is_active'] ? 0 : 1; ?>)" 
                                                class="btn <?php echo $school['is_active'] ? 'btn-danger' : 'btn-primary'; ?> btn-sm">
                                            <?php echo $school['is_active'] ? '❌' : '✅'; ?>
                                        </button>
                                        <button onclick="deleteSchool(<?php echo $school['id']; ?>)" 
                                                class="btn btn-danger btn-sm">🗑️</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function setViewMode(mode) {
            const url = new URL(window.location);
            url.searchParams.set('view', mode);
            window.location.href = url.toString();
        }

        function setStatusFilter(status) {
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }

        function searchSchools(query) {
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(() => {
                const url = new URL(window.location);
                if (query) {
                    url.searchParams.set('search', query);
                } else {
                    url.searchParams.delete('search');
                }
                window.location.href = url.toString();
            }, 500);
        }

        function toggleSchoolStatus(schoolId, newStatus) {
            if (confirm(newStatus ? 'Schule aktivieren?' : 'Schule deaktivieren?')) {
                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('school_id', schoolId);
                formData.append('is_active', newStatus);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                fetch('dashboard.php', {
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

        function deleteSchool(schoolId) {
            if (confirm('Schule wirklich löschen? Alle Daten gehen verloren!')) {
                const formData = new FormData();
                formData.append('action', 'delete_school');
                formData.append('school_id', schoolId);
                formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

                fetch('dashboard.php', {
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
    </script>
</body>
</html>