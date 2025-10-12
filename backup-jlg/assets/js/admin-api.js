jQuery(function($) {
    'use strict';

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
            const displaySecret = key && typeof key.display_secret === 'string'
                ? key.display_secret
                : '';
            const maskedSecret = key && typeof key.masked_secret === 'string' && key.masked_secret.trim() !== ''
                ? key.masked_secret
                : 'Clé masquée';
            const isSecretHidden = displaySecret === '' || !!(key && (key.is_secret_hidden || key.secret_hidden));
            const secretValue = displaySecret !== '' ? displaySecret : maskedSecret;
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
                'data-last-rotated-at': rotatedAt,
                'data-secret-hidden': isSecretHidden ? '1' : '0'
            });

            $('<td/>').append(
                $('<strong/>', {
                    'class': 'bjlg-api-key-label',
                    text: label
                })
            ).appendTo($row);

            const $secretCell = $('<td/>').appendTo($row);
            const secretClasses = ['bjlg-api-key-value'];

            if (isSecretHidden) {
                secretClasses.push('bjlg-api-key-value--hidden');
            }

            $('<code/>', {
                'class': secretClasses.join(' '),
                'aria-label': isSecretHidden ? 'Clé API masquée' : 'Clé API',
                text: secretValue
            }).appendTo($secretCell);

            if (isSecretHidden) {
                $('<span/>', {
                    'class': 'bjlg-api-key-hidden-note',
                    text: 'Secret masqué. Régénérez la clé pour obtenir un nouveau secret.'
                }).appendTo($secretCell);
            }

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
