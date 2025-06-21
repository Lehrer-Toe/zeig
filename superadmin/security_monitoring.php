<?php
require_once '../config.php';

// Nur Superadmin hat Zugriff
if (!isLoggedIn() || $_SESSION['user_type'] !== 'superadmin') {
    header('Location: ../index.php');
    exit();
}

$db = getDB();
$error = '';
$success = '';

// Prüfen ob erforderliche Tabellen existieren
$tablesExist = false;
try {
    $stmt = $db->prepare("SHOW TABLES LIKE 'login_attempts'");
    $stmt->execute();
    $loginAttemptsExists = $stmt->rowCount() > 0;
    
    $stmt = $db->prepare("SHOW TABLES LIKE 'account_lockouts'");
    $stmt->execute();
    $accountLockoutsExists = $stmt->rowCount() > 0;
    
    $tablesExist = $loginAttemptsExists && $accountLockoutsExists;
} catch (Exception $e) {
    $error = "Datenbankfehler: " . $e->getMessage();
}

// Funktionen nur definieren wenn sie nicht existieren
if (!function_exists('getLoginAttemptStatistics')) {
    function getLoginAttemptStatistics($hours = 24) {
        $db = getDB();
        
        try {
            // Prüfen ob Tabellen existieren
            $stmt = $db->prepare("SHOW TABLES LIKE 'login_attempts'");
            $stmt->execute();
            if ($stmt->rowCount() == 0) {
                return [
                    'totals' => ['total_attempts' => 0, 'successful_attempts' => 0, 'failed_attempts' => 0],
                    'top_failed_ips' => [],
                    'current_lockouts' => [],
                    'hourly_stats' => []
                ];
            }
            
            // Gesamte Versuche
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_attempts,
                    SUM(success) as successful_attempts,
                    COUNT(*) - SUM(success) as failed_attempts
                FROM login_attempts 
                WHERE attempt_time > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$hours]);
            $totals = $stmt->fetch();
            
            // Top Failed IPs
            $stmt = $db->prepare("
                SELECT ip_address, COUNT(*) as failed_count
                FROM login_attempts 
                WHERE success = 0 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY ip_address 
                ORDER BY failed_count DESC 
                LIMIT 10
            ");
            $stmt->execute([$hours]);
            $topFailedIPs = $stmt->fetchAll();
            
            // Aktuelle Sperrungen
            $stmt = $db->prepare("
                SELECT identifier, identifier_type, locked_until, attempts_count, reason
                FROM account_lockouts 
                WHERE locked_until > NOW()
                ORDER BY locked_until DESC
            ");
            $stmt->execute();
            $currentLockouts = $stmt->fetchAll();
            
            return [
                'totals' => $totals,
                'top_failed_ips' => $topFailedIPs,
                'current_lockouts' => $currentLockouts,
                'hourly_stats' => []
            ];
            
        } catch (Exception $e) {
            error_log("Login statistics error: " . $e->getMessage());
            return [
                'totals' => ['total_attempts' => 0, 'successful_attempts' => 0, 'failed_attempts' => 0],
                'top_failed_ips' => [],
                'current_lockouts' => [],
                'hourly_stats' => []
            ];
        }
    }
}

