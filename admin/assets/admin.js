// Hilfsfunktion für HTML-Escaping
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Filter-Funktionen
function applyFilters() {
    const classFilter = document.querySelector('.filter-select').value;
    const yearFilter = document.querySelectorAll('.filter-select')[1]?.value || 'all';
    
    const url = new URL(window.location);
    url.searchParams.set('tab', 'klassen');
    url.searchParams.set('class_filter', classFilter);
    url.searchParams.set('year_filter', yearFilter);
    window.location.href = url.toString();
}

// Klassen-Funktionen
function createClass(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('action', 'create_class');
    formData.append('csrf_token', window.csrfToken);

    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Fehler: ' + data.message);
        }
    })
    .catch(error => {
        alert('Ein Fehler ist aufgetreten.');
        console.error(error);
    });
}

function editClass(classId) {
    const button = event.target;
    const card = button.closest('.class-card');
    const className = card.querySelector('.class-name').textContent.trim();
    const classYearElement = card.querySelector('.class-year');
    const classYear = classYearElement ? classYearElement.textContent.trim() : '';
    
    document.getElementById('editClassId').value = classId;
    document.getElementById('editClassName').value = className;
    
    const schoolYearSelect = document.getElementById('editSchoolYear');
    if (schoolYearSelect && classYear !== 'Klasse') {
        schoolYearSelect.value = classYear;
    }
    
    showModal('editClassModal');
}

function updateClass(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('action', 'update_class');
    formData.append('csrf_token', window.csrfToken);

    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('editClassModal');
            location.reload();
        } else {
            alert('Fehler: ' + data.message);
        }
    })
    .catch(error => {
        alert('Ein Fehler ist aufgetreten.');
        console.error(error);
    });
}

function deleteClass(classId) {
    if (confirm('Klasse wirklich löschen? Alle Schülerdaten gehen verloren!')) {
        const formData = new FormData();
        formData.append('action', 'delete_class');
        formData.append('class_id', classId);
        formData.append('csrf_token', window.csrfToken);

        fetch('dashboard.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Fehler: ' + data.message);
            }
        })
        .catch(error => {
            alert('Ein Fehler ist aufgetreten.');
            console.error(error);
        });
    }
}

// Schüler-Funktionen
function showStudents(classId) {
    document.getElementById('addStudentClassId').value = classId;
    resetStudentForm();
    loadStudentsList(classId);
    showModal('studentsModal');
}

function loadStudentsList(classId) {
    const studentsList = document.getElementById('studentsList');
    studentsList.innerHTML = '<div style="padding: 2rem; text-align: center; opacity: 0.7;">Lädt...</div>';
    
    const formData = new FormData();
    formData.append('action', 'get_students');
    formData.append('class_id', classId);
    formData.append('csrf_token', window.csrfToken);
    
    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const students = data.data;
            
            if (students.length === 0) {
                studentsList.innerHTML = '<div style="padding: 2rem; text-align: center; opacity: 0.7;">Keine Schüler in dieser Klasse</div>';
                return;
            }
            
            studentsList.innerHTML = students.map(student => `
                <div class="student-item" onclick="editStudent(${student.id}, '${escapeHtml(student.first_name)}', '${escapeHtml(student.last_name)}')">
                    <div class="student-name">
                        <strong>${student.first_name === '-' ? '' : escapeHtml(student.first_name)} ${escapeHtml(student.last_name)}</strong>
                    </div>
                    <div class="student-actions" onclick="event.stopPropagation()">
                        <button class="btn btn-danger btn-sm" onclick="deleteStudent(${student.id}, ${classId})" title="Schüler entfernen">
                            🗑️
                        </button>
                    </div>
                </div>
            `).join('');
        } else {
            studentsList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #ef4444;">Fehler beim Laden der Schüler</div>';
        }
    })
    .catch(error => {
        console.error('Error loading students:', error);
        studentsList.innerHTML = '<div style="padding: 2rem; text-align: center; color: #ef4444;">Fehler beim Laden der Schüler</div>';
    });
}

