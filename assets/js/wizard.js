(function () {
    'use strict';

    var storageKey = 'nemsu_complaint_draft';

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function saveDraft(form) {
        if (!form || !window.localStorage) {
            return;
        }
        var data = {};
        new FormData(form).forEach(function (value, key) {
            if (key !== 'csrf_token' && key !== 'supporting_documents') {
                data[key] = value;
            }
        });
        localStorage.setItem(storageKey, JSON.stringify(data));
    }

    function loadDraft(form) {
        if (!form || !window.localStorage) {
            return;
        }
        var raw = localStorage.getItem(storageKey);
        if (!raw) {
            return;
        }
        try {
            var data = JSON.parse(raw);
            Object.keys(data).forEach(function (key) {
                var field = form.elements.namedItem(key);
                if (field && field.type !== 'file') {
                    field.value = data[key];
                }
            });
            var cat = data.complaint_category;
            if (cat) {
                qsa('.nemsu-category-card').forEach(function (card) {
                    card.classList.toggle('is-selected', card.getAttribute('data-category') === cat);
                });
            }
            showSeverity(form);
        } catch (e) {
            /* ignore */
        }
    }

    function clearDraft() {
        if (window.localStorage) {
            localStorage.removeItem(storageKey);
        }
    }

    function showStep(root, step) {
        qsa('[data-wizard-step]', root).forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-wizard-step') !== String(step);
        });
        qsa('[data-wizard-indicator]', root).forEach(function (indicator) {
            var n = parseInt(indicator.getAttribute('data-wizard-indicator'), 10);
            indicator.classList.toggle('is-active', n === step);
            indicator.classList.toggle('is-done', n < step);
        });
        root.setAttribute('data-current-step', String(step));
    }

    function validateStep(form, step) {
        var errors = [];
        if (step === 1) {
            if (!form.elements.complaint_category.value) {
                errors.push('Please choose a complaint category.');
            }
        }
        if (step === 2) {
            if (!form.elements.complaint_title.value.trim()) {
                errors.push('Please enter a title.');
            }
            if (!form.elements.complaint_description.value.trim()) {
                errors.push('Please describe what happened.');
            }
            if (!form.elements.incident_date.value) {
                errors.push('Please select the incident date.');
            }
            if (personFieldsShown() && form.elements.person_name.value.trim() && !form.elements.person_details.value.trim()) {
                errors.push('Add a short description of the person involved.');
            }
        }
        return errors;
    }

    function renderReview(form) {
        var box = qs('#wizardReview');
        if (!box) {
            return;
        }
        var fields = [
            ['Category', form.elements.complaint_category.value],
            ['Title', form.elements.complaint_title.value],
            ['Description', form.elements.complaint_description.value],
            ['Incident date', form.elements.incident_date.value],
            ['Location', form.elements.incident_location.value || '—'],
            ['Severity', severityLabel()],
            ['Person involved', personFieldsShown() && form.elements.person_name.value.trim()
                ? form.elements.person_name.value.trim() + ' — ' + form.elements.person_details.value.trim()
                : '—'],
        ];
        box.innerHTML = fields.map(function (row) {
            return '<div class="nemsu-review-row"><dt>' + row[0] + '</dt><dd>' + escapeHtml(row[1]) + '</dd></div>';
        }).join('');
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Severity follows the nature of the complaint (set on each category card).
    function severityLabel() {
        var card = document.querySelector('.nemsu-category-card.is-selected');
        return card ? (card.getAttribute('data-severity-label') || '') : '';
    }

    function showSeverity(form) {
        var box = document.getElementById('severity_display');
        if (box) {
            box.value = severityLabel() || 'Choose the nature of the complaint first';
        }
        syncPersonFields(form);
    }

    // "Name of person involved": not asked for Campus Facilities; the short
    // description appears (and is required) once a name is typed.
    function personFieldsShown() {
        var box = document.getElementById('personInvolvedFields');
        return !!box && !box.hidden;
    }

    function syncPersonFields(form) {
        var box = document.getElementById('personInvolvedFields');
        var details = document.getElementById('personDetailsField');
        if (!box || !form.elements.person_name) {
            return;
        }
        var hide = form.elements.complaint_category.value === box.getAttribute('data-hide-for-category');
        box.hidden = hide;
        form.elements.person_name.disabled = hide;
        var named = !hide && form.elements.person_name.value.trim() !== '';
        if (details) {
            details.hidden = !named;
        }
        form.elements.person_details.disabled = !named;
        form.elements.person_details.required = named;
    }

    function initCategoryCards(form) {
        qsa('.nemsu-category-card').forEach(function (card) {
            card.addEventListener('click', function () {
                qsa('.nemsu-category-card').forEach(function (c) {
                    c.classList.remove('is-selected');
                    c.setAttribute('aria-pressed', 'false');
                });
                card.classList.add('is-selected');
                card.setAttribute('aria-pressed', 'true');
                form.elements.complaint_category.value = card.getAttribute('data-category') || '';
                showSeverity(form);
                saveDraft(form);
            });
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    card.click();
                }
            });
        });
    }

    function initFileDrop(form) {
        var zone = qs('#fileDropZone');
        var input = form.elements.supporting_documents;
        var preview = qs('#filePreview');
        if (!zone || !input || !preview) {
            return;
        }

        function updatePreview() {
            preview.innerHTML = '';
            if (!input.files || !input.files[0]) {
                return;
            }
            var file = input.files[0];
            var item = document.createElement('div');
            item.className = 'nemsu-file-preview';
            item.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
            preview.appendChild(item);
        }

        zone.addEventListener('dragover', function (event) {
            event.preventDefault();
            zone.classList.add('is-dragover');
        });
        zone.addEventListener('dragleave', function () {
            zone.classList.remove('is-dragover');
        });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-dragover');
            if (event.dataTransfer.files.length) {
                input.files = event.dataTransfer.files;
                updatePreview();
            }
        });
        input.addEventListener('change', updatePreview);
    }

    function initCharCounter(form) {
        var ta = form.elements.complaint_description;
        var counter = qs('#descCounter');
        if (!ta || !counter) {
            return;
        }
        function update() {
            counter.textContent = ta.value.length + ' characters';
        }
        ta.addEventListener('input', update);
        update();
    }

    function initWizard() {
        var root = qs('#complaintWizard');
        var form = qs('#submitComplaintForm');
        if (!root || !form) {
            return;
        }

        loadDraft(form);
        initCategoryCards(form);
        if (form.elements.person_name) {
            form.elements.person_name.addEventListener('input', function () {
                syncPersonFields(form);
            });
            syncPersonFields(form);
        }
        initFileDrop(form);
        initCharCounter(form);

        form.addEventListener('input', function () {
            saveDraft(form);
        });

        qsa('[data-wizard-next]', root).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var step = parseInt(root.getAttribute('data-current-step') || '1', 10);
                var errors = validateStep(form, step);
                var errBox = qs('#wizardErrors');
                if (errors.length) {
                    if (errBox) {
                        errBox.textContent = errors.join(' ');
                        errBox.hidden = false;
                    }
                    return;
                }
                if (errBox) {
                    errBox.hidden = true;
                }
                if (step === 2) {
                    renderReview(form);
                }
                showStep(root, Math.min(3, step + 1));
            });
        });

        qsa('[data-wizard-back]', root).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var step = parseInt(root.getAttribute('data-current-step') || '1', 10);
                showStep(root, Math.max(1, step - 1));
            });
        });

        form.addEventListener('submit', function () {
            clearDraft();
        });
    }

    document.addEventListener('DOMContentLoaded', initWizard);
})();
