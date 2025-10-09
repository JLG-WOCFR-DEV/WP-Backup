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
            $overview.find('.bjlg-chart-card').each(function() {
                setChartEmptyState($(this), true);
            });
            return;
        }

        ensureChartsReady()
            .then(function() {
                updateHistoryChart();
                updateStorageChart();
            })
            .catch(function() {
                destroyChart('historyTrend');
                destroyChart('storageTrend');
                $overview.find('.bjlg-chart-card').each(function() {
                    setChartEmptyState($(this), true);
                });
            });
    };

    updateSummary(state.metrics.summary || {});
    updateAlerts(state.metrics.alerts || []);
    updateOnboarding(state.metrics.onboarding || []);
    updateActions(state.metrics);
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
        updateAlerts(state.metrics.alerts || []);
        updateOnboarding(state.metrics.onboarding || []);
        updateActions(state.metrics);
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
