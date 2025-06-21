<?php
/**
 * Security Headers für "Zeig, was du kannst!"
 * 
 * Diese Datei setzt alle wichtigen HTTP Security Headers
 * um die Anwendung gegen verschiedene Angriffe zu schützen.
 */

// Verhindere direkten Zugriff
if (!defined('APP_NAME')) {
    die('Direct access not permitted');
}

/**
 * Hauptfunktion zum Setzen aller Security Headers
 */
function setSecurityHeaders() {
    // Nur setzen wenn noch keine Headers gesendet wurden
    if (headers_sent()) {
        return false;
    }
    
    // 1. Content-Security-Policy (CSP)
    // Strikte CSP mit spezifischen Erlaubnissen
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com", // unsafe-inline für bestehende Inline-Scripts
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", // unsafe-inline für bestehende Inline-Styles
        "img-src 'self' data: https:",
        "font-src 'self' https://fonts.gstatic.com",
        "connect-src 'self'",
        "media-src 'self'",
        "object-src 'none'",
        "frame-src 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        "base-uri 'self'",
        "upgrade-insecure-requests"
    ];
    header("Content-Security-Policy: " . implode('; ', $csp));
    
    // 2. X-Frame-Options - Clickjacking-Schutz
    header("X-Frame-Options: DENY");
    
    // 3. X-Content-Type-Options - MIME-Type Sniffing verhindern
    header("X-Content-Type-Options: nosniff");
    
    // 4. X-XSS-Protection - XSS-Filter in älteren Browsern aktivieren
    header("X-XSS-Protection: 1; mode=block");
    
    // 5. Referrer-Policy - Kontrolliere Referrer-Informationen
    header("Referrer-Policy: strict-origin-when-cross-origin");
    
    // 6. Permissions-Policy (früher Feature-Policy)
    $permissions = [
        "geolocation=()",
        "microphone=()",
        "camera=()",
        "payment=()",
        "usb=()",
        "magnetometer=()",
        "gyroscope=()",
        "accelerometer=()",
        "ambient-light-sensor=()",
        "autoplay=()",
        "encrypted-media=()",
        "picture-in-picture=()",
        "sync-xhr=(self)",
        "fullscreen=(self)",
        "notifications=()"
    ];
    header("Permissions-Policy: " . implode(', ', $permissions));
    
    // 7. Strict-Transport-Security (HSTS) - nur über HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    }
    
    // 8. Cache-Control für sensible Seiten
    if (isLoggedIn()) {
        header("Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    }
    
    // 9. X-Powered-By Header entfernen (Information Disclosure)
    header_remove("X-Powered-By");
    
    // 10. Server Header minimieren (wenn möglich)
    header("Server: " . APP_NAME);
    
    return true;
}

/**
 * Spezielle Headers für Downloads setzen
 */
function setDownloadSecurityHeaders($filename, $mimeType = 'application/octet-stream') {
    // Basis Security Headers
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    
    // Download-spezifische Headers
    header("Content-Type: " . $mimeType);
    header("Content-Disposition: attachment; filename=\"" . basename($filename) . "\"");
    header("Content-Transfer-Encoding: binary");
    
    // Cache-Control für Downloads
    header("Cache-Control: private, no-transform, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

/**
 * Headers für API-Endpoints
 */
function setAPISecurityHeaders($allowedOrigins = []) {
    // Basis Security Headers
    setSecurityHeaders();
    
    // CORS-Headers wenn Origins definiert
    if (!empty($allowedOrigins)) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: " . $origin);
            header("Access-Control-Allow-Credentials: true");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
            header("Access-Control-Max-Age: 86400"); // 24 Stunden
        }
    }
    
    // API-spezifische Headers
    header("Content-Type: application/json; charset=utf-8");
    header("X-Content-Type-Options: nosniff");
}

/**
 * Nonce für Inline-Scripts generieren (für strengere CSP)
 */
function generateCSPNonce() {
    if (!isset($_SESSION['csp_nonce'])) {
        $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $_SESSION['csp_nonce'];
}

/**
 * CSP-Report-Endpunkt Headers
 */
function setCSPReportHeaders() {
    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: no-store");
}

/**
 * Sichere Cookie-Optionen setzen
 */
function setSecureCookieParams() {
    $cookieParams = session_get_cookie_params();
    
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => '/',
        'domain' => $cookieParams['domain'],
        'secure' => $secure, // Nur über HTTPS
        'httponly' => true, // Kein JavaScript-Zugriff
        'samesite' => 'Strict' // CSRF-Schutz
    ]);
}

/**
 * Security Headers Monitoring
 */
function logSecurityHeaderViolation($type, $details) {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            INSERT INTO security_violations (violation_type, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $type,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Security violation logging error: " . $e->getMessage());
    }
}

/**
 * Content-Type validieren
 */
function validateContentType($expectedType) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    // Entferne Charset und andere Parameter
    $contentType = explode(';', $contentType)[0];
    $contentType = trim(strtolower($contentType));
    $expectedType = trim(strtolower($expectedType));
    
    if ($contentType !== $expectedType) {
        header("HTTP/1.1 415 Unsupported Media Type");
        die("Invalid Content-Type");
    }
}

/**
 * Request-Method validieren
 */
function validateRequestMethod($allowedMethods) {
    if (!is_array($allowedMethods)) {
        $allowedMethods = [$allowedMethods];
    }
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    if (!in_array($method, $allowedMethods)) {
        header("HTTP/1.1 405 Method Not Allowed");
        header("Allow: " . implode(', ', $allowedMethods));
        die("Method not allowed");
    }
}

/**
 * Rate Limiting Headers setzen
 */
function setRateLimitHeaders($limit, $remaining, $reset) {
    header("X-RateLimit-Limit: " . $limit);
    header("X-RateLimit-Remaining: " . $remaining);
    header("X-RateLimit-Reset: " . $reset);
}

/**
 * Security Headers für Fehlerseiten
 */
function setErrorPageHeaders($statusCode = 500) {
    http_response_code($statusCode);
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
}

/**
 * Prüfe ob alle wichtigen Security Headers gesetzt sind
 */
function validateSecurityHeaders() {
    $requiredHeaders = [
        'Content-Security-Policy',
        'X-Frame-Options',
        'X-Content-Type-Options',
        'X-XSS-Protection',
        'Referrer-Policy',
        'Permissions-Policy'
    ];
    
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $missingHeaders = [];
    
    foreach ($requiredHeaders as $header) {
        $headerLower = strtolower($header);
        if (!isset($headers[$headerLower])) {
            $missingHeaders[] = $header;
        }
    }
    
    return [
        'valid' => empty($missingHeaders),
        'missing' => $missingHeaders
    ];
}

// Automatisch sichere Cookie-Parameter setzen beim Laden
if (session_status() === PHP_SESSION_NONE) {
    setSecureCookieParams();
}

// Security Headers automatisch setzen (kann durch Konstante deaktiviert werden)
if (!defined('DISABLE_AUTO_SECURITY_HEADERS') || !DISABLE_AUTO_SECURITY_HEADERS) {
    setSecurityHeaders();
}