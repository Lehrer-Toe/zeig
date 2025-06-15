<?php
/**
 * Datenbankfunktionen für "Zeig, was du kannst"
 */

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Datenbankverbindung fehlgeschlagen. Bitte versuchen Sie es später erneut.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

/**
 * Helper function to get database connection
 */
function getDB() {
    return Database::getInstance()->getConnection();
}

/**
 * User Functions
 */
function getUserByEmail($email) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function getUserById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function createUser($email, $password, $userType, $name, $schoolId = null) {
    $db = getDB();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("
        INSERT INTO users (email, password_hash, user_type, name, school_id, first_login) 
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    
    return $stmt->execute([$email, $passwordHash, $userType, $name, $schoolId]);
}

function updateUserPassword($userId, $newPassword) {
    $db = getDB();
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("
        UPDATE users 
        SET password_hash = ?, first_login = 0, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    
    return $stmt->execute([$passwordHash, $userId]);
}

/**
 * School Functions
 */
function getAllSchools() {
    $db = getDB();
    
    // Basis-Query
    $sql = "SELECT s.*";
    
    // Teacher count - prüfen ob users Tabelle existiert
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'users'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $sql .= ", (SELECT COUNT(*) FROM users WHERE school_id = s.id AND user_type = 'lehrer') as teacher_count";
        } else {
            $sql .= ", 0 as teacher_count";
        }
    } catch (Exception $e) {
        $sql .= ", 0 as teacher_count";
    }
    
    // Student count - prüfen ob students Tabelle existiert
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $sql .= ", (SELECT COUNT(*) FROM students WHERE school_id = s.id) as student_count";
        } else {
            $sql .= ", 0 as student_count";
        }
    } catch (Exception $e) {
        $sql .= ", 0 as student_count";
    }
    
    // Class count - prüfen ob classes Tabelle existiert
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'classes'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $sql .= ", (SELECT COUNT(*) FROM classes WHERE school_id = s.id) as class_count";
        } else {
            $sql .= ", 0 as class_count";
        }
    } catch (Exception $e) {
        $sql .= ", 0 as class_count";
    }
    
    // License status
    $sql .= ", CASE 
                   WHEN s.license_until < CURDATE() THEN 'expired'
                   WHEN s.license_until < DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring'
                   ELSE 'active'
               END as license_status";
    
    $sql .= " FROM schools s ORDER BY s.name ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getSchoolById($id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.*, u.name as admin_name 
        FROM schools s 
        LEFT JOIN users u ON s.admin_email = u.email AND u.user_type = 'schuladmin' 
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function createSchool($data) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Insert school
        $stmt = $db->prepare("
            INSERT INTO schools (name, location, contact_phone, contact_email, contact_person, 
                               school_type, school_type_custom, license_until, max_classes, 
                               max_students_per_class, is_active, admin_email) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name'],
            $data['location'], 
            $data['contact_phone'],
            $data['contact_email'],
            $data['contact_person'],
            $data['school_type'],
            $data['school_type_custom'],
            $data['license_until'],
            $data['max_classes'],
            $data['max_students_per_class'],
            $data['is_active'],
            $data['admin_email']
        ]);
        
        $schoolId = $db->lastInsertId();
        
        // Create school admin user
        $adminCreated = createUser(
            $data['admin_email'],
            $data['admin_password'],
            'schuladmin',
            $data['admin_name'],
            $schoolId
        );
        
        if (!$adminCreated) {
            throw new Exception("Fehler beim Erstellen des Schuladmins");
        }
        
        $db->commit();
        return $schoolId;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error creating school: " . $e->getMessage());
        return false;
    }
}

function updateSchool($id, $data) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Update school
        $stmt = $db->prepare("
            UPDATE schools 
            SET name = ?, location = ?, contact_phone = ?, contact_email = ?, 
                contact_person = ?, school_type = ?, school_type_custom = ?, 
                license_until = ?, max_classes = ?, max_students_per_class = ?, 
                is_active = ?, admin_email = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $data['name'],
            $data['location'],
            $data['contact_phone'], 
            $data['contact_email'],
            $data['contact_person'],
            $data['school_type'],
            $data['school_type_custom'],
            $data['license_until'],
            $data['max_classes'],
            $data['max_students_per_class'],
            $data['is_active'],
            $data['admin_email'],
            $id
        ]);
        
        // Update admin user
        $stmt = $db->prepare("
            UPDATE users 
            SET email = ?, name = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE school_id = ? AND user_type = 'schuladmin'
        ");
        $stmt->execute([$data['admin_email'], $data['admin_name'], $id]);
        
        // Update password if provided
        if (!empty($data['admin_password'])) {
            $stmt = $db->prepare("
                UPDATE users 
                SET password_hash = ?, first_login = 1, updated_at = CURRENT_TIMESTAMP 
                WHERE school_id = ? AND user_type = 'schuladmin'
            ");
            $passwordHash = password_hash($data['admin_password'], PASSWORD_DEFAULT);
            $stmt->execute([$passwordHash, $id]);
        }
        
        $db->commit();
        return $result;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error updating school: " . $e->getMessage());
        return false;
    }
}

