<?php
require_once '../config.php';
require_once 'includes/class_functions.php';
require_once 'includes/student_functions.php';

// Schuladmin-Zugriff prüfen
$user = requireSchuladmin();
requireValidSchoolLicense($user['school_id']);

// Aktiver Tab
$activeTab = $_GET['tab'] ?? 'klassen';

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Error reporting für AJAX-Requests anpassen (keine HTML-Ausgabe)
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', 0);
    
    // Output buffering starten um unerwünschte Ausgaben zu verhindern
    ob_start();
    
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        ob_end_clean();
        sendErrorResponse('Sicherheitsfehler.');
    }
    
    $action = $_POST['action'] ?? '';
    
    // Actions an entsprechende Handler delegieren
    switch ($action) {
        // Klassen-Aktionen
        case 'create_class':
        case 'update_class':
        case 'delete_class':
            require_once 'ajax/class_actions.php';
            handleClassAction($action, $_POST, $user);
            break;
            
        // Schüler-Aktionen
        case 'get_students':
        case 'upload_students':
        case 'add_single_student':
        case 'update_student':
        case 'delete_student':
            require_once 'ajax/student_actions.php';
            handleStudentAction($action, $_POST, $_FILES ?? [], $user);
            break;
            
        // Weitere Module können hier ergänzt werden
        default:
            ob_end_clean();
            sendErrorResponse('Unbekannte Aktion.');
    }
    exit;
}

// Daten für die Ansicht laden
$school = getSchoolById($user['school_id']);
$flashMessage = getFlashMessage();

// Tab-spezifische Daten laden
switch ($activeTab) {
    case 'klassen':
        $classFilter = $_GET['class_filter'] ?? 'all';
        $yearFilter = $_GET['year_filter'] ?? 'all';
        $classes = getSchoolClasses($user['school_id'], $classFilter, $yearFilter);
        $schoolYears = getSchoolYears($user['school_id']);
        
        // Prüfen ob school_year Spalte existiert
        $db = getDB();
        $stmt = $db->prepare("SHOW COLUMNS FROM classes LIKE 'school_year'");
        $stmt->execute();
        $hasSchoolYearColumn = $stmt->rowCount() > 0;
        
        // Aktuelle Schuljahre für Dropdown
        $currentYear = date('Y');
        $nextYear = $currentYear + 1;
        $availableYears = [
            ($currentYear - 1) . '/' . substr($currentYear, 2),
            $currentYear . '/' . substr($nextYear, 2),
            $nextYear . '/' . substr($nextYear + 1, 2)
        ];
        break;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schuladmin - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <?php if ($flashMessage): ?>
            <div class="flash-message flash-<?php echo $flashMessage['type']; ?>">
                <?php echo escape($flashMessage['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <?php include 'includes/tab_navigation.php'; ?>

        <!-- Tab-Inhalte -->
        <div class="tab-contents">
            <?php
            switch ($activeTab) {
                case 'news':
                    include 'tabs/news_tab.php';
                    break;
                case 'klassen':
                    include 'tabs/klassen_tab.php';
                    break;
                case 'lehrer':
                    include 'tabs/lehrer_tab.php';
                    break;
                case 'faecher':
                    include 'tabs/faecher_tab.php';
                    break;
                case 'staerken':
                    include 'tabs/staerken_tab.php';
                    break;
                case 'dokumente':
                    include 'tabs/dokumente_tab.php';
                    break;
                case 'uebersicht':
                    include 'tabs/uebersicht_tab.php';
                    break;
            }
            ?>
        </div>
    </div>

    <!-- Modals -->
    <?php include 'includes/modals.php'; ?>

    <script src="assets/admin.js"></script>
</body>
</html>