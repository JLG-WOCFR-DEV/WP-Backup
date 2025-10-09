jQuery(function($) {
    'use strict';

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
        onedrive: { label: 'OneDrive', color: '#0ea5e9' },
        pcloud: { label: 'pCloud', color: '#f97316' }
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

function bjlgParseBackupPatternInput(value) {
    let raw = [];
    if (Array.isArray(value)) {
        raw = value;
    } else if (typeof value === 'string') {
        raw = value.split(/[\r\n,]+/);
    } else if (value && typeof value === 'object') {
        raw = Object.values(value);
    } else if (typeof value !== 'undefined' && value !== null) {
        raw = [value];
    }

    const normalized = [];
    raw.forEach(function(entry) {
        if (!entry && entry !== 0) {
            return;
        }
        const text = String(entry).trim();
        if (!text) {
            return;
        }
        const formatted = text.replace(/\\/g, '/');
        if (!normalized.includes(formatted)) {
            normalized.push(formatted);
        }
    });

    return normalized;
}

function bjlgFormatBackupPatternsForTextarea(patterns) {
    if (!Array.isArray(patterns)) {
        return '';
    }

    return patterns
        .map(function(pattern) {
            return typeof pattern === 'string' ? pattern : String(pattern || '');
        })
        .filter(function(pattern) {
            return pattern.trim() !== '';
        })
        .join('\n');
}

function bjlgCollectBackupFormState($form) {
    if (!$form || !$form.length) {
        return {
            components: [],
            encrypt: false,
            incremental: false,
            include_patterns: [],
            exclude_patterns: [],
            include_patterns_text: '',
            exclude_patterns_text: '',
            post_checks: {},
            post_checks_array: [],
            secondary_destinations: []
        };
    }

    const components = [];
    $form.find('input[name="backup_components[]"]').each(function() {
        const value = $(this).val();
        if (!value) {
            return;
        }
        if ($(this).is(':checked')) {
            components.push(String(value));
        }
    });

    const includeText = ($form.find('textarea[name="include_patterns"]').val() || '').toString();
    const excludeText = ($form.find('textarea[name="exclude_patterns"]').val() || '').toString();

    const postChecksMap = {};
    const postChecksArray = [];
    $form.find('input[name="post_checks[]"]').each(function() {
        const rawValue = ($(this).val() || '').toString();
        if (rawValue === '') {
            return;
        }
        const normalized = rawValue.trim();
        const isChecked = $(this).is(':checked');
        postChecksMap[normalized] = isChecked;
        if (isChecked) {
            postChecksArray.push(normalized);
        }
    });

    const secondaryDestinations = [];
    $form.find('input[name="secondary_destinations[]"]').each(function() {
        const raw = ($(this).val() || '').toString();
        if (raw === '') {
            return;
        }
        if ($(this).is(':checked')) {
            secondaryDestinations.push(raw);
        }
    });

    return {
        components: components,
        encrypt: $form.find('input[name="encrypt_backup"]').is(':checked'),
        incremental: $form.find('input[name="incremental_backup"]').is(':checked'),
        include_patterns: bjlgParseBackupPatternInput(includeText),
        exclude_patterns: bjlgParseBackupPatternInput(excludeText),
        include_patterns_text: includeText,
        exclude_patterns_text: excludeText,
        post_checks: postChecksMap,
        post_checks_array: postChecksArray,
        secondary_destinations: secondaryDestinations
    };
}

function bjlgApplyBackupPresetToForm($form, preset) {
    if (!$form || !$form.length || !preset || typeof preset !== 'object') {
        return false;
    }

    const components = Array.isArray(preset.components) ? preset.components.map(function(value) {
        return String(value);
    }) : [];
    const componentSet = new Set(components);
    $form.find('input[name="backup_components[]"]').each(function() {
        const value = ($(this).val() || '').toString();
        $(this).prop('checked', componentSet.has(value));
    });

    $form.find('input[name="encrypt_backup"]').prop('checked', !!preset.encrypt);
    $form.find('input[name="incremental_backup"]').prop('checked', !!preset.incremental);

    $form.find('textarea[name="include_patterns"]').val(bjlgFormatBackupPatternsForTextarea(preset.include_patterns));
    $form.find('textarea[name="exclude_patterns"]').val(bjlgFormatBackupPatternsForTextarea(preset.exclude_patterns));

    const postChecksSet = new Set();
    if (preset.post_checks && typeof preset.post_checks === 'object') {
        Object.keys(preset.post_checks).forEach(function(key) {
            if (preset.post_checks[key]) {
                postChecksSet.add(String(key));
            }
        });
    } else if (Array.isArray(preset.post_checks)) {
        preset.post_checks.forEach(function(value) {
            postChecksSet.add(String(value));
        });
    }

    $form.find('input[name="post_checks[]"]').each(function() {
        const value = ($(this).val() || '').toString();
        $(this).prop('checked', postChecksSet.has(value));
    });

    const secondarySet = new Set(Array.isArray(preset.secondary_destinations) ? preset.secondary_destinations.map(function(value) {
        return String(value);
    }) : []);
    $form.find('input[name="secondary_destinations[]"]').each(function() {
        const value = ($(this).val() || '').toString();
        $(this).prop('checked', secondarySet.has(value));
    });

    return true;
}

(function setupBackupPresets() {
    const $form = $('#bjlg-backup-creation-form');
    if (!$form.length) {
        return;
    }

    const $panel = $form.find('.bjlg-backup-presets');
    if (!$panel.length) {
        return;
    }

    const $select = $panel.find('.bjlg-backup-presets__select');
    const $apply = $panel.find('.bjlg-backup-presets__apply');
    const $save = $panel.find('.bjlg-backup-presets__save');
    const $status = $panel.find('.bjlg-backup-presets__status');

    const state = {
        presets: {}
    };

    function normalizePresetList(raw) {
        if (Array.isArray(raw)) {
            return raw;
        }

        if (raw && typeof raw === 'object') {
            return Object.keys(raw).map(function(key) {
                return raw[key];
            });
        }

        return [];
    }

    function updatePresets(rawList) {
        const list = normalizePresetList(rawList);
        state.presets = {};
        list.forEach(function(item) {
            if (!item || typeof item !== 'object') {
                return;
            }
            const id = item.id ? String(item.id) : '';
            if (!id) {
                return;
            }
            state.presets[id] = $.extend(true, {}, item, { id: id });
        });
    }

    function renderSelect(selectedId) {
        const entries = Object.values(state.presets).sort(function(a, b) {
            const labelA = (a.label || '').toString();
            const labelB = (b.label || '').toString();
            return labelA.localeCompare(labelB, undefined, { sensitivity: 'base' });
        });

        const currentSelection = selectedId || $select.val() || '';
        $select.empty();
        $('<option/>', { value: '', text: 'Sélectionnez un modèle…' }).appendTo($select);
        entries.forEach(function(entry) {
            $('<option/>', {
                value: entry.id,
                text: entry.label || entry.id
            }).appendTo($select);
        });

        if (currentSelection && state.presets[currentSelection]) {
            $select.val(currentSelection);
        } else {
            $select.val('');
        }
    }

    function showStatus(type, message) {
        if (!$status.length) {
            return;
        }

        const normalizedType = type === 'error' ? 'error' : 'success';
        $status
            .removeClass('bjlg-backup-presets__status--error bjlg-backup-presets__status--success')
            .addClass('bjlg-backup-presets__status--' + normalizedType)
            .text(message || '')
            .show();
    }

    function parseInitialPresets() {
        const raw = $panel.attr('data-bjlg-presets');
        if (!raw) {
            return [];
        }
        try {
            return JSON.parse(raw);
        } catch (error) {
            return [];
        }
    }

    updatePresets(parseInitialPresets());
    renderSelect();
    if ($status.length) {
        $status.hide();
    }

    function applySelectedPreset() {
        const selectedId = $select.val();
        if (!selectedId) {
            showStatus('error', 'Veuillez sélectionner un modèle à appliquer.');
            return;
        }

        const preset = state.presets[selectedId];
        if (!preset) {
            showStatus('error', 'Modèle introuvable.');
            return;
        }

        bjlgApplyBackupPresetToForm($form, preset);
        $form.trigger('bjlg-backup-form-updated');
        showStatus('success', `Modèle « ${preset.label || selectedId} » appliqué.`);
    }

    $apply.on('click', function(e) {
        e.preventDefault();
        applySelectedPreset();
    });

    $save.on('click', function(e) {
        e.preventDefault();

        const formState = bjlgCollectBackupFormState($form);
        if (!formState.components.length) {
            showStatus('error', 'Sélectionnez au moins un composant avant d’enregistrer un modèle.');
            return;
        }

        const selectedId = $select.val();
        const currentPreset = selectedId && state.presets[selectedId] ? state.presets[selectedId] : null;
        const defaultName = currentPreset && currentPreset.label ? currentPreset.label : '';

        let name = window.prompt('Nom du modèle', defaultName);
        if (name === null) {
            return;
        }

        name = name.trim();
        if (name === '') {
            showStatus('error', 'Le nom du modèle ne peut pas être vide.');
            return;
        }

        let presetId = '';
        if (currentPreset) {
            const sameName = (currentPreset.label || '').toLowerCase() === name.toLowerCase();
            if (sameName || window.confirm('Mettre à jour le modèle existant « ' + (currentPreset.label || selectedId) + ' » ? Cliquez sur Annuler pour créer un nouveau modèle.')) {
                presetId = currentPreset.id;
            }
        }

        const payload = {
            action: 'bjlg_save_backup_preset',
            nonce: bjlg_ajax.nonce,
            preset_id: presetId,
            name: name,
            preset: JSON.stringify({
                label: name,
                components: formState.components,
                encrypt: formState.encrypt,
                incremental: formState.incremental,
                include_patterns: formState.include_patterns,
                exclude_patterns: formState.exclude_patterns,
                post_checks: formState.post_checks,
                secondary_destinations: formState.secondary_destinations
            })
        };

        $save.prop('disabled', true).addClass('is-busy');

        $.post(bjlg_ajax.ajax_url, payload)
            .done(function(response) {
                if (!response || response.success === false) {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : "Impossible d'enregistrer le modèle.";
                    showStatus('error', errorMessage);
                    return;
                }

                const data = response.data || {};
                updatePresets(data.presets || []);
                const saved = data.saved && data.saved.id ? String(data.saved.id) : '';
                renderSelect(saved);
                if (saved && state.presets[saved]) {
                    $select.val(saved);
                }
                showStatus('success', data.message || 'Modèle enregistré.');
            })
            .fail(function(xhr) {
                let message = "Impossible d'enregistrer le modèle.";
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                } else if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                } else if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
                    message = xhr.responseText;
                }
                showStatus('error', message);
            })
            .always(function() {
                $save.prop('disabled', false).removeClass('is-busy');
            });
    });
})();