function editStudent(studentId, firstName, lastName) {
    // Form für Bearbeitung vorbereiten
    document.getElementById('studentId').value = studentId;
    document.getElementById('studentFirstName').value = firstName === '-' ? '' : firstName;
    document.getElementById('studentLastName').value = lastName;
    
    // Button-Text und Styling ändern
    const submitBtn = document.getElementById('studentSubmitBtn');
    submitBtn.textContent = '💾 Speichern';
    submitBtn.classList.add('edit-mode');
    
    // Abbrechen-Button anzeigen
    document.getElementById('studentCancelBtn').style.display = 'inline-block';
    
    // Markierung in der Liste
    document.querySelectorAll('.student-item').forEach(item => {
        item.classList.remove('selected');
    });
    event.currentTarget.classList.add('selected');
    
    // Fokus auf das erste Eingabefeld
    document.getElementById('studentFirstName').focus();
}

function resetStudentForm() {
    document.getElementById('studentId').value = '';
    document.getElementById('studentFirstName').value = '';
    document.getElementById('studentLastName').value = '';
    
    const submitBtn = document.getElementById('studentSubmitBtn');
    submitBtn.textContent = '➕ Hinzufügen';
    submitBtn.classList.remove('edit-mode');
    
    document.getElementById('studentCancelBtn').style.display = 'none';
    
    // Markierung entfernen
    document.querySelectorAll('.student-item').forEach(item => {
        item.classList.remove('selected');
    });
}

function saveStudent(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const studentId = formData.get('student_id');
    const action = studentId ? 'update_student' : 'add_single_student';
    
    formData.append('action', action);
    formData.append('csrf_token', window.csrfToken);

    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Form zurücksetzen
            resetStudentForm();
            
            // Schülerliste neu laden
            const classId = document.getElementById('addStudentClassId').value;
            loadStudentsList(classId);
            
            // Kurze Erfolgsmeldung
            showToast(data.message, 'success');
        } else {
            alert('Fehler: ' + data.message);
        }
    })
    .catch(error => {
        alert('Ein Fehler ist aufgetreten.');
        console.error(error);
    });
}

function deleteStudent(studentId, classId) {
    if (confirm('Schüler wirklich entfernen?')) {
        const formData = new FormData();
        formData.append('action', 'delete_student');
        formData.append('student_id', studentId);
        formData.append('csrf_token', window.csrfToken);

        fetch('dashboard.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadStudentsList(classId);
                showToast('Schüler erfolgreich entfernt', 'success');
            } else {
                alert('Fehler: ' + data.message);
            }
        })
        .catch(error => {
            alert('Ein Fehler ist aufgetreten.');
            console.error(error);
        });
    }
}

// Upload-Funktionen
function uploadStudents(event) {
    event.preventDefault();
    
    const fileInput = document.getElementById('studentFile');
    const classSelect = document.getElementById('uploadClass');
    
    if (!fileInput.files[0]) {
        alert('Bitte wählen Sie eine Datei aus.');
        return;
    }
    
    if (!classSelect.value) {
        alert('Bitte wählen Sie eine Klasse aus.');
        return;
    }
    
    const formData = new FormData(event.target);
    formData.append('action', 'upload_students');
    formData.append('csrf_token', window.csrfToken);

    const submitButton = event.target.querySelector('button[type="submit"]');
    const originalText = submitButton.textContent;
    submitButton.textContent = '⏳ Hochladen...';
    submitButton.disabled = true;

    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                alert(data.message);
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                alert('Fehler: ' + data.message);
            }
        } catch (e) {
            console.error('Parse error:', e);
            console.error('Response text:', text);
            alert('Server-Fehler: Die Antwort konnte nicht verarbeitet werden.');
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        alert('Ein Fehler ist aufgetreten: ' + error.message);
    })
    .finally(() => {
        submitButton.textContent = originalText;
        submitButton.disabled = false;
    });
}

function updateFileName(input) {
    const fileName = document.getElementById('fileName');
    if (input.files[0]) {
        fileName.textContent = input.files[0].name;
        
        if (input.files[0].size > 5 * 1024 * 1024) {
            alert('Datei ist zu groß. Maximum: 5MB');
            input.value = '';
            fileName.textContent = 'Keine ausgewählt';
        }
    } else {
        fileName.textContent = 'Keine ausgewählt';
    }
}

// Modal-Funktionen
function showModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

// Toast-Benachrichtigungen
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 10);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 300);
    }, 3000);
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // CSRF Token speichern
    window.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                      '<?php echo $_SESSION["csrf_token"] ?? ""; ?>';
    
    // ESC key to close modals
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                openModal.classList.remove('show');
                resetStudentForm();
            }
        }
    });

    // Click outside to close modal
    document.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('show');
            resetStudentForm();
        }
    });
});