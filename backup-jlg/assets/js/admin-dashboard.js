jQuery(function($) {
    'use strict';

// --- DASHBOARD OVERVIEW ---
(function setupDashboardOverview() {
    const $overview = $('.bjlg-dashboard-overview');
    if (!$overview.length) {
        return;
    }

    const wpGlobal = window.wp || {};
    const i18n = wpGlobal.i18n || null;
    const a11y = wpGlobal.a11y || null;
    const $liveRegion = $('#bjlg-dashboard-live-region');

    const __ = function(text) {
        if (i18n && typeof i18n.__ === 'function') {
            return i18n.__(text, 'backup-jlg');
        }
        return text;
    };

    const sprintf = function() {
        if (i18n && typeof i18n.sprintf === 'function') {
            return i18n.sprintf.apply(i18n, arguments);
        }
        const args = Array.prototype.slice.call(arguments);
        let format = args.shift();
        args.forEach(function(arg) {
            format = format.replace(/%s/, String(arg));
        });
        return format;
    };

    const _n = function(singular, plural, number) {
        if (i18n && typeof i18n._n === 'function') {
            return i18n._n(singular, plural, number, 'backup-jlg');
        }
        return number === 1 ? singular : plural;
    };

    window.bjlg_ajax = window.bjlg_ajax || {};
    const ajaxData = window.bjlg_ajax;

    const queueActionMap = {
        'acknowledge-notification': { action: 'bjlg_notification_ack', param: 'entry_id' },
        'resolve-notification': {
            action: 'bjlg_notification_resolve',
            param: 'entry_id',
            promptField: 'notes',
            prompt: __('Ajoutez des notes de résolution (optionnel)', 'backup-jlg')
        },
        'retry-notification': { action: 'bjlg_notification_queue_retry', param: 'entry_id' },
        'clear-notification': { action: 'bjlg_notification_queue_delete', param: 'entry_id' },
        'acknowledge-notification': { action: 'bjlg_notification_acknowledge', param: 'entry_id', summary: { required: false, prompt: __('Ajouter une note pour l’accusé ?', 'backup-jlg') } },
        'resolve-notification': { action: 'bjlg_notification_resolve', param: 'entry_id', summary: { required: true, prompt: __('Consigner la résolution :', 'backup-jlg') } },
        'retry-remote-purge': { action: 'bjlg_remote_purge_retry', param: 'file' },
        'clear-remote-purge': { action: 'bjlg_remote_purge_delete', param: 'file' }
    };

    window.bjlgDashboardQueueActions = queueActionMap;

    const announce = function(message, priority) {
        if (!message) {
            return;
        }
        if (a11y && typeof a11y.speak === 'function') {
            a11y.speak(message, priority || 'polite');
        }
        if ($liveRegion.length) {
            $liveRegion.text(message);
        }
    };

    const buildAnnouncement = function(metrics) {
        metrics = metrics || {};
        const summary = metrics.summary || {};
        const alerts = Array.isArray(metrics.alerts) ? metrics.alerts : [];
        const parts = [];

        const lastBackup = summary.history_last_backup_relative || summary.history_last_backup;
        if (lastBackup) {
            parts.push(sprintf(__('Dernière sauvegarde : %s', 'backup-jlg'), lastBackup));
        }

        const nextRun = summary.scheduler_next_run_relative || summary.scheduler_next_run;
        if (nextRun) {
            parts.push(sprintf(__('Prochaine sauvegarde planifiée : %s', 'backup-jlg'), nextRun));
        }

        if (summary.storage_backup_count !== undefined && summary.storage_backup_count !== null) {
            parts.push(sprintf(__('Archives stockées : %s', 'backup-jlg'), formatNumber(summary.storage_backup_count)));
        }

        if (summary.scheduler_success_rate) {
            parts.push(sprintf(__('Taux de succès planificateur : %s', 'backup-jlg'), summary.scheduler_success_rate));
        }

        if (alerts.length) {
            const alert = alerts[0];
            const label = alert.title || alert.message;
            if (label) {
                parts.push(sprintf(__('Alerte active : %s', 'backup-jlg'), label));
            }
        }

        const reliability = metrics.reliability || {};
        if (reliability.level) {
            const scoreValue = reliability.score !== undefined && reliability.score !== null
                ? formatNumber(reliability.score)
                : reliability.score_label || '';
            if (scoreValue) {
                parts.push(sprintf(__('Fiabilité : %1$s (%2$s/100)', 'backup-jlg'), reliability.level, scoreValue));
            } else {
                parts.push(sprintf(__('Fiabilité : %s', 'backup-jlg'), reliability.level));
            }
        }

        if (!parts.length) {
            return '';
        }

        return sprintf(__('Tableau de bord mis à jour. %s', 'backup-jlg'), parts.join(' • '));
    };

    let readyForAnnouncements = false;
    let lastAnnouncement = '';

    const parseJSON = function(raw) {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    };

    const formatNumber = function(value) {
        const numeric = typeof value === 'number' ? value : parseFloat(value);
        if (!Number.isFinite(numeric)) {
            return value || '0';
        }
        return numeric.toLocaleString(undefined);
    };

    const defaults = {};
    $overview.find('[data-field]').each(function() {
        const field = $(this).data('field');
        if (field && defaults[field] === undefined) {
            defaults[field] = $(this).text();
        }
    });

    const setField = function(field, value, fallback) {
        const $field = $overview.find('[data-field="' + field + '"]');
        if (!$field.length) {
            return;
        }
        const defaultValue = defaults[field] !== undefined ? defaults[field] : '';
        const resolved = value === undefined || value === null || value === '' ? (fallback !== undefined ? fallback : defaultValue) : value;
        $field.text(resolved);
    };

    const state = {
        metrics: parseJSON($overview.attr('data-bjlg-dashboard')) || {},
        charts: {}
    };

    const setChartEmptyState = function($card, isEmpty) {
        const $canvas = $card.find('canvas');
        const $message = $card.find('[data-role="empty-message"]');
        if (isEmpty) {
            $card.addClass('bjlg-chart-card--empty');
            $canvas.attr('hidden', 'hidden');
            $message.removeAttr('hidden').show();
        } else {
            $card.removeClass('bjlg-chart-card--empty');
            $canvas.removeAttr('hidden');
            $message.attr('hidden', 'hidden').hide();
        }
    };

    const destroyChart = function(key) {
        if (state.charts[key]) {
            try {
                state.charts[key].destroy();
            } catch (error) {
                // Ignored
            }
            delete state.charts[key];
        }
    };

    const updateSummary = function(summary) {
        summary = summary || {};
        setField('history_total_actions', formatNumber(summary.history_total_actions || 0));
        setField('history_successful_backups', formatNumber(summary.history_successful_backups || 0));
        setField('history_last_backup', summary.history_last_backup || '');
        setField('history_last_backup_relative', summary.history_last_backup_relative || '');
        setField('scheduler_next_run', summary.scheduler_next_run || '');
        setField('scheduler_next_run_relative', summary.scheduler_next_run_relative || '');
        setField('scheduler_active_count', formatNumber(summary.scheduler_active_count || 0));
        setField('scheduler_success_rate', summary.scheduler_success_rate || '0%');
        setField('storage_total_size_human', summary.storage_total_size_human || '0');
        setField('storage_backup_count', formatNumber(summary.storage_backup_count || 0));
    };

    const updateActions = function(metrics) {
        const $actions = $overview.find('[data-role="actions"]');
        if (!$actions.length) {
            return;
        }

        const summary = metrics.summary || {};
        const storage = metrics.storage || {};

        const lastBackupRelative = summary.history_last_backup_relative || '';
        const lastBackupAbsolute = summary.history_last_backup || '';
        const nextRunRelative = summary.scheduler_next_run_relative || '';
        const nextRunAbsolute = summary.scheduler_next_run || '';

        setField('cta_backup_last_backup', lastBackupRelative || lastBackupAbsolute || '');
        setField('cta_backup_next_run', nextRunRelative || nextRunAbsolute || '');
        setField('cta_restore_last_backup', lastBackupAbsolute || lastBackupRelative || '');
        setField('cta_restore_backup_count', formatNumber(summary.storage_backup_count || storage.backup_count || 0));
        setField('chart_history_subtitle', 'Total : ' + formatNumber(summary.history_total_actions || 0) + ' actions');
        setField('chart_storage_subtitle', 'Total : ' + (storage.total_size_human || summary.storage_total_size_human || '0'));

        const $restoreCard = $actions.find('[data-action="restore"]');
        const $restoreButton = $restoreCard.find('[data-action-target="restore"]');
        const backupCount = parseInt(storage.backup_count || summary.storage_backup_count || 0, 10);

        if (!Number.isFinite(backupCount) || backupCount <= 0) {
            $restoreCard.addClass('bjlg-action-card--disabled');
            $restoreButton.addClass('disabled').attr('aria-disabled', 'true').attr('tabindex', '-1');
        } else {
            $restoreCard.removeClass('bjlg-action-card--disabled');
            $restoreButton.removeClass('disabled').removeAttr('aria-disabled').removeAttr('tabindex');
        }
    };

    const refreshStateLabels = {
        'fresh': __('Actualisé récemment', 'backup-jlg'),
        'stale': __('Instantané daté', 'backup-jlg'),
        'expired': __('Actualisation requise', 'backup-jlg'),
        'unknown': __('État inconnu', 'backup-jlg')
    };

    const updateRemoteStorage = function(storage) {
        const $card = $overview.find('[data-metric="remote-storage"]');
        if (!$card.length) {
            return;
        }

        storage = storage || {};
        const destinations = Array.isArray(storage.remote_destinations) ? storage.remote_destinations : [];
        const refreshInfo = typeof storage.remote_refresh === 'object' && storage.remote_refresh !== null
            ? storage.remote_refresh
            : {};
        const thresholdRatioConfig = Number(storage.remote_threshold || storage.threshold_ratio || 0.85);
        const threshold = Number.isFinite(thresholdRatioConfig) ? thresholdRatioConfig : 0.85;
        const $list = $card.find('[data-field="remote_storage_list"]');
        const thresholdPercentRaw = Number(storage.remote_warning_threshold || storage.threshold_percent || 85);
        const thresholdPercent = Number.isFinite(thresholdPercentRaw) ? Math.max(1, Math.min(100, thresholdPercentRaw)) : 85;
        const thresholdRatio = thresholdPercent / 100;
        const refreshFormatted = storage.remote_last_refreshed_formatted || '';
        const refreshRelative = storage.remote_last_refreshed_relative || '';
        const refreshStale = !!storage.remote_refresh_stale;
        const refreshDetail = refreshRelative || refreshFormatted;
        let refreshText = '';

        if (refreshFormatted) {
            refreshText = refreshStale
                ? sprintf(__('Rafraîchi %s — données à actualiser', 'backup-jlg'), refreshDetail)
                : sprintf(__('Rafraîchi %s', 'backup-jlg'), refreshDetail);
        } else {
            refreshText = __('Aucun rafraîchissement enregistré.', 'backup-jlg');
        }

        setField('remote_storage_refresh', refreshText);

        const $refresh = $card.find('[data-field="remote_storage_refresh"]');
        if ($refresh.length) {
            $refresh.toggleClass('is-warning', refreshStale);
        }

        const $actions = $card.find('[data-field="remote_storage_actions"]');

        if (!destinations.length) {
            setField('remote_storage_connected', __('Aucune destination distante configurée.', 'backup-jlg'));
            setField('remote_storage_caption', __('Connectez une destination distante pour suivre les quotas.', 'backup-jlg'));
            if ($list.length) {
                $list.empty().append($('<li/>', {
                    'class': 'bjlg-card__list-item',
                    'data-empty': 'true',
                    text: __('Aucune donnée distante disponible.', 'backup-jlg')
                }));
            }
            if ($actions.length) {
                $actions.attr('hidden', 'hidden');
            }
            return;
        }

        if ($actions.length) {
            $actions.removeAttr('hidden');
        }

        const connected = destinations.filter(function(dest) { return dest && dest.connected; }).length;
        const summaryText = sprintf(
            _n('%1$s destination distante active sur %2$s', '%1$s destinations distantes actives sur %2$s', connected),
            formatNumber(connected),
            formatNumber(destinations.length)
        );
        setField('remote_storage_connected', summaryText);

        const watchList = [];
        const offlineList = [];
        const criticalForecasts = [];
        const rendered = [];
        const captionParts = [];

        if (refreshInfo && (refreshInfo.relative || refreshInfo.formatted)) {
            const refreshLabel = refreshInfo.relative || refreshInfo.formatted;
            const stateLabel = refreshStateLabels[refreshInfo.state] || refreshStateLabels.unknown;
            captionParts.push(sprintf(__('Actualisé %1$s (%2$s)', 'backup-jlg'), refreshLabel, stateLabel));
        }

        destinations.forEach(function(dest) {
            if (!dest || typeof dest !== 'object') {
                return;
            }

            const name = (dest.name || dest.id || __('Destination inconnue', 'backup-jlg')).toString();
            const connectedFlag = !!dest.connected;
            const errors = Array.isArray(dest.errors) ? dest.errors.filter(function(message) {
                return message && message.toString().trim() !== '';
            }).map(function(message) { return message.toString(); }) : [];
            const usedHuman = dest.used_human || '';
            const quotaHuman = dest.quota_human || '';
            const freeHuman = dest.free_human || '';
            const backupsCount = Number(dest.backups_count || 0);
            const usedBytes = Number(dest.used_bytes);
            const quotaBytes = Number(dest.quota_bytes);
            const ratioValue = typeof dest.ratio === 'number' && Number.isFinite(dest.ratio) ? dest.ratio : null;
            const ratioLabel = dest.ratio_label || (ratioValue !== null ? formatNumber((ratioValue * 100).toFixed(1)) + '%' : '');
            const refreshLabel = dest.refresh_label || dest.refreshed_relative || dest.refreshed_formatted || '';
            const refreshState = dest.refresh_state && refreshStateLabels[dest.refresh_state]
                ? dest.refresh_state
                : 'unknown';
            const forecastLabel = dest.forecast_label || dest.daily_delta_label || '';
            const daysToThreshold = Number(dest.days_to_threshold);
            const daysLabel = dest.days_to_threshold_label || '';
            const projectionIntent = (dest.projection_intent || '').toString().toLowerCase();

            let ratio = null;
            const ratioFromSnapshot = Number(dest.utilization_ratio);
            if (Number.isFinite(ratioFromSnapshot)) {
                ratio = Math.min(1, Math.max(0, ratioFromSnapshot));
            } else if (Number.isFinite(usedBytes) && Number.isFinite(quotaBytes) && quotaBytes > 0) {
                ratio = Math.min(1, Math.max(0, usedBytes / quotaBytes));
            }

            if (!connectedFlag) {
                offlineList.push(name);
            }
            if (errors.length) {
                offlineList.push(name);
            }
            if (ratio !== null && ratio >= thresholdRatio) {
                watchList.push(name);
            }

            const details = [];
            if (usedHuman && quotaHuman) {
                details.push(sprintf(__('Utilisé : %1$s / %2$s', 'backup-jlg'), usedHuman, quotaHuman));
            } else if (usedHuman) {
                details.push(sprintf(__('Utilisé : %s', 'backup-jlg'), usedHuman));
            }

            if (freeHuman) {
                details.push(sprintf(__('Libre : %s', 'backup-jlg'), freeHuman));
            }

            if (Number.isFinite(backupsCount) && backupsCount > 0) {
                details.push(sprintf(_n('%s archive stockée', '%s archives stockées', backupsCount), formatNumber(backupsCount)));
            }

            if (ratio !== null) {
                if (ratioLabel) {
                    details.push(sprintf(__('Utilisation : %s', 'backup-jlg'), ratioLabel));
                } else {
                    details.push(sprintf(__('Utilisation : %s%%', 'backup-jlg'), formatNumber(Math.round(ratio * 100))));
                }
            }

            if (refreshLabel) {
                const stateText = refreshStateLabels[refreshState] || refreshStateLabels.unknown;
                details.push(sprintf(__('Actualisé : %1$s (%2$s)', 'backup-jlg'), refreshLabel, stateText));
            }

            const latency = Number(dest.latency_ms);
            if (Number.isFinite(latency) && latency > 0) {
                details.push(sprintf(__('Relevé en %s ms', 'backup-jlg'), formatNumber(Math.round(latency))));
            }

            if (forecastLabel) {
                details.push(forecastLabel);
            }

            if (daysLabel) {
                details.push(daysLabel);
            }

            let intent = 'info';
            if (!connectedFlag || errors.length) {
                intent = 'error';
            } else if (ratio !== null && ratio >= thresholdRatio) {
                intent = 'warning';
            }

            if (Number.isFinite(daysToThreshold)) {
                if (daysToThreshold <= 1) {
                    intent = 'error';
                    criticalForecasts.push({ name: name, label: daysLabel || forecastLabel });
                } else if (daysToThreshold <= 3 && intent !== 'error') {
                    intent = 'warning';
                    watchList.push(name);
                }
            }

            if (projectionIntent === 'success' && intent === 'info') {
                intent = 'success';
            }

            rendered.push({
                name: name,
                details: details,
                errors: errors,
                intent: intent,
                badge: dest.badge || intent,
                actions: {
                    settings: dest.cta_settings_url || '',
                    test: dest.cta_test_url || ''
                }
            });
        });

        const uniqueOffline = offlineList.filter(function(value, index, array) { return array.indexOf(value) === index; });
        const uniqueWatch = watchList.filter(function(value, index, array) { return array.indexOf(value) === index; });

        if (uniqueOffline.length) {
            captionParts.push(sprintf(__('Attention : vérifier %s', 'backup-jlg'), uniqueOffline.join(', ')));
        }

        if (criticalForecasts.length) {
            const labels = criticalForecasts.map(function(item) {
                return item.name;
            });
            captionParts.push(sprintf(__('Saturation estimée très bientôt pour %s', 'backup-jlg'), labels.join(', ')));
        } else if (uniqueWatch.length) {
            captionParts.push(sprintf(__('Capacité > %s%% pour %s', 'backup-jlg'), formatNumber(Math.round(thresholdPercent)), uniqueWatch.join(', ')));
        }

        if (!captionParts.length) {
            captionParts.push(__('Capacité hors-site nominale.', 'backup-jlg'));
        }

        setField('remote_storage_caption', captionParts.join(' — '));

        if ($list.length) {
            $list.empty();
            rendered.forEach(function(item) {
                const $item = $('<li/>', {
                    'class': 'bjlg-card__list-item bjlg-card__list-item--' + item.intent,
                    'data-intent': item.intent
                });
                const $title = $('<strong/>', { text: item.name }).appendTo($item);
                if (item.badge === 'critical') {
                    $('<span/>', {
                        'class': 'bjlg-badge bjlg-badge-bg-rose',
                        text: __('Critique', 'backup-jlg')
                    }).appendTo($title);
                } else if (item.badge === 'warning') {
                    $('<span/>', {
                        'class': 'bjlg-badge bjlg-badge-bg-amber',
                        text: __('À surveiller', 'backup-jlg')
                    }).appendTo($title);
                }
                if (item.details.length) {
                    $('<span/>', { 'class': 'bjlg-card__list-meta', text: item.details.join(' • ') }).appendTo($item);
                }
                if (item.errors.length) {
                    $('<span/>', { 'class': 'bjlg-card__list-error', text: item.errors.join(' • ') }).appendTo($item);
                }
                const hasSettings = item.actions.settings && item.actions.settings !== '';
                const hasTest = item.actions.test && item.actions.test !== '';
                if (hasSettings || hasTest) {
                    const $actions = $('<div/>', { 'class': 'bjlg-card__list-meta bjlg-card__list-meta--cta' });
                    if (hasSettings) {
                        $('<a/>', {
                            href: item.actions.settings,
                            'class': 'button button-secondary button-small',
                            text: __('Ouvrir les réglages', 'backup-jlg')
                        }).appendTo($actions);
                    }
                    if (hasTest) {
                        $('<a/>', {
                            href: item.actions.test,
                            'class': 'button button-small',
                            text: __('Tester la connexion', 'backup-jlg')
                        }).appendTo($actions);
                    }
                    $actions.appendTo($item);
                }
                $list.append($item);
            });
        }
    };

    const updateReliability = function(reliability) {
        const $section = $overview.find('[data-role="reliability"]');
        if (!$section.length) {
            return;
        }

        reliability = reliability || {};

        const intent = (reliability.intent || 'info').toString().toLowerCase();
        $section.attr('data-intent', intent);

        const hasScore = reliability.score !== undefined && reliability.score !== null && reliability.score !== '';
        setField('reliability_score_value', hasScore ? formatNumber(reliability.score) : '');
        setField('reliability_score_label', reliability.score_label || '');
        setField('reliability_level', reliability.level || '');
        setField('reliability_description', reliability.description || '');
        setField('reliability_caption', reliability.caption || '');

        const $pillars = $section.find('[data-role="reliability-pillars"]');
        if ($pillars.length) {
            $pillars.empty();
            const pillars = Array.isArray(reliability.pillars) ? reliability.pillars : [];
            if (!pillars.length) {
                $('<li/>', {
                    'class': 'bjlg-reliability-pillar bjlg-reliability-pillar--empty',
                    text: __('Les signaux clés apparaîtront après vos premières sauvegardes.', 'backup-jlg')
                }).appendTo($pillars);
            } else {
                pillars.forEach(function(pillar) {
                    if (!pillar || typeof pillar !== 'object') {
                        return;
                    }

                    const icon = pillar.icon || 'dashicons-admin-generic';
                    const intentValue = (pillar.intent || pillar.status || 'info').toString().toLowerCase();
                    const $item = $('<li/>', {
                        'class': 'bjlg-reliability-pillar',
                        'data-intent': intentValue
                    });

                    $('<span/>', {
                        'class': 'bjlg-reliability-pillar__icon dashicons ' + icon,
                        'aria-hidden': 'true'
                    }).appendTo($item);

                    const $content = $('<div/>', { 'class': 'bjlg-reliability-pillar__content' }).appendTo($item);

                    if (pillar.label) {
                        $('<span/>', {
                            'class': 'bjlg-reliability-pillar__label',
                            text: pillar.label
                        }).appendTo($content);
                    }

                    if (pillar.message) {
                        $('<span/>', {
                            'class': 'bjlg-reliability-pillar__message',
                            text: pillar.message
                        }).appendTo($content);
                    }

                    $pillars.append($item);
                });
            }
        }

        const $actions = $section.find('[data-role="reliability-actions"]');
        if ($actions.length) {
            $actions.empty();
            const recommendations = Array.isArray(reliability.recommendations) ? reliability.recommendations : [];
            if (!recommendations.length) {
                $actions.attr('hidden', 'hidden');
            } else {
                $actions.removeAttr('hidden');
                recommendations.forEach(function(reco) {
                    if (!reco || typeof reco !== 'object' || !reco.label) {
                        return;
                    }

                    const classes = ['button'];
                    if (reco.intent === 'primary') {
                        classes.push('button-primary');
                    } else {
                        classes.push('button-secondary');
                    }

                    $('<a/>', {
                        'class': classes.join(' '),
                        'href': reco.url || '#',
                        text: reco.label
                    }).appendTo($actions);
                });
            }
        }
    };

    const updateQueues = function(queues) {
        const $section = $overview.find('.bjlg-queues');
        if (!$section.length || !queues || typeof queues !== 'object') {
            return;
        }

        Object.keys(queues).forEach(function(key) {
            if (!Object.prototype.hasOwnProperty.call(queues, key)) {
                return;
            }

            const queue = queues[key] || {};
            const $card = $section.find('.bjlg-queue-card[data-queue="' + key + '"]');
            if (!$card.length) {
                return;
            }

            const total = Number(queue.total || 0);
            const totalLabel = sprintf(_n('%s entrée', '%s entrées', total), formatNumber(total));
            $card.find('[data-field="total"]').text(totalLabel);

            const counts = queue.status_counts || {};
            const pending = Number(counts.pending || 0);
            const retry = Number(counts.retry || 0);
            const failed = Number(counts.failed || 0);
            const countsText = sprintf(__('En attente : %1$s • Nouvel essai : %2$s • Échecs : %3$s', 'backup-jlg'), formatNumber(pending), formatNumber(retry), formatNumber(failed));
            $card.find('[data-field="status-counts"]').text(countsText);

            const $nextField = $card.find('[data-field="next"]');
            if (queue.next_attempt_relative) {
                $nextField.text(sprintf(__('Prochain passage %s', 'backup-jlg'), queue.next_attempt_relative));
            } else {
                $nextField.text(__('Aucun traitement planifié.', 'backup-jlg'));
            }

            const $oldestField = $card.find('[data-field="oldest"]');
            if (queue.oldest_entry_relative) {
                $oldestField.text(sprintf(__('Entrée la plus ancienne %s', 'backup-jlg'), queue.oldest_entry_relative));
            } else {
                $oldestField.text('');
            }

            const sla = queue.sla || null;
            const $sla = $card.find('[data-field="sla"]');
            if ($sla.length) {
                if (sla && typeof sla === 'object') {
                    const $caption = $sla.find('.bjlg-queue-card__metrics-caption');
                    if (sla.updated_relative) {
                        if ($caption.length) {
                            $caption.text(sprintf(__('Mise à jour %s', 'backup-jlg'), sla.updated_relative));
                        } else {
                            $('<p/>', {
                                'class': 'bjlg-queue-card__metrics-caption',
                                text: sprintf(__('Mise à jour %s', 'backup-jlg'), sla.updated_relative)
                            }).prependTo($sla);
                        }
                    } else if ($caption.length) {
                        $caption.remove();
                    }

                    const $list = $sla.find('.bjlg-queue-card__metrics-list');
                    if ($list.length) {
                        $list.empty();

                        if (sla.pending_average) {
                            $('<li/>', { text: sprintf(__('Âge moyen en file : %s', 'backup-jlg'), sla.pending_average) }).appendTo($list);
                        }

                        if (sla.pending_oldest) {
                            $('<li/>', { text: sprintf(__('Plus ancien : %s', 'backup-jlg'), sla.pending_oldest) }).appendTo($list);
                        }

                        if (sla.pending_over_threshold) {
                            $('<li/>', { text: sprintf(__('%s entrée(s) au-delà du seuil', 'backup-jlg'), formatNumber(Number(sla.pending_over_threshold))) }).appendTo($list);
                        }

                        if (sla.pending_destinations) {
                            $('<li/>', { text: sprintf(__('Destinations impactées : %s', 'backup-jlg'), sla.pending_destinations) }).appendTo($list);
                        }

                        if (sla.throughput_average) {
                            $('<li/>', { text: sprintf(__('Durée moyenne de purge : %s', 'backup-jlg'), sla.throughput_average) }).appendTo($list);
                        }

                        if (sla.duration_peak) {
                            $('<li/>', { text: sprintf(__('Durée maximale récente : %s', 'backup-jlg'), sla.duration_peak) }).appendTo($list);
                        }

                        if (sla.duration_last) {
                            $('<li/>', { text: sprintf(__('Dernière purge traitée en %s', 'backup-jlg'), sla.duration_last) }).appendTo($list);
                        }

                        if (sla.throughput_last_completion_relative) {
                            $('<li/>', { text: sprintf(__('Dernière purge réussie %s', 'backup-jlg'), sla.throughput_last_completion_relative) }).appendTo($list);
                        }

                        if (sla.failures_total) {
                            $('<li/>', { text: sprintf(__('Échecs cumulés : %s', 'backup-jlg'), formatNumber(Number(sla.failures_total))) }).appendTo($list);
                        }

                        if (sla.last_failure_relative && sla.last_failure_message) {
                            $('<li/>', { text: sprintf(__('Dernier échec %1$s : %2$s', 'backup-jlg'), sla.last_failure_relative, sla.last_failure_message) }).appendTo($list);
                        } else if (sla.last_failure_relative) {
                            $('<li/>', { text: sprintf(__('Dernier échec %s', 'backup-jlg'), sla.last_failure_relative) }).appendTo($list);
                        }

                        if (sla.forecast_label) {
                            $('<li/>', { text: sla.forecast_label }).appendTo($list);
                        }

                        if (sla.forecast_projected_relative) {
                            $('<li/>', { text: sprintf(__('Projection de vidage %s', 'backup-jlg'), sla.forecast_projected_relative) }).appendTo($list);
                        }

                        if (Array.isArray(sla.forecast_destinations) && sla.forecast_destinations.length) {
                            sla.forecast_destinations.forEach(function(destination) {
                                if (!destination || typeof destination !== 'object') {
                                    return;
                                }

                                const parts = [destination.label || destination.id || ''];
                                if (destination.forecast_label) {
                                    parts.push(destination.forecast_label);
                                }
                                if (destination.projected_relative) {
                                    parts.push(sprintf(__('vidage %s', 'backup-jlg'), destination.projected_relative));
                                }

                                $('<li/>', { text: parts.filter(Boolean).join(' • ') }).appendTo($list);
                            });
                        }
                    }
                } else {
                    $sla.remove();
                }
            }

            if (sla && sla.saturation_warning) {
                $card.attr('data-saturation-warning', 'true');
            } else {
                $card.removeAttr('data-saturation-warning');
            }

            const $entries = $card.find('[data-role="entries"]');
            if (!$entries.length) {
                return;
            }

            $entries.empty();

            const entries = Array.isArray(queue.entries) ? queue.entries : [];
            if (!entries.length) {
                $('<li/>', {
                    'class': 'bjlg-queue-card__entry bjlg-queue-card__entry--empty',
                    text: __('Aucune entrée en attente.', 'backup-jlg')
                }).appendTo($entries);
                return;
            }

            entries.forEach(function(entry) {
                if (!entry || typeof entry !== 'object') {
                    return;
                }

                const $entry = $('<li/>', { 'class': 'bjlg-queue-card__entry' });
                if (entry.id) {
                    $entry.attr('data-entry-id', entry.id);
                }
                if (entry.file) {
                    $entry.attr('data-entry-file', entry.file);
                }
                if (entry.severity) {
                    $entry.attr('data-severity', entry.severity);
                }

                const $header = $('<div/>', { 'class': 'bjlg-queue-card__entry-header' }).appendTo($entry);
                $('<span/>', { 'class': 'bjlg-queue-card__entry-title', text: entry.title || '' }).appendTo($header);

                if (entry.status_label) {
                    $('<span/>', {
                        'class': 'bjlg-queue-card__entry-status bjlg-queue-card__entry-status--' + (entry.status_intent || 'info'),
                        text: entry.status_label
                    }).appendTo($header);
                }

                const $primaryMeta = $('<p/>', { 'class': 'bjlg-queue-card__entry-meta' }).appendTo($entry);
                if (entry.severity_label) {
                    $('<span/>', {
                        'class': 'bjlg-queue-card__entry-severity bjlg-queue-card__entry-severity--' + (entry.severity_intent || 'info'),
                        text: sprintf(__('Gravité : %s', 'backup-jlg'), entry.severity_label)
                    }).appendTo($primaryMeta);
                }
                if (entry.attempt_label) {
                    $('<span/>', { text: entry.attempt_label }).appendTo($primaryMeta);
                }

                const $timestamps = $('<p/>', { 'class': 'bjlg-queue-card__entry-meta', 'data-field': 'timestamps' }).appendTo($entry);
                if (entry.created_relative) {
                    $('<span/>', { text: sprintf(__('Créée %s', 'backup-jlg'), entry.created_relative) }).appendTo($timestamps);
                }
                if (entry.next_attempt_relative) {
                    $('<span/>', { text: sprintf(__('Rejouée %s', 'backup-jlg'), entry.next_attempt_relative) }).appendTo($timestamps);
                }

                if (entry.details && entry.details.destinations) {
                    $('<p/>', {
                        'class': 'bjlg-queue-card__entry-meta',
                        text: sprintf(__('Destinations : %s', 'backup-jlg'), entry.details.destinations)
                    }).appendTo($entry);
                }

                if (entry.details && entry.details.quiet_until_relative) {
                    $('<p/>', {
                        'class': 'bjlg-queue-card__entry-flag',
                        'data-field': 'quiet-until',
                        text: sprintf(__('Silence actif jusqu’à %s', 'backup-jlg'), entry.details.quiet_until_relative)
                    }).appendTo($entry);
                }

                if (entry.details && entry.details.acknowledged_label) {
                    $('<p/>', {
                        'class': 'bjlg-queue-card__entry-meta',
                        'data-field': 'acknowledged',
                        text: entry.details.acknowledged_label
                    }).appendTo($entry);
                }

                if (entry.details && entry.details.resolved_label) {
                    $('<p/>', {
                        'class': 'bjlg-queue-card__entry-meta',
                        'data-field': 'resolved',
                        text: entry.details.resolved_label
                    }).appendTo($entry);
                }

                if (entry.details && (entry.details.escalation_channels || entry.details.escalation_scenario)) {
                    const escalationParts = [];

                    if (entry.details.escalation_scenario) {
                        escalationParts.push(sprintf(__('Escalade séquentielle : %s', 'backup-jlg'), entry.details.escalation_scenario));
                    } else if (entry.details.escalation_channels) {
                        escalationParts.push(sprintf(__('Escalade vers %s', 'backup-jlg'), entry.details.escalation_channels));
                    }

                    if (entry.details.escalation_scenario && entry.details.escalation_channels) {
                        escalationParts.push(sprintf(__('Canaux activés : %s', 'backup-jlg'), entry.details.escalation_channels));
                    }

                    if (entry.details.escalation_delay) {
                        escalationParts.push(sprintf(__('délai : %s', 'backup-jlg'), entry.details.escalation_delay));
                    }

                    if (entry.details.escalation_next_relative) {
                        escalationParts.push(sprintf(__('prochaine tentative %s', 'backup-jlg'), entry.details.escalation_next_relative));
                    }

                    $('<p/>', {
                        'class': 'bjlg-queue-card__entry-flag',
                        'data-field': 'escalation',
                        text: escalationParts.join(' • ')
                    }).appendTo($entry);
                }

                if (entry.message) {
                    $('<p/>', { 'class': 'bjlg-queue-card__entry-message', text: entry.message }).appendTo($entry);
                }

                if (entry.details && entry.details.resolution_notes) {
                    $('<p/>', {
                        'class': 'bjlg-queue-card__entry-message',
                        'data-field': 'resolution-notes',
                        text: entry.details.resolution_notes
                    }).appendTo($entry);
                }

                const $actions = $('<div/>', { 'class': 'bjlg-queue-card__entry-actions' }).appendTo($entry);
                if (key === 'notifications' && entry.id) {
                    const $ackButton = $('<button/>', {
                        type: 'button',
                        'class': 'button button-secondary button-small',
                        'data-queue-action': 'acknowledge-notification',
                        'data-entry-id': entry.id,
                        text: __('Accuser réception', 'backup-jlg')
                    }).appendTo($actions);

                    if (entry.acknowledged) {
                        $ackButton.prop('disabled', true).attr('aria-disabled', 'true');
                    }

                    const $resolveButton = $('<button/>', {
                        type: 'button',
                        'class': 'button button-secondary button-small',
                        'data-queue-action': 'resolve-notification',
                        'data-entry-id': entry.id,
                        text: __('Clore', 'backup-jlg')
                    }).appendTo($actions);

                    if (entry.resolved) {
                        $resolveButton.prop('disabled', true).attr('aria-disabled', 'true');
                    }

                    $('<button/>', {
                        type: 'button',
                        'class': 'button button-secondary button-small',
                        'data-queue-action': 'retry-notification',
                        'data-entry-id': entry.id,
                        text: __('Relancer', 'backup-jlg')
                    }).appendTo($actions);
                    $('<button/>', {
                        type: 'button',
                        'class': 'button button-link-delete',
                        'data-queue-action': 'clear-notification',
                        'data-entry-id': entry.id,
                        text: __('Ignorer', 'backup-jlg')
                    }).appendTo($actions);
                } else if (key === 'remote_purge' && entry.file) {
                    $('<button/>', {
                        type: 'button',
                        'class': 'button button-secondary button-small',
                        'data-queue-action': 'retry-remote-purge',
                        'data-file': entry.file,
                        text: __('Relancer la purge', 'backup-jlg')
                    }).appendTo($actions);
                    $('<button/>', {
                        type: 'button',
                        'class': 'button button-link-delete',
                        'data-queue-action': 'clear-remote-purge',
                        'data-file': entry.file,
                        text: __('Retirer de la file', 'backup-jlg')
                    }).appendTo($actions);
                }

                $entries.append($entry);
            });
        });
    };

    const updateAlerts = function(alerts) {
        const $container = $overview.find('[data-role="alerts"]');
        if (!$container.length) {
            return;
        }

        $container.empty();

        if (!Array.isArray(alerts) || !alerts.length) {
            return;
        }

        alerts.forEach(function(alert) {
            const type = (alert.type || 'info').toLowerCase();
            const $alert = $('<div/>', {
                'class': 'bjlg-alert bjlg-alert--' + type
            });

            const $content = $('<div/>', { 'class': 'bjlg-alert__content' });
            if (alert.title) {
                $('<strong/>', { 'class': 'bjlg-alert__title', text: alert.title }).appendTo($content);
            }
            if (alert.message) {
                $('<p/>', { 'class': 'bjlg-alert__message', text: alert.message }).appendTo($content);
            }
            $alert.append($content);

            if (alert.action && alert.action.url && alert.action.label) {
                $('<a/>', {
                    'class': 'bjlg-alert__action button button-secondary',
                    'href': alert.action.url,
                    'text': alert.action.label
                }).appendTo($alert);
            }

            $container.append($alert);
        });
    };

    const updateOnboarding = function(resources) {
        const $container = $overview.find('[data-role="onboarding"]');
        if (!$container.length) {
            return;
        }

        const $list = $container.find('.bjlg-onboarding__list');
        if (!$list.length) {
            return;
        }

        $list.empty();

        if (!Array.isArray(resources) || !resources.length) {
            $container.addClass('bjlg-hidden');
            return;
        }

        $container.removeClass('bjlg-hidden');

        resources.forEach(function(resource) {
            const $item = $('<li/>', { 'class': 'bjlg-onboarding__item' });
            const $content = $('<div/>', { 'class': 'bjlg-onboarding__content' }).appendTo($item);

            if (resource.title) {
                $('<strong/>', { 'class': 'bjlg-onboarding__label', text: resource.title }).appendTo($content);
            }

            if (resource.description) {
                $('<p/>', { 'class': 'bjlg-onboarding__description', text: resource.description }).appendTo($content);
            }

            if (resource.command) {
                $('<code/>', {
                    'class': 'bjlg-onboarding__command',
                    'data-command': resource.command,
                    text: resource.command
                }).appendTo($content);
            }

            if (resource.url) {
                $('<a/>', {
                    'class': 'bjlg-onboarding__action button button-secondary',
                    'href': resource.url,
                    'text': resource.action_label || 'Ouvrir',
                    'target': '_blank',
                    'rel': 'noopener noreferrer'
                }).appendTo($item);
            }

            $list.append($item);
        });
    };

    const getHistoryTrendDataset = function() {
        const history = state.metrics.history || {};
        const stats = history.stats || {};
        const timeline = Array.isArray(stats.timeline) ? stats.timeline : [];
        if (timeline.length < 2) {
            return null;
        }

        const labels = [];
        const successful = [];
        const failed = [];

        timeline.forEach(function(entry) {
            labels.push(entry.label || entry.date || entry.day || '');
            successful.push(Number(entry.success || entry.successful || entry.successes || 0));
            failed.push(Number(entry.failed || entry.failures || 0));
        });

        const total = successful.reduce(function(sum, value) {
            return sum + (Number.isFinite(value) ? value : 0);
        }, 0) + failed.reduce(function(sum, value) {
            return sum + (Number.isFinite(value) ? value : 0);
        }, 0);

        if (total === 0) {
            return null;
        }

        return { labels: labels, successful: successful, failed: failed };
    };

    const getStorageTrendDataset = function() {
        const storage = state.metrics.storage || {};
        const trend = Array.isArray(storage.trend) ? storage.trend : [];
        if (trend.length < 2) {
            return null;
        }

        const labels = [];
        const usage = [];

        trend.forEach(function(entry) {
            labels.push(entry.label || entry.date || '');
            const bytes = Number(entry.bytes || entry.size || entry.value || entry.total_bytes || 0);
            if (Number.isFinite(bytes)) {
                usage.push(bytes / (1024 * 1024));
            } else {
                usage.push(0);
            }
        });

        const total = usage.reduce(function(sum, value) {
            return sum + (Number.isFinite(value) ? value : 0);
        }, 0);

        if (total === 0) {
            return null;
        }

        return { labels: labels, usage: usage };
    };

    const getRemotePurgeForecastDataset = function() {
        const queues = state.metrics.queues || {};
        const remote = queues.remote_purge || {};
        const sla = remote.sla || {};
        const forecast = sla.quota_forecast || {};
        const destinations = Array.isArray(forecast.destinations) ? forecast.destinations : [];

        if (!destinations.length) {
            return null;
        }

        const historyCandidates = destinations.filter(function(destination) {
            return Array.isArray(destination.history) && destination.history.length >= 2;
        }).slice(0, 3);

        if (!historyCandidates.length) {
            return null;
        }

        const labels = [];
        const datasets = [];
        const palette = ['#2563eb', '#f97316', '#0ea5e9'];

        historyCandidates.forEach(function(destination, index) {
            const history = destination.history;
            const data = [];

            history.forEach(function(point, pointIndex) {
                const ratio = Number(point.ratio);
                const normalized = Number.isFinite(ratio) ? Math.max(0, Math.min(1, ratio)) * 100 : null;
                data.push(normalized);

                if (!labels[pointIndex]) {
                    let label = point.label || '';
                    const timestamp = Number(point.timestamp || 0);
                    if (!label && timestamp) {
                        const date = new Date(timestamp * 1000);
                        const datePart = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                        const timePart = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
                        label = datePart + ' ' + timePart;
                    }
                    if (!label) {
                        label = sprintf(__('Échantillon %s', 'backup-jlg'), pointIndex + 1);
                    }
                    labels[pointIndex] = label;
                }
            });

            datasets.push({
                label: destination.label || destination.id || sprintf(__('Destination %s', 'backup-jlg'), index + 1),
                data: data,
                borderColor: palette[index % palette.length],
                backgroundColor: 'rgba(0,0,0,0)',
                tension: 0.3,
                fill: false,
                pointRadius: 3,
                spanGaps: true,
            });
        });

        return {
            labels: labels,
            datasets: datasets,
            threshold: Number(forecast.threshold_percent || 0),
        };
    };

    const updateHistoryChart = function() {
        const $card = $overview.find('[data-chart="history-trend"]');
        if (!$card.length) {
            return;
        }

        const dataset = getHistoryTrendDataset();

        if (typeof window.Chart === 'undefined' || !dataset) {
            destroyChart('historyTrend');
            setChartEmptyState($card, true);
            return;
        }

        setChartEmptyState($card, false);

        const canvas = $card.find('canvas')[0];
        const ctx = canvas ? canvas.getContext('2d') : null;
        if (!ctx) {
            return;
        }

        if (!state.charts.historyTrend) {
            state.charts.historyTrend = new window.Chart(ctx, {
                type: 'line',
                data: {
                    labels: dataset.labels,
                    datasets: [
                        {
                            label: 'Succès',
                            data: dataset.successful,
                            borderColor: '#1d976c',
                            backgroundColor: 'rgba(29, 151, 108, 0.15)',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3
                        },
                        {
                            label: 'Échecs',
                            data: dataset.failed,
                            borderColor: '#d63638',
                            backgroundColor: 'rgba(214, 54, 56, 0.1)',
                            tension: 0.3,
                            fill: false,
                            pointRadius: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        } else {
            const chart = state.charts.historyTrend;
            chart.data.labels = dataset.labels;
            chart.data.datasets[0].data = dataset.successful;
            chart.data.datasets[1].data = dataset.failed;
            chart.update();
        }
    };

    const updateStorageChart = function() {
        const $card = $overview.find('[data-chart="storage-trend"]');
        if (!$card.length) {
            return;
        }

        const dataset = getStorageTrendDataset();

        if (typeof window.Chart === 'undefined' || !dataset) {
            destroyChart('storageTrend');
            setChartEmptyState($card, true);
            return;
        }

        setChartEmptyState($card, false);

        const canvas = $card.find('canvas')[0];
        const ctx = canvas ? canvas.getContext('2d') : null;
        if (!ctx) {
            return;
        }

        const usageLabel = 'Utilisation (Mo)';

        if (!state.charts.storageTrend) {
            state.charts.storageTrend = new window.Chart(ctx, {
                type: 'line',
                data: {
                    labels: dataset.labels,
                    datasets: [
                        {
                            label: usageLabel,
                            data: dataset.usage,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.15)',
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
                                }
                            }
                        }
                    }
                }
            });
        } else {
            const chart = state.charts.storageTrend;
            chart.data.labels = dataset.labels;
            chart.data.datasets[0].data = dataset.usage;
            chart.update();
        }
    };

    const updateRemotePurgeForecastChart = function() {
        const $card = $overview.find('[data-chart="remote-purge-forecast"]');
        if (!$card.length) {
            return;
        }

        const dataset = getRemotePurgeForecastDataset();

        if (typeof window.Chart === 'undefined' || !dataset) {
            destroyChart('remotePurgeForecast');
            setChartEmptyState($card, true);
            return;
        }

        setChartEmptyState($card, false);

        const canvas = $card.find('canvas')[0];
        const ctx = canvas ? canvas.getContext('2d') : null;
        if (!ctx) {
            return;
        }

        const thresholdData = dataset.labels.map(function() {
            return dataset.threshold;
        });

        const thresholdLabel = sprintf(__('Seuil %s%%', 'backup-jlg'), Number(dataset.threshold).toLocaleString(undefined, { maximumFractionDigits: 1 }));

        if (!state.charts.remotePurgeForecast) {
            state.charts.remotePurgeForecast = new window.Chart(ctx, {
                type: 'line',
                data: {
                    labels: dataset.labels,
                    datasets: dataset.datasets.concat([
                        {
                            label: thresholdLabel,
                            data: thresholdData,
                            borderColor: '#f97316',
                            borderDash: [6, 4],
                            borderWidth: 1,
                            pointRadius: 0,
                            fill: false,
                        }
                    ]),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    if (context.parsed.y === null || Number.isNaN(context.parsed.y)) {
                                        return '';
                                    }
                                    return context.dataset.label + ': ' + context.parsed.y.toLocaleString(undefined, { maximumFractionDigits: 1 }) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString(undefined, { maximumFractionDigits: 0 }) + '%';
                                }
                            }
                        }
                    }
                }
            });
        } else {
            const chart = state.charts.remotePurgeForecast;
            chart.data.labels = dataset.labels;
            chart.data.datasets = dataset.datasets.concat([
                {
                    label: thresholdLabel,
                    data: thresholdData,
                    borderColor: '#f97316',
                    borderDash: [6, 4],
                    borderWidth: 1,
                    pointRadius: 0,
                    fill: false,
                }
            ]);
            chart.update();
        }
    };

    const ensureChartsReady = typeof window.bjlgEnsureChart === 'function'
        ? window.bjlgEnsureChart
        : function() { return Promise.resolve(); };

    const hasChartTargets = function() {
        return $overview.find('[data-chart] canvas').length > 0;
    };

    const updateCharts = function() {
        if (!hasChartTargets()) {
            destroyChart('historyTrend');
            destroyChart('storageTrend');
            destroyChart('remotePurgeForecast');
            $overview.find('.bjlg-chart-card').each(function() {
                setChartEmptyState($(this), true);
            });
            return;
        }

        ensureChartsReady()
            .then(function() {
                updateHistoryChart();
                updateStorageChart();
                updateRemotePurgeForecastChart();
            })
            .catch(function() {
                destroyChart('historyTrend');
                destroyChart('storageTrend');
                destroyChart('remotePurgeForecast');
                $overview.find('.bjlg-chart-card').each(function() {
                    setChartEmptyState($(this), true);
                });
            });
    };

    const $queueDelegateRoot = $overview.length ? $overview : $(document);

    $queueDelegateRoot.on('click', '[data-queue-action]', function(event) {
        event.preventDefault();

        const $button = $(this);
        const actionKey = $button.data('queueAction');
        const config = queueActionMap[actionKey];

        if (!config) {
            return;
        }

        const $container = $overview.length ? $overview : $button.closest('.bjlg-dashboard-overview');

        if (!$container.length || !ajaxData.ajax_url || !ajaxData.nonce) {
            announce(__('Impossible de contacter le serveur. Rechargez la page.', 'backup-jlg'), 'assertive');
            return;
        }

        if ($button.prop('disabled')) {
            return;
        }

        const value = $button.data(config.param);
        if (!value) {
            announce(__('Action impossible : données manquantes.', 'backup-jlg'), 'assertive');
            return;
        }

        const payload = {
            action: config.action,
            nonce: ajaxData.nonce
        };
        payload[config.param] = value;

        if (config.channelParam) {
            const channelValue = $button.data(config.channelParam) || $button.data('channel');
            if (channelValue) {
                payload[config.channelParam] = channelValue;
            }
        }

        if (config.promptField) {
            const promptMessage = typeof config.prompt === 'string'
                ? config.prompt
                : __('Ajoutez des notes (optionnel)', 'backup-jlg');
            const defaultValue = $button.data('defaultNotes') || '';
            const userNotes = window.prompt(promptMessage, defaultValue);

            if (userNotes === null) {
                return;
            }

            payload[config.promptField] = userNotes;
        }

        $button.prop('disabled', true).attr('aria-busy', 'true');

        $.ajax({
            url: ajaxData.ajax_url,
            method: 'POST',
            data: payload
        })
            .done(function(response) {
                if (response && response.success) {
                    const message = response.data && response.data.message
                        ? response.data.message
                        : __('Action effectuée.', 'backup-jlg');
                    announce(message, 'assertive');

                    if (response.data && response.data.metrics && window.bjlgDashboard && typeof window.bjlgDashboard.updateMetrics === 'function') {
                        window.bjlgDashboard.updateMetrics(response.data.metrics);
                    }
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : __('Impossible de traiter la demande.', 'backup-jlg');
                    announce(errorMessage, 'assertive');
                }
            })
            .fail(function() {
                const errorMessage = __('Erreur de communication avec le serveur.', 'backup-jlg');
                announce(errorMessage, 'assertive');
            })
            .always(function() {
                $button.prop('disabled', false).removeAttr('aria-busy');
            });
    });

    updateSummary(state.metrics.summary || {});
    updateReliability(state.metrics.reliability || {});
    updateAlerts(state.metrics.alerts || []);
    updateOnboarding(state.metrics.onboarding || []);
    updateActions(state.metrics);
    updateRemoteStorage(state.metrics.storage || {});
    updateQueues(state.metrics.queues || {});
    updateCharts();

    lastAnnouncement = buildAnnouncement(state.metrics);
    readyForAnnouncements = true;

    window.bjlgDashboard = window.bjlgDashboard || {};
    window.bjlgDashboard.updateMetrics = function(nextMetrics) {
        if (!nextMetrics || typeof nextMetrics !== 'object') {
            return;
        }

        state.metrics = $.extend(true, {}, state.metrics, nextMetrics);
        updateSummary(state.metrics.summary || {});
        updateReliability(state.metrics.reliability || {});
        updateAlerts(state.metrics.alerts || []);
        updateOnboarding(state.metrics.onboarding || []);
        updateActions(state.metrics);
        updateRemoteStorage(state.metrics.storage || {});
        updateQueues(state.metrics.queues || {});
        updateCharts();

        const announcement = buildAnnouncement(state.metrics);
        if (readyForAnnouncements && announcement && announcement !== lastAnnouncement) {
            announce(announcement);
        }
        lastAnnouncement = announcement || '';

        try {
            $overview.attr('data-bjlg-dashboard', JSON.stringify(state.metrics));
        } catch (error) {
            // Ignored
        }
    };
})();
});
