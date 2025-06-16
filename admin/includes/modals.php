<!-- Edit Class Modal -->
<div id="editClassModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">✏️ Klasse bearbeiten</h3>
            <button class="modal-close" onclick="closeModal('editClassModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editClassForm" onsubmit="updateClass(event)">
                <input type="hidden" id="editClassId" name="class_id">
                
                <div class="form-group">
                    <label for="editClassName">Klassenname</label>
                    <input type="text" id="editClassName" name="class_name" required>
                </div>
                
                <?php if (isset($hasSchoolYearColumn) && $hasSchoolYearColumn): ?>
                <div class="form-group">
                    <label for="editSchoolYear">Schuljahr</label>
                    <select id="editSchoolYear" name="school_year">
                        <option value="">Optional wählen...</option>
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('editClassModal')">Abbrechen</button>
            <button class="btn btn-primary" onclick="document.getElementById('editClassForm').dispatchEvent(new Event('submit'))">
                💾 Speichern
            </button>
        </div>
    </div>
</div>

<!-- Students Modal -->
<div id="studentsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">👥 Schüler verwalten</h3>
            <button class="modal-close" onclick="closeModal('studentsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="add-student-form">
                <h4>➕ Schüler hinzufügen/bearbeiten</h4>
                <form id="addStudentForm" onsubmit="saveStudent(event)">
                    <input type="hidden" id="studentId" name="student_id" value="">
                    <input type="hidden" id="addStudentClassId" name="class_id">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="studentFirstName">Vorname</label>
                            <input type="text" id="studentFirstName" name="first_name" 
                                   placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label for="studentLastName">Nachname *</label>
                            <input type="text" id="studentLastName" name="last_name" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" id="studentSubmitBtn">
                            ➕ Hinzufügen
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="resetStudentForm()" 
                                style="display: none;" id="studentCancelBtn">
                            ❌ Abbrechen
                        </button>
                    </div>
                </form>
            </div>
            
            <div>
                <h4 style="color: #3b82f6; margin-bottom: 1rem;">📋 Schülerliste</h4>
                <div class="student-list-info">
                    <small style="color: #94a3b8;">
                        Klicken Sie auf einen Schüler zum Bearbeiten
                    </small>
                </div>
                <div id="studentsList" class="student-list">
                    <!-- Wird dynamisch gefüllt -->
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal('studentsModal')">Schließen</button>
        </div>
    </div>
</div>

<style>
/* Modal-spezifische Styles */
.student-list-info {
    margin-bottom: 0.5rem;
    text-align: center;
}

.student-item {
    cursor: pointer;
    transition: all 0.2s ease;
}

.student-item:hover {
    background: rgba(59, 130, 246, 0.1) !important;
    transform: translateX(5px);
}

.student-item.selected {
    background: rgba(59, 130, 246, 0.2) !important;
    border-left: 3px solid #3b82f6;
}

.student-name {
    user-select: none;
}

.form-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
}

.form-row .form-group {
    flex: 1;
}

#studentSubmitBtn.edit-mode {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

#studentSubmitBtn.edit-mode:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}
</style>