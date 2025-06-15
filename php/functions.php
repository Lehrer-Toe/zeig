<?php
/**
 * Allgemeine Hilfsfunktionen für "Zeig, was du kannst"
 */

/**
 * HTML-String sicher ausgeben
 */
function escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * E-Mail-Adresse validieren
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Datum formatieren für deutsche Anzeige
 */
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

/**
 * Datum für Datenbank formatieren
 */
function formatDateForDB($dateString) {
    if (empty($dateString)) {
        return null;
    }
    
    // Deutsche Datumsformate unterstützen
    $formats = ['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d'];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateString);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }
    
    return null;
}

/**
 * Zufälliges Passwort generieren
 */
function generatePassword($length = 12) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, strlen($characters) - 1)];
    }
    
    return $password;
}

/**
 * Dateigröße human-readable formatieren
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Debug-Output (nur in Development)
 */
function debug($data, $die = false) {
    if (defined('DEBUG') && DEBUG) {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
        
        if ($die) {
            die();
        }
    }
}

/**
 * JSON-Response senden
 */
function sendJsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Erfolgs-Response senden
 */
function sendSuccessResponse($message = 'Erfolgreich', $data = null) {
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    sendJsonResponse($response);
}

/**
 * Fehler-Response senden
 */
function sendErrorResponse($message = 'Ein Fehler ist aufgetreten', $status = 400) {
    sendJsonResponse(['success' => false, 'message' => $message], $status);
}

/**
 * Weiterleitung mit Nachricht
 */
function redirectWithMessage($url, $message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url);
    exit;
}

/**
 * Flash-Message anzeigen und löschen
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        
        return ['message' => $message, 'type' => $type];
    }
    
    return null;
}

/**
 * Breadcrumb generieren
 */
function generateBreadcrumb($items) {
    $html = '<nav class="breadcrumb">';
    $html .= '<ol class="breadcrumb-list">';
    
    foreach ($items as $index => $item) {
        $isLast = $index === count($items) - 1;
        $html .= '<li class="breadcrumb-item' . ($isLast ? ' active' : '') . '">';
        
        if (!$isLast && isset($item['url'])) {
            $html .= '<a href="' . escape($item['url']) . '">' . escape($item['title']) . '</a>';
        } else {
            $html .= escape($item['title']);
        }
        
        $html .= '</li>';
    }
    
    $html .= '</ol>';
    $html .= '</nav>';
    
    return $html;
}

/**
 * Pagination generieren
 */
function generatePagination($currentPage, $totalPages, $baseUrl, $params = []) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<nav class="pagination">';
    $html .= '<ul class="pagination-list">';
    
    // Previous
    if ($currentPage > 1) {
        $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $currentPage - 1]));
        $html .= '<li><a href="' . $url . '" class="pagination-link">&laquo; Zurück</a></li>';
    }
    
    // Pages
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => 1]));
        $html .= '<li><a href="' . $url . '" class="pagination-link">1</a></li>';
        if ($start > 2) {
            $html .= '<li><span class="pagination-ellipsis">...</span></li>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $html .= '<li><span class="pagination-current">' . $i . '</span></li>';
        } else {
            $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $i]));
            $html .= '<li><a href="' . $url . '" class="pagination-link">' . $i . '</a></li>';
        }
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li><span class="pagination-ellipsis">...</span></li>';
        }
        $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $totalPages]));
        $html .= '<li><a href="' . $url . '" class="pagination-link">' . $totalPages . '</a></li>';
    }
    
    // Next
    if ($currentPage < $totalPages) {
        $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $currentPage + 1]));
        $html .= '<li><a href="' . $url . '" class="pagination-link">Weiter &raquo;</a></li>';
    }
    
    $html .= '</ul>';
    $html .= '</nav>';
    
    return $html;
}

/**
 * Status-Badge HTML generieren
 */
function getStatusBadge($status, $text = null) {
    $classes = [
        'active' => 'badge-success',
        'inactive' => 'badge-danger',
        'expired' => 'badge-danger',
        'expiring' => 'badge-warning',
        'pending' => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger'
    ];
    
    $texts = [
        'active' => 'Aktiv',
        'inactive' => 'Inaktiv',
        'expired' => 'Abgelaufen',
        'expiring' => 'Läuft ab',
        'pending' => 'Ausstehend',
        'approved' => 'Genehmigt',
        'rejected' => 'Abgelehnt'
    ];
    
    $class = $classes[$status] ?? 'badge-secondary';
    $displayText = $text ?? $texts[$status] ?? ucfirst($status);
    
    return '<span class="badge ' . $class . '">' . escape($displayText) . '</span>';
}

/**
 * Telefonnummer formatieren
 */
function formatPhoneNumber($phone) {
    if (empty($phone)) {
        return '';
    }
    
    // Einfache deutsche Telefonnummer-Formatierung
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    if (strlen($phone) >= 10) {
        return preg_replace('/^(\+49|0049|0)(\d{3,4})(\d{3,8})$/', '+49 $2 $3', $phone);
    }
    
    return $phone;
}

/**
 * Sicherheitskopien-Funktionen
 */
function createBackup($filename = null) {
    if (!$filename) {
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    }
    
    $backupDir = ROOT_PATH . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $backupFile = $backupDir . '/' . $filename;
    
    $command = sprintf(
        'mysqldump --host=%s --user=%s --password=%s %s > %s',
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_NAME),
        escapeshellarg($backupFile)
    );
    
    exec($command, $output, $returnCode);
    
    return $returnCode === 0 ? $backupFile : false;
}

/**
 * Log-Funktion
 */
function writeLog($message, $level = 'INFO', $file = 'app.log') {
    $logDir = ROOT_PATH . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/' . $file;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Array sortieren nach deutschem Alphabet
 */
function sortGerman($array, $key = null) {
    if ($key) {
        usort($array, function($a, $b) use ($key) {
            return strcoll($a[$key], $b[$key]);
        });
    } else {
        usort($array, 'strcoll');
    }
    
    return $array;
}

/**
 * Benutzerfreundliche Fehlermeldung
 */
function getFriendlyErrorMessage($error) {
    $messages = [
        'duplicate_email' => 'Diese E-Mail-Adresse wird bereits verwendet.',
        'invalid_email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        'weak_password' => 'Das Passwort ist zu schwach. Verwenden Sie mindestens 8 Zeichen mit Groß- und Kleinbuchstaben, Zahlen und Sonderzeichen.',
        'license_expired' => 'Die Lizenz für diese Schule ist abgelaufen.',
        'school_inactive' => 'Diese Schule ist deaktiviert.',
        'access_denied' => 'Sie haben keine Berechtigung für diese Aktion.',
        'not_found' => 'Die angeforderte Ressource wurde nicht gefunden.',
        'server_error' => 'Ein Serverfehler ist aufgetreten. Bitte versuchen Sie es später erneut.'
    ];
    
    return $messages[$error] ?? 'Ein unbekannter Fehler ist aufgetreten.';
}
?>