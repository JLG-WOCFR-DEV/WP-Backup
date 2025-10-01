jQuery(document).ready(function($) {

    // --- GESTIONNAIRE DE PLANIFICATION ---
    const $scheduleForm = $('#bjlg-schedule-form');
    if ($scheduleForm.length && typeof bjlg_ajax !== 'undefined') {
        const $recurrenceSelect = $scheduleForm.find('#bjlg-schedule-recurrence');
        const $weeklyOptionsRow = $scheduleForm.find('.bjlg-schedule-weekly-options');
        const $timeOptionsRow = $scheduleForm.find('.bjlg-schedule-time-options');

        let $feedback = $('#bjlg-schedule-feedback');
        if (!$feedback.length) {
            $feedback = $('<div/>', {
                id: 'bjlg-schedule-feedback',
                class: 'notice',
                style: 'display:none;'
            });

            const $submitRow = $scheduleForm.find('.submit').last();
            if ($submitRow.length) {
                $feedback.insertBefore($submitRow);
            } else {
                $feedback.prependTo($scheduleForm);
            }
        }

        let $nextRunDisplay = $('#bjlg-schedule-next-run');
        if (!$nextRunDisplay.length) {
            $nextRunDisplay = $('<p/>', {
                id: 'bjlg-schedule-next-run',
                class: 'description',
                'aria-live': 'polite'
            }).text('Prochaine exécution : Non planifiée');

            const $submitRow = $scheduleForm.find('.submit').last();
            if ($submitRow.length) {
                $nextRunDisplay.insertAfter($submitRow);
            } else {
                $scheduleForm.append($nextRunDisplay);
            }
        }

        let nextRunLabel = $nextRunDisplay.data('label');
        if (typeof nextRunLabel !== 'string' || nextRunLabel.trim() === '') {
            const initialText = ($nextRunDisplay.text() || '').trim();
            const labelMatch = initialText.match(/^(.+?:)/);
            if (labelMatch && labelMatch[1]) {
                nextRunLabel = labelMatch[1];
            } else {
                nextRunLabel = 'Prochaine exécution :';
            }
        }

        function setRowVisibility($row, shouldShow) {
            if (!$row || !$row.length) {
                return;
            }

            if (shouldShow) {
                $row.show().attr('aria-hidden', 'false');
            } else {
                $row.hide().attr('aria-hidden', 'true');
            }
        }

        function toggleScheduleOptions() {
            const value = ($recurrenceSelect.val() || '').toString();
            setRowVisibility($weeklyOptionsRow, value === 'weekly');
            setRowVisibility($timeOptionsRow, value !== 'disabled');
        }

        if ($recurrenceSelect.length) {
            $recurrenceSelect.on('change', toggleScheduleOptions);
            toggleScheduleOptions();
        }

        function resetScheduleFeedback() {
            if (!$feedback.length) {
                return;
            }

            $feedback
                .removeClass('notice-success notice-error notice-info')
                .hide()
                .empty()
                .removeAttr('role');
        }

        function normalizeErrorList(raw) {
            const messages = [];
            const seen = new Set();

            function pushMessage(value) {
                if (typeof value !== 'string') {
                    return;
                }

                const trimmed = value.trim();
                if (trimmed === '' || seen.has(trimmed)) {
                    return;
                }

                seen.add(trimmed);
                messages.push(trimmed);
            }

            (function walk(value) {
                if (value === undefined || value === null) {
                    return;
                }

                if (typeof value === 'string') {
                    pushMessage(value);
                    return;
                }

                if (Array.isArray(value)) {
                    value.forEach(walk);
                    return;
                }

                if (typeof value === 'object') {
                    if (typeof value.message === 'string') {
                        pushMessage(value.message);
                    }

                    Object.keys(value).forEach(function(key) {
                        if (key === 'message') {
                            return;
                        }
                        walk(value[key]);
                    });
                }
            })(raw);

            return messages;
        }

        function renderScheduleFeedback(type, message, details) {
            if (!$feedback.length) {
                return;
            }

            const classes = ['notice'];
            if (type === 'success') {
                classes.push('notice-success');
            } else if (type === 'error') {
                classes.push('notice-error');
            } else {
                classes.push('notice-info');
            }

            $feedback.attr('class', classes.join(' '));

            if (message && message.trim() !== '') {
                $('<p/>').text(message).appendTo($feedback);
            }

            if (Array.isArray(details) && details.length) {
                const $list = $('<ul/>');
                details.forEach(function(item) {
                    $('<li/>').text(item).appendTo($list);
                });
                $feedback.append($list);
            }

            $feedback.attr('role', 'alert').show();
        }

        function updateNextRunDisplay(nextRunText) {
            if (!$nextRunDisplay.length) {
                return;
            }

            const displayText = nextRunText && nextRunText.trim() !== ''
                ? nextRunText.trim()
                : 'Non planifiée';

            $nextRunDisplay.text(nextRunLabel + ' ' + displayText);
        }

        updateNextRunDisplay(($nextRunDisplay.text() || '').replace(/^.*?:\s*/, ''));

        $scheduleForm.on('submit', function(event) {
            event.preventDefault();

            resetScheduleFeedback();

            const $submitButton = $scheduleForm.find('button[type="submit"]').first();
            $submitButton.prop('disabled', true);

            const payload = {
                action: 'bjlg_save_schedule_settings',
                nonce: bjlg_ajax.nonce
            };

            const serialized = $scheduleForm.serializeArray();
            serialized.forEach(function(field) {
                if (!field || !field.name) {
                    return;
                }

                let name = field.name;
                let value = field.value;

                if (name === 'encrypt' || name === 'incremental') {
                    value = 'true';
                }

                if (name.slice(-2) === '[]') {
                    name = name.slice(0, -2);
                    if (!Array.isArray(payload[name])) {
                        payload[name] = [];
                    }
                    payload[name].push(value);
                    return;
                }

                if (Object.prototype.hasOwnProperty.call(payload, name)) {
                    if (!Array.isArray(payload[name])) {
                        payload[name] = [payload[name]];
                    }
                    payload[name].push(value);
                    return;
                }

                payload[name] = value;
            });

            ['encrypt', 'incremental'].forEach(function(fieldName) {
                if (!$scheduleForm.find('[name="' + fieldName + '"]').length) {
                    return;
                }

                if (typeof payload[fieldName] === 'undefined') {
                    payload[fieldName] = 'false';
                }
            });

            $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                data: payload
            })
                .done(function(response) {
                    const data = response && typeof response === 'object' ? response.data || {} : {};

                    if (response && response.success) {
                        const message = data && typeof data.message === 'string'
                            ? data.message
                            : 'Planification enregistrée.';
                        renderScheduleFeedback('success', message);
                        if (data && Object.prototype.hasOwnProperty.call(data, 'next_run')) {
                            updateNextRunDisplay(data.next_run);
                        }
                        return;
                    }

                    const payloadData = data && Object.keys(data).length ? data : (response && response.data) || response;
                    const message = payloadData && typeof payloadData.message === 'string'
                        ? payloadData.message
                        : 'Impossible d\'enregistrer la planification.';
                    const details = payloadData
                        ? normalizeErrorList(payloadData.errors || payloadData.validation_errors || payloadData.field_errors)
                        : [];

                    renderScheduleFeedback('error', message, details);

                    if (payloadData && Object.prototype.hasOwnProperty.call(payloadData, 'next_run')) {
                        updateNextRunDisplay(payloadData.next_run);
                    }
                })
                .fail(function(jqXHR) {
                    let message = 'Erreur de communication avec le serveur.';
                    let details = [];

                    if (jqXHR && jqXHR.responseJSON) {
                        const errorData = jqXHR.responseJSON.data || jqXHR.responseJSON;
                        if (errorData && typeof errorData.message === 'string') {
                            message = errorData.message;
                        }
                        details = normalizeErrorList(
                            (errorData && (errorData.errors || errorData.validation_errors || errorData.field_errors)) || []
                        );
                    } else if (jqXHR && typeof jqXHR.responseText === 'string') {
                        try {
                            const parsed = JSON.parse(jqXHR.responseText);
                            if (parsed && typeof parsed === 'object') {
                                const errorData = parsed.data || parsed;
                                if (errorData && typeof errorData.message === 'string') {
                                    message = errorData.message;
                                }
                                details = normalizeErrorList(
                                    (errorData && (errorData.errors || errorData.validation_errors || errorData.field_errors)) || []
                                );
                            }
                        } catch (error) {
                            // Ignore JSON parse errors and keep the default message.
                        }
                    }

                    renderScheduleFeedback('error', message, details);
                })
                .always(function() {
                    $submitButton.prop('disabled', false);
                });
        });
    }

    // La navigation par onglets est gérée par PHP via rechargement de page.

    // --- GESTIONNAIRE DE SAUVEGARDE ASYNCHRONE ---
    $('#bjlg-backup-creation-form').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $button = $form.find('button[type="submit"]');
        const $progressArea = $('#bjlg-backup-progress-area');
        const $statusText = $('#bjlg-backup-status-text');
        const $progressBar = $('#bjlg-backup-progress-bar');
        const $debugWrapper = $('#bjlg-backup-debug-wrapper');
        const $debugOutput = $('#bjlg-backup-ajax-debug');
        
        let components = [];
        $form.find('input[name="backup_components[]"]:checked').each(function() {
            components.push($(this).val());
        });

        const encrypt = $form.find('input[name="encrypt_backup"]').is(':checked');
        const incremental = $form.find('input[name="incremental_backup"]').is(':checked');

        if (components.length === 0) {
            alert('Veuillez sélectionner au moins un composant à sauvegarder.');
            return;
        }

        $button.prop('disabled', true);
        $progressArea.show();
        if ($debugWrapper.length) $debugWrapper.show();
        $statusText.text('Initialisation...');
        $progressBar.css('width', '5%').text('5%');

        const data = {
            action: 'bjlg_start_backup_task',
            nonce: bjlg_ajax.nonce,
            components: components,
            encrypt: encrypt,
            incremental: incremental
        };
        let debugReport = "--- 1. REQUÊTE DE LANCEMENT ---\nDonnées envoyées:\n" + JSON.stringify(data, null, 2);
        if ($debugOutput.length) $debugOutput.text(debugReport);

        $.ajax({
            url: bjlg_ajax.ajax_url,
            type: 'POST',
            data: data
        })
        .done(function(response) {
            if ($debugOutput.length) {
                debugReport += "\n\nRéponse du serveur:\n" + JSON.stringify(response, null, 2);
                $debugOutput.text(debugReport);
            }
            if (response.success && response.data.task_id) {
                if ($debugOutput.length) {
                    debugReport += "\n\n--- 2. SUIVI DE LA PROGRESSION ---";
                    $debugOutput.text(debugReport);
                }
                pollBackupProgress(response.data.task_id);
            } else {
                $statusText.text('Erreur lors du lancement : ' + (response.data.message || 'Réponse invalide.'));
                $button.prop('disabled', false);
            }
        })
        .fail(function(xhr) {
            if ($debugOutput.length) {
                debugReport += "\n\nERREUR CRITIQUE DE COMMUNICATION\nStatut: " + xhr.status + "\nRéponse brute:\n" + xhr.responseText;
                $debugOutput.text(debugReport);
            }
            $statusText.text('Erreur de communication.');
            $button.prop('disabled', false);
        });

        function pollBackupProgress(taskId) {
            const interval = setInterval(function() {
                $.ajax({
                    url: bjlg_ajax.ajax_url, type: 'POST',
                    data: { action: 'bjlg_check_backup_progress', nonce: bjlg_ajax.nonce, task_id: taskId }
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        const data = response.data;
                        $statusText.text(data.status_text || 'Progression...');

                        const progressValue = Number.parseFloat(data.progress);
                        const hasNumericProgress = Number.isFinite(progressValue);
                        const progressDisplay = hasNumericProgress ? progressValue.toFixed(1) : data.progress;

                        $progressBar
                            .css('width', progressDisplay + '%')
                            .text(progressDisplay + '%');
                        
                        if (data.progress >= 100) {
                            clearInterval(interval);
                            if (data.status === 'error') {
                                $statusText.html('<span style="color:red;">❌ Erreur : ' + data.status_text + '</span>');
                                $button.prop('disabled', false);
                            } else {
                                $statusText.html('✔️ Terminé ! La page va se recharger.');
                                setTimeout(() => window.location.reload(), 2000);
                            }
                        }
                    } else {
                        clearInterval(interval);
                        $statusText.text('Erreur : La tâche de sauvegarde a été perdue.');
                        $button.prop('disabled', false);
                    }
                })
                .fail(function() {
                     clearInterval(interval);
                     $statusText.text('Erreur de communication lors du suivi.');
                     $button.prop('disabled', false);
                });
            }, 3000);
        }
    });
    
    // --- GESTIONNAIRE DE RESTAURATION ASYNCHRONE ---
    $('#bjlg-restore-form').on('submit', function(e) {
        e.preventDefault();

        const $form = $(this);
        const $button = $form.find('button[type="submit"]');
        const $statusWrapper = $('#bjlg-restore-status');
        const $statusText = $('#bjlg-restore-status-text');
        const $progressBar = $('#bjlg-restore-progress-bar');
        const $debugWrapper = $('#bjlg-restore-debug-wrapper');
        const $debugOutput = $('#bjlg-restore-ajax-debug');
        const fileInput = document.getElementById('bjlg-restore-file-input');
        const passwordInput = document.getElementById('bjlg-restore-password');
        const passwordHelp = document.getElementById('bjlg-restore-password-help');
        const passwordHelpDefaultText = passwordHelp
            ? (passwordHelp.getAttribute('data-default-text') || passwordHelp.textContent.trim())
            : '';
        const passwordHelpEncryptedText = passwordHelp
            ? (passwordHelp.getAttribute('data-encrypted-text') || passwordHelpDefaultText)
            : '';
        const $errorNotice = $('#bjlg-restore-errors');
        const errorFieldClass = 'bjlg-input-error';

        function applyPasswordHelpText(text) {
            if (!passwordHelp) {
                return;
            }

            const resolved = typeof text === 'string' && text.trim() !== ''
                ? text.trim()
                : passwordHelpDefaultText;

            passwordHelp.textContent = resolved;
        }

        function updatePasswordRequirement() {
            if (!passwordInput) {
                return;
            }

            let requiresPassword = false;

            if (fileInput && fileInput.files && fileInput.files.length > 0) {
                const filename = fileInput.files[0].name || '';
                requiresPassword = /\.zip\.enc$/i.test(filename.trim());
            }

            if (requiresPassword) {
                passwordInput.setAttribute('required', 'required');
                passwordInput.setAttribute('aria-required', 'true');
                applyPasswordHelpText(passwordHelpEncryptedText);
            } else {
                passwordInput.removeAttribute('required');
                passwordInput.removeAttribute('aria-required');
                applyPasswordHelpText(passwordHelpDefaultText);
            }
        }

        if (fileInput) {
            fileInput.addEventListener('change', updatePasswordRequirement);
        }

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                $(this)
                    .removeClass(errorFieldClass)
                    .removeAttr('aria-invalid');
                $(this).nextAll('.bjlg-field-error').remove();
            });
        }

        updatePasswordRequirement();

        function getValidationErrors(payload) {
            if (!payload || typeof payload !== 'object') {
                return null;
            }

            if (payload.errors && typeof payload.errors === 'object') {
                return payload.errors;
            }

            if (payload.validation_errors && typeof payload.validation_errors === 'object') {
                return payload.validation_errors;
            }

            if (payload.field_errors && typeof payload.field_errors === 'object') {
                return payload.field_errors;
            }

            return null;
        }

        function parseErrors(rawErrors) {
            const result = {
                general: [],
                fields: {}
            };

            if (!rawErrors) {
                return result;
            }

            const collectMessages = function(target, value) {
                if (Array.isArray(value)) {
                    value.forEach(function(item) {
                        collectMessages(target, item);
                    });
                    return;
                }

                if (!value) {
                    return;
                }

                if (typeof value === 'string') {
                    const trimmed = value.trim();
                    if (trimmed !== '') {
                        target.push(trimmed);
                    }
                    return;
                }

                if (typeof value === 'object' && typeof value.message === 'string') {
                    const trimmed = value.message.trim();
                    if (trimmed !== '') {
                        target.push(trimmed);
                    }
                }
            };

            if (typeof rawErrors === 'string' || Array.isArray(rawErrors)) {
                collectMessages(result.general, rawErrors);
                return result;
            }

            if (typeof rawErrors === 'object') {
                $.each(rawErrors, function(key, value) {
                    if (key === undefined || key === null) {
                        collectMessages(result.general, value);
                        return;
                    }

                    const normalizedKey = String(key);
                    const generalKeys = ['', '_', '_general', '_global', '*', 'general', 'messages'];

                    if (generalKeys.indexOf(normalizedKey) !== -1 || !Number.isNaN(Number(normalizedKey))) {
                        collectMessages(result.general, value);
                        return;
                    }

                    const messages = [];
                    collectMessages(messages, value);

                    if (messages.length) {
                        result.fields[normalizedKey] = messages;
                    }
                });
            }

            return result;
        }

        function clearRestoreErrors() {
            if ($errorNotice.length) {
                $errorNotice.hide().empty();
            }

            $form.find('.bjlg-field-error').remove();
            $form.find('.' + errorFieldClass)
                .removeClass(errorFieldClass)
                .removeAttr('aria-invalid');
        }

        function displayRestoreErrors(message, rawErrors) {
            const parsed = parseErrors(rawErrors);
            const summaryItems = [];
            const seenMessages = new Set();

            const appendSummary = function(text) {
                if (!text || typeof text !== 'string') {
                    return;
                }

                const trimmed = text.trim();
                if (trimmed === '' || seenMessages.has(trimmed)) {
                    return;
                }

                seenMessages.add(trimmed);
                summaryItems.push(trimmed);
            };

            if ($errorNotice.length) {
                $errorNotice.empty();

                if (message && message.trim() !== '') {
                    $('<p/>').text(message).appendTo($errorNotice);
                }

                parsed.general.forEach(appendSummary);

                $.each(parsed.fields, function(field, messages) {
                    messages.forEach(appendSummary);
                });

                if (summaryItems.length) {
                    const $list = $('<ul/>');
                    summaryItems.forEach(function(item) {
                        $('<li/>').text(item).appendTo($list);
                    });
                    $errorNotice.append($list);
                }

                if ($errorNotice.children().length) {
                    $errorNotice.show();
                }
            }

            $.each(parsed.fields, function(fieldName, messages) {
                const $field = $form.find('[name="' + fieldName + '"]');
                if (!$field.length) {
                    return;
                }

                $field.addClass(errorFieldClass).attr('aria-invalid', 'true');

                if (!messages || !messages.length) {
                    return;
                }

                const messageText = messages[0];
                if (!messageText || typeof messageText !== 'string') {
                    return;
                }

                $('<p class="bjlg-field-error description" style="color:#b32d2e; margin-top:4px;"></p>')
                    .text(messageText)
                    .insertAfter($field.last());
            });
        }

        clearRestoreErrors();

        let restoreDebugReport = '';
        const appendRestoreDebug = function(message, payload) {
            if (!$debugOutput.length) {
                return;
            }

            if (restoreDebugReport.length) {
                restoreDebugReport += "\n\n";
            }

            restoreDebugReport += message;

            if (typeof payload !== 'undefined') {
                restoreDebugReport += "\n";
                if (typeof payload === 'string') {
                    restoreDebugReport += payload;
                } else {
                    try {
                        restoreDebugReport += JSON.stringify(payload, null, 2);
                    } catch (error) {
                        restoreDebugReport += String(payload);
                    }
                }
            }

            $debugOutput.text(restoreDebugReport);
        };

        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            alert('Veuillez sélectionner un fichier de sauvegarde à téléverser.');
            return;
        }

        const createRestorePoint = $form
            .find('input[name="create_backup_before_restore"]')
            .is(':checked');

        $button.prop('disabled', true);
        $statusWrapper.show();
        $statusText.text('Téléversement du fichier en cours...');
        $progressBar.css('width', '0%').text('0%');
        if ($debugWrapper.length) {
            $debugWrapper.show();
        }
        if ($debugOutput.length) {
            restoreDebugReport = '';
            $debugOutput.text('');
        }

        const formData = new FormData();
        formData.append('action', 'bjlg_upload_restore_file');
        formData.append('nonce', bjlg_ajax.nonce);
        formData.append('restore_file', fileInput.files[0]);

        if ($debugOutput.length) {
            appendRestoreDebug(
                '--- 1. TÉLÉVERSEMENT DU FICHIER ---\nRequête envoyée (métadonnées)',
                {
                    filename: fileInput.files[0].name,
                    size: fileInput.files[0].size,
                    type: fileInput.files[0].type || 'inconnu',
                    create_backup_before_restore: createRestorePoint
                }
            );
        }

        $.ajax({
            url: bjlg_ajax.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false
        })
        .done(function(response) {
            appendRestoreDebug('Réponse du serveur (téléversement)', response);
            if (response.success && response.data && response.data.filename) {
                $statusText.text('Fichier téléversé. Préparation de la restauration...');
                runRestore(response.data.filename, createRestorePoint);
            } else {
                const payload = response && response.data ? response.data : {};
                const message = payload && payload.message
                    ? payload.message
                    : 'Réponse invalide du serveur.';
                displayRestoreErrors(message, getValidationErrors(payload));
                $statusText.html('<span style="color:red;">❌ ' + message + '</span>');
                $button.prop('disabled', false);
            }
        })
        .fail(function(xhr) {
            appendRestoreDebug(
                'Erreur de communication (téléversement)',
                {
                    status: xhr ? xhr.status : 'inconnu',
                    responseText: xhr ? xhr.responseText : 'Aucune réponse',
                    message: xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                        ? xhr.responseJSON.data.message
                        : undefined
                }
            );
            let errorMessage = 'Erreur de communication lors du téléversement.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage += ' ' + xhr.responseJSON.data.message;
            }
            const errors = xhr && xhr.responseJSON ? getValidationErrors(xhr.responseJSON.data) : null;
            displayRestoreErrors(errorMessage, errors);
            $statusText.html('<span style="color:red;">❌ ' + errorMessage + '</span>');
            $button.prop('disabled', false);
        });

        function runRestore(filename, createRestorePointChecked) {
            const requestData = {
                action: 'bjlg_run_restore',
                nonce: bjlg_ajax.nonce,
                filename: filename,
                create_backup_before_restore: createRestorePointChecked ? 1 : 0,
                password: passwordInput ? passwordInput.value : ''
            };

            $statusText.text('Initialisation de la restauration...');
            appendRestoreDebug('--- 2. DÉMARRAGE DE LA RESTAURATION ---\nRequête envoyée', requestData);

            $.ajax({
                url: bjlg_ajax.ajax_url,
                type: 'POST',
                data: requestData
            })
            .done(function(response) {
                appendRestoreDebug('Réponse du serveur (démarrage restauration)', response);
                if (response.success && response.data && response.data.task_id) {
                    pollRestoreProgress(response.data.task_id);
                } else {
                    const payload = response && response.data ? response.data : {};
                    const message = payload && payload.message
                        ? payload.message
                        : 'Impossible de démarrer la restauration.';
                    displayRestoreErrors(message, getValidationErrors(payload));
                    $statusText.html('<span style="color:red;">❌ ' + message + '</span>');
                    $button.prop('disabled', false);
                }
            })
            .fail(function(xhr) {
                appendRestoreDebug(
                    'Erreur de communication (démarrage restauration)',
                    {
                        status: xhr ? xhr.status : 'inconnu',
                        responseText: xhr ? xhr.responseText : 'Aucune réponse',
                        message: xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                            ? xhr.responseJSON.data.message
                            : undefined
                    }
                );
                let errorMessage = 'Erreur de communication lors du démarrage de la restauration.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage += ' ' + xhr.responseJSON.data.message;
                }
                const errors = xhr && xhr.responseJSON ? getValidationErrors(xhr.responseJSON.data) : null;
                displayRestoreErrors(errorMessage, errors);
                $statusText.html('<span style="color:red;">❌ ' + errorMessage + '</span>');
                $button.prop('disabled', false);
            });
        }

        function pollRestoreProgress(taskId) {
            appendRestoreDebug('--- 3. SUIVI DE LA RESTAURATION ---\nRequête envoyée', {
                action: 'bjlg_check_restore_progress',
                nonce: '***',
                task_id: taskId
            });
            const interval = setInterval(function() {
                $.ajax({
                    url: bjlg_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bjlg_check_restore_progress',
                        nonce: bjlg_ajax.nonce,
                        task_id: taskId
                    }
                })
                .done(function(response) {
                    appendRestoreDebug('Mise à jour progression', response);
                    if (response.success && response.data) {
                        const data = response.data;

                        if (data.status_text) {
                            $statusText.text(data.status_text);
                        }

                        const progressValue = Number.parseFloat(data.progress);
                        if (Number.isFinite(progressValue)) {
                            const clampedProgress = Math.max(0, Math.min(100, progressValue));
                            $progressBar
                                .css('width', clampedProgress + '%')
                                .text(clampedProgress + '%');
                        }

                        if (data.status === 'error') {
                            clearInterval(interval);
                            const message = data.status_text || 'La restauration a échoué.';
                            displayRestoreErrors(message, getValidationErrors(data));
                            $statusText.html('<span style="color:red;">❌ ' + message + '</span>');
                            $button.prop('disabled', false);
                        } else if (data.status === 'complete' || (Number.isFinite(progressValue) && progressValue >= 100)) {
                            clearInterval(interval);
                            $progressBar.css('width', '100%').text('100%');
                            $statusText.html('✔️ Restauration terminée ! La page va se recharger.');
                            setTimeout(() => window.location.reload(), 3000);
                        }
                    } else {
                        clearInterval(interval);
                        const message = response && response.data && response.data.message
                            ? response.data.message
                            : 'Tâche de restauration introuvable.';
                        $statusText.html('<span style="color:red;">❌ ' + message + '</span>');
                        $button.prop('disabled', false);
                    }
                })
                .fail(function(xhr) {
                    appendRestoreDebug(
                        'Erreur de communication (suivi restauration)',
                        {
                            status: xhr ? xhr.status : 'inconnu',
                            responseText: xhr ? xhr.responseText : 'Aucune réponse'
                        }
                    );
                    clearInterval(interval);
                    $statusText.html('<span style="color:red;">❌ Erreur de communication lors du suivi de la restauration.</span>');
                    $button.prop('disabled', false);
                });
            }, 3000);
        }
    });

    // --- GESTIONNAIRE SAUVEGARDE DES RÉGLAGES ---
    $('.bjlg-settings-form').on('submit', function(e) {
        e.preventDefault();
        // ... (Code complet fourni précédemment) ...
    });

    // --- DEMANDE DE TÉLÉCHARGEMENT SÉCURISÉ ---
    $('body').on('click', '.bjlg-download-button', function(e) {
        e.preventDefault();

        const $button = $(this);
        const filename = $button.data('filename');

        if (!filename) {
            return;
        }

        const originalText = $button.data('original-text') || $button.text();
        $button.data('original-text', originalText);

        $button.prop('disabled', true).text('Préparation...');

        $.ajax({
            url: bjlg_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bjlg_prepare_download',
                nonce: bjlg_ajax.nonce,
                filename: filename
            }
        })
        .done(function(response) {
            if (response && response.success && response.data && response.data.download_url) {
                window.location.href = response.data.download_url;
            } else {
                const message = response && response.data && response.data.message
                    ? response.data.message
                    : 'Impossible de préparer le téléchargement.';
                alert(message);
            }
        })
        .fail(function(xhr) {
            let message = 'Une erreur est survenue lors de la préparation du téléchargement.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message += '\n' + xhr.responseJSON.data.message;
            }
            alert(message);
        })
        .always(function() {
            $button.prop('disabled', false).text(originalText);
        });
    });

    // --- GESTIONNAIRE SUPPRESSION DE SAUVEGARDE ---
    $('body').on('click', '.bjlg-delete-button', function(e) {
        e.preventDefault();
        if (!confirm("Êtes-vous sûr de vouloir supprimer définitivement ce fichier de sauvegarde ?")) return;

        const $button = $(this);
        const filename = $button.data('filename');
        const $row = $button.closest('tr');
        $button.prop('disabled', true);
        
        $.ajax({
            url: bjlg_ajax.ajax_url, type: 'POST',
            data: { action: 'bjlg_delete_backup', nonce: bjlg_ajax.nonce, filename: filename }
        })
        .done(function(response) {
            if (response.success) {
                $row.fadeOut(400, function() { $(this).remove(); });
            } else {
                alert('Erreur : ' + response.data.message);
                $button.prop('disabled', false);
            }
        })
        .fail(function() {
            alert('Erreur critique de communication lors de la suppression.');
            $button.prop('disabled', false);
        });
    });

    // --- COPIE RAPIDE POUR LES CHAMPS WEBHOOK ---
    $('body').on('click', '.bjlg-copy-field', function(e) {
        e.preventDefault();

        const $button = $(this);
        const targetSelector = $button.data('copyTarget');
        if (!targetSelector) {
            return;
        }

        const $target = $(targetSelector);
        if (!$target.length || !$target[0]) {
            return;
        }

        const value = typeof $target.val === 'function' ? $target.val() : '';
        if (typeof value !== 'string' || value.length === 0) {
            return;
        }

        const originalText = $button.data('original-text') || $button.text();
        $button.data('original-text', originalText);

        const showFeedback = function(success) {
            $button.text(success ? 'Copié !' : 'Copie impossible');
            setTimeout(() => {
                $button.text(originalText);
            }, 2000);
        };

        const fallbackCopy = function() {
            try {
                $target.trigger('focus');
                if (typeof $target[0].select === 'function') {
                    $target[0].select();
                }
                document.execCommand('copy');
                showFeedback(true);
            } catch (error) {
                showFeedback(false);
            } finally {
                if (typeof $target[0].blur === 'function') {
                    $target[0].blur();
                }
            }
        };

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && typeof window !== 'undefined' && window.isSecureContext) {
            navigator.clipboard.writeText(value)
                .then(() => showFeedback(true))
                .catch(() => fallbackCopy());
        } else {
            fallbackCopy();
        }
    });

    // --- GESTIONNAIRE RÉGÉNÉRATION WEBHOOK ---
    $('#bjlg-regenerate-webhook').on('click', function(e) {
        e.preventDefault();
        if (confirm("Êtes-vous sûr de vouloir régénérer la clé ? L'ancienne URL ne fonctionnera plus.")) {
            $.post(bjlg_ajax.ajax_url, { action: 'bjlg_regenerate_webhook_key', nonce: bjlg_ajax.nonce })
                .done(() => window.location.reload())
                .fail(() => alert("Erreur lors de la régénération de la clé."));
        }
    });

    // --- GESTIONNAIRE DU PACK DE SUPPORT ---
    $('#bjlg-generate-support-package').on('click', function(e) {
        // ... (Code complet fourni précédemment) ...
    });
});