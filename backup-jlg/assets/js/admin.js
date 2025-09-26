jQuery(document).ready(function($) {

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
        const fileInput = document.getElementById('bjlg-restore-file-input');
        const passwordInput = document.getElementById('bjlg-restore-password');
        const $errorNotice = $('#bjlg-restore-errors');
        const errorFieldClass = 'bjlg-input-error';

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

        const formData = new FormData();
        formData.append('action', 'bjlg_upload_restore_file');
        formData.append('nonce', bjlg_ajax.nonce);
        formData.append('restore_file', fileInput.files[0]);

        $.ajax({
            url: bjlg_ajax.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false
        })
        .done(function(response) {
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

            $.ajax({
                url: bjlg_ajax.ajax_url,
                type: 'POST',
                data: requestData
            })
            .done(function(response) {
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
                .fail(function() {
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