jQuery(function($) {
    'use strict';

// --- TEST DE CONNEXION GOOGLE DRIVE ---
$(document).on('click', '.bjlg-gdrive-test-connection', function(e) {
    e.preventDefault();

    const $button = $(this);
    const $container = $button.closest('.bjlg-destination--gdrive');
    if (!$container.length) {
        return;
    }

    const $feedback = $container.find('.bjlg-gdrive-test-feedback');
    const $spinner = $container.find('.bjlg-gdrive-test-spinner');
    const $lastTest = $container.find('.bjlg-gdrive-last-test');

    if ($feedback.length) {
        $feedback.removeClass('notice-success notice-error').hide().addClass('bjlg-hidden').empty();
    }

    const payload = {
        action: 'bjlg_test_gdrive_connection',
        nonce: bjlg_ajax.nonce,
        gdrive_client_id: $container.find('input[name="gdrive_client_id"]').val() || '',
        gdrive_client_secret: $container.find('input[name="gdrive_client_secret"]').val() || '',
        gdrive_folder_id: $container.find('input[name="gdrive_folder_id"]').val() || ''
    };

    if (!$button.data('bjlg-original-text')) {
        $button.data('bjlg-original-text', $button.text());
    }

    $button.prop('disabled', true).text('Test en cours...');

    if ($spinner.length) {
        $spinner.addClass('is-active').show();
    }

    function updateLastTest(status, data, fallbackMessage) {
        if (!$lastTest.length) {
            return;
        }

        const testedAtFormatted = data && data.tested_at_formatted ? String(data.tested_at_formatted) : '';
        const testedAtRaw = data && typeof data.tested_at !== 'undefined' ? Number(data.tested_at) : null;
        const statusMessage = data && data.status_message ? String(data.status_message) : (fallbackMessage ? String(fallbackMessage) : '');
        const parts = [];

        if (testedAtFormatted) {
            if (status === 'success') {
                parts.push('Dernier test réussi le ' + testedAtFormatted + '.');
            } else {
                parts.push('Dernier test échoué le ' + testedAtFormatted + '.');
            }
        } else if (status === 'success') {
            parts.push('Dernier test réussi.');
        } else {
            parts.push('Dernier test : échec.');
        }

        if (statusMessage) {
            parts.push(statusMessage);
        }

        const iconClass = status === 'success' ? 'dashicons dashicons-yes' : 'dashicons dashicons-warning';

        $lastTest
            .css('display', '')
            .css('color', status === 'success' ? '' : '#b32d2e')
            .empty()
            .append($('<span/>', { class: iconClass, 'aria-hidden': 'true' }))
            .append(document.createTextNode(' ' + parts.join(' ')))
            .show();

        if (testedAtRaw && Number.isFinite(testedAtRaw)) {
            $lastTest.attr('data-bjlg-tested-at', testedAtRaw);
            $container.attr('data-bjlg-tested-at', testedAtRaw);
        } else {
            $lastTest.removeAttr('data-bjlg-tested-at');
            $container.removeAttr('data-bjlg-tested-at');
        }
    }

    $.post(bjlg_ajax.ajax_url, payload)
        .done(function(response) {
            const data = response && response.data ? response.data : {};
            const message = data.message ? String(data.message) : 'Connexion Google Drive vérifiée avec succès.';

            showFeedback($feedback, 'success', message);
            updateLastTest('success', data, message);
        })
        .fail(function(xhr) {
            let message = 'Impossible de tester la connexion Google Drive.';
            let data = null;

            if (xhr && xhr.responseJSON) {
                if (xhr.responseJSON.data) {
                    data = xhr.responseJSON.data;
                    if (data.message) {
                        message = String(data.message);
                    }
                } else if (xhr.responseJSON.message) {
                    message = String(xhr.responseJSON.message);
                }
            } else if (xhr && xhr.responseText) {
                message = xhr.responseText;
            }

            showFeedback($feedback, 'error', message);
            updateLastTest('error', data, message);
        })
        .always(function() {
            const original = $button.data('bjlg-original-text') || 'Tester la connexion';
            $button.prop('disabled', false).text(original);

            if ($spinner.length) {
                $spinner.removeClass('is-active').hide();
            }
        });
});

function updateLastTestUI($lastTest, status, data, fallbackMessage, $container) {
    if (!$lastTest.length) {
        return;
    }

    const testedAtFormatted = data && data.tested_at_formatted ? String(data.tested_at_formatted) : '';
    const testedAtRaw = data && typeof data.tested_at !== 'undefined' ? Number(data.tested_at) : null;
    const statusMessage = data && data.status_message ? String(data.status_message) : (fallbackMessage ? String(fallbackMessage) : '');
    const parts = [];

    if (testedAtFormatted) {
        if (status === 'success') {
            parts.push('Dernier test réussi le ' + testedAtFormatted + '.');
        } else {
            parts.push('Dernier test échoué le ' + testedAtFormatted + '.');
        }
    } else if (status === 'success') {
        parts.push('Dernier test réussi.');
    } else {
        parts.push('Dernier test : échec.');
    }

    if (statusMessage) {
        parts.push(statusMessage);
    }

    const iconClass = status === 'success' ? 'dashicons dashicons-yes' : 'dashicons dashicons-warning';

    $lastTest
        .css('display', '')
        .css('color', status === 'success' ? '' : '#b32d2e')
        .removeClass('bjlg-hidden')
        .empty()
        .append($('<span/>', { class: iconClass, 'aria-hidden': 'true' }))
        .append(document.createTextNode(' ' + parts.join(' ')))
        .show();

    if ($container && $container.length) {
        if (testedAtRaw && Number.isFinite(testedAtRaw)) {
            $lastTest.attr('data-bjlg-tested-at', testedAtRaw);
            $container.attr('data-bjlg-tested-at', testedAtRaw);
        } else {
            $lastTest.removeAttr('data-bjlg-tested-at');
            $container.removeAttr('data-bjlg-tested-at');
        }
    }
}

function registerSimpleDestinationTest(config) {
    $(document).on('click', config.buttonSelector, function(e) {
        e.preventDefault();

        const $button = $(this);
        const $container = $button.closest(config.containerSelector);
        if (!$container.length) {
            return;
        }

        const $feedback = $container.find(config.feedbackSelector);
        const $spinner = config.spinnerSelector ? $container.find(config.spinnerSelector) : $();
        const $lastTest = config.lastTestSelector ? $container.find(config.lastTestSelector) : $();

        if ($feedback.length) {
            $feedback.removeClass('notice-success notice-error').hide().addClass('bjlg-hidden').empty();
        }

        const payload = {
            action: config.action,
            nonce: bjlg_ajax.nonce
        };

        if (Array.isArray(config.fields)) {
            config.fields.forEach(function(field) {
                if (!field || !field.name) {
                    return;
                }

                const selector = field.selector || 'input[name="' + field.name + '"]';
                payload[field.name] = $container.find(selector).val() || '';
            });
        }

        if (!$button.data('bjlg-original-text')) {
            $button.data('bjlg-original-text', $button.text());
        }

        $button.prop('disabled', true).text('Test en cours...');

        if ($spinner.length) {
            $spinner.addClass('is-active').show();
        }

        $.post(bjlg_ajax.ajax_url, payload)
            .done(function(response) {
                const data = response && response.data ? response.data : {};
                const message = data.message ? String(data.message) : config.defaultSuccessMessage;

                showFeedback($feedback, 'success', message || config.defaultSuccessMessage);
                updateLastTestUI($lastTest, 'success', data, message || config.defaultSuccessMessage, $container);
            })
            .fail(function(xhr) {
                let message = config.defaultErrorMessage || 'Impossible de tester la connexion.';
                let data = null;

                if (xhr && xhr.responseJSON) {
                    if (xhr.responseJSON.data) {
                        data = xhr.responseJSON.data;
                        if (data.message) {
                            message = String(data.message);
                        }
                    } else if (xhr.responseJSON.message) {
                        message = String(xhr.responseJSON.message);
                    }
                } else if (xhr && xhr.responseText) {
                    message = xhr.responseText;
                }

                showFeedback($feedback, 'error', message);
                updateLastTestUI($lastTest, 'error', data || {}, message, $container);
            })
            .always(function() {
                const original = $button.data('bjlg-original-text') || 'Tester la connexion';
                $button.prop('disabled', false).text(original);

                if ($spinner.length) {
                    $spinner.removeClass('is-active').hide();
                }
            });
    });
}

registerSimpleDestinationTest({
    buttonSelector: '.bjlg-dropbox-test-connection',
    containerSelector: '.bjlg-destination--dropbox',
    feedbackSelector: '.bjlg-dropbox-test-feedback',
    spinnerSelector: '.bjlg-dropbox-test-spinner',
    lastTestSelector: '.bjlg-dropbox-last-test',
    action: 'bjlg_test_dropbox_connection',
    fields: [
        { name: 'dropbox_access_token', selector: 'input[name="dropbox_access_token"]' },
        { name: 'dropbox_folder', selector: 'input[name="dropbox_folder"]' }
    ],
    defaultSuccessMessage: 'Connexion Dropbox vérifiée avec succès.',
    defaultErrorMessage: 'Impossible de tester la connexion Dropbox.'
});

registerSimpleDestinationTest({
    buttonSelector: '.bjlg-onedrive-test-connection',
    containerSelector: '.bjlg-destination--onedrive',
    feedbackSelector: '.bjlg-onedrive-test-feedback',
    spinnerSelector: '.bjlg-onedrive-test-spinner',
    lastTestSelector: '.bjlg-onedrive-last-test',
    action: 'bjlg_test_onedrive_connection',
    fields: [
        { name: 'onedrive_access_token', selector: 'input[name="onedrive_access_token"]' },
        { name: 'onedrive_folder', selector: 'input[name="onedrive_folder"]' }
    ],
    defaultSuccessMessage: 'Connexion OneDrive vérifiée avec succès.',
    defaultErrorMessage: 'Impossible de tester la connexion OneDrive.'
});

registerSimpleDestinationTest({
    buttonSelector: '.bjlg-pcloud-test-connection',
    containerSelector: '.bjlg-destination--pcloud',
    feedbackSelector: '.bjlg-pcloud-test-feedback',
    spinnerSelector: '.bjlg-pcloud-test-spinner',
    lastTestSelector: '.bjlg-pcloud-last-test',
    action: 'bjlg_test_pcloud_connection',
    fields: [
        { name: 'pcloud_access_token', selector: 'input[name="pcloud_access_token"]' },
        { name: 'pcloud_folder', selector: 'input[name="pcloud_folder"]' }
    ],
    defaultSuccessMessage: 'Connexion pCloud vérifiée avec succès.',
    defaultErrorMessage: 'Impossible de tester la connexion pCloud.'
});

$('.bjlg-notification-test-button').on('click', function(e) {
    e.preventDefault();

    const $button = $(this);
    const $container = $button.closest('.bjlg-notification-test');
    const $spinner = $container.find('.spinner');
    const $feedback = $container.find('.bjlg-notification-test-feedback');

    if ($feedback.length) {
        $feedback.removeClass('notice-success notice-error').addClass('bjlg-hidden').empty();
    }

    const payload = {
        action: 'bjlg_send_notification_test',
        nonce: bjlg_ajax.nonce
    };

    const $preferencesForm = $('.bjlg-notification-preferences-form');
    if ($preferencesForm.length) {
        Object.assign(payload, collectFormData($preferencesForm));
    }

    const $channelsForm = $('.bjlg-notification-channels-form');
    if ($channelsForm.length) {
        Object.assign(payload, collectFormData($channelsForm));
    }

    const originalText = $button.text();
    const loadingLabel = $button.attr('data-loading-label') || 'Envoi…';

    $button.prop('disabled', true).data('bjlg-original-text', originalText).text(loadingLabel);

    if ($spinner.length) {
        $spinner.addClass('is-active').show();
    }

    const displayFeedback = function(type, message) {
        if (!$feedback.length) {
            return;
        }

        showFeedback($feedback, type, message);
    };

    $.post(bjlg_ajax.ajax_url, payload)
        .done(function(response) {
            const normalized = normalizeSettingsResponse(response);
            if (normalized.success) {
                const message = normalized.message || 'Notification de test planifiée.';
                displayFeedback('success', message);
            } else {
                const message = normalized.message || 'Impossible d\'envoyer la notification de test.';
                displayFeedback('error', message);
            }
        })
        .fail(function(xhr) {
            let message = 'Impossible d\'envoyer la notification de test.';

            if (xhr && xhr.responseJSON) {
                if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                } else if (xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
            }

            displayFeedback('error', message);
        })
        .always(function() {
            $button.prop('disabled', false);
            if (typeof originalText === 'string') {
                $button.text(originalText);
            }

            if ($spinner.length) {
                $spinner.removeClass('is-active').hide();
            }
        });
});

// --- GESTIONNAIRE SAUVEGARDE DES RÉGLAGES ---
$('.bjlg-settings-form').on('submit', function(e) {
    e.preventDefault();

    const $form = $(this);
    const $submit = $form.find('button[type="submit"], input[type="submit"]').first();
    const $feedback = ensureFeedbackElement($form);

    if ($feedback.length) {
        $feedback.removeClass('notice-success notice-error').hide().addClass('bjlg-hidden').empty();
    }

    const payload = collectFormData($form);
    payload.action = 'bjlg_save_settings';
    payload.nonce = bjlg_ajax.nonce;

    const submitState = rememberSubmitState($submit);
    setSubmitLoadingState($submit);

    $.post(bjlg_ajax.ajax_url, payload)
        .done(function(response) {
            const normalized = normalizeSettingsResponse(response);
            const successMessage = $form.data('successMessage');
            const errorMessage = $form.data('errorMessage');

            if (normalized.success) {
                const message = successMessage || normalized.message || 'Réglages sauvegardés avec succès !';
                showFeedback($feedback, 'success', message);
            } else {
                const message = normalized.message || errorMessage || 'Une erreur est survenue lors de la sauvegarde des réglages.';
                showFeedback($feedback, 'error', message);
            }
        })
        .fail(function(xhr) {
            const errorMessage = $form.data('errorMessage');
            let message = errorMessage || 'Impossible de sauvegarder les réglages.';

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

    $form.find('input[type="checkbox"]').each(function() {
        const name = this.name;

        if (!name || this.disabled || name.endsWith('[]')) {
            return;
        }

        if (!Object.prototype.hasOwnProperty.call(data, name)) {
            data[name] = '0';
        }
    });

    return data;
}

function ensureFeedbackElement($form) {
    let $feedback = $form.find('.bjlg-settings-feedback');

    if (!$feedback.length) {
        $feedback = $('<div class="bjlg-settings-feedback notice bjlg-hidden" role="status" aria-live="polite"></div>');
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
        .removeClass('notice-success notice-error bjlg-hidden')
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

// --- AFFICHER / MASQUER LES SECRETS ---
$('body').on('click', '.bjlg-toggle-secret', function(e) {
    e.preventDefault();

    const $button = $(this);
    const targetSelector = $button.data('target');
    if (!targetSelector) {
        return;
    }

    const $input = $(targetSelector);
    if (!$input.length) {
        return;
    }

    const currentType = ($input.attr('type') || '').toLowerCase();
    const isHidden = currentType !== 'text';
    $input.attr('type', isHidden ? 'text' : 'password');

    const showLabel = $button.data('labelShow');
    const hideLabel = $button.data('labelHide');
    const nextLabel = isHidden ? hideLabel : showLabel;
    if (typeof nextLabel === 'string' && nextLabel.length) {
        $button.attr('aria-label', nextLabel);
        const $srOnly = $button.find('.screen-reader-text');
        if ($srOnly.length) {
            $srOnly.text(nextLabel);
        }
    }

    const $icon = $button.find('.dashicons');
    if ($icon.length) {
        if (isHidden) {
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    }

    $button.attr('aria-pressed', isHidden ? 'true' : 'false');
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
});