if (!function_exists('unlockAccount')) {
    function unlockAccount($identifier, $identifierType = null) {
        $db = getDB();
        
        try {
            if ($identifierType) {
                $stmt = $db->prepare("DELETE FROM account_lockouts WHERE identifier = ? AND identifier_type = ?");
                $result = $stmt->execute([$identifier, $identifierType]);
            } else {
                $stmt = $db->prepare("DELETE FROM account_lockouts WHERE identifier = ?");
                $result = $stmt->execute([$identifier]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Unlock account error: " . $e->getMessage());
            return false;
        }
    }
}

// CSV Export verarbeiten
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tablesExist) {
    try {
        // Filter anwenden (gleiche Logik wie für die Anzeige)
        $sql = "SELECT ip_address, email, attempt_time, success, reason, user_agent FROM login_attempts WHERE 1=1";
        $params = [];
        
        $timeFilter = $_GET['time'] ?? '24h';
        $statusFilter = $_GET['status'] ?? 'all';
        $ipFilter = $_GET['ip'] ?? '';
        $emailFilter = $_GET['email'] ?? '';
        $browserFilter = $_GET['browser'] ?? '';
        $reasonFilter = $_GET['reason'] ?? '';
        
        $timeMapping = ['1h' => 1, '24h' => 24, '7d' => 168, '30d' => 720, 'all' => null];
        $hours = $timeMapping[$timeFilter] ?? 24;
        
        if ($hours) {
            $sql .= " AND attempt_time > DATE_SUB(NOW(), INTERVAL ? HOUR)";
            $params[] = $hours;
        }
        
        if ($statusFilter === 'success') {
            $sql .= " AND success = 1";
        } elseif ($statusFilter === 'failed') {
            $sql .= " AND success = 0";
        }
        
        if ($ipFilter) {
            $sql .= " AND ip_address LIKE ?";
            $params[] = "%$ipFilter%";
        }
        
        if ($emailFilter) {
            $sql .= " AND email LIKE ?";
            $params[] = "%$emailFilter%";
        }
        
        if ($browserFilter) {
            $sql .= " AND user_agent LIKE ?";
            $params[] = "%$browserFilter%";
        }
        
        if ($reasonFilter) {
            $sql .= " AND reason LIKE ?";
            $params[] = "%$reasonFilter%";
        }
        
        $sql .= " ORDER BY attempt_time DESC LIMIT 5000";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        // CSV Headers setzen
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="security_log_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // CSV Output
        $output = fopen('php://output', 'w');
        
        // BOM für UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // CSV Header
        fputcsv($output, [
            'Zeitpunkt',
            'IP-Adresse', 
            'E-Mail',
            'Status',
            'Grund',
            'Browser',
            'User-Agent'
        ], ';');
        
        // Daten
        foreach ($data as $row) {
            $browser = '';
            $ua = $row['user_agent'];
            if (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
            elseif (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
            elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
            elseif (strpos($ua, 'Edge') !== false) $browser = 'Edge';
            else $browser = 'Unbekannt';
            
            fputcsv($output, [
                date('d.m.Y H:i:s', strtotime($row['attempt_time'])),
                $row['ip_address'],
                $row['email'] ?? '',
                $row['success'] ? 'Erfolgreich' : 'Fehlgeschlagen',
                $row['reason'] ?? '',
                $browser,
                $row['user_agent']
            ], ';');
        }
        
        fclose($output);
        exit;
        
    } catch (Exception $e) {
        error_log("CSV Export error: " . $e->getMessage());
        header('Location: ?error=export_failed');
        exit;
    }
}

// Unlock-Action verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unlock' && $tablesExist) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'CSRF-Fehler';
    } else {
        $identifier = $_POST['identifier'] ?? '';
        $identifierType = $_POST['identifier_type'] ?? '';
        
        if (unlockAccount($identifier, $identifierType)) {
            $success = "Account {$identifier} ({$identifierType}) wurde entsperrt.";
        } else {
            $error = "Fehler beim Entsperren des Accounts.";
        }
    }
}

// Clean-Up Action verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cleanup' && $tablesExist) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'CSRF-Fehler';
    } else {
        $cleanupType = $_POST['cleanup_type'] ?? '';
        $cleanupDays = (int)($_POST['cleanup_days'] ?? 7);
        
        try {
            $db->beginTransaction();
            
            switch ($cleanupType) {
                case 'old_attempts':
                    $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$cleanupDays]);
                    $deleted = $stmt->rowCount();
                    $success = "Alte Login-Versuche gelöscht: {$deleted} Einträge (älter als {$cleanupDays} Tage)";
                    break;
                    
                case 'failed_only':
                    $stmt = $db->prepare("DELETE FROM login_attempts WHERE success = 0 AND attempt_time < DATE_SUB(NOW(), INTERVAL ? DAY)");
                    $stmt->execute([$cleanupDays]);
                    $deleted = $stmt->rowCount();
                    $success = "Fehlgeschlagene Login-Versuche gelöscht: {$deleted} Einträge (älter als {$cleanupDays} Tage)";
                    break;
                    
                case 'all_attempts':
                    $stmt = $db->prepare("DELETE FROM login_attempts");
                    $stmt->execute();
                    $deleted = $stmt->rowCount();
                    $success = "Alle Login-Versuche gelöscht: {$deleted} Einträge";
                    break;
                    
                case 'expired_lockouts':
                    $stmt = $db->prepare("DELETE FROM account_lockouts WHERE locked_until < NOW()");
                    $stmt->execute();
                    $deleted = $stmt->rowCount();
                    $success = "Abgelaufene Account-Sperrungen gelöscht: {$deleted} Einträge";
                    break;
                    
                default:
                    throw new Exception('Ungültiger Cleanup-Typ');
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Fehler beim Aufräumen: " . $e->getMessage();
        }
    }
}