(function setupBackupStepper() {
    const $form = $('#bjlg-backup-creation-form');
    if (!$form.length) {
        return;
    }

    const $stepsContainer = $form.find('.bjlg-backup-steps');
    if (!$stepsContainer.length) {
        return;
    }

        const $steps = $stepsContainer.find('.bjlg-backup-step');
        if (!$steps.length) {
            return;
        }

        $stepsContainer.attr('data-enhanced', 'true');

        const $navItems = $stepsContainer.find('.bjlg-backup-steps__item');
        const $navButtons = $stepsContainer.find('.bjlg-backup-steps__button');
    const $summary = $stepsContainer.find('[data-role="backup-summary"]');
    const $warning = $stepsContainer.find('[data-role="backup-summary-warning"]');
    const totalSteps = $steps.length;
    let currentStep = 1;

    function sanitizeStep(value) {
        const numeric = parseInt(value, 10);
        if (!Number.isFinite(numeric)) {
            return 1;
        }
        return Math.min(Math.max(numeric, 1), totalSteps);
    }

    function cleanLabelText($input) {
        const $label = $input.closest('label');
        if (!$label.length) {
            return '';
        }
        return $label.text().replace(/\s+/g, ' ').trim();
    }

    function renderSummary() {
        if (!$summary.length) {
            return;
        }

        const state = bjlgCollectBackupFormState($form);
        const $content = $('<dl/>');

        const componentLabels = [];
        $form.find('input[name="backup_components[]"]').each(function() {
            if ($(this).is(':checked')) {
                const label = cleanLabelText($(this));
                if (label) {
                    componentLabels.push(label);
                }
            }
        });

        $('<dt/>', { text: 'Composants sélectionnés' }).appendTo($content);
        $('<dd/>', {
            text: componentLabels.length ? componentLabels.join(', ') : 'Aucun composant sélectionné'
        }).appendTo($content);

        const optionLabels = [];
        if (state.encrypt) {
            optionLabels.push('Chiffrement AES-256 activé');
        }
        if (state.incremental) {
            optionLabels.push('Sauvegarde incrémentale activée');
        }
        if (!optionLabels.length) {
            optionLabels.push('Options essentielles désactivées');
        }
        $('<dt/>', { text: 'Options' }).appendTo($content);
        $('<dd/>', { text: optionLabels.join(', ') }).appendTo($content);

        const postCheckLabels = [];
        $form.find('input[name="post_checks[]"]').each(function() {
            if ($(this).is(':checked')) {
                const label = cleanLabelText($(this));
                if (label) {
                    postCheckLabels.push(label);
                }
            }
        });
        if (postCheckLabels.length) {
            $('<dt/>', { text: 'Vérifications post-sauvegarde' }).appendTo($content);
            $('<dd/>', { text: postCheckLabels.join(', ') }).appendTo($content);
        }

        const includeText = (state.include_patterns_text || '').trim();
        if (includeText !== '') {
            $('<dt/>', { text: 'Inclusions personnalisées' }).appendTo($content);
            $('<dd/>').append($('<pre/>', { text: includeText })).appendTo($content);
        }

        const excludeText = (state.exclude_patterns_text || '').trim();
        if (excludeText !== '') {
            $('<dt/>', { text: 'Exclusions' }).appendTo($content);
            $('<dd/>').append($('<pre/>', { text: excludeText })).appendTo($content);
        }

        const destinationLabels = [];
        $form.find('input[name="secondary_destinations[]"]').each(function() {
            if ($(this).is(':checked')) {
                const label = cleanLabelText($(this));
                if (label) {
                    destinationLabels.push(label);
                }
            }
        });
        if (destinationLabels.length) {
            $('<dt/>', { text: 'Destinations secondaires' }).appendTo($content);
            $('<dd/>', { text: destinationLabels.join(', ') }).appendTo($content);
        }

        if (!$content.children().length) {
            $('<dt/>', { text: 'Résumé' }).appendTo($content);
            $('<dd/>', { text: 'Aucun paramètre personnalisé.' }).appendTo($content);
        }

        $summary.empty().append($content);

        if ($warning.length) {
            if (componentLabels.length === 0) {
                $warning.show();
            } else {
                $warning.hide();
            }
        }
    }

    function setActiveStep(step) {
        const sanitized = sanitizeStep(step);
        currentStep = sanitized;
        $stepsContainer.attr('data-current-step', String(sanitized));

        if (sanitized === 2 && typeof window.bjlgLoadModule === 'function') {
            window.bjlgLoadModule('advanced').catch(function() {
                // Module optional, ignore loading errors.
            });
        }

        $steps.each(function() {
            const stepIndex = sanitizeStep($(this).data('step-index'));
            if (stepIndex === sanitized) {
                $(this).removeAttr('hidden').attr('aria-hidden', 'false');
                const $heading = $(this).find('h3').first();
                if ($heading.length) {
                    $heading.attr('tabindex', '-1');
                    $heading.focus();
                    $heading.one('blur', function() {
                        $(this).removeAttr('tabindex');
                    });
                }
            } else {
                $(this).attr('hidden', 'hidden').attr('aria-hidden', 'true');
            }
        });

        $navItems.each(function(index) {
            const stepIndex = index + 1;
            const isActive = stepIndex === sanitized;
            $(this).toggleClass('is-active', isActive);
            $(this).toggleClass('is-complete', stepIndex < sanitized);
            const $button = $(this).find('.bjlg-backup-steps__button');
            if ($button.length) {
                if (isActive) {
                    $button.attr('aria-current', 'step');
                } else {
                    $button.removeAttr('aria-current');
                }
            }
        });

        if (sanitized === totalSteps) {
            renderSummary();
        }

        $(document).trigger('bjlg-backup-step-activated', sanitized);
    }

    $navButtons.on('click', function(event) {
        event.preventDefault();
        const target = sanitizeStep($(this).data('step-target'));
        setActiveStep(target);
    });

    $stepsContainer.on('click', '[data-step-action="next"]', function(event) {
        event.preventDefault();
        setActiveStep(currentStep + 1);
    });

    $stepsContainer.on('click', '[data-step-action="prev"]', function(event) {
        event.preventDefault();
        setActiveStep(currentStep - 1);
    });

    $form.on('change input', 'input, textarea, select', function() {
        if (currentStep === totalSteps) {
            renderSummary();
        }
    });

    $form.on('bjlg-backup-form-updated', function() {
        if (currentStep === totalSteps) {
            renderSummary();
        }
    });

    setActiveStep(1);
    if ($warning.length) {
        $warning.hide();
    }
})();

