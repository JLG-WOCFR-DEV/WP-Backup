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

    // --- GESTIONNAIRE DE RESTAURATION ---
    $('#bjlg-restore-form').on('submit', function(e) {
        e.preventDefault();

        const $form = $(this);
        const $button = $form.find('button[type="submit"]');
        const $statusWrapper = $('#bjlg-restore-status');
        const $statusText = $('#bjlg-restore-status-text');
        const $progressBar = $('#bjlg-restore-progress-bar');
        const fileInput = $form.find('input[name="restore_file"]')[0];

        if (!fileInput || !fileInput.files || !fileInput.files.length) {
            alert('Veuillez sélectionner un fichier de sauvegarde à restaurer.');
            return;
        }

        const createBackupBeforeRestore = $form.find('input[name="create_backup_before_restore"]').is(':checked');

        $button.prop('disabled', true);
        $statusWrapper.show();
        $statusText.text('Téléversement du fichier...');
        $progressBar.css('width', '0%').text('0%');

        const formData = new FormData();
        formData.append('action', 'bjlg_upload_restore_file');
        formData.append('nonce', bjlg_ajax.nonce);
        formData.append('restore_file', fileInput.files[0]);

        $.ajax({
            url: bjlg_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        })
        .done(function(response) {
            if (response.success && response.data && response.data.filename) {
                $statusText.text('Fichier téléversé. Initialisation de la restauration...');
                runRestoreTask(response.data.filename, createBackupBeforeRestore);
            } else {
                const message = response.data && response.data.message ? response.data.message : 'Réponse invalide lors du téléversement.';
                $statusText.text('Erreur : ' + message);
                $button.prop('disabled', false);
            }
        })
        .fail(function(xhr) {
            const errorMessage = xhr && xhr.status ? ' (' + xhr.status + ')' : '';
            $statusText.text('Erreur lors du téléversement du fichier' + errorMessage + '.');
            $button.prop('disabled', false);
        });

        function runRestoreTask(filename, createBackup) {
            $.ajax({
                url: bjlg_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bjlg_run_restore',
                    nonce: bjlg_ajax.nonce,
                    filename: filename,
                    create_backup_before_restore: createBackup ? '1' : '0'
                }
            })
            .done(function(response) {
                if (response.success && response.data && response.data.task_id) {
                    $statusText.text('Restauration en cours...');
                    pollRestoreProgress(response.data.task_id);
                } else {
                    const message = response.data && response.data.message ? response.data.message : 'Réponse invalide lors du lancement de la restauration.';
                    $statusText.text('Erreur : ' + message);
                    $button.prop('disabled', false);
                }
            })
            .fail(function(xhr) {
                const errorMessage = xhr && xhr.status ? ' (' + xhr.status + ')' : '';
                $statusText.text('Erreur lors du lancement de la restauration' + errorMessage + '.');
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

                        if (typeof data.status_text === 'string') {
                            $statusText.text(data.status_text);
                        }

                        if (typeof data.progress !== 'undefined') {
                            const progressValue = Number.parseFloat(data.progress);
                            const hasNumericProgress = Number.isFinite(progressValue);
                            const progressDisplay = hasNumericProgress ? progressValue.toFixed(1) : data.progress;
                            $progressBar
                                .css('width', progressDisplay + '%')
                                .text(progressDisplay + '%');
                        }

                        if (data.status === 'complete') {
                            clearInterval(interval);
                            $statusText.html('✔️ Restauration terminée ! Rechargement de la page...');
                            $progressBar.css('width', '100%').text('100%');
                            setTimeout(function() { window.location.reload(); }, 2500);
                        } else if (data.status === 'error') {
                            clearInterval(interval);
                            const errorText = data.status_text || 'Une erreur est survenue pendant la restauration.';
                            $statusText.html('<span style="color:red;">❌ ' + errorText + '</span>');
                            $button.prop('disabled', false);
                        }
                    } else {
                        clearInterval(interval);
                        const message = response.data && response.data.message ? response.data.message : 'La progression de la restauration est introuvable.';
                        $statusText.text('Erreur : ' + message);
                        $button.prop('disabled', false);
                    }
                })
                .fail(function() {
                    clearInterval(interval);
                    $statusText.text('Erreur de communication lors du suivi de la restauration.');
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