// Statistiken abrufen (nur wenn Tabellen existieren)
$stats = [];
$recentAttempts = [];

// Filter-Parameter
$timeFilter = $_GET['time'] ?? '24h';
$statusFilter = $_GET['status'] ?? 'all';
$ipFilter = $_GET['ip'] ?? '';
$emailFilter = $_GET['email'] ?? '';
$browserFilter = $_GET['browser'] ?? '';
$reasonFilter = $_GET['reason'] ?? '';

if ($tablesExist) {
    // Zeitraum bestimmen
    $timeMapping = [
        '1h' => 1,
        '24h' => 24,
        '7d' => 168, // 7 * 24
        '30d' => 720, // 30 * 24
        'all' => null
    ];
    
    $hours = $timeMapping[$timeFilter] ?? 24;
    $stats = getLoginAttemptStatistics($hours ?: 8760); // 1 Jahr wenn 'all'
    
    try {
        // Erweiterte Login-Versuche mit Filtern
        $sql = "SELECT ip_address, email, attempt_time, success, reason, user_agent FROM login_attempts WHERE 1=1";
        $params = [];
        
        // Zeit-Filter
        if ($hours) {
            $sql .= " AND attempt_time > DATE_SUB(NOW(), INTERVAL ? HOUR)";
            $params[] = $hours;
        }
        
        // Status-Filter
        if ($statusFilter === 'success') {
            $sql .= " AND success = 1";
        } elseif ($statusFilter === 'failed') {
            $sql .= " AND success = 0";
        }
        
        // IP-Filter
        if ($ipFilter) {
            $sql .= " AND ip_address LIKE ?";
            $params[] = "%$ipFilter%";
        }
        
        // Email-Filter
        if ($emailFilter) {
            $sql .= " AND email LIKE ?";
            $params[] = "%$emailFilter%";
        }
        
        // Browser-Filter
        if ($browserFilter) {
            $sql .= " AND user_agent LIKE ?";
            $params[] = "%$browserFilter%";
        }
        
        // Grund-Filter
        if ($reasonFilter) {
            $sql .= " AND reason LIKE ?";
            $params[] = "%$reasonFilter%";
        }
        
        $sql .= " ORDER BY attempt_time DESC LIMIT 200";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $recentAttempts = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Recent attempts error: " . $e->getMessage());
    }
} else {
    $stats = [
        'totals' => ['total_attempts' => 0, 'successful_attempts' => 0, 'failed_attempts' => 0],
        'top_failed_ips' => [],
        'current_lockouts' => [],
        'hourly_stats' => []
    ];
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sicherheits-Monitoring - <?php echo APP_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f0f23, #1a1a2e, #16213e);
            color: #e2e8f0;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: rgba(15, 15, 35, 0.95);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(59, 130, 246, 0.1);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(59, 130, 246, 0.2);
            background: rgba(0, 0, 0, 0.3);
            padding: 1.5rem 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
        }

        .header h1 {
            color: #60a5fa;
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-shadow: 0 0 20px rgba(96, 165, 250, 0.3);
        }

        .nav-link {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .nav-link:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        .controls-section {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.9rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .filter-input, .filter-select {
            padding: 0.6rem;
            background: rgba(15, 15, 35, 0.8);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 8px;
            color: #e2e8f0;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
            background: rgba(15, 15, 35, 1);
        }

        .filter-select option {
            background: #1a1a2e;
            color: #e2e8f0;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(59, 130, 246, 0.2);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }

        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 15px;
            padding: 1.5rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: #60a5fa;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-card.success {
            border-color: rgba(16, 185, 129, 0.3);
        }

        .stat-card.danger {
            border-color: rgba(239, 68, 68, 0.3);
        }

        .stat-card.warning {
            border-color: rgba(245, 158, 11, 0.3);
        }

        .stat-card h3 {
            color: #94a3b8;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #60a5fa;
            text-shadow: 0 0 20px rgba(96, 165, 250, 0.3);
        }

        .stat-card.success .stat-number {
            color: #10b981;
            text-shadow: 0 0 20px rgba(16, 185, 129, 0.3);
        }

        .stat-card.danger .stat-number {
            color: #ef4444;
            text-shadow: 0 0 20px rgba(239, 68, 68, 0.3);
        }

        .stat-card.warning .stat-number {
            color: #f59e0b;
            text-shadow: 0 0 20px rgba(245, 158, 11, 0.3);
        }

        .section {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(59, 130, 246, 0.1);
        }

        .section h2 {
            color: #60a5fa;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.4rem;
            text-shadow: 0 0 20px rgba(96, 165, 250, 0.3);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            overflow: hidden;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(59, 130, 246, 0.1);
        }

        .table th {
            background: rgba(59, 130, 246, 0.2);
            font-weight: 600;
            color: #60a5fa;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        .table tr:hover {
            background: rgba(59, 130, 246, 0.1);
        }

        .status-success {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-failed {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .lockout-item {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .lockout-info {
            flex: 1;
        }

        .lockout-meta {
            font-size: 0.9rem;
            color: #94a3b8;
            margin-top: 0.3rem;
        }

        .time-badge {
            background: #374151;
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }

        .cleanup-section {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .cleanup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .refresh-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
            box-shadow: 0 5px 15px rgba(96, 165, 250, 0.4);
            transition: all 0.3s ease;
        }

        .refresh-btn:hover {
            transform: scale(1.1);
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            box-shadow: 0 8px 25px rgba(96, 165, 250, 0.5);
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            opacity: 0.7;
        }

        .no-data h3 {
            margin-bottom: 1rem;
            color: #60a5fa;
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .container {
                padding: 1rem;
            }

            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
                padding: 1rem;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .controls-grid {
                grid-template-columns: 1fr;
            }

            .cleanup-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .table {
                font-size: 0.8rem;
            }

            .table th,
            .table td {
                padding: 0.5rem;
            }

            .lockout-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .section {
                padding: 1rem;
            }

            .refresh-btn {
                bottom: 1rem;
                right: 1rem;
                padding: 0.8rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🛡️ Sicherheits-Monitoring</h1>
        <a href="dashboard.php" class="nav-link">← Zurück zum Dashboard</a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!$tablesExist): ?>
        <div class="alert alert-error">
            <h3>⚠️ Sicherheitstabellen nicht gefunden</h3>
            <p>Die erforderlichen Datenbanktabellen für das Sicherheits-Monitoring existieren noch nicht.</p>
            <p>Mögliche Lösungen:</p>
            <ul>
                <li>Führen Sie das Datenbankupgrade aus</li>
                <li>Erstellen Sie die Tabellen 'login_attempts' und 'account_lockouts' manuell</li>
                <li>Kontaktieren Sie den Administrator</li>
            </ul>
        </div>
    <?php else: ?>

    <!-- Statistiken -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Gesamt-Versuche (<?php echo $timeFilter; ?>)</h3>
            <div class="stat-number"><?php echo number_format($stats['totals']['total_attempts']); ?></div>
        </div>
        <div class="stat-card success">
            <h3>Erfolgreich</h3>
            <div class="stat-number"><?php echo number_format($stats['totals']['successful_attempts']); ?></div>
        </div>
        <div class="stat-card danger">
            <h3>Fehlgeschlagen</h3>
            <div class="stat-number"><?php echo number_format($stats['totals']['failed_attempts']); ?></div>
        </div>
        <div class="stat-card warning">
            <h3>Aktive Sperrungen</h3>
            <div class="stat-number"><?php echo count($stats['current_lockouts']); ?></div>
        </div>
    </div>

    <!-- Filter -->
    <div class="controls-section">
        <h2>🎛️ Filter & Steuerung</h2>
        <form method="GET" id="filterForm">
            <div class="controls-grid">
                <div class="filter-group">
                    <label class="filter-label">Zeitraum</label>
                    <select name="time" class="filter-select" onchange="this.form.submit()">
                        <option value="1h" <?php echo $timeFilter === '1h' ? 'selected' : ''; ?>>Letzte Stunde</option>
                        <option value="24h" <?php echo $timeFilter === '24h' ? 'selected' : ''; ?>>Letzte 24h</option>
                        <option value="7d" <?php echo $timeFilter === '7d' ? 'selected' : ''; ?>>Letzte 7 Tage</option>
                        <option value="30d" <?php echo $timeFilter === '30d' ? 'selected' : ''; ?>>Letzte 30 Tage</option>
                        <option value="all" <?php echo $timeFilter === 'all' ? 'selected' : ''; ?>>Alle</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Alle</option>
                        <option value="success" <?php echo $statusFilter === 'success' ? 'selected' : ''; ?>>Erfolgreich</option>
                        <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Fehlgeschlagen</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">IP-Adresse</label>
                    <input type="text" name="ip" class="filter-input" placeholder="IP filtern..." value="<?php echo htmlspecialchars($ipFilter); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">E-Mail</label>
                    <input type="text" name="email" class="filter-input" placeholder="E-Mail filtern..." value="<?php echo htmlspecialchars($emailFilter); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Browser</label>
                    <input type="text" name="browser" class="filter-input" placeholder="Browser filtern..." value="<?php echo htmlspecialchars($browserFilter); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Grund</label>
                    <input type="text" name="reason" class="filter-input" placeholder="Grund filtern..." value="<?php echo htmlspecialchars($reasonFilter); ?>">
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="submit" class="btn btn-primary">🔍 Filter anwenden</button>
                <a href="?" class="btn btn-secondary">🔄 Filter zurücksetzen</a>
                <button type="button" onclick="exportData()" class="btn btn-success">📊 CSV Export</button>
            </div>
        </form>
    </div>

    <!-- Aktive Sperrungen -->
    <?php if (!empty($stats['current_lockouts'])): ?>
    <div class="section">
        <h2>🔒 Aktive Account-Sperrungen</h2>
        <?php foreach ($stats['current_lockouts'] as $lockout): ?>
            <div class="lockout-item">
                <div class="lockout-info">
                    <strong><?php echo htmlspecialchars($lockout['identifier']); ?></strong>
                    <span class="time-badge"><?php echo ucfirst($lockout['identifier_type']); ?></span>
                    <div class="lockout-meta">
                        Gesperrt bis: <?php echo date('d.m.Y H:i', strtotime($lockout['locked_until'])); ?> 
                        | Versuche: <?php echo $lockout['attempts_count']; ?>
                        | Grund: <?php echo htmlspecialchars($lockout['reason']); ?>
                    </div>
                </div>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Account wirklich entsperren?')">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="unlock">
                    <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($lockout['identifier']); ?>">
                    <input type="hidden" name="identifier_type" value="<?php echo htmlspecialchars($lockout['identifier_type']); ?>">
                    <button type="submit" class="btn btn-danger btn-small">Entsperren</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Top Failed IPs -->
    <?php if (!empty($stats['top_failed_ips'])): ?>
    <div class="section">
        <h2>⚠️ Top Fehlgeschlagene IPs (<?php echo $timeFilter; ?>)</h2>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>IP-Adresse</th>
                        <th>Fehlversuche</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['top_failed_ips'] as $ip): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ip['ip_address']); ?></td>
                        <td><?php echo $ip['failed_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Letzte Login-Versuche -->
    <div class="section">
        <h2>📊 Letzte Login-Versuche</h2>
        <?php if (!empty($recentAttempts)): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Zeit</th>
                        <th>IP-Adresse</th>
                        <th>E-Mail</th>
                        <th>Status</th>
                        <th>Grund</th>
                        <th>Browser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAttempts as $attempt): ?>
                    <tr>
                        <td><?php echo date('d.m.Y H:i:s', strtotime($attempt['attempt_time'])); ?></td>
                        <td><?php echo htmlspecialchars($attempt['ip_address']); ?></td>
                        <td><?php echo htmlspecialchars($attempt['email'] ?? '-'); ?></td>
                        <td>
                            <?php if ($attempt['success']): ?>
                                <span class="status-success">✓ Erfolgreich</span>
                            <?php else: ?>
                                <span class="status-failed">✗ Fehlgeschlagen</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($attempt['reason'] ?? '-'); ?></td>
                        <td title="<?php echo htmlspecialchars($attempt['user_agent']); ?>">
                            <?php 
                            $ua = $attempt['user_agent'];
                            if (strpos($ua, 'Chrome') !== false) echo '🌐 Chrome';
                            elseif (strpos($ua, 'Firefox') !== false) echo '🦊 Firefox';
                            elseif (strpos($ua, 'Safari') !== false) echo '🧭 Safari';
                            elseif (strpos($ua, 'Edge') !== false) echo '🔷 Edge';
                            else echo '❓ Unbekannt';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="no-data">
            <h3>Keine Login-Versuche gefunden</h3>
            <p>Für die ausgewählten Filter wurden keine Login-Versuche gefunden.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Clean-Up Bereich -->
    <div class="cleanup-section">
        <h2>🧹 Datenbereinigung</h2>
        <p style="margin-bottom: 1rem; opacity: 0.8;">⚠️ Vorsicht: Diese Aktionen können nicht rückgängig gemacht werden!</p>
        
        <form method="POST" onsubmit="return confirmCleanup(document.querySelector('[name=cleanup_type] option:checked').textContent)">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="cleanup">
            
            <div class="cleanup-grid">
                <div class="filter-group">
                    <label class="filter-label">Cleanup-Typ</label>
                    <select name="cleanup_type" class="filter-select" required>
                        <option value="">Bitte wählen...</option>
                        <option value="old_attempts">Alte Login-Versuche löschen</option>
                        <option value="failed_only">Nur fehlgeschlagene Versuche löschen</option>
                        <option value="expired_lockouts">Abgelaufene Sperrungen löschen</option>
                        <option value="all_attempts">ALLE Login-Versuche löschen</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Alter (Tage)</label>
                    <input type="number" name="cleanup_days" class="filter-input" value="7" min="1" max="365" required>
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="submit" class="btn btn-danger">🗑️ Bereinigung starten</button>
            </div>
        </form>
    </div>

    <?php endif; ?>
</div>

<button class="refresh-btn" onclick="location.reload()" title="Seite aktualisieren">
    🔄
</button>

<script>
// Auto-refresh alle 60 Sekunden
setTimeout(function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.toString() === '') {
        location.reload();
    }
}, 60000);

// CSV Export Funktion
function exportData() {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('export', 'csv');
    
    const link = document.createElement('a');
    link.href = '?' + urlParams.toString();
    link.download = 'security_log_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Clean-Up Bestätigung
function confirmCleanup(type) {
    return confirm(
        '⚠️ WARNUNG: Sie sind dabei, ' + type + ' zu löschen!\n\n' +
        'Diese Aktion kann NICHT rückgängig gemacht werden.\n\n' +
        'Sind Sie sicher, dass Sie fortfahren möchten?'
    );
}

// Live-Search für Text-Felder
document.querySelectorAll('.filter-input').forEach(input => {
    let timeout;
    input.addEventListener('input', function() {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            if (this.value.length >= 2 || this.value.length === 0) {
                document.getElementById('filterForm').submit();
            }
        }, 1000);
    });
});

// Keyboard Shortcuts
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        location.reload();
    }
    
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        exportData();
    }
    
    if (e.key === 'Escape') {
        window.location.href = window.location.pathname;
    }
});
</script>

</body>
</html>