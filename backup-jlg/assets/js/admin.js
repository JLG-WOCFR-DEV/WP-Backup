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

        function setBackupBusyState(isBusy) {
            const busyValue = isBusy ? 'true' : 'false';
            $statusText.attr('aria-busy', busyValue);
            $progressBar.attr('aria-busy', busyValue);
        }

        function updateBackupProgress(progressValue) {
            if (Number.isFinite(progressValue)) {
                const clamped = Math.max(0, Math.min(100, progressValue));
                const displayValue = Number.isInteger(clamped)
                    ? String(clamped)
                    : clamped.toFixed(1).replace(/\.0$/, '');
                const percentText = displayValue + '%';
                $progressBar
                    .css('width', percentText)
                    .text(percentText)
                    .attr('aria-valuenow', String(clamped))
                    .attr('aria-valuetext', percentText);
                return clamped;
            }

            if (typeof progressValue === 'string' && progressValue.trim() !== '') {
                const textValue = progressValue.trim();
                $progressBar
                    .text(textValue)
                    .removeAttr('aria-valuenow')
                    .attr('aria-valuetext', textValue);
            }

            return null;
        }

        function setBackupStatusText(message) {
            const textValue = typeof message === 'string' ? message.trim() : '';
            $statusText.text(textValue === '' ? '' : textValue);
        }
        
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
        setBackupBusyState(true);
        setBackupStatusText('Initialisation...');
        updateBackupProgress(5);

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
                setBackupBusyState(false);
                setBackupStatusText('❌ Erreur lors du lancement : ' + (response.data.message || 'Réponse invalide.'));
                $button.prop('disabled', false);
            }
        })
        .fail(function(xhr) {
            if ($debugOutput.length) {
                debugReport += "\n\nERREUR CRITIQUE DE COMMUNICATION\nStatut: " + xhr.status + "\nRéponse brute:\n" + xhr.responseText;
                $debugOutput.text(debugReport);
            }
            setBackupBusyState(false);
            setBackupStatusText('❌ Erreur de communication.');
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
                        setBackupStatusText(data.status_text || 'Progression...');
                        setBackupBusyState(true);

                        const progressValue = Number.parseFloat(data.progress);
                        updateBackupProgress(progressValue);

                        if (Number.isFinite(progressValue) && progressValue >= 100) {
                            clearInterval(interval);
                            if (data.status === 'error') {
                                setBackupBusyState(false);
                                setBackupStatusText('❌ Erreur : ' + (data.status_text || 'La sauvegarde a échoué.'));
                                $button.prop('disabled', false);
                            } else {
                                setBackupBusyState(false);
                                updateBackupProgress(100);
                                setBackupStatusText('✔️ Terminé ! La page va se recharger.');
                                setTimeout(() => window.location.reload(), 2000);
                            }
                            return;
                        }
                    } else {
                        clearInterval(interval);
                        setBackupBusyState(false);
                        setBackupStatusText('❌ Erreur : La tâche de sauvegarde a été perdue.');
                        $button.prop('disabled', false);
                    }
                })
                .fail(function() {
                     clearInterval(interval);
                     setBackupBusyState(false);
                     setBackupStatusText('❌ Erreur de communication lors du suivi.');
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

        function setRestoreBusyState(isBusy) {
            const busyValue = isBusy ? 'true' : 'false';
            $statusText.attr('aria-busy', busyValue);
            $progressBar.attr('aria-busy', busyValue);
        }

        function updateRestoreProgress(progressValue) {
            if (Number.isFinite(progressValue)) {
                const clamped = Math.max(0, Math.min(100, progressValue));
                const displayValue = Number.isInteger(clamped)
                    ? String(clamped)
                    : clamped.toFixed(1).replace(/\.0$/, '');
                const percentText = displayValue + '%';
                $progressBar
                    .css('width', percentText)
                    .text(percentText)
                    .attr('aria-valuenow', String(clamped))
                    .attr('aria-valuetext', percentText);
                return clamped;
            }

            if (typeof progressValue === 'string' && progressValue.trim() !== '') {
                const textValue = progressValue.trim();
                $progressBar
                    .text(textValue)
                    .removeAttr('aria-valuenow')
                    .attr('aria-valuetext', textValue);
            }

            return null;
        }

        function setRestoreStatusText(message) {
            const textValue = typeof message === 'string' ? message.trim() : '';
            $statusText.text(textValue === '' ? '' : textValue);
        }

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
        setRestoreBusyState(true);
        setRestoreStatusText('Téléversement du fichier en cours...');
        updateRestoreProgress(0);
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
                setRestoreStatusText('Fichier téléversé. Préparation de la restauration...');
                runRestore(response.data.filename, createRestorePoint);
            } else {
                const payload = response && response.data ? response.data : {};
                const message = payload && payload.message
                    ? payload.message
                    : 'Réponse invalide du serveur.';
                displayRestoreErrors(message, getValidationErrors(payload));
                setRestoreBusyState(false);
                setRestoreStatusText('❌ ' + message);
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
            setRestoreBusyState(false);
            setRestoreStatusText('❌ ' + errorMessage);
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

            setRestoreBusyState(true);
            setRestoreStatusText('Initialisation de la restauration...');
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
                    setRestoreBusyState(false);
                    setRestoreStatusText('❌ ' + message);
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
                setRestoreBusyState(false);
                setRestoreStatusText('❌ ' + errorMessage);
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
                            setRestoreStatusText(data.status_text);
                        }

                        setRestoreBusyState(true);

                        const progressValue = Number.parseFloat(data.progress);
                        updateRestoreProgress(progressValue);

                        if (data.status === 'error') {
                            clearInterval(interval);
                            const message = data.status_text || 'La restauration a échoué.';
                            displayRestoreErrors(message, getValidationErrors(data));
                            setRestoreBusyState(false);
                            setRestoreStatusText('❌ ' + message);
                            $button.prop('disabled', false);
                        } else if (data.status === 'complete' || (Number.isFinite(progressValue) && progressValue >= 100)) {
                            clearInterval(interval);
                            setRestoreBusyState(false);
                            updateRestoreProgress(100);
                            setRestoreStatusText('✔️ Restauration terminée ! La page va se recharger.');
                            setTimeout(() => window.location.reload(), 3000);
                        }
                    } else {
                        clearInterval(interval);
                        const message = response && response.data && response.data.message
                            ? response.data.message
                            : 'Tâche de restauration introuvable.';
                        setRestoreBusyState(false);
                        setRestoreStatusText('❌ ' + message);
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
                    setRestoreBusyState(false);
                    setRestoreStatusText('❌ Erreur de communication lors du suivi de la restauration.');
                    $button.prop('disabled', false);
                });
            }, 3000);
        }
    });

    // --- TEST DE CONNEXION AMAZON S3 ---
    $(document).on('click', '.bjlg-s3-test-connection', function(e) {
        e.preventDefault();

        const $button = $(this);
        const $container = $button.closest('.bjlg-destination--s3');
        if (!$container.length) {
            return;
        }

        const $feedback = $container.find('.bjlg-s3-test-feedback');
        if ($feedback.length) {
            $feedback.removeClass('notice-success notice-error').hide().empty();
        }

        const payload = {
            action: 'bjlg_test_s3_connection',
            nonce: bjlg_ajax.nonce,
            s3_access_key: $container.find('input[name="s3_access_key"]').val() || '',
            s3_secret_key: $container.find('input[name="s3_secret_key"]').val() || '',
            s3_region: $container.find('input[name="s3_region"]').val() || '',
            s3_bucket: $container.find('input[name="s3_bucket"]').val() || '',
            s3_server_side_encryption: $container.find('select[name="s3_server_side_encryption"]').val() || '',
            s3_object_prefix: $container.find('input[name="s3_object_prefix"]').val() || ''
        };

        if (!$button.data('bjlg-original-text')) {
            $button.data('bjlg-original-text', $button.text());
        }

        $button.prop('disabled', true).text('Test en cours...');

        $.post(bjlg_ajax.ajax_url, payload)
            .done(function(response) {
                const message = response && response.data && response.data.message
                    ? response.data.message
                    : 'Connexion Amazon S3 vérifiée avec succès.';
                showFeedback($feedback, 'success', message);
            })
            .fail(function(xhr) {
                let message = 'Impossible de tester la connexion Amazon S3.';
                if (xhr && xhr.responseJSON) {
                    if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    } else if (xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                } else if (xhr && xhr.responseText) {
                    message = xhr.responseText;
                }

                showFeedback($feedback, 'error', message);
            })
            .always(function() {
                const original = $button.data('bjlg-original-text') || 'Tester la connexion';
                $button.prop('disabled', false).text(original);
            });
    });

    // --- GESTIONNAIRE SAUVEGARDE DES RÉGLAGES ---
    $('.bjlg-settings-form').on('submit', function(e) {
        e.preventDefault();

        const $form = $(this);
        const $submit = $form.find('button[type="submit"], input[type="submit"]').first();
        const $feedback = ensureFeedbackElement($form);

        if ($feedback.length) {
            $feedback.removeClass('notice-success notice-error').hide().empty();
        }

        const payload = collectFormData($form);
        payload.action = 'bjlg_save_settings';
        payload.nonce = bjlg_ajax.nonce;

        const submitState = rememberSubmitState($submit);
        setSubmitLoadingState($submit);

        $.post(bjlg_ajax.ajax_url, payload)
            .done(function(response) {
                const normalized = normalizeSettingsResponse(response);
                if (normalized.success) {
                    showFeedback($feedback, 'success', normalized.message || 'Réglages sauvegardés avec succès !');
                } else {
                    const message = normalized.message || 'Une erreur est survenue lors de la sauvegarde des réglages.';
                    showFeedback($feedback, 'error', message);
                }
            })
            .fail(function(xhr) {
                let message = 'Impossible de sauvegarder les réglages.';

                if (xhr && xhr.responseJSON) {
                    if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    } else if (xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                }

                showFeedback($feedback, 'error', message);
            })
            .always(function() {
                restoreSubmitState($submit, submitState);
            });
    });

    function collectFormData($form) {
        const data = {};

        $.each($form.serializeArray(), function(_, field) {
            if (Object.prototype.hasOwnProperty.call(data, field.name)) {
                if (!Array.isArray(data[field.name])) {
                    data[field.name] = [data[field.name]];
                }
                data[field.name].push(field.value);
            } else {
                data[field.name] = field.value;
            }
        });

        return data;
    }

    function ensureFeedbackElement($form) {
        let $feedback = $form.find('.bjlg-settings-feedback');

        if (!$feedback.length) {
            $feedback = $('<div class="bjlg-settings-feedback notice" role="status" aria-live="polite" style="display:none;"></div>');
            $form.prepend($feedback);
        }

        return $feedback;
    }

    function showFeedback($feedback, type, message) {
        if (!$feedback || !$feedback.length) {
            return;
        }

        const isSuccess = type === 'success';

        $feedback
            .removeClass('notice-success notice-error')
            .addClass(isSuccess ? 'notice-success' : 'notice-error')
            .text(message)
            .show();
    }

    function normalizeSettingsResponse(response) {
        if (typeof response === 'string' && response !== '') {
            try {
                response = JSON.parse(response);
            } catch (error) {
                return { success: false, message: response };
            }
        }

        if (response && typeof response === 'object') {
            if (typeof response.success !== 'undefined') {
                const data = response.data || {};
                return {
                    success: response.success === true,
                    message: data.message || response.message || ''
                };
            }

            if (response.message || response.saved) {
                return {
                    success: true,
                    message: response.message || ''
                };
            }
        }

        return { success: false, message: '' };
    }

    function rememberSubmitState($submit) {
        if (!$submit || !$submit.length) {
            return null;
        }

        if ($submit.data('bjlg-original-state')) {
            return $submit.data('bjlg-original-state');
        }

        const isButton = $submit.is('button');
        const state = {
            isButton: isButton,
            content: isButton ? $submit.html() : $submit.val()
        };

        $submit.data('bjlg-original-state', state);

        return state;
    }

    function setSubmitLoadingState($submit) {
        if (!$submit || !$submit.length) {
            return;
        }

        const state = $submit.data('bjlg-original-state');

        $submit.prop('disabled', true).addClass('is-busy');

        const loadingText = $submit.attr('data-loading-label') || $submit.data('bjlg-loading-label') || 'Enregistrement...';

        if (state) {
            if (state.isButton) {
                $submit.html(loadingText);
            } else {
                $submit.val(loadingText);
            }
        }
    }

    function restoreSubmitState($submit, state) {
        if (!$submit || !$submit.length) {
            return;
        }

        if (state) {
            if (state.isButton) {
                $submit.html(state.content);
            } else {
                $submit.val(state.content);
            }
        }

        $submit.prop('disabled', false).removeClass('is-busy');
    }

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
        e.preventDefault();

        const $button = $(this);
        const $statusArea = $('#bjlg-support-package-status');

        $button.prop('disabled', true).attr('aria-busy', 'true');

        if ($statusArea.length) {
            $statusArea.removeClass('bjlg-status-error bjlg-status-success');
            $statusArea.text('Génération du pack de support en cours...');
        }

        $.ajax({
            url: bjlg_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'bjlg_generate_support_package',
                nonce: bjlg_ajax.nonce
            }
        })
        .done(function(response) {
            const data = response && response.data ? response.data : null;

            if (response && response.success && data && data.download_url) {
                const details = [];

                if (data.filename) {
                    details.push('Fichier : ' + data.filename);
                }

                if (data.size) {
                    details.push('Taille : ' + data.size);
                }

                if ($statusArea.length) {
                    const message = data.message || 'Pack de support généré avec succès.';
                    const suffix = details.length ? ' (' + details.join(' • ') + ')' : '';
                    $statusArea.text(message + suffix);
                }

                const link = document.createElement('a');
                link.href = data.download_url;

                if (data.filename) {
                    link.download = data.filename;
                }

                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                const errorMessage = data && data.message
                    ? data.message
                    : 'Réponse inattendue du serveur.';

                if ($statusArea.length) {
                    $statusArea.text('Erreur : ' + errorMessage);
                }
            }
        })
        .fail(function(xhr) {
            let errorMessage = 'Erreur de communication avec le serveur.';

            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage = xhr.responseJSON.data.message;
            } else if (xhr && xhr.responseText) {
                errorMessage = xhr.responseText;
            }

            if ($statusArea.length) {
                $statusArea.text('Erreur : ' + errorMessage);
            }
        })
        .always(function() {
            $button.prop('disabled', false).removeAttr('aria-busy');
        });
    });

    // --- GESTION DES CLÉS API ---
    const $apiSection = $('#bjlg-api-keys-section');

    if ($apiSection.length && typeof bjlg_ajax !== 'undefined') {
        const nonceKey = typeof bjlg_ajax.api_keys_nonce !== 'undefined'
            ? 'api_keys_nonce'
            : 'nonce';
        const $feedback = $apiSection.find('#bjlg-api-keys-feedback');
        const $table = $apiSection.find('#bjlg-api-keys-table');
        const $tbody = $table.find('tbody');
        const $emptyState = $apiSection.find('.bjlg-api-keys-empty');
        const $form = $apiSection.find('#bjlg-create-api-key');

        function getNonce() {
            if (typeof bjlg_ajax[nonceKey] === 'string' && bjlg_ajax[nonceKey].length) {
                return bjlg_ajax[nonceKey];
            }

            return bjlg_ajax.nonce;
        }

        function setNonce(newNonce) {
            if (typeof newNonce === 'string' && newNonce.length) {
                bjlg_ajax[nonceKey] = newNonce;
            }
        }

        function clearFeedback() {
            if (!$feedback.length) {
                return;
            }

            $feedback
                .removeClass('notice-success notice-error notice-info')
                .hide()
                .empty()
                .removeAttr('role');
        }

        function renderFeedback(type, message, details) {
            if (!$feedback.length) {
                return;
            }

            clearFeedback();

            const classes = ['notice'];

            if (type === 'success') {
                classes.push('notice-success');
            } else if (type === 'error') {
                classes.push('notice-error');
            } else {
                classes.push('notice-info');
            }

            $feedback.attr('class', classes.join(' '));

            if (typeof message === 'string' && message.trim() !== '') {
                $('<p/>').text(message).appendTo($feedback);
            }

            if (Array.isArray(details) && details.length) {
                const $list = $('<ul/>');
                details.forEach(function(item) {
                    if (typeof item === 'string' && item.trim() !== '') {
                        $('<li/>').text(item).appendTo($list);
                    }
                });

                if ($list.children().length) {
                    $feedback.append($list);
                }
            }

            $feedback.attr('role', 'alert').show();
        }

        function toggleEmptyState() {
            const hasRows = $tbody.children('tr').length > 0;

            if (hasRows) {
                $table.show().attr('aria-hidden', 'false');
                $emptyState.hide().attr('aria-hidden', 'true');
            } else {
                $table.hide().attr('aria-hidden', 'true');
                $emptyState.show().attr('aria-hidden', 'false');
            }
        }

        function buildKeyRow(key) {
            const id = key && key.id ? key.id : '';
            const label = key && typeof key.label === 'string' && key.label.trim() !== ''
                ? key.label
                : 'Sans nom';
            const secret = key && key.secret ? key.secret : '';
            const createdAt = key && typeof key.created_at !== 'undefined' ? key.created_at : '';
            const createdHuman = key && key.created_at_human ? key.created_at_human : '';
            const createdIso = key && key.created_at_iso ? key.created_at_iso : '';
            const rotatedAt = key && typeof key.last_rotated_at !== 'undefined'
                ? key.last_rotated_at
                : createdAt;
            const rotatedHuman = key && key.last_rotated_at_human ? key.last_rotated_at_human : createdHuman;
            const rotatedIso = key && key.last_rotated_at_iso ? key.last_rotated_at_iso : createdIso;

            const $row = $('<tr/>', {
                'data-key-id': id,
                'data-created-at': createdAt,
                'data-last-rotated-at': rotatedAt
            });

            $('<td/>').append(
                $('<strong/>', {
                    'class': 'bjlg-api-key-label',
                    text: label
                })
            ).appendTo($row);

            $('<td/>').append(
                $('<code/>', {
                    'class': 'bjlg-api-key-value',
                    'aria-label': 'Clé API',
                    text: secret
                })
            ).appendTo($row);

            $('<td/>').append(
                $('<time/>', {
                    'class': 'bjlg-api-key-created',
                    datetime: createdIso,
                    text: createdHuman
                })
            ).appendTo($row);

            $('<td/>').append(
                $('<time/>', {
                    'class': 'bjlg-api-key-rotated',
                    datetime: rotatedIso,
                    text: rotatedHuman
                })
            ).appendTo($row);

            const $actionsCell = $('<td/>').appendTo($row);
            const $actionsWrapper = $('<div/>', {
                'class': 'bjlg-api-key-actions'
            }).appendTo($actionsCell);

            const $rotateButton = $('<button/>', {
                type: 'button',
                'class': 'button bjlg-rotate-api-key',
                'data-key-id': id
            });

            $('<span/>', {
                'class': 'dashicons dashicons-update',
                'aria-hidden': 'true'
            }).appendTo($rotateButton);
            $rotateButton.append(' Régénérer');
            $actionsWrapper.append($rotateButton);

            const $revokeButton = $('<button/>', {
                type: 'button',
                'class': 'button button-link-delete bjlg-revoke-api-key',
                'data-key-id': id
            });

            $('<span/>', {
                'class': 'dashicons dashicons-no',
                'aria-hidden': 'true'
            }).appendTo($revokeButton);
            $revokeButton.append(' Révoquer');
            $actionsWrapper.append($revokeButton);

            return $row;
        }

        function upsertKeyRow(key) {
            if (!key || !key.id) {
                return;
            }

            const $row = buildKeyRow(key);
            const $existing = $tbody.find('tr[data-key-id="' + key.id + '"]');

            if ($existing.length) {
                $existing.replaceWith($row);
            } else {
                $tbody.prepend($row);
            }

            toggleEmptyState();
        }

        function removeKeyRow(keyId) {
            if (typeof keyId !== 'string' || keyId === '') {
                return;
            }

            $tbody.find('tr[data-key-id="' + keyId + '"]').remove();
            toggleEmptyState();
        }

        function extractErrorMessages(payload) {
            const messages = [];

            if (!payload) {
                return messages;
            }

            if (typeof payload === 'string') {
                messages.push(payload);
                return messages;
            }

            if (payload.message && typeof payload.message === 'string') {
                messages.push(payload.message);
            }

            if (Array.isArray(payload.errors)) {
                payload.errors.forEach(function(item) {
                    if (typeof item === 'string' && item.trim() !== '') {
                        messages.push(item.trim());
                    }
                });
            }

            return messages;
        }

        function handleAjaxError(jqXHR) {
            let message = 'Erreur de communication avec le serveur.';
            let details = [];

            if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data) {
                const payload = jqXHR.responseJSON.data;
                details = extractErrorMessages(payload);

                if (payload.message && typeof payload.message === 'string') {
                    message = payload.message;
                } else if (details.length) {
                    message = details.shift();
                }
            }

            renderFeedback('error', message, details);
        }

        toggleEmptyState();

        $form.on('submit', function(event) {
            event.preventDefault();

            clearFeedback();

            const $submitButton = $form.find('button[type="submit"]').first();
            $submitButton.prop('disabled', true).attr('aria-busy', 'true');

            const payload = {
                action: 'bjlg_create_api_key',
                nonce: getNonce(),
                label: ($form.find('input[name="label"]').val() || '').toString()
            };

            $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: payload
            })
            .done(function(response) {
                if (response && response.success && response.data) {
                    if (response.data.key) {
                        upsertKeyRow(response.data.key);
                    }

                    if ($form.length && $form[0]) {
                        $form[0].reset();
                    }

                    if (response.data.nonce) {
                        setNonce(response.data.nonce);
                    }

                    renderFeedback('success', response.data.message || 'Clé API créée.');
                } else if (response && response.data) {
                    const messages = extractErrorMessages(response.data);
                    const message = response.data.message || (messages.length ? messages[0] : 'Échec de la création de la clé API.');
                    renderFeedback('error', message, messages);
                } else {
                    renderFeedback('error', 'Échec de la création de la clé API.');
                }
            })
            .fail(handleAjaxError)
            .always(function() {
                $submitButton.prop('disabled', false).removeAttr('aria-busy');
            });
        });

        $tbody.on('click', '.bjlg-revoke-api-key', function(event) {
            event.preventDefault();

            clearFeedback();

            const $button = $(this);
            const keyId = ($button.data('key-id') || '').toString();

            if (!keyId) {
                renderFeedback('error', 'Identifiant de clé introuvable.');
                return;
            }

            const confirmation = window.confirm('Êtes-vous sûr de vouloir révoquer cette clé ?');

            if (!confirmation) {
                return;
            }

            $button.prop('disabled', true).attr('aria-busy', 'true');

            $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'bjlg_revoke_api_key',
                    nonce: getNonce(),
                    key_id: keyId
                }
            })
            .done(function(response) {
                if (response && response.success && response.data) {
                    removeKeyRow(keyId);

                    if (response.data.nonce) {
                        setNonce(response.data.nonce);
                    }

                    renderFeedback('success', response.data.message || 'Clé API révoquée.');
                } else if (response && response.data) {
                    const messages = extractErrorMessages(response.data);
                    const message = response.data.message || (messages.length ? messages[0] : 'Échec de la révocation.');
                    renderFeedback('error', message, messages);
                } else {
                    renderFeedback('error', 'Échec de la révocation.');
                }
            })
            .fail(handleAjaxError)
            .always(function() {
                $button.prop('disabled', false).removeAttr('aria-busy');
            });
        });

        $tbody.on('click', '.bjlg-rotate-api-key', function(event) {
            event.preventDefault();

            clearFeedback();

            const $button = $(this);
            const keyId = ($button.data('key-id') || '').toString();

            if (!keyId) {
                renderFeedback('error', 'Identifiant de clé introuvable.');
                return;
            }

            $button.prop('disabled', true).attr('aria-busy', 'true');

            $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'bjlg_rotate_api_key',
                    nonce: getNonce(),
                    key_id: keyId
                }
            })
            .done(function(response) {
                if (response && response.success && response.data) {
                    if (response.data.key) {
                        upsertKeyRow(response.data.key);
                    }

                    if (response.data.nonce) {
                        setNonce(response.data.nonce);
                    }

                    renderFeedback('success', response.data.message || 'Clé API régénérée.');
                } else if (response && response.data) {
                    const messages = extractErrorMessages(response.data);
                    const message = response.data.message || (messages.length ? messages[0] : 'Échec de la rotation de la clé.');
                    renderFeedback('error', message, messages);
                } else {
                    renderFeedback('error', 'Échec de la rotation de la clé.');
                }
            })
            .fail(handleAjaxError)
            .always(function() {
                $button.prop('disabled', false).removeAttr('aria-busy');
            });
        });
    }
});
