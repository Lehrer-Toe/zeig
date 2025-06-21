<?php
/**
 * CSP Violation Report Endpoint
 * 
 * Dieser Endpoint empfängt und verarbeitet Content Security Policy Violation Reports
 * vom Browser wenn CSP-Regeln verletzt werden.
 */

// Basis-Konfiguration laden
require_once 'config.php';

// Spezielle Headers für CSP-Report-Endpoint setzen
setCSPReportHeaders();

// Nur POST-Requests erlaubt
validateRequestMethod(['POST']);

// Content-Type validieren
validateContentType('application/csp-report');

// Report-Daten lesen
$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(400);
    die(json_encode(['error' => 'No report data']));
}

// JSON dekodieren
$report = json_decode($input, true);
if (!$report || !isset($report['csp-report'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid report format']));
}

$cspReport = $report['csp-report'];

// Report in Datenbank speichern
try {
    $db = getDB();
    
    // Grundlegende Validierung
    $documentUri = substr($cspReport['document-uri'] ?? '', 0, 500);
    $referrer = substr($cspReport['referrer'] ?? '', 0, 500);
    $violatedDirective = substr($cspReport['violated-directive'] ?? '', 0, 255);
    $effectiveDirective = substr($cspReport['effective-directive'] ?? '', 0, 255);
    $originalPolicy = substr($cspReport['original-policy'] ?? '', 0, 1000);
    $disposition = substr($cspReport['disposition'] ?? '', 0, 20);
    $blockedUri = substr($cspReport['blocked-uri'] ?? '', 0, 500);
    $lineNumber = isset($cspReport['line-number']) ? (int)$cspReport['line-number'] : null;
    $columnNumber = isset($cspReport['column-number']) ? (int)$cspReport['column-number'] : null;
    $sourceFile = substr($cspReport['source-file'] ?? '', 0, 500);
    $statusCode = isset($cspReport['status-code']) ? (int)$cspReport['status-code'] : null;
    $scriptSample = substr($cspReport['script-sample'] ?? '', 0, 500);
    
    // IP-Adresse und User-Agent
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // In Datenbank einfügen
    $stmt = $db->prepare("
        INSERT INTO csp_reports (
            document_uri, referrer, violated_directive, effective_directive,
            original_policy, disposition, blocked_uri, line_number,
            column_number, source_file, status_code, script_sample,
            ip_address, user_agent
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $documentUri, $referrer, $violatedDirective, $effectiveDirective,
        $originalPolicy, $disposition, $blockedUri, $lineNumber,
        $columnNumber, $sourceFile, $statusCode, $scriptSample,
        $ipAddress, $userAgent
    ]);
    
    // Optional: Security-Violation auch loggen für übergreifende Statistik
    logSecurityHeaderViolation('csp-violation', [
        'directive' => $violatedDirective,
        'blocked_uri' => $blockedUri,
        'document_uri' => $documentUri
    ]);
    
    // Erfolg zurückgeben
    http_response_code(204); // No Content
    
} catch (Exception $e) {
    error_log("CSP Report Error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Internal server error']));
}

/**
 * Rate Limiting für CSP Reports (optional)
 * Verhindert Spam/DoS durch massenhaft gesendete Reports
 */
function checkCSPReportRateLimit($ipAddress) {
    $db = getDB();
    
    try {
        // Prüfe Anzahl Reports in letzter Minute
        $stmt = $db->prepare("
            SELECT COUNT(*) as report_count 
            FROM csp_reports 
            WHERE ip_address = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$ipAddress]);
        $count = $stmt->fetchColumn();
        
        // Max 10 Reports pro Minute pro IP
        if ($count >= 10) {
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("CSP Rate Limit Check Error: " . $e->getMessage());
        return true; // Im Fehlerfall erlauben
    }
}

// Optional: Rate Limiting aktivieren
if (!checkCSPReportRateLimit($_SERVER['REMOTE_ADDR'] ?? '')) {
    http_response_code(429); // Too Many Requests
    die(json_encode(['error' => 'Rate limit exceeded']));
}