function deleteSchool($id) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Delete all related data (CASCADE should handle this, but let's be explicit)
        $tables = ['ratings', 'group_students', 'groups', 'students', 'classes', 'users'];
        
        foreach ($tables as $table) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE school_id = ?");
            $stmt->execute([$id]);
        }
        
        // Delete school
        $stmt = $db->prepare("DELETE FROM schools WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        $db->commit();
        return $result;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error deleting school: " . $e->getMessage());
        return false;
    }
}

/**
 * License Functions
 */
function checkSchoolLicense($schoolId) {
    $school = getSchoolById($schoolId);
    if (!$school) return false;
    
    return $school['is_active'] && $school['license_until'] >= date('Y-m-d');
}

function getExpiringLicenses($days = 30) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM schools 
        WHERE is_active = 1 
        AND license_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY license_until ASC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Class and Student Limits
 */
function getSchoolClassCount($schoolId) {
    $db = getDB();
    
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
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE " . $whereClause);
    $stmt->execute([$schoolId]);
    return $stmt->fetch()['count'];
}

function canCreateClass($schoolId) {
    $school = getSchoolById($schoolId);
    if (!$school) return false;
    
    $currentClasses = getSchoolClassCount($schoolId);
    return $currentClasses < $school['max_classes'];
}

function getClassStudentCount($classId) {
    $db = getDB();
    
    // Prüfen ob is_active Spalte in students existiert
    $whereClause = "class_id = ?";
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM students LIKE 'is_active'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $whereClause .= " AND is_active = 1";
        }
    } catch (Exception $e) {
        // Spalte existiert nicht, ignorieren
    }
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE " . $whereClause);
    $stmt->execute([$classId]);
    return $stmt->fetch()['count'];
}

function canAddStudentToClass($classId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.max_students_per_class 
        FROM classes c 
        JOIN schools s ON c.school_id = s.id 
        WHERE c.id = ?
    ");
    $stmt->execute([$classId]);
    $school = $stmt->fetch();
    
    if (!$school) return false;
    
    $currentStudents = getClassStudentCount($classId);
    return $currentStudents < $school['max_students_per_class'];
}

/**
 * Statistics Functions
 */
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
    
    // Total classes - prüfen ob Tabelle existiert
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'classes'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes");
            $stmt->execute();
            $stats['total_classes'] = $stmt->fetch()['count'];
        } else {
            $stats['total_classes'] = 0;
        }
    } catch (Exception $e) {
        $stats['total_classes'] = 0;
    }
    
    // Total users
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'users'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
            $stmt->execute();
            $stats['total_users'] = $stmt->fetch()['count'];
        } else {
            $stats['total_users'] = 0;
        }
    } catch (Exception $e) {
        $stats['total_users'] = 0;
    }
    
    // Total students - prüfen ob Tabelle existiert
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE 'students'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM students");
            $stmt->execute();
            $stats['total_students'] = $stmt->fetch()['count'];
        } else {
            $stats['total_students'] = 0;
        }
    } catch (Exception $e) {
        $stats['total_students'] = 0;
    }
    
    // Expiring licenses
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM schools 
        WHERE is_active = 1 
        AND license_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $stats['expiring_licenses'] = $stmt->fetch()['count'];
    
    return $stats;
}

/**
 * Database Update Functions
 */
function updateDatabaseSchema() {
    $db = getDB();
    
    try {
        // Check if new columns exist, if not add them
        $stmt = $db->prepare("SHOW COLUMNS FROM schools LIKE 'max_classes'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $db->exec("ALTER TABLE schools ADD COLUMN max_classes INT DEFAULT 3 AFTER license_until");
        }
        
        $stmt = $db->prepare("SHOW COLUMNS FROM schools LIKE 'max_students_per_class'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $db->exec("ALTER TABLE schools ADD COLUMN max_students_per_class INT DEFAULT 32 AFTER max_classes");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Database update error: " . $e->getMessage());
        return false;
    }
}

/**
 * Initialize Database with Superadmin
 */
function initializeDatabase() {
    $db = getDB();
    
    // Update schema first
    updateDatabaseSchema();
    
    // Check if superadmin already exists
    $superadmin = getUserByEmail('tilama@mail.de');
    
    if (!$superadmin) {
        // Create superadmin
        $passwordHash = password_hash('wandermaus17', PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("
            INSERT INTO users (email, password_hash, user_type, name, is_active, first_login) 
            VALUES (?, ?, 'superadmin', 'Super Administrator', 1, 0)
        ");
        
        return $stmt->execute(['tilama@mail.de', $passwordHash]);
    }
    
    return true;
}