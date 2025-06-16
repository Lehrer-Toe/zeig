<div id="klassen" class="tab-content <?php echo $activeTab === 'klassen' ? 'active' : ''; ?>">
    <div class="content-header">
        <h2 class="content-title">🏫 Klassen verwalten</h2>
    </div>

    <!-- Schullimits anzeigen -->
    <div class="school-limits">
        <h4>📋 Schullimits</h4>
        <div class="limits-grid">
            <div class="limit-item">
                <span>Klassen:</span>
                <span class="limit-current"><?php echo count($classes); ?> / <?php echo $school['max_classes']; ?></span>
            </div>
            <div class="limit-item">
                <span>Max. Schüler/Klasse:</span>
                <span class="limit-current"><?php echo $school['max_students_per_class']; ?></span>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="filters">
        <span style="font-weight: 500;">Filter:</span>
        <div class="filter-group">
            <label>nach Klasse:</label>
            <select class="filter-select" onchange="applyFilters()">
                <option value="all">Alle Klassen</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo $class['id']; ?>" 
                            <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>>
                        <?php echo escape($class['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!empty($schoolYears)): ?>
        <div class="filter-group">
            <label>nach Schuljahr:</label>
            <select class="filter-select" onchange="applyFilters()">
                <option value="all">Alle Jahre</option>
                <?php foreach ($schoolYears as $year): ?>
                    <option value="<?php echo escape($year); ?>" 
                            <?php echo $yearFilter === $year ? 'selected' : ''; ?>>
                        <?php echo escape($year); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <!-- Neue Klasse anlegen -->
    <div class="create-section">
        <h3>➕ Neue Klasse anlegen</h3>
        <form class="create-form" onsubmit="createClass(event)">
            <div class="form-group">
                <label for="className">Klassenname</label>
                <input type="text" id="className" name="class_name" 
                       placeholder="z.B. 9a, 10b" required>
            </div>
            <?php if ($hasSchoolYearColumn): ?>
            <div class="form-group">
                <label for="schoolYear">Schuljahr</label>
                <select id="schoolYear" name="school_year">
                    <option value="">Optional wählen...</option>
                    <?php foreach ($availableYears as $year): ?>
                        <option value="<?php echo $year; ?>" 
                                <?php echo $year === $currentYear . '/' . substr($nextYear, 2) ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" 
                    <?php echo count($classes) >= $school['max_classes'] ? 'disabled' : ''; ?>>
                ➕ Klasse anlegen
            </button>
        </form>
    </div>

    <!-- Schüler aus Datei hochladen -->
    <div class="upload-section">
        <h3>📁 Schüler aus Datei hochladen</h3>
        
        <div class="format-info">
            <h4>Unterstützte Formate:</h4>
            <ul>
                <li><strong>CSV/TXT:</strong> Ein Schüler pro Zeile oder Vor- und Nachname getrennt</li>
                <li>Format 1: Max Mustermann</li>
                <li>Format 2: Mustermann, Max</li>
                <li>Format 3: Max,Mustermann (CSV mit Komma)</li>
            </ul>
        </div>

        <form class="upload-form" onsubmit="uploadStudents(event)">
            <div class="form-group">
                <label for="uploadClass">Klasse auswählen</label>
                <select id="uploadClass" name="class_id" required>
                    <option value="">Klasse auswählen...</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" 
                                data-current="<?php echo $class['student_count']; ?>"
                                data-max="<?php echo $school['max_students_per_class']; ?>">
                            <?php 
                            echo escape($class['name']);
                            if (isset($class['school_year']) && !empty($class['school_year'])) {
                                echo ' (' . escape($class['school_year']) . ')';
                            }
                            echo ' - ' . $class['student_count'] . '/' . $school['max_students_per_class'] . ' Schüler';
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="nameFormat">Namensformat</label>
                <select id="nameFormat" name="name_format" required>
                    <option value="vorname_nachname">Vorname Nachname</option>
                    <option value="nachname_vorname">Nachname, Vorname</option>
                </select>
            </div>
            <div class="form-group">
                <div class="file-input-wrapper">
                    <input type="file" id="studentFile" name="student_file" 
                           class="file-input" accept=".csv,.txt" 
                           onchange="updateFileName(this)">
                    <label for="studentFile" class="file-input-label">
                        📁 Datei auswählen
                    </label>
                </div>
                <div class="file-name" id="fileName">Keine ausgewählt</div>
            </div>
            <button type="submit" class="btn btn-primary">
                📤 Schüler hochladen
            </button>
        </form>
    </div>

    <!-- Klassenliste -->
    <?php if (empty($classes)): ?>
        <div class="no-data">
            <div class="icon">🏫</div>
            <h3>Keine Klassen vorhanden</h3>
            <p>Erstellen Sie Ihre erste Klasse über das Formular oben.</p>
        </div>
    <?php else: ?>
        <div class="classes-grid">
            <?php foreach ($classes as $class): ?>
                <div class="class-card">
                    <div class="class-header">
                        <div>
                            <div class="class-name"><?php echo escape($class['name']); ?></div>
                            <?php if (isset($class['created_at'])): ?>
                            <div style="font-size: 0.8rem; opacity: 0.7;">
                                Erstellt: <?php echo formatDate($class['created_at'], 'd.m.Y'); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if (isset($class['school_year']) && !empty($class['school_year'])): ?>
                                <div class="class-year"><?php echo escape($class['school_year']); ?></div>
                            <?php else: ?>
                                <div class="class-year">Klasse</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="class-stats">
                        <div class="stat-item">
                            <span>🎓</span>
                            <span><?php echo $class['student_count']; ?> Schüler</span>
                        </div>
                        <div class="stat-item">
                            <span>📊</span>
                            <span><?php echo round(($class['student_count'] / $school['max_students_per_class']) * 100); ?>% belegt</span>
                        </div>
                    </div>

                    <!-- Kapazitätsbalken -->
                    <?php 
                    $capacity = ($class['student_count'] / $school['max_students_per_class']) * 100;
                    $capacityClass = $capacity >= 90 ? 'danger' : ($capacity >= 75 ? 'warning' : '');
                    ?>
                    <div class="capacity-bar">
                        <div class="capacity-fill <?php echo $capacityClass; ?>" 
                             style="width: <?php echo min(100, $capacity); ?>%"></div>
                    </div>
                    
                    <div style="font-size: 0.8rem; text-align: center; opacity: 0.7;">
                        <?php 
                        // Schüler-Vorschau (maximal 3 Namen)
                        $students = getClassStudentsSimple($class['id']);
                        $studentNames = [];
                        foreach (array_slice($students, 0, 3) as $student) {
                            $name = $student['first_name'] === '-' 
                                ? $student['last_name'] 
                                : $student['first_name'] . ' ' . $student['last_name'];
                            $studentNames[] = $name;
                        }
                        if (!empty($studentNames)) {
                            echo escape(implode(', ', $studentNames));
                            if (count($students) > 3) {
                                echo '...';
                            }
                        } else {
                            echo 'Noch keine Schüler';
                        }
                        ?>
                    </div>

                    <div class="class-actions">
                        <button class="btn btn-secondary btn-sm" onclick="editClass(<?php echo $class['id']; ?>)">
                            ✏️ Bearbeiten
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="showStudents(<?php echo $class['id']; ?>)">
                            👥 Schüler
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteClass(<?php echo $class['id']; ?>)">
                            🗑️ Löschen
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* Klassen-spezifische Styles */
.school-limits {
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 2rem;
    font-size: 0.9rem;
}

.school-limits h4 {
    color: #93c5fd;
    margin-bottom: 0.5rem;
}

.limits-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.limit-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.limit-current {
    font-weight: bold;
    color: #3b82f6;
}

.filters {
    display: flex;
    gap: 1rem;
    align-items: center;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.filter-select {
    padding: 0.5rem;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(100, 116, 139, 0.3);
    border-radius: 0.5rem;
    color: white;
    font-size: 0.9rem;
}

.filter-select option {
    background: #1e293b;
    color: white;
}

.create-section {
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 1rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
    backdrop-filter: blur(10px);
}

.create-section h3 {
    color: #3b82f6;
    margin-bottom: 1rem;
    font-size: 1.2rem;
}

.create-form {
    display: flex;
    gap: 1rem;
    align-items: end;
    flex-wrap: wrap;
}

.upload-section {
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 1rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
    backdrop-filter: blur(10px);
}

.upload-section h3 {
    color: #3b82f6;
    margin-bottom: 1rem;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.format-info {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
}

.format-info h4 {
    color: #fbbf24;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.format-info ul {
    color: #fbbf24;
    margin-left: 1.5rem;
    font-size: 0.9rem;
}

.upload-form {
    display: flex;
    gap: 1rem;
    align-items: end;
    flex-wrap: wrap;
}

.file-input-wrapper {
    position: relative;
    overflow: hidden;
    display: inline-block;
}

.file-input {
    position: absolute;
    left: -9999px;
}

.file-input-label {
    padding: 0.75rem 1.5rem;
    background: rgba(100, 116, 139, 0.2);
    border: 1px solid rgba(100, 116, 139, 0.3);
    border-radius: 0.5rem;
    color: #cbd5e1;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.file-input-label:hover {
    background: rgba(100, 116, 139, 0.3);
}

.file-name {
    color: #94a3b8;
    font-size: 0.9rem;
    font-style: italic;
}

.classes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
}

.class-card {
    background: rgba(0, 0, 0, 0.4);
    border: 1px solid rgba(100, 116, 139, 0.2);
    border-radius: 1rem;
    padding: 1.5rem;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.class-card:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
}

.class-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.class-name {
    font-size: 1.3rem;
    font-weight: 600;
    color: #3b82f6;
    margin-bottom: 0.25rem;
}

.class-year {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
    padding: 0.25rem 0.75rem;
    border-radius: 0.75rem;
    font-size: 0.8rem;
    font-weight: 500;
}

.class-stats {
    display: flex;
    justify-content: space-between;
    margin: 1rem 0;
    font-size: 0.9rem;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    opacity: 0.8;
}

.class-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}

.capacity-bar {
    background: rgba(100, 116, 139, 0.3);
    border-radius: 0.5rem;
    height: 0.5rem;
    margin: 0.5rem 0;
    overflow: hidden;
}

.capacity-fill {
    height: 100%;
    background: linear-gradient(90deg, #22c55e, #16a34a);
    transition: width 0.3s ease;
}

.capacity-fill.warning {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.capacity-fill.danger {
    background: linear-gradient(90deg, #ef4444, #dc2626);
}

@media (max-width: 768px) {
    .filters {
        justify-content: space-between;
    }
    
    .create-form,
    .upload-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .classes-grid {
        grid-template-columns: 1fr;
    }
}
</style>