-- Superadmin für "Zeig, was du kannst!" erstellen
-- E-Mail: tilama@mail.de
-- Passwort: wandermaus17

-- Zuerst prüfen ob der Benutzer bereits existiert und ggf. löschen
DELETE FROM users WHERE email = 'tilama@mail.de';

-- Superadmin mit korrekt gehashtem Passwort erstellen
-- Das Passwort 'wandermaus17' wird hier mit einem PHP-generierten Hash eingefügt
INSERT INTO users (
    email, 
    password_hash, 
    user_type, 
    name, 
    school_id, 
    is_active, 
    first_login, 
    created_at, 
    updated_at
) VALUES (
    'tilama@mail.de',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Hash für 'wandermaus17'
    'superadmin',
    'Super Administrator',
    NULL,
    1,
    0,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
);

-- Standard-Bewertungskriterien einfügen (falls noch nicht vorhanden)
INSERT IGNORE INTO rating_criteria (name, description, max_points, is_active) VALUES
('Kreativität', 'Bewertung der kreativen Leistung und Originalität', 10, 1),
('Teamarbeit', 'Bewertung der Zusammenarbeit und Kommunikation im Team', 10, 1),
('Präsentation', 'Bewertung der Präsentationsfähigkeiten und des Auftretens', 10, 1),
('Fachliche Kompetenz', 'Bewertung des fachlichen Wissens und der Anwendung', 10, 1),
('Selbstständigkeit', 'Bewertung der eigenständigen Arbeitsweise und Initiative', 10, 1),
('Problemlösung', 'Bewertung der Fähigkeit, Probleme zu erkennen und zu lösen', 10, 1),
('Organisation', 'Bewertung der Planung und Organisation des Projekts', 10, 1);

-- Erfolgsmeldung ausgeben
SELECT 'Superadmin erfolgreich erstellt!' as Status, 
       'E-Mail: tilama@mail.de' as Login, 
       'Passwort: wandermaus17' as Passwort;