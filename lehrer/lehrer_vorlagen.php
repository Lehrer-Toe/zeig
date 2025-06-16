<?php
// Sicherheitsprüfung
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    exit('Keine Berechtigung');
}
?>

<div class="module-container">
    <h2>Bewertungsvorlagen</h2>
    
    <div class="placeholder-content">
        <div class="placeholder-icon">📋</div>
        <h3>Vorlagen-Verwaltung</h3>
        <p>Erstellen Sie wiederverwendbare Bewertungsvorlagen.</p>
        <p>Funktionen:</p>
        <ul>
            <li>Vorlagen für verschiedene Fächer erstellen</li>
            <li>Bewertungskriterien definieren</li>
            <li>Punktesysteme festlegen</li>
            <li>Vorlagen importieren und exportieren</li>
        </ul>
    </div>
</div>

<style>
.module-container {
    max-width: 1000px;
}

.module-container h2 {
    color: #fff;
    margin-bottom: 30px;
    font-size: 24px;
}

.placeholder-content {
    background: rgba(255,255,255,0.05);
    border: 2px dashed #555;
    border-radius: 15px;
    padding: 60px 40px;
    text-align: center;
    color: #aaa;
}

.placeholder-icon {
    font-size: 64px;
    margin-bottom: 20px;
}

.placeholder-content h3 {
    color: #fff;
    margin-bottom: 15px;
    font-size: 22px;
}

.placeholder-content p {
    margin-bottom: 10px;
    font-size: 16px;
}

.placeholder-content ul {
    list-style: none;
    margin-top: 20px;
}

.placeholder-content li {
    padding: 8px 0;
    font-size: 15px;
}

.placeholder-content li::before {
    content: "→ ";
    color: #5b67ca;
    font-weight: bold;
}
</style>