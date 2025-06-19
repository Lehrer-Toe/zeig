<?php
require_once '../config.php';

// Lehrer-Zugriff prüfen
if (!isLoggedIn() || $_SESSION['user_type'] !== 'lehrer') {
    die('Zugriff verweigert');
}

$rating_id = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;

if (!$rating_id) {
    die('Keine Bewertungs-ID angegeben');
}

// Bewertung laden und Berechtigung prüfen
$db = getDB();
$stmt = $db->prepare("
    SELECT r.*, s.first_name, s.last_name, g.name as group_name,
           rt.name as template_name, u.name as teacher_name,
           sch.name as school_name
    FROM ratings r
    JOIN students s ON r.student_id = s.id
    JOIN groups g ON r.group_id = g.id
    JOIN rating_templates rt ON r.template_id = rt.id
    JOIN users u ON r.teacher_id = u.id
    JOIN schools sch ON g.school_id = sch.id
    WHERE r.id = ? AND r.teacher_id = ? AND r.is_complete = 1
");
$stmt->execute([$rating_id, $_SESSION['user_id']]);
$rating = $stmt->fetch();

if (!$rating) {
    die('Bewertung nicht gefunden oder keine Berechtigung');
}

// Platzhalter für PDF-Generierung
// In einer echten Implementierung würden Sie hier eine PDF-Bibliothek wie TCPDF oder mPDF verwenden

header('Content-Type: text/plain');
echo "PDF-Generierung - Platzhalter\n";
echo "==============================\n\n";
echo "Schüler: " . $rating['first_name'] . " " . $rating['last_name'] . "\n";
echo "Thema: " . $rating['group_name'] . "\n";
echo "Endnote: " . number_format($rating['final_grade'], 1, ',', '') . "\n";
echo "Bewertungsvorlage: " . $rating['template_name'] . "\n";
echo "Bewertet von: " . $rating['teacher_name'] . "\n";
echo "Datum: " . date('d.m.Y', strtotime($rating['rating_date'])) . "\n\n";

echo "Hinweis: Dies ist nur ein Platzhalter. Für die echte PDF-Generierung\n";
echo "müssen Sie eine PDF-Bibliothek wie TCPDF oder mPDF integrieren.";

// Beispiel für TCPDF-Integration (auskommentiert):
/*
require_once('tcpdf/tcpdf.php');

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('Bewertungssystem');
$pdf->SetAuthor($rating['teacher_name']);
$pdf->SetTitle('Bewertung - ' . $rating['first_name'] . ' ' . $rating['last_name']);

$pdf->AddPage();

// PDF-Inhalt generieren...
$html = '<h1>Projektbewertung</h1>';
$html .= '<p>Schüler: ' . $rating['first_name'] . ' ' . $rating['last_name'] . '</p>';
// ... weitere Inhalte

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('bewertung_' . $rating_id . '.pdf', 'D');
*/