// La navigation par onglets est gérée par PHP via rechargement de page.

// --- GESTIONNAIRE DE SAUVEGARDE ASYNCHRONE ---
$('#bjlg-backup-creation-form').on('submit.bjlgBackupAjax', function(e) {
    e.preventDefault();
    const $form = $(this);
    const $button = $form.find('button[type="submit"]');
    const $progressArea = $('#bjlg-backup-progress-area');
    const $statusText = $('#bjlg-backup-status-text');
    const $progressBar = $('#bjlg-backup-progress-bar');
    const $debugWrapper = $('#bjlg-backup-debug-wrapper');
    const $debugOutput = $('#bjlg-backup-ajax-debug');

    function fallbackSubmit(reason) {
        if ($form.data('bjlgFallbackSubmitted')) {
            return;
        }

        $form.data('bjlgFallbackSubmitted', true);

        if (typeof reason === 'string' && reason.trim() !== '') {
            let $reasonField = $form.find('input[name="bjlg_fallback_reason"]');
            if (!$reasonField.length) {
                $reasonField = $('<input>', {
                    type: 'hidden',
                    name: 'bjlg_fallback_reason'
                }).appendTo($form);
            }
            $reasonField.val(reason.trim());
        }

        $form.off('submit.bjlgBackupAjax');

        const formElement = $form.get(0);
        if (formElement && typeof formElement.submit === 'function') {
            window.setTimeout(function() {
                formElement.submit();
            }, 10);
        }
    }

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

    const formState = bjlgCollectBackupFormState($form);

    if (formState.components.length === 0) {
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
        components: formState.components,
        encrypt: formState.encrypt,
        incremental: formState.incremental,
        include_patterns: formState.include_patterns_text,
        exclude_patterns: formState.exclude_patterns_text,
        post_checks: formState.post_checks_array,
        secondary_destinations: formState.secondary_destinations
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
            const errorMessage = response && response.data && response.data.message
                ? response.data.message
                : 'Réponse invalide.';
            setBackupBusyState(false);
            setBackupStatusText('❌ Erreur lors du lancement : ' + errorMessage + ' Passage en mode sécurisé…');
            fallbackSubmit('ajax_launch_incomplete');
        }
    })
    .fail(function(xhr) {
        if ($debugOutput.length) {
            debugReport += "\n\nERREUR CRITIQUE DE COMMUNICATION\nStatut: " + xhr.status + "\nRéponse brute:\n" + xhr.responseText;
            $debugOutput.text(debugReport);
        }
        setBackupBusyState(false);
        setBackupStatusText('❌ Erreur de communication. Passage en mode sécurisé…');
        fallbackSubmit('ajax_transport_error');
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
$('#bjlg-restore-form').on('submit.bjlgRestoreAjax', function(e) {
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
    const $sandboxToggle = $form.find('input[name="restore_to_sandbox"]');
    const $environmentField = $form.find('[data-role="restore-environment-field"]');
    const $sandboxPathInput = $form.find('input[name="sandbox_path"]');
    const passwordHelpDefaultText = passwordHelp
        ? (passwordHelp.getAttribute('data-default-text') || passwordHelp.textContent.trim())
        : '';
    const passwordHelpEncryptedText = passwordHelp
        ? (passwordHelp.getAttribute('data-encrypted-text') || passwordHelpDefaultText)
        : '';
    const $errorNotice = $('#bjlg-restore-errors');
    const errorFieldClass = 'bjlg-input-error';

    function fallbackSubmit(reason) {
        if ($form.data('bjlgFallbackSubmitted')) {
            return;
        }

        $form.data('bjlgFallbackSubmitted', true);

        if (typeof reason === 'string' && reason.trim() !== '') {
            let $reasonField = $form.find('input[name="bjlg_fallback_reason"]');
            if (!$reasonField.length) {
                $reasonField = $('<input>', {
                    type: 'hidden',
                    name: 'bjlg_fallback_reason'
                }).appendTo($form);
            }
            $reasonField.val(reason.trim());
        }

        $form.off('submit.bjlgRestoreAjax');

        const formElement = $form.get(0);
        if (formElement && typeof formElement.submit === 'function') {
            window.setTimeout(function() {
                formElement.submit();
            }, 10);
        }
    }

    function syncSandboxState() {
        if (!$sandboxPathInput.length) {
            if ($environmentField.length) {
                $environmentField.val('production');
            }
            return;
        }

        const enabled = $sandboxToggle.length && $sandboxToggle.is(':checked');
        $sandboxPathInput.prop('disabled', !enabled);

        if ($environmentField.length) {
            $environmentField.val(enabled ? 'sandbox' : 'production');
        }

        if (!enabled) {
            $sandboxPathInput.removeClass(errorFieldClass).removeAttr('aria-invalid');
        }
    }

    if ($form.data('bjlgSandboxBound') !== true) {
        $form.data('bjlgSandboxBound', true);

        if ($sandboxToggle.length) {
            $sandboxToggle.on('change', syncSandboxState);
        }
    }

    syncSandboxState();

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

    const sandboxEnabled = $sandboxToggle.length && $sandboxToggle.is(':checked');
    const sandboxPathValue = $sandboxPathInput.length && typeof $sandboxPathInput.val() === 'string'
        ? $sandboxPathInput.val().trim()
        : '';
    const restoreEnvironment = sandboxEnabled ? 'sandbox' : 'production';

    const formData = new FormData();
    formData.append('action', 'bjlg_upload_restore_file');
    formData.append('nonce', bjlg_ajax.nonce);
    formData.append('restore_file', fileInput.files[0]);
    formData.append('restore_environment', restoreEnvironment);

    if (sandboxEnabled) {
        formData.append('sandbox_path', sandboxPathValue);
    }

    if ($debugOutput.length) {
        appendRestoreDebug(
            '--- 1. TÉLÉVERSEMENT DU FICHIER ---\nRequête envoyée (métadonnées)',
            {
                filename: fileInput.files[0].name,
                size: fileInput.files[0].size,
                type: fileInput.files[0].type || 'inconnu',
                create_backup_before_restore: createRestorePoint,
                restore_environment: restoreEnvironment,
                sandbox_path: sandboxEnabled ? sandboxPathValue : ''
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
            runRestore(response.data.filename, createRestorePoint, restoreEnvironment, sandboxPathValue);
        } else {
            const payload = response && response.data ? response.data : {};
            const message = payload && payload.message
                ? payload.message
                : 'Réponse invalide du serveur.';
            displayRestoreErrors(message, getValidationErrors(payload));
            setRestoreBusyState(false);
            setRestoreStatusText('❌ ' + message + ' Passage en mode sécurisé…');
            fallbackSubmit('ajax_upload_incomplete');
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
        setRestoreStatusText('❌ ' + errorMessage + ' Passage en mode sécurisé…');
        fallbackSubmit('ajax_upload_transport');
    });

    function runRestore(filename, createRestorePointChecked, environment, sandboxPath) {
        const requestData = {
            action: 'bjlg_run_restore',
            nonce: bjlg_ajax.nonce,
            filename: filename,
            create_backup_before_restore: createRestorePointChecked ? 1 : 0,
            password: passwordInput ? passwordInput.value : '',
            restore_environment: environment
        };

        if (environment === 'sandbox') {
            requestData.sandbox_path = sandboxPath;
        }

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
                setRestoreStatusText('❌ ' + message + ' Passage en mode sécurisé…');
                fallbackSubmit('ajax_restore_incomplete');
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
            setRestoreStatusText('❌ ' + errorMessage + ' Passage en mode sécurisé…');
            fallbackSubmit('ajax_restore_transport');
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
});
