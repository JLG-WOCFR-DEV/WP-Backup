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
            $recurrenceSelect.on('change', function() {
                toggleScheduleOptions();
                updateScheduleSummary();
            });
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

        const badgeStyles = {
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            borderRadius: '4px',
            padding: '2px 6px',
            fontSize: '0.8em',
            fontWeight: '600',
            color: '#ffffff',
            marginRight: '4px',
            marginTop: '2px'
        };

        const groupStyles = {
            display: 'flex',
            flexWrap: 'wrap',
            alignItems: 'center',
            gap: '4px',
            marginBottom: '6px'
        };

        const recurrenceLabels = {
            disabled: 'Désactivée',
            hourly: 'Toutes les heures',
            twice_daily: 'Deux fois par jour',
            daily: 'Journalière',
            weekly: 'Hebdomadaire',
            monthly: 'Mensuelle'
        };

        const componentLabels = {
            db: { label: 'Base de données', color: '#6366f1' },
            plugins: { label: 'Extensions', color: '#f59e0b' },
            themes: { label: 'Thèmes', color: '#10b981' },
            uploads: { label: 'Médias', color: '#3b82f6' }
        };

        const $summary = $('#bjlg-schedule-summary');
        const $overview = $('#bjlg-schedule-overview');
        const $overviewContent = $('#bjlg-schedule-overview-content');
        const $overviewFrequency = $('#bjlg-schedule-overview-frequency');

        function createScheduleBadgeElement(badge) {
            const classes = Array.isArray(badge.classes) ? badge.classes.slice() : [];
            classes.unshift('bjlg-badge');

            const $badge = $('<span/>', {
                class: classes.join(' '),
                text: badge.label
            });

            const styles = $.extend({}, badgeStyles, {
                backgroundColor: badge.color || '#4b5563'
            });

            $badge.css(styles);

            return $badge;
        }

        function renderScheduleBadgeGroups($target, groups) {
            if (!$target || !$target.length) {
                return;
            }

            const fragment = $(document.createDocumentFragment());

            groups.forEach(function(group) {
                const $group = $('<div/>', { class: 'bjlg-badge-group' }).css(groupStyles);
                $('<strong/>', { text: group.title + ' :' }).css({ marginRight: '4px' }).appendTo($group);

                group.badges.forEach(function(badge) {
                    $group.append(createScheduleBadgeElement(badge));
                });

                fragment.append($group);
            });

            $target.empty().append(fragment);
        }

        function countPatternEntries(rawValue) {
            if (typeof rawValue !== 'string') {
                return 0;
            }

            return rawValue.split(/[\r\n,]+/).reduce(function(total, entry) {
                return entry.trim() === '' ? total : total + 1;
            }, 0);
        }

        function updateScheduleSummary() {
            if (!$summary.length && !$overviewContent.length && !$overviewFrequency.length) {
                return;
            }

            const recurrenceValue = ($recurrenceSelect.val() || '').toString();

            if ($overview.length) {
                $overview.attr('data-recurrence', recurrenceValue);
            }

            if ($overviewFrequency && $overviewFrequency.length) {
                const prefix = ($overviewFrequency.data('prefix') || '').toString();
                const label = recurrenceLabels[recurrenceValue] || (recurrenceValue ? recurrenceValue : '—');
                $overviewFrequency.text(prefix + label);
            }

            const componentBadges = [];
            const seenComponents = new Set();

            $scheduleForm.find('input[name="components[]"]').each(function() {
                const $input = $(this);
                const value = ($input.val() || '').toString();
                if (!$input.is(':checked') || value === '') {
                    return;
                }

                const key = value.toLowerCase();
                if (seenComponents.has(key)) {
                    return;
                }
                seenComponents.add(key);

                const config = componentLabels[key] || { label: value, color: '#4b5563' };
                componentBadges.push({
                    label: config.label,
                    color: config.color,
                    classes: ['bjlg-badge-component']
                });
            });

            if (!componentBadges.length) {
                componentBadges.push({
                    label: 'Aucun composant',
                    color: '#4b5563',
                    classes: ['bjlg-badge-component', 'bjlg-badge-empty']
                });
            }

            const encryptChecked = $scheduleForm.find('[name="encrypt"]').is(':checked');
            const incrementalChecked = $scheduleForm.find('[name="incremental"]').is(':checked');

            const optionBadges = [
                {
                    label: encryptChecked ? 'Chiffrée' : 'Non chiffrée',
                    color: encryptChecked ? '#7c3aed' : '#4b5563',
                    classes: ['bjlg-badge-encrypted']
                },
                {
                    label: incrementalChecked ? 'Incrémentale' : 'Complète',
                    color: incrementalChecked ? '#2563eb' : '#6b7280',
                    classes: ['bjlg-badge-incremental']
                }
            ];

            const includeCount = countPatternEntries(($scheduleForm.find('[name="include_patterns"]').val() || '').toString());
            const excludeCount = countPatternEntries(($scheduleForm.find('[name="exclude_patterns"]').val() || '').toString());

            const includeBadges = includeCount > 0
                ? [{ label: includeCount + ' motif(s)', color: '#0ea5e9', classes: ['bjlg-badge-include'] }]
                : [{ label: 'Tout le contenu', color: '#10b981', classes: ['bjlg-badge-include'] }];

            const excludeBadges = excludeCount > 0
                ? [{ label: excludeCount + ' exclusion(s)', color: '#f97316', classes: ['bjlg-badge-exclude'] }]
                : [{ label: 'Aucune', color: '#4b5563', classes: ['bjlg-badge-exclude'] }];

            const checksumChecked = $scheduleForm.find('input[name="post_checks[]"][value="checksum"]').is(':checked');
            const dryRunChecked = $scheduleForm.find('input[name="post_checks[]"][value="dry_run"]').is(':checked');

            const controlBadges = [];
            if (checksumChecked) {
                controlBadges.push({ label: 'Checksum', color: '#2563eb', classes: ['bjlg-badge-checksum'] });
            }
            if (dryRunChecked) {
                controlBadges.push({ label: 'Test restauration', color: '#7c3aed', classes: ['bjlg-badge-restore'] });
            }
            if (!controlBadges.length) {
                controlBadges.push({ label: 'Aucun contrôle', color: '#4b5563', classes: ['bjlg-badge-control'] });
            }

            const destinationBadges = [];
            const seenDestinations = new Set();

            $scheduleForm.find('input[name="secondary_destinations[]"]:checked').each(function() {
                const $input = $(this);
                const value = ($input.val() || '').toString();
                if (value === '') {
                    return;
                }

                const normalized = value.toLowerCase();
                if (seenDestinations.has(normalized)) {
                    return;
                }
                seenDestinations.add(normalized);

                const labelText = ($input.closest('label').text() || '').trim().replace(/\s+/g, ' ');
                destinationBadges.push({
                    label: labelText !== '' ? labelText : value,
                    color: '#0ea5e9',
                    classes: ['bjlg-badge-destination']
                });
            });

            if (!destinationBadges.length) {
                destinationBadges.push({
                    label: 'Stockage local',
                    color: '#4b5563',
                    classes: ['bjlg-badge-destination']
                });
            }

            const groups = [
                { title: 'Composants', badges: componentBadges },
                { title: 'Options', badges: optionBadges },
                { title: 'Inclusions', badges: includeBadges },
                { title: 'Exclusions', badges: excludeBadges },
                { title: 'Contrôles', badges: controlBadges },
                { title: 'Destinations', badges: destinationBadges }
            ];

            if ($summary.length) {
                renderScheduleBadgeGroups($summary, groups);
            }

            if ($overviewContent.length) {
                renderScheduleBadgeGroups($overviewContent, groups);
            }
        }

        $scheduleForm.on('change', 'input[name="components[]"], [name="encrypt"], [name="incremental"]', updateScheduleSummary);
        $scheduleForm.on('input', 'textarea[name="include_patterns"], textarea[name="exclude_patterns"]', updateScheduleSummary);
        $scheduleForm.on('change', 'input[name="post_checks[]"], input[name="secondary_destinations[]"]', updateScheduleSummary);
        $scheduleForm.on('change', '[role="switch"]', function() {
            const $input = $(this);
            $input.attr('aria-checked', $input.is(':checked') ? 'true' : 'false');
        });

        updateNextRunDisplay(($nextRunDisplay.text() || '').replace(/^.*?:\s*/, ''));
        updateScheduleSummary();

        $scheduleForm.on('submit', function(event) {
            event.preventDefault();

            resetScheduleFeedback();

            const $submitButton = $scheduleForm.find('button[type="submit"]').first();
            $submitButton.prop('disabled', true);

            const payload = {
                action: 'bjlg_save_schedule_settings',
                nonce: bjlg_ajax.nonce
            };

            const recurrenceField = $scheduleForm.find('[name="recurrence"]');
            if (recurrenceField.length) {
                payload.recurrence = (recurrenceField.val() || '').toString();
            }

            const dayField = $scheduleForm.find('[name="day"]');
            if (dayField.length) {
                payload.day = (dayField.val() || '').toString();
            }

            const timeField = $scheduleForm.find('[name="time"]');
            if (timeField.length) {
                payload.time = (timeField.val() || '').toString();
            }

            const componentValues = [];
            $scheduleForm.find('input[name="components[]"]').each(function() {
                const $input = $(this);
                const value = ($input.val() || '').toString();
                if ($input.is(':checked') && value) {
                    componentValues.push(value);
                }
            });
            payload.components = componentValues;

            ['encrypt', 'incremental'].forEach(function(fieldName) {
                const $field = $scheduleForm.find('[name="' + fieldName + '"]');
                if (!$field.length) {
                    return;
                }
                payload[fieldName] = $field.is(':checked') ? 'true' : 'false';
            });

            payload.include_patterns = ($scheduleForm.find('[name="include_patterns"]').val() || '').toString();
            payload.exclude_patterns = ($scheduleForm.find('[name="exclude_patterns"]').val() || '').toString();

            const postCheckValues = [];
            $scheduleForm.find('input[name="post_checks[]"]:checked').each(function() {
                const value = ($(this).val() || '').toString();
                if (value) {
                    postCheckValues.push(value);
                }
            });
            payload.post_checks = postCheckValues;

            const destinationValues = [];
            $scheduleForm.find('input[name="secondary_destinations[]"]:checked').each(function() {
                const value = ($(this).val() || '').toString();
                if (value) {
                    destinationValues.push(value);
                }
            });
            payload.secondary_destinations = destinationValues;

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
                        updateScheduleSummary();
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

    // --- LISTE DES SAUVEGARDES VIA L'API REST ---
    (function setupBackupListUI() {
        const $section = $('#bjlg-backup-list-section');
        if (!$section.length) {
            return;
        }

        const restSettings = (typeof bjlg_ajax === 'object' && bjlg_ajax) ? bjlg_ajax : {};
        const normalizedRoot = typeof restSettings.rest_root === 'string'
            ? restSettings.rest_root.replace(/\/?$/, '/')
            : '';
        let backupsEndpoint = typeof restSettings.rest_backups === 'string' && restSettings.rest_backups
            ? restSettings.rest_backups
            : '';

        if (!backupsEndpoint && normalizedRoot && typeof restSettings.rest_namespace === 'string') {
            const namespace = restSettings.rest_namespace.replace(/^\//, '').replace(/\/$/, '');
            backupsEndpoint = normalizedRoot + namespace + '/backups';
        }

        const $tableBody = $('#bjlg-backup-table-body');
        const $pagination = $('#bjlg-backup-pagination');
        const $feedback = $('#bjlg-backup-list-feedback');
        const $summary = $('#bjlg-backup-summary');
        const $filterType = $('#bjlg-backup-filter-type');
        const $perPage = $('#bjlg-backup-per-page');
        const $refreshButton = $('#bjlg-backup-refresh');

        const defaultPage = parseInt($section.data('default-page'), 10);
        const defaultPerPage = parseInt($section.data('default-per-page'), 10);

        const state = {
            page: Number.isFinite(defaultPage) ? defaultPage : 1,
            perPage: Number.isFinite(defaultPerPage) ? defaultPerPage : 10,
            type: ($filterType.val() || 'all'),
            sort: 'date_desc',
            loading: false
        };

        if (!$perPage.val() || parseInt($perPage.val(), 10) !== state.perPage) {
            $perPage.val(String(state.perPage));
        }

        const typeLabels = {
            full: { label: 'Complète', color: '#34d399' },
            incremental: { label: 'Incrémentale', color: '#60a5fa' },
            'pre-restore': { label: 'Pré-restauration', color: '#f59e0b' },
            standard: { label: 'Standard', color: '#9ca3af' }
        };

        const componentLabels = {
            db: { label: 'Base de données', color: '#6366f1' },
            plugins: { label: 'Extensions', color: '#f59e0b' },
            themes: { label: 'Thèmes', color: '#10b981' },
            uploads: { label: 'Médias', color: '#3b82f6' }
        };

        const destinationLabels = {
            local: { label: 'Local', color: '#6b7280' },
            local_storage: { label: 'Local', color: '#6b7280' },
            filesystem: { label: 'Local', color: '#6b7280' },
            google_drive: { label: 'Google Drive', color: '#facc15' },
            'google-drive': { label: 'Google Drive', color: '#facc15' },
            gdrive: { label: 'Google Drive', color: '#facc15' },
            aws_s3: { label: 'Amazon S3', color: '#8b5cf6' },
            s3: { label: 'Amazon S3', color: '#8b5cf6' },
            dropbox: { label: 'Dropbox', color: '#2563eb' },
            onedrive: { label: 'OneDrive', color: '#0ea5e9' }
        };

        function createBadge(config, fallbackLabel, extraClasses) {
            const label = config && config.label ? config.label : fallbackLabel;
            const background = config && config.color ? config.color : '#4b5563';
            const $badge = $('<span/>', {
                class: ['bjlg-badge'].concat(extraClasses || []).join(' '),
                text: label
            });

            $badge.css({
                display: 'inline-flex',
                'align-items': 'center',
                'justify-content': 'center',
                'border-radius': '4px',
                padding: '2px 6px',
                'font-size': '0.8em',
                'font-weight': '600',
                color: '#ffffff',
                'background-color': background,
                'margin-right': '4px',
                'margin-top': '2px'
            });

            return $badge;
        }

        function clearFeedback() {
            if ($feedback.length) {
                $feedback.hide().removeClass('notice-success notice-warning notice-info notice-error').empty();
            }
        }

        function showError(message) {
            if ($feedback.length) {
                $feedback
                    .attr('class', 'notice notice-error')
                    .text(message)
                    .show();
            }
        }

        function setControlsDisabled(disabled) {
            const value = !!disabled;
            const ariaValue = value ? 'true' : 'false';
            $filterType.prop('disabled', value);
            $perPage.prop('disabled', value);
            $refreshButton.prop('disabled', value);
            $pagination.find('a')
                .prop('disabled', value)
                .attr('aria-disabled', ariaValue)
                .toggleClass('disabled', value);
        }

        function renderLoadingRow() {
            if (!$tableBody.length) {
                return;
            }
            $tableBody.empty();
            const $row = $('<tr/>', { class: 'bjlg-backup-loading-row' });
            const $cell = $('<td/>', { colspan: 5 });
            $('<span/>', { class: 'spinner is-active', 'aria-hidden': 'true' }).appendTo($cell);
            $('<span/>').text('Chargement des sauvegardes...').appendTo($cell);
            $row.append($cell);
            $tableBody.append($row);
        }

        function renderEmptyRow() {
            if (!$tableBody.length) {
                return;
            }
            $tableBody.empty();
            const $row = $('<tr/>', { class: 'bjlg-backup-empty-row' });
            const $cell = $('<td/>', { colspan: 5, text: 'Aucune sauvegarde trouvée pour ces critères.' });
            $row.append($cell);
            $tableBody.append($row);
        }

        function renderErrorRow(message) {
            if (!$tableBody.length) {
                return;
            }
            $tableBody.empty();
            const $row = $('<tr/>', { class: 'bjlg-backup-error-row' });
            const $cell = $('<td/>', { colspan: 5 });
            $('<strong/>').text('Erreur : ').appendTo($cell);
            $('<span/>').text(message).appendTo($cell);
            $row.append($cell);
            $tableBody.append($row);
        }

        function formatSize(backup) {
            if (backup && typeof backup.size_formatted === 'string' && backup.size_formatted.trim() !== '') {
                return backup.size_formatted;
            }

            const size = backup && Number.isFinite(backup.size) ? backup.size : null;
            if (!Number.isFinite(size) || size < 0) {
                return '—';
            }

            const units = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];
            let index = 0;
            let value = size;
            while (value >= 1024 && index < units.length - 1) {
                value /= 1024;
                index += 1;
            }

            const rounded = value >= 10 ? Math.round(value) : Math.round(value * 10) / 10;
            return `${rounded} ${units[index]}`;
        }

        function formatDate(isoString) {
            if (typeof isoString !== 'string' || isoString.trim() === '') {
                return '—';
            }

            const date = new Date(isoString);
            if (Number.isNaN(date.getTime())) {
                return isoString;
            }

            try {
                return new Intl.DateTimeFormat(undefined, {
                    dateStyle: 'medium',
                    timeStyle: 'short'
                }).format(date);
            } catch (error) {
                return date.toLocaleString();
            }
        }

        function normalizeComponents(backup) {
            if (backup && Array.isArray(backup.components) && backup.components.length) {
                return backup.components;
            }
            if (backup && backup.manifest && Array.isArray(backup.manifest.contains) && backup.manifest.contains.length) {
                return backup.manifest.contains;
            }
            return [];
        }

        function normalizeDestinations(backup) {
            if (!backup) {
                return [];
            }

            const manifest = backup.manifest || {};
            if (Array.isArray(backup.destinations) && backup.destinations.length) {
                return backup.destinations;
            }
            if (Array.isArray(manifest.destinations) && manifest.destinations.length) {
                return manifest.destinations;
            }
            if (typeof manifest.destination === 'string' && manifest.destination.trim() !== '') {
                return [manifest.destination];
            }
            return ['local'];
        }

        function renderBackups(backups) {
            if (!$tableBody.length) {
                return;
            }

            $tableBody.empty();

            if (!Array.isArray(backups) || backups.length === 0) {
                renderEmptyRow();
                return;
            }

            backups.forEach(function(backup) {
                const $row = $('<tr/>', { class: 'bjlg-card-row' });

                const $nameCell = $('<td/>', { class: 'bjlg-card-cell', 'data-label': 'Nom du fichier' });
                $('<strong/>').text(backup && backup.filename ? backup.filename : 'Sauvegarde inconnue').appendTo($nameCell);

                const $badgeContainer = $('<div/>', { class: 'bjlg-badge-group', style: 'margin-top:6px; display:flex; flex-wrap:wrap;' });
                const typeKey = backup && typeof backup.type === 'string' ? backup.type.toLowerCase() : 'standard';
                const typeConfig = typeLabels[typeKey] || typeLabels.standard;
                createBadge(typeConfig, typeConfig.label, ['bjlg-badge-type']).appendTo($badgeContainer);

                if (backup && backup.is_encrypted) {
                    const encryptedConfig = { label: 'Chiffré', color: '#a78bfa' };
                    createBadge(encryptedConfig, 'Chiffré', ['bjlg-badge-encrypted']).appendTo($badgeContainer);
                }

                const destinations = normalizeDestinations(backup);
                const uniqueDestinations = Array.from(new Set(destinations.filter(function(item) {
                    return typeof item === 'string' && item.trim() !== '';
                }).map(function(item) {
                    return item.toLowerCase();
                })));

                uniqueDestinations.forEach(function(destinationKey) {
                    const config = destinationLabels[destinationKey] || { label: destinationKey, color: '#4b5563' };
                    createBadge(config, config.label, ['bjlg-badge-destination']).appendTo($badgeContainer);
                });

                $nameCell.append($badgeContainer);
                $row.append($nameCell);

                const $componentsCell = $('<td/>', { class: 'bjlg-card-cell', 'data-label': 'Composants' });
                const components = normalizeComponents(backup);

                if (components.length === 0) {
                    $componentsCell.text('—');
                } else {
                    const $componentsWrapper = $('<div/>', { style: 'display:flex; flex-wrap:wrap;' });
                    components.forEach(function(componentKeyRaw) {
                        const componentKey = typeof componentKeyRaw === 'string' ? componentKeyRaw.toLowerCase() : '';
                        const config = componentLabels[componentKey] || { label: componentKey || 'Inconnu', color: '#9ca3af' };
                        createBadge(config, config.label, ['bjlg-badge-component']).appendTo($componentsWrapper);
                    });
                    $componentsCell.append($componentsWrapper);
                }

                $row.append($componentsCell);

                const $sizeCell = $('<td/>', { class: 'bjlg-card-cell', 'data-label': 'Taille' });
                $sizeCell.text(formatSize(backup));
                $row.append($sizeCell);

                const $dateCell = $('<td/>', { class: 'bjlg-card-cell', 'data-label': 'Date' });
                const dateString = backup && backup.created_at ? backup.created_at : (backup && backup.modified_at ? backup.modified_at : null);
                $dateCell.text(formatDate(dateString));
                $row.append($dateCell);

                const $actionsCell = $('<td/>', { class: 'bjlg-card-cell bjlg-card-actions-cell', 'data-label': 'Actions' });
                const $actionsWrapper = $('<div/>', { class: 'bjlg-card-actions' });

                const filename = backup && backup.filename ? backup.filename : '';

                $('<button/>', {
                    class: 'button button-primary bjlg-restore-button',
                    text: 'Restaurer',
                    'data-filename': filename
                }).appendTo($actionsWrapper);

                $('<button/>', {
                    class: 'button bjlg-download-button',
                    text: 'Télécharger',
                    type: 'button',
                    'data-filename': filename
                }).appendTo($actionsWrapper);

                $('<button/>', {
                    class: 'button button-link-delete bjlg-delete-button',
                    text: 'Supprimer',
                    'data-filename': filename
                }).appendTo($actionsWrapper);

                $actionsCell.append($actionsWrapper);
                $row.append($actionsCell);

                $tableBody.append($row);
            });
        }

        function renderPagination(pagination) {
            if (!$pagination.length) {
                return;
            }

            $pagination.empty();

            if (!pagination) {
                return;
            }

            const pagesRaw = parseInt(pagination.pages, 10);
            if (!Number.isFinite(pagesRaw) || pagesRaw <= 0) {
                return;
            }

            const totalPages = Math.max(1, pagesRaw);
            const currentPage = Math.min(Math.max(1, state.page), totalPages);

            const $links = $('<span/>', { class: 'pagination-links' });

            function appendNav(label, targetPage, disabled, className) {
                const classes = [className || '', 'button'].join(' ').trim();
                if (disabled) {
                    $('<span/>', {
                        class: `tablenav-pages-navspan ${className || ''} disabled`,
                        text: label,
                        'aria-hidden': 'true'
                    }).appendTo($links);
                    return;
                }

                $('<a/>', {
                    href: '#',
                    text: label,
                    class: `${classes} bjlg-backup-page-button`,
                    'data-page': targetPage,
                    'aria-label': `Aller à la page ${targetPage}`
                }).appendTo($links);
            }

            appendNav('«', 1, currentPage === 1, 'first-page');
            appendNav('‹', currentPage - 1, currentPage === 1, 'prev-page');

            $('<span/>', {
                class: 'tablenav-paging-text',
                text: `${currentPage} / ${totalPages}`
            }).appendTo($links);

            appendNav('›', currentPage + 1, currentPage >= totalPages, 'next-page');
            appendNav('»', totalPages, currentPage >= totalPages, 'last-page');

            $pagination.append($links);
        }

        function updateSummary(pagination, displayedCount) {
            if (!$summary.length) {
                return;
            }

            if (!pagination) {
                $summary.text('');
                return;
            }

            const total = Number.isFinite(pagination.total) ? pagination.total : parseInt(pagination.total, 10);
            const totalCount = Number.isFinite(total) ? total : 0;

            if (totalCount === 0) {
                $summary.text('Total : 0 sauvegarde');
                return;
            }

            const page = Math.max(1, state.page);
            const perPage = Math.max(1, state.perPage);
            const start = (page - 1) * perPage + 1;
            const end = start + Math.max(0, displayedCount - 1);
            const cappedEnd = Math.min(end, totalCount);

            $summary.text(`Affichage ${start}-${cappedEnd} sur ${totalCount} sauvegarde${totalCount > 1 ? 's' : ''}`);
        }

        function requestBackups() {
            if (!backupsEndpoint) {
                renderErrorRow("L'API REST des sauvegardes est indisponible.");
                showError("Impossible de contacter l'API des sauvegardes. Vérifiez la configuration REST.");
                return;
            }

            state.loading = true;
            clearFeedback();
            setControlsDisabled(true);
            renderLoadingRow();

            $.ajax({
                url: backupsEndpoint,
                method: 'GET',
                dataType: 'json',
                data: {
                    page: state.page,
                    per_page: state.perPage,
                    type: state.type,
                    sort: state.sort
                },
                beforeSend: function(xhr) {
                    if (restSettings.rest_nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', restSettings.rest_nonce);
                    }
                }
            })
            .done(function(response) {
                const backups = response && Array.isArray(response.backups) ? response.backups : [];
                renderBackups(backups);
                renderPagination(response ? response.pagination : null);
                updateSummary(response ? response.pagination : null, backups.length);
            })
            .fail(function(jqXHR) {
                let message = "Impossible de récupérer les sauvegardes.";
                if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
                    message = jqXHR.responseJSON.message;
                } else if (jqXHR && typeof jqXHR.responseText === 'string' && jqXHR.responseText.trim() !== '') {
                    message = jqXHR.responseText;
                }
                renderErrorRow(message);
                showError(message);
                updateSummary(null, 0);
            })
            .always(function() {
                state.loading = false;
                setControlsDisabled(false);
            });
        }

        $filterType.on('change', function() {
            state.type = $(this).val() || 'all';
            state.page = 1;
            requestBackups();
        });

        $perPage.on('change', function() {
            const selected = parseInt($(this).val(), 10);
            state.perPage = Number.isFinite(selected) ? selected : state.perPage;
            state.page = 1;
            requestBackups();
        });

        $refreshButton.on('click', function(e) {
            e.preventDefault();
            requestBackups();
        });

        $pagination.on('click', '.bjlg-backup-page-button', function(e) {
            e.preventDefault();
            if (state.loading) {
                return;
            }
            const target = parseInt($(this).data('page'), 10);
            if (!Number.isFinite(target) || target === state.page) {
                return;
            }
            state.page = Math.max(1, target);
            requestBackups();
        });

        requestBackups();
    })();

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
        const includePatterns = ($form.find('textarea[name="include_patterns"]').val() || '').toString();
        const excludePatterns = ($form.find('textarea[name="exclude_patterns"]').val() || '').toString();
        const postChecks = [];
        $form.find('input[name="post_checks[]"]:checked').each(function() {
            postChecks.push($(this).val());
        });
        const secondaryDestinations = [];
        $form.find('input[name="secondary_destinations[]"]:checked').each(function() {
            secondaryDestinations.push($(this).val());
        });

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
            incremental: incremental,
            include_patterns: includePatterns,
            exclude_patterns: excludePatterns,
            post_checks: postChecks,
            secondary_destinations: secondaryDestinations
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
            $feedback.removeClass('notice-success notice-error').hide().addClass('bjlg-hidden').empty();
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
