jQuery(document).ready(function($) {

    // --- DASHBOARD OVERVIEW ---
    (function setupDashboardOverview() {
        const $overview = $('.bjlg-dashboard-overview');
        if (!$overview.length) {
            return;
        }

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

        const updateCharts = function() {
            updateHistoryChart();
            updateStorageChart();
        };

        updateSummary(state.metrics.summary || {});
        updateAlerts(state.metrics.alerts || []);
        updateOnboarding(state.metrics.onboarding || []);
        updateActions(state.metrics);
        updateCharts();

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

            try {
                $overview.attr('data-bjlg-dashboard', JSON.stringify(state.metrics));
            } catch (error) {
                // Ignored
            }
        };
    })();

    // --- GESTIONNAIRE DE PLANIFICATION ---
    (function setupScheduleManager() {
        const $scheduleForm = $('#bjlg-schedule-form');
        if (!$scheduleForm.length || typeof bjlg_ajax !== 'object') {
            return;
        }

        const $scheduleList = $scheduleForm.find('.bjlg-schedule-list');
        const $template = $scheduleList.find('.bjlg-schedule-item--template').first();
        if (!$template.length) {
            return;
        }

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

        const defaultNextRunSummary = { next_run_formatted: 'Non planifié', next_run_relative: '' };
        const defaultScheduleData = parseJSONAttr($scheduleForm.attr('data-default-schedule')) || {};
        const initialNextRuns = parseJSONAttr($scheduleForm.attr('data-next-runs')) || {};
        let newScheduleCounter = 0;

        const destinationLabels = {};
        const $timeline = $('#bjlg-schedule-timeline');
        const initialSchedules = parseJSONAttr($scheduleForm.attr('data-schedules')) || [];
        const state = {
            schedules: Array.isArray(initialSchedules) ? initialSchedules : [],
            nextRuns: initialNextRuns,
            timelineView: 'week',
            timezone: $timeline.length ? ($timeline.data('timezone') || '').toString() : '',
            timezoneOffset: $timeline.length ? parseFloat($timeline.data('offset')) || 0 : 0
        };
        const timelineRanges = { week: 7, month: 30 };
        const statusLabels = {
            active: { label: 'Active', className: 'bjlg-status-badge--active' },
            pending: { label: 'En attente', className: 'bjlg-status-badge--pending' },
            paused: { label: 'En pause', className: 'bjlg-status-badge--paused' }
        };

        $scheduleForm.find('[data-field="secondary_destinations"]').each(function() {
            const value = ($(this).val() || '').toString();
            if (!value || destinationLabels[value]) {
                return;
            }
            const labelText = ($(this).closest('label').text() || value).replace(/\s+/g, ' ').trim();
            destinationLabels[value] = labelText || value;
        });

        const $feedback = $('#bjlg-schedule-feedback');

        function parseJSONAttr(raw) {
            if (typeof raw !== 'string' || raw.trim() === '') {
                return null;
            }
            try {
                return JSON.parse(raw);
            } catch (error) {
                return null;
            }
        }

        function resetScheduleFeedback() {
            if (!$feedback.length) {
                return;
            }
            $feedback.removeClass('notice-success notice-error notice-info')
                .hide()
                .empty()
                .removeAttr('role');
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
            } else if (type === 'info') {
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

        function normalizeErrorList(raw) {
            const messages = [];
            const seen = new Set();

            function push(value) {
                if (typeof value !== 'string') {
                    return;
                }
                const trimmed = value.trim();
                if (!trimmed || seen.has(trimmed)) {
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
                    push(value);
                    return;
                }
                if (Array.isArray(value)) {
                    value.forEach(walk);
                    return;
                }
                if (typeof value === 'object') {
                    if (typeof value.message === 'string') {
                        push(value.message);
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

        function safeJson(value) {
            try {
                return JSON.stringify(value);
            } catch (error) {
                return null;
            }
        }

        function getScheduleStatusKey(schedule, info) {
            const recurrence = (schedule && schedule.recurrence ? schedule.recurrence : 'disabled').toString();
            if (recurrence === 'disabled') {
                return 'paused';
            }

            if (info && typeof info.enabled === 'boolean' && !info.enabled) {
                return 'paused';
            }

            let rawNextRun = info && Object.prototype.hasOwnProperty.call(info, 'next_run') ? info.next_run : null;
            if (rawNextRun === null && info && Object.prototype.hasOwnProperty.call(info, 'nextRun')) {
                rawNextRun = info.nextRun;
            }

            const nextRunTimestamp = parseInt(rawNextRun, 10);
            if (Number.isFinite(nextRunTimestamp) && nextRunTimestamp > 0) {
                return 'active';
            }

            return 'pending';
        }

        function getStatusDescriptor(status) {
            return statusLabels[status] || statusLabels.pending;
        }

        function parseTimeParts(schedule) {
            const raw = schedule && schedule.time ? schedule.time.toString() : '23:59';
            const parts = raw.split(':');
            const hour = parseInt(parts[0], 10);
            const minute = parseInt(parts[1], 10);

            return {
                hour: Number.isFinite(hour) ? Math.min(Math.max(hour, 0), 23) : 23,
                minute: Number.isFinite(minute) ? Math.min(Math.max(minute, 0), 59) : 59
            };
        }

        function getIntervalMs(recurrence) {
            switch ((recurrence || '').toString()) {
                case 'hourly':
                    return 60 * 60 * 1000;
                case 'twice_daily':
                    return 12 * 60 * 60 * 1000;
                case 'daily':
                    return 24 * 60 * 60 * 1000;
                case 'weekly':
                    return 7 * 24 * 60 * 60 * 1000;
                default:
                    return 0;
            }
        }

        function addMonths(date, count) {
            const next = new Date(date.getTime());
            next.setMonth(next.getMonth() + count);
            return next;
        }

        function computeFallbackNextTimestamp(schedule, referenceSeconds) {
            const recurrence = (schedule && schedule.recurrence ? schedule.recurrence : 'disabled').toString();
            if (recurrence === 'disabled') {
                return null;
            }

            const reference = Number.isFinite(referenceSeconds) ? referenceSeconds : Math.floor(Date.now() / 1000);
            const referenceDate = new Date(reference * 1000);
            const timeParts = parseTimeParts(schedule);

            if (recurrence === 'hourly') {
                const occurrence = new Date(referenceDate.getTime());
                occurrence.setMinutes(timeParts.minute, 0, 0);
                if (occurrence.getTime() / 1000 <= reference) {
                    occurrence.setHours(occurrence.getHours() + 1);
                }
                return Math.floor(occurrence.getTime() / 1000);
            }

            const occurrence = new Date(referenceDate.getTime());
            occurrence.setHours(timeParts.hour, timeParts.minute, 0, 0);

            if (recurrence === 'twice_daily') {
                while (occurrence.getTime() / 1000 <= reference) {
                    occurrence.setHours(occurrence.getHours() + 12);
                    if (occurrence.getTime() / 1000 - reference > 12 * 60 * 60) {
                        break;
                    }
                }
                return Math.floor(occurrence.getTime() / 1000);
            }

            if (recurrence === 'daily') {
                if (occurrence.getTime() / 1000 <= reference) {
                    occurrence.setDate(occurrence.getDate() + 1);
                }
                return Math.floor(occurrence.getTime() / 1000);
            }

            if (recurrence === 'weekly') {
                const days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                const dayKey = (schedule && schedule.day ? schedule.day : 'sunday').toString().toLowerCase();
                const targetIndex = days.indexOf(dayKey);
                const currentIndex = occurrence.getDay();

                let diff = targetIndex - currentIndex;
                if (diff < 0 || (diff === 0 && occurrence.getTime() / 1000 <= reference)) {
                    diff += 7;
                }
                occurrence.setDate(occurrence.getDate() + diff);
                if (occurrence.getTime() / 1000 <= reference) {
                    occurrence.setDate(occurrence.getDate() + 7);
                }
                return Math.floor(occurrence.getTime() / 1000);
            }

            if (recurrence === 'monthly') {
                // Sans information dédiée, nous ne pouvons pas calculer précisément.
                return null;
            }

            return null;
        }

        function buildTimelineEvents(schedules, nextRuns, view) {
            if (!Array.isArray(schedules) || !schedules.length) {
                return [];
            }

            const events = [];
            const start = new Date();
            const end = new Date(start.getTime() + (timelineRanges[view] || timelineRanges.week) * 24 * 60 * 60 * 1000);
            const nextRunsMap = nextRuns && typeof nextRuns === 'object' ? nextRuns : {};

            schedules.forEach(function(schedule) {
                if (!schedule || typeof schedule !== 'object') {
                    return;
                }
                const recurrence = (schedule.recurrence || 'disabled').toString();
                if (recurrence === 'disabled') {
                    return;
                }

                const scheduleId = (schedule.id || '').toString();
                const info = nextRunsMap[scheduleId];

                let baseTimestamp = info && info.next_run ? parseInt(info.next_run, 10) : NaN;
                if (!Number.isFinite(baseTimestamp) || baseTimestamp <= 0) {
                    baseTimestamp = computeFallbackNextTimestamp(schedule, Math.floor(start.getTime() / 1000));
                }

                if (!Number.isFinite(baseTimestamp) || !baseTimestamp) {
                    return;
                }

                let occurrence = new Date(baseTimestamp * 1000);
                const interval = getIntervalMs(recurrence);
                let guard = 0;

                if (occurrence < start && (interval > 0 || recurrence === 'monthly')) {
                    while (occurrence < start && guard < 200) {
                        occurrence = recurrence === 'monthly'
                            ? addMonths(occurrence, 1)
                            : new Date(occurrence.getTime() + interval);
                        guard++;
                    }
                }

                guard = 0;
                while (occurrence <= end && guard < 200) {
                    events.push({
                        schedule: schedule,
                        info: info,
                        timestamp: Math.floor(occurrence.getTime() / 1000),
                        status: getScheduleStatusKey(schedule, info)
                    });

                    if (recurrence === 'monthly') {
                        occurrence = addMonths(occurrence, 1);
                    } else if (interval > 0) {
                        occurrence = new Date(occurrence.getTime() + interval);
                    } else {
                        break;
                    }

                    guard++;
                }
            });

            events.sort(function(a, b) {
                return a.timestamp - b.timestamp;
            });

            return events;
        }

        function renderTimeline() {
            if (!$timeline.length) {
                return;
            }

            const view = state.timelineView || 'week';
            const schedules = Array.isArray(state.schedules) ? state.schedules : [];
            const nextRuns = state.nextRuns || {};

            const $grid = $timeline.find('[data-role="timeline-grid"]');
            const $list = $timeline.find('[data-role="timeline-list"]');
            const $empty = $timeline.find('[data-role="timeline-empty"]');

            if (!$grid.length || !$list.length || !$empty.length) {
                return;
            }

            $grid.empty();
            $list.empty();

            const events = buildTimelineEvents(schedules, nextRuns, view);
            const rangeDays = timelineRanges[view] || timelineRanges.week;
            const startDay = new Date();
            startDay.setHours(0, 0, 0, 0);

            const buckets = new Map();
            events.forEach(function(event) {
                const date = new Date(event.timestamp * 1000);
                const key = date.toISOString().slice(0, 10);
                if (!buckets.has(key)) {
                    buckets.set(key, []);
                }
                buckets.get(key).push(event);
            });

            let dayFormatter;
            let timeFormatter;
            try {
                dayFormatter = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'numeric' });
                timeFormatter = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });
            } catch (error) {
                dayFormatter = null;
                timeFormatter = null;
            }

            const $gridInner = $('<div/>', { class: 'bjlg-schedule-timeline__grid-inner' });
            for (let dayIndex = 0; dayIndex < rangeDays; dayIndex++) {
                const dayDate = new Date(startDay.getTime() + dayIndex * 24 * 60 * 60 * 1000);
                const key = dayDate.toISOString().slice(0, 10);
                const entries = (buckets.get(key) || []).slice().sort(function(a, b) {
                    return a.timestamp - b.timestamp;
                });

                const $column = $('<div/>', { class: 'bjlg-schedule-timeline__column' });
                const heading = dayFormatter ? dayFormatter.format(dayDate) : dayDate.toLocaleDateString();
                $('<div/>', { class: 'bjlg-schedule-timeline__column-header', text: heading }).appendTo($column);

                if (!entries.length) {
                    $('<div/>', { class: 'bjlg-schedule-timeline__event bjlg-schedule-timeline__event--empty', text: '—' }).appendTo($column);
                } else {
                    entries.forEach(function(entry) {
                        const schedule = entry.schedule || {};
                        const descriptor = getStatusDescriptor(entry.status);
                        const recurrence = (schedule.recurrence || '').toString();
                        const eventDate = new Date(entry.timestamp * 1000);
                        const $event = $('<div/>', {
                            class: 'bjlg-schedule-timeline__event bjlg-schedule-timeline__event--' + entry.status,
                            'data-schedule-id': schedule.id || ''
                        });

                        $('<strong/>', {
                            class: 'bjlg-schedule-timeline__event-label',
                            text: schedule.label || schedule.id || 'Planification'
                        }).appendTo($event);

                        const timeText = timeFormatter ? timeFormatter.format(eventDate) : eventDate.toLocaleTimeString();
                        $('<span/>', { class: 'bjlg-schedule-timeline__event-time', text: timeText }).appendTo($event);
                        $('<span/>', {
                            class: 'bjlg-schedule-timeline__event-meta',
                            text: recurrenceLabels[recurrence] || recurrence || '—'
                        }).appendTo($event);
                        $('<span/>', {
                            class: 'bjlg-status-badge ' + descriptor.className,
                            text: descriptor.label
                        }).appendTo($event);

                        $column.append($event);
                    });
                }

                $gridInner.append($column);
            }

            $grid.append($gridInner);

            events.forEach(function(entry) {
                const schedule = entry.schedule || {};
                const descriptor = getStatusDescriptor(entry.status);
                const eventDate = new Date(entry.timestamp * 1000);
                const $item = $('<li/>', {
                    class: 'bjlg-schedule-timeline__list-item bjlg-schedule-timeline__list-item--' + entry.status,
                    'data-schedule-id': schedule.id || ''
                });

                const $header = $('<div/>', { class: 'bjlg-schedule-timeline__list-header' }).appendTo($item);
                $('<strong/>', { text: schedule.label || schedule.id || 'Planification' }).appendTo($header);
                $('<span/>', { class: 'bjlg-status-badge ' + descriptor.className, text: descriptor.label }).appendTo($header);

                const $meta = $('<div/>', { class: 'bjlg-schedule-timeline__list-meta' }).appendTo($item);
                const dateText = dayFormatter ? dayFormatter.format(eventDate) : eventDate.toLocaleDateString();
                const timeText = timeFormatter ? timeFormatter.format(eventDate) : eventDate.toLocaleTimeString();
                const recurrenceText = recurrenceLabels[(schedule.recurrence || '').toString()] || schedule.recurrence || '—';
                $meta.text(dateText + ' • ' + timeText + ' • ' + recurrenceText);

                $list.append($item);
            });

            $empty.prop('hidden', events.length > 0);

            $timeline.find('[data-role="timeline-view"]').removeClass('is-active');
            $timeline.find('[data-role="timeline-view"][data-view="' + view + '"]').addClass('is-active');
        }

        function updateState(schedules, nextRuns, options) {
            options = options || {};

            if (Array.isArray(schedules)) {
                state.schedules = schedules.slice();
            }

            if (nextRuns && typeof nextRuns === 'object') {
                state.nextRuns = $.extend(true, {}, nextRuns);
            }

            const schedulesJson = safeJson(state.schedules);
            if (schedulesJson) {
                $scheduleForm.attr('data-schedules', schedulesJson);
                $('#bjlg-schedule-overview').attr('data-schedules', schedulesJson);
                if ($timeline.length) {
                    $timeline.attr('data-schedules', schedulesJson);
                }
            }

            const nextRunsJson = safeJson(state.nextRuns);
            if (nextRunsJson) {
                $scheduleForm.attr('data-next-runs', nextRunsJson);
                $('#bjlg-schedule-overview').attr('data-next-runs', nextRunsJson);
                if ($timeline.length) {
                    $timeline.attr('data-next-runs', nextRunsJson);
                }
            }

            if ($timeline.length && !options.skipTimeline) {
                renderTimeline();
            }
        }

        function findScheduleById(scheduleId) {
            if (!Array.isArray(state.schedules) || !state.schedules.length) {
                return null;
            }
            const target = (scheduleId || '').toString();
            for (let index = 0; index < state.schedules.length; index += 1) {
                const schedule = state.schedules[index];
                if (schedule && (schedule.id || '').toString() === target) {
                    return schedule;
                }
            }
            return null;
        }

        function scheduleItems() {
            return $scheduleList.find('.bjlg-schedule-item').not('.bjlg-schedule-item--template');
        }

        function cloneTemplate() {
            const $clone = $template.clone();
            $clone.removeClass('bjlg-schedule-item--template');
            $clone.removeAttr('data-template');
            $clone.removeAttr('style');
            return $clone;
        }

        function assignFieldPrefix($item, prefix) {
            $item.find('[name]').each(function() {
                const $field = $(this);
                const name = $field.attr('name');
                if (!name) {
                    return;
                }
                const updated = name.replace(/schedules\[[^\]]+\]/, 'schedules[' + prefix + ']');
                $field.attr('name', updated);
            });

            const $labelField = $item.find('[data-field="label"]');
            if ($labelField.length) {
                const oldId = $labelField.attr('id');
                const newId = 'bjlg-schedule-label-' + prefix;
                $labelField.attr('id', newId);
                if (typeof oldId === 'string') {
                    $item.find('label[for="' + oldId + '"]').attr('for', newId);
                }
                $item.find('label[for^="bjlg-schedule-label-"]').attr('for', newId);
            }

            $item.find('[data-id-template]').each(function() {
                const $node = $(this);
                const template = $node.attr('data-id-template');
                if (typeof template !== 'string' || !template.includes('%s')) {
                    return;
                }
                const newId = template.replace('%s', prefix);
                $node.attr('id', newId);
            });

            $item.find('[data-for-template]').each(function() {
                const $label = $(this);
                const template = $label.attr('data-for-template');
                if (typeof template !== 'string' || !template.includes('%s')) {
                    return;
                }
                const newFor = template.replace('%s', prefix);
                $label.attr('for', newFor);
            });

            $item.find('[data-describedby-template]').each(function() {
                const $node = $(this);
                const template = $node.attr('data-describedby-template');
                if (typeof template !== 'string' || !template.includes('%s')) {
                    return;
                }
                const newValue = template.replace('%s', prefix);
                $node.attr('aria-describedby', newValue);
            });
        }

        function setScheduleId($item, scheduleId) {
            $item.attr('data-schedule-id', scheduleId || '');
            $item.find('[data-field="id"]').val(scheduleId || '');
            updateRunButtonState($item);
        }

        function updateRunButtonState($item) {
            const $button = $item.find('.bjlg-run-schedule-now');
            if (!$button.length) {
                return;
            }
            const scheduleId = ($item.find('[data-field="id"]').val() || '').toString();
            $button.prop('disabled', !scheduleId);
        }

        function toggleScheduleRows($item) {
            const recurrence = ($item.find('[data-field="recurrence"]').val() || '').toString();
            const $weekly = $item.find('.bjlg-schedule-weekly-options');
            const $time = $item.find('.bjlg-schedule-time-options');

            if ($weekly.length) {
                if (recurrence === 'weekly') {
                    $weekly.show().attr('aria-hidden', 'false');
                } else {
                    $weekly.hide().attr('aria-hidden', 'true');
                }
            }

            if ($time.length) {
                if (recurrence === 'disabled') {
                    $time.hide().attr('aria-hidden', 'true');
                } else {
                    $time.show().attr('aria-hidden', 'false');
                }
            }
        }

        function normalizePatterns(value) {
            if (Array.isArray(value)) {
                return value.map(function(entry) {
                    return (entry || '').toString().trim();
                }).filter(Boolean);
            }
            if (typeof value !== 'string') {
                return [];
            }
            return value.split(/[
,]+/).map(function(entry) {
                return entry.trim();
            }).filter(Boolean);
        }

        function patternsToTextarea(value) {
            if (Array.isArray(value)) {
                return value.join('
');
            }
            if (typeof value === 'string') {
                return value;
            }
            return '';
        }

        function normalizePostChecks(value) {
            const normalized = { checksum: false, dry_run: false };
            if (Array.isArray(value)) {
                value.forEach(function(entry) {
                    const key = (entry || '').toString().toLowerCase();
                    if (key === 'checksum' || key === 'dry_run') {
                        normalized[key] = true;
                    }
                });
                return normalized;
            }
            if (value && typeof value === 'object') {
                normalized.checksum = !!value.checksum;
                normalized.dry_run = !!value.dry_run;
            }
            return normalized;
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

        function createScheduleBadgeElement(badge) {
            const classes = Array.isArray(badge.classes) ? badge.classes.slice() : [];
            classes.unshift('bjlg-badge');
            const $badge = $('<span/>', { class: classes.join(' '), text: badge.label });
            const styles = $.extend({}, badgeStyles, { backgroundColor: badge.color || '#4b5563' });
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
                $('<strong/>', { class: 'bjlg-badge-group-title', text: group.title + ' :' }).appendTo($group);
                group.badges.forEach(function(badge) {
                    $group.append(createScheduleBadgeElement(badge));
                });
                fragment.append($group);
            });
            $target.empty().append(fragment);
        }

        function buildSummaryGroupsFromData(data) {
            const components = Array.isArray(data.components) ? data.components : [];
            const componentBadges = [];
            const seenComponents = new Set();
            components.forEach(function(component) {
                const key = (component || '').toString();
                if (!key || seenComponents.has(key)) {
                    return;
                }
                seenComponents.add(key);
                const config = componentLabels[key] || { label: key, color: '#4b5563' };
                componentBadges.push({ label: config.label, color: config.color, classes: ['bjlg-badge-component'] });
            });
            if (!componentBadges.length) {
                componentBadges.push({ label: 'Aucun composant', color: '#4b5563', classes: ['bjlg-badge-component', 'bjlg-badge-empty'] });
            }

            const optionBadges = [
                {
                    label: data.encrypt ? 'Chiffrée' : 'Non chiffrée',
                    color: data.encrypt ? '#7c3aed' : '#4b5563',
                    classes: ['bjlg-badge-encrypted']
                },
                {
                    label: data.incremental ? 'Incrémentale' : 'Complète',
                    color: data.incremental ? '#2563eb' : '#6b7280',
                    classes: ['bjlg-badge-incremental']
                }
            ];

            const includePatterns = normalizePatterns(data.include_patterns);
            const includeBadges = includePatterns.length
                ? [{ label: includePatterns.length + ' motif(s)', color: '#0ea5e9', classes: ['bjlg-badge-include'] }]
                : [{ label: 'Tout le contenu', color: '#10b981', classes: ['bjlg-badge-include'] }];

            const excludePatterns = normalizePatterns(data.exclude_patterns);
            const excludeBadges = excludePatterns.length
                ? [{ label: excludePatterns.length + ' exclusion(s)', color: '#f97316', classes: ['bjlg-badge-exclude'] }]
                : [{ label: 'Aucune', color: '#4b5563', classes: ['bjlg-badge-exclude'] }];

            const postChecks = normalizePostChecks(data.post_checks);
            const controlBadges = [];
            if (postChecks.checksum) {
                controlBadges.push({ label: 'Checksum', color: '#2563eb', classes: ['bjlg-badge-checksum'] });
            }
            if (postChecks.dry_run) {
                controlBadges.push({ label: 'Test restauration', color: '#7c3aed', classes: ['bjlg-badge-restore'] });
            }
            if (!controlBadges.length) {
                controlBadges.push({ label: 'Aucun contrôle', color: '#4b5563', classes: ['bjlg-badge-control'] });
            }

            const destinationBadges = [];
            const destinations = Array.isArray(data.secondary_destinations) ? data.secondary_destinations : [];
            const seenDestinations = new Set();
            destinations.forEach(function(destination) {
                const key = (destination || '').toString();
                if (!key || seenDestinations.has(key)) {
                    return;
                }
                seenDestinations.add(key);
                const label = destinationLabels[key] || key;
                destinationBadges.push({ label: label, color: '#0ea5e9', classes: ['bjlg-badge-destination'] });
            });
            if (!destinationBadges.length) {
                destinationBadges.push({ label: 'Stockage local', color: '#4b5563', classes: ['bjlg-badge-destination'] });
            }

            return [
                { title: 'Composants', badges: componentBadges },
                { title: 'Options', badges: optionBadges },
                { title: 'Inclusions', badges: includeBadges },
                { title: 'Exclusions', badges: excludeBadges },
                { title: 'Contrôles', badges: controlBadges },
                { title: 'Destinations', badges: destinationBadges }
            ];
        }

        function collectScheduleData($item, forSummary) {
            const id = ($item.find('[data-field="id"]').val() || '').toString();
            const label = ($item.find('[data-field="label"]').val() || '').toString();
            const recurrence = ($item.find('[data-field="recurrence"]').val() || 'disabled').toString();
            const day = ($item.find('[data-field="day"]').val() || 'sunday').toString();
            const time = ($item.find('[data-field="time"]').val() || '23:59').toString();
            const previousRecurrence = ($item.find('[data-field="previous_recurrence"]').val() || '').toString();

            const components = [];
            $item.find('[data-field="components"]').each(function() {
                const value = ($(this).val() || '').toString();
                if ($(this).is(':checked') && value) {
                    components.push(value);
                }
            });

            const encrypt = $item.find('[data-field="encrypt"]').is(':checked');
            const incremental = $item.find('[data-field="incremental"]').is(':checked');

            const includeRaw = ($item.find('[data-field="include_patterns"]').val() || '').toString();
            const excludeRaw = ($item.find('[data-field="exclude_patterns"]').val() || '').toString();

            const postChecksValues = [];
            $item.find('[data-field="post_checks"]:checked').each(function() {
                const value = ($(this).val() || '').toString();
                if (value) {
                    postChecksValues.push(value);
                }
            });

            const destinations = [];
            $item.find('[data-field="secondary_destinations"]:checked').each(function() {
                const value = ($(this).val() || '').toString();
                if (value) {
                    destinations.push(value);
                }
            });

            const data = {
                id: id,
                label: label,
                recurrence: recurrence,
                previous_recurrence: previousRecurrence,
                day: day,
                time: time,
                components: components,
                encrypt: encrypt,
                incremental: incremental,
                post_checks: postChecksValues,
                secondary_destinations: destinations
            };

            if (forSummary) {
                data.include_patterns = normalizePatterns(includeRaw);
                data.exclude_patterns = normalizePatterns(excludeRaw);
            } else {
                data.include_patterns = includeRaw;
                data.exclude_patterns = excludeRaw;
            }

            return data;
        }

        function updateScheduleSummaryForItem($item) {
            const summaryData = collectScheduleData($item, true);
            const $summary = $item.find('[data-field="summary"]');
            renderScheduleBadgeGroups($summary, buildSummaryGroupsFromData(summaryData));
        }

        function populateScheduleItem($item, schedule, nextRun, index) {
            const prefix = schedule && schedule.id ? schedule.id : 'schedule_' + (index + 1);
            assignFieldPrefix($item, prefix);
            const scheduleId = schedule && schedule.id ? schedule.id : '';
            setScheduleId($item, scheduleId);

            $item.find('[data-field="previous_recurrence"]').val(schedule && schedule.previous_recurrence ? schedule.previous_recurrence : '');

            $item.find('[data-field="label"]').val(schedule && schedule.label ? schedule.label : '');

            $item.find('[data-field="recurrence"]').val(schedule && schedule.recurrence ? schedule.recurrence : 'disabled');
            $item.find('[data-field="day"]').val(schedule && schedule.day ? schedule.day : 'sunday');
            $item.find('[data-field="time"]').val(schedule && schedule.time ? schedule.time : '23:59');

            const components = Array.isArray(schedule && schedule.components) ? schedule.components : (defaultScheduleData.components || []);
            $item.find('[data-field="components"]').each(function() {
                const value = ($(this).val() || '').toString();
                $(this).prop('checked', components.indexOf(value) !== -1);
            });

            $item.find('[data-field="encrypt"]').prop('checked', !!(schedule && schedule.encrypt));
            $item.find('[data-field="incremental"]').prop('checked', !!(schedule && schedule.incremental));

            const includeValue = schedule && schedule.include_patterns ? schedule.include_patterns : (defaultScheduleData.include_patterns || []);
            const excludeValue = schedule && schedule.exclude_patterns ? schedule.exclude_patterns : (defaultScheduleData.exclude_patterns || []);
            $item.find('[data-field="include_patterns"]').val(patternsToTextarea(includeValue));
            $item.find('[data-field="exclude_patterns"]').val(patternsToTextarea(excludeValue));

            const postChecks = normalizePostChecks(schedule && schedule.post_checks);
            $item.find('[data-field="post_checks"]').each(function() {
                const value = ($(this).val() || '').toString();
                if (value === 'checksum') {
                    $(this).prop('checked', postChecks.checksum);
                } else if (value === 'dry_run') {
                    $(this).prop('checked', postChecks.dry_run);
                } else {
                    $(this).prop('checked', postChecks[value] || false);
                }
            });

            const destinations = Array.isArray(schedule && schedule.secondary_destinations) ? schedule.secondary_destinations : (defaultScheduleData.secondary_destinations || []);
            $item.find('[data-field="secondary_destinations"]').each(function() {
                const value = ($(this).val() || '').toString();
                $(this).prop('checked', destinations.indexOf(value) !== -1);
            });

            const info = nextRun && typeof nextRun === 'object' ? nextRun : defaultNextRunSummary;
            const $nextRunValue = $item.find('.bjlg-next-run-value');
            if ($nextRunValue.length) {
                $nextRunValue.text(info.next_run_formatted || 'Non planifié');
            }
            const $nextRunRelative = $item.find('.bjlg-next-run-relative');
            if ($nextRunRelative.length) {
                if (info.next_run_relative) {
                    $nextRunRelative.text('(' + info.next_run_relative + ')').show();
                } else {
                    $nextRunRelative.text('').hide();
                }
            }

            toggleScheduleRows($item);
            updateScheduleSummaryForItem($item);
            updateRunButtonState($item);
        }

        function rebuildScheduleItems(schedules, nextRuns) {
            scheduleItems().remove();

            if (!Array.isArray(schedules) || !schedules.length) {
                const $item = cloneTemplate();
                populateScheduleItem($item, defaultScheduleData, defaultNextRunSummary, 0);
                $item.insertBefore($template);
                return;
            }

            schedules.forEach(function(schedule, index) {
                if (!schedule || typeof schedule !== 'object') {
                    return;
                }
                const scheduleId = schedule.id || 'schedule_' + (index + 1);
                const nextRun = nextRuns && typeof nextRuns === 'object' ? nextRuns[scheduleId] : null;
                const $item = cloneTemplate();
                populateScheduleItem($item, schedule, nextRun, index);
                $item.insertBefore($template);
            });
        }

        function rebuildOverview(schedules, nextRuns) {
            const $overview = $('#bjlg-schedule-overview');
            if (!$overview.length) {
                return;
            }
            const $list = $overview.find('.bjlg-schedule-overview-list');
            if (!$list.length) {
                return;
            }

            $list.empty();

            if (!Array.isArray(schedules) || !schedules.length) {
                $('<p/>', { class: 'description', text: 'Aucune planification active pour le moment.' }).appendTo($list);
                return;
            }

            schedules.forEach(function(schedule, index) {
                if (!schedule || typeof schedule !== 'object') {
                    return;
                }

                const scheduleId = schedule.id || 'schedule_' + (index + 1);
                const label = schedule.label || ('Planification #' + (index + 1));
                const recurrence = schedule.recurrence || 'disabled';
                const recurrenceLabel = recurrenceLabels[recurrence] || recurrence || '—';
                const info = nextRuns && typeof nextRuns === 'object' ? nextRuns[scheduleId] : null;
                const nextRun = info && typeof info === 'object' ? info : defaultNextRunSummary;
                const statusKey = getScheduleStatusKey(schedule, nextRun);
                const descriptor = getStatusDescriptor(statusKey);
                const toggleLabel = statusKey === 'paused' ? 'Reprendre' : 'Mettre en pause';
                const toggleTarget = statusKey === 'paused' ? 'resume' : 'pause';

                const $card = $('<article/>', {
                    class: 'bjlg-schedule-overview-card',
                    'data-schedule-id': scheduleId,
                    'data-recurrence': recurrence,
                    'data-status': statusKey
                });

                const $header = $('<header/>', { class: 'bjlg-schedule-overview-card__header' }).appendTo($card);
                $('<h4/>', { class: 'bjlg-schedule-overview-card__title', text: label }).appendTo($header);

                const $frequency = $('<p/>', {
                    class: 'bjlg-schedule-overview-frequency',
                    text: 'Fréquence : ' + recurrenceLabel
                }).appendTo($header);
                $frequency.attr('data-prefix', 'Fréquence : ');

                const $nextRun = $('<p/>', { class: 'bjlg-schedule-overview-next-run' }).appendTo($header);
                $('<strong/>').text('Prochaine exécution :').appendTo($nextRun);
                $('<span/>', {
                    class: 'bjlg-next-run-value',
                    text: nextRun.next_run_formatted || 'Non planifié'
                }).appendTo($nextRun);
                const $relative = $('<span/>', { class: 'bjlg-next-run-relative' }).appendTo($nextRun);
                if (nextRun.next_run_relative) {
                    $relative.text('(' + nextRun.next_run_relative + ')');
                } else {
                    $relative.hide();
                }

                $('<p/>', { class: 'bjlg-schedule-overview-status' })
                    .append($('<span/>', { class: 'bjlg-status-badge ' + descriptor.className, text: descriptor.label }))
                    .appendTo($header);

                const $summary = $('<div/>', { class: 'bjlg-schedule-overview-card__summary' }).appendTo($card);
                renderScheduleBadgeGroups($summary, buildSummaryGroupsFromData(schedule));

                const $footer = $('<footer/>', { class: 'bjlg-schedule-overview-card__footer' }).appendTo($card);
                const $actions = $('<div/>', {
                    class: 'bjlg-schedule-overview-card__actions',
                    role: 'group',
                    'aria-label': 'Actions de planification'
                }).appendTo($footer);

                $('<button/>', {
                    type: 'button',
                    class: 'button button-primary button-small bjlg-schedule-action',
                    'data-action': 'run',
                    'data-schedule-id': scheduleId,
                    text: 'Exécuter'
                }).appendTo($actions);

                $('<button/>', {
                    type: 'button',
                    class: 'button button-secondary button-small bjlg-schedule-action',
                    'data-action': 'toggle',
                    'data-target-state': toggleTarget,
                    'data-schedule-id': scheduleId,
                    text: toggleLabel
                }).appendTo($actions);

                $('<button/>', {
                    type: 'button',
                    class: 'button button-secondary button-small bjlg-schedule-action',
                    'data-action': 'duplicate',
                    'data-schedule-id': scheduleId,
                    text: 'Dupliquer'
                }).appendTo($actions);

                $list.append($card);
            });
        }

        function updateOverviewFromNextRuns(nextRuns) {
            if (!nextRuns || typeof nextRuns !== 'object') {
                return;
            }
            const $overview = $('#bjlg-schedule-overview');
            if (!$overview.length) {
                return;
            }

            updateState(null, nextRuns, { skipTimeline: true });

            Object.keys(nextRuns).forEach(function(scheduleId) {
                const info = nextRuns[scheduleId];
                if (!info || typeof info !== 'object') {
                    return;
                }
                const $card = $overview.find('[data-schedule-id="' + scheduleId + '"]');
                if (!$card.length) {
                    return;
                }

                const recurrence = info.recurrence || 'disabled';
                const recurrenceLabel = recurrenceLabels[recurrence] || recurrence || '—';
                $card.attr('data-recurrence', recurrence);

                const $frequency = $card.find('.bjlg-schedule-overview-frequency');
                if ($frequency.length) {
                    const prefix = ($frequency.data('prefix') || 'Fréquence : ').toString();
                    $frequency.text(prefix + recurrenceLabel);
                }

                const $nextRunValue = $card.find('.bjlg-next-run-value');
                if ($nextRunValue.length) {
                    $nextRunValue.text(info.next_run_formatted || 'Non planifié');
                }

                const $relative = $card.find('.bjlg-next-run-relative');
                if ($relative.length) {
                    if (info.next_run_relative) {
                        $relative.text('(' + info.next_run_relative + ')').show();
                    } else {
                        $relative.text('').hide();
                    }
                }

                const schedule = findScheduleById(scheduleId) || { recurrence: recurrence };
                const statusKey = getScheduleStatusKey(schedule, info);
                const descriptor = getStatusDescriptor(statusKey);
                $card.attr('data-status', statusKey);

                const $statusBadge = $card.find('.bjlg-schedule-overview-status .bjlg-status-badge');
                if ($statusBadge.length) {
                    $statusBadge.attr('class', 'bjlg-status-badge ' + descriptor.className).text(descriptor.label);
                }

                const $toggleButton = $card.find('.bjlg-schedule-overview-card__actions [data-action="toggle"]');
                if ($toggleButton.length) {
                    const targetState = statusKey === 'paused' ? 'resume' : 'pause';
                    const label = statusKey === 'paused' ? 'Reprendre' : 'Mettre en pause';
                    $toggleButton.text(label).attr('data-target-state', targetState);
                }
            });

            if ($timeline.length) {
                renderTimeline();
            }
        }

        function collectSchedulesForRequest() {
            const schedules = [];
            scheduleItems().each(function() {
                schedules.push(collectScheduleData($(this), false));
            });
            return schedules;
        }

        function submitRunSchedule(scheduleId, $button, $item) {
            if (!scheduleId) {
                renderScheduleFeedback('error', 'Enregistrez cette planification avant de l\'exécuter.', []);
                return $.Deferred().reject().promise();
            }

            if ($button && $button.length) {
                $button.prop('disabled', true);
            }

            return $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'bjlg_run_scheduled_now',
                    nonce: bjlg_ajax.nonce,
                    schedule_id: scheduleId
                }
            }).done(function(response) {
                if (response && response.success) {
                    const data = response.data || {};
                    renderScheduleFeedback('success', typeof data.message === 'string' ? data.message : 'Sauvegarde planifiée lancée.', []);
                    if (data.next_runs && typeof data.next_runs === 'object') {
                        updateOverviewFromNextRuns(data.next_runs);
                    }
                } else {
                    const data = response && response.data ? response.data : response;
                    const message = data && typeof data.message === 'string' ? data.message : 'Impossible d\'exécuter la planification.';
                    const details = normalizeErrorList(data && (data.errors || data.validation_errors || data.field_errors));
                    renderScheduleFeedback('error', message, details);
                    if (data && data.next_runs) {
                        updateOverviewFromNextRuns(data.next_runs);
                    }
                }
            }).fail(function(jqXHR) {
                let message = 'Erreur de communication avec le serveur.';
                let details = [];
                if (jqXHR && jqXHR.responseJSON) {
                    const data = jqXHR.responseJSON.data || jqXHR.responseJSON;
                    if (data && typeof data.message === 'string') {
                        message = data.message;
                    }
                    details = normalizeErrorList(data && (data.errors || data.validation_errors || data.field_errors));
                }
                renderScheduleFeedback('error', message, details);
            }).always(function() {
                if ($button && $button.length) {
                    $button.prop('disabled', false);
                }
                if ($item && $item.length) {
                    updateRunButtonState($item);
                }
            });
        }

        function toggleScheduleState(scheduleId, targetState, $button) {
            if (!scheduleId || !targetState) {
                renderScheduleFeedback('error', 'Action de planification invalide.', []);
                return;
            }

            if ($button && $button.length) {
                $button.prop('disabled', true);
            }

            $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'bjlg_toggle_schedule_state',
                    nonce: bjlg_ajax.nonce,
                    schedule_id: scheduleId,
                    state: targetState
                }
            }).done(function(response) {
                const data = response && typeof response === 'object' ? response.data || {} : {};

                if (response && response.success) {
                    const schedulesData = Array.isArray(data.schedules) ? data.schedules : [];
                    const nextRuns = data.next_runs && typeof data.next_runs === 'object' ? data.next_runs : {};
                    renderScheduleFeedback('success', typeof data.message === 'string' ? data.message : 'Planification mise à jour.', []);
                    rebuildScheduleItems(schedulesData, nextRuns);
                    rebuildOverview(schedulesData, nextRuns);
                    updateState(schedulesData, nextRuns);
                } else {
                    const payload = Object.keys(data).length ? data : (response && response.data) || response;
                    const message = payload && typeof payload.message === 'string' ? payload.message : 'Impossible de modifier la planification.';
                    const details = normalizeErrorList(payload && (payload.errors || payload.validation_errors || payload.field_errors));
                    renderScheduleFeedback('error', message, details);
                    if (payload && payload.next_runs) {
                        updateOverviewFromNextRuns(payload.next_runs);
                    }
                }
            }).fail(function(jqXHR) {
                let message = 'Erreur de communication avec le serveur.';
                let details = [];
                if (jqXHR && jqXHR.responseJSON) {
                    const data = jqXHR.responseJSON.data || jqXHR.responseJSON;
                    if (data && typeof data.message === 'string') {
                        message = data.message;
                    }
                    details = normalizeErrorList(data && (data.errors || data.validation_errors || data.field_errors));
                }
                renderScheduleFeedback('error', message, details);
            }).always(function() {
                if ($button && $button.length) {
                    $button.prop('disabled', false);
                }
            });
        }

        function duplicateScheduleRequest(scheduleId, $button) {
            if (!scheduleId) {
                renderScheduleFeedback('error', 'Planification introuvable.', []);
                return;
            }

            if ($button && $button.length) {
                $button.prop('disabled', true);
            }

            $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'bjlg_duplicate_schedule',
                    nonce: bjlg_ajax.nonce,
                    schedule_id: scheduleId
                }
            }).done(function(response) {
                const data = response && typeof response === 'object' ? response.data || {} : {};

                if (response && response.success) {
                    const schedulesData = Array.isArray(data.schedules) ? data.schedules : [];
                    const nextRuns = data.next_runs && typeof data.next_runs === 'object' ? data.next_runs : {};
                    renderScheduleFeedback('success', typeof data.message === 'string' ? data.message : 'Planification dupliquée.', []);
                    rebuildScheduleItems(schedulesData, nextRuns);
                    rebuildOverview(schedulesData, nextRuns);
                    updateState(schedulesData, nextRuns);
                } else {
                    const payload = Object.keys(data).length ? data : (response && response.data) || response;
                    const message = payload && typeof payload.message === 'string' ? payload.message : 'Impossible de dupliquer la planification.';
                    const details = normalizeErrorList(payload && (payload.errors || payload.validation_errors || payload.field_errors));
                    renderScheduleFeedback('error', message, details);
                    if (payload && payload.next_runs) {
                        updateOverviewFromNextRuns(payload.next_runs);
                    }
                }
            }).fail(function(jqXHR) {
                let message = 'Erreur de communication avec le serveur.';
                let details = [];
                if (jqXHR && jqXHR.responseJSON) {
                    const data = jqXHR.responseJSON.data || jqXHR.responseJSON;
                    if (data && typeof data.message === 'string') {
                        message = data.message;
                    }
                    details = normalizeErrorList(data && (data.errors || data.validation_errors || data.field_errors));
                }
                renderScheduleFeedback('error', message, details);
            }).always(function() {
                if ($button && $button.length) {
                    $button.prop('disabled', false);
                }
            });
        }

        // Initialisation des planifications existantes
        scheduleItems().each(function(index) {
            const $item = $(this);
            const scheduleId = ($item.find('[data-field="id"]').val() || '').toString();
            const nextRun = scheduleId && initialNextRuns[scheduleId] ? initialNextRuns[scheduleId] : defaultNextRunSummary;
            populateScheduleItem($item, collectScheduleData($item, true), nextRun, index);
        });

        updateState(collectSchedulesForRequest(), state.nextRuns);

        // Gestion des événements de champ
        $scheduleForm.on('change', '.bjlg-schedule-item [data-field="recurrence"]', function() {
            const $item = $(this).closest('.bjlg-schedule-item');
            toggleScheduleRows($item);
            updateScheduleSummaryForItem($item);
            updateState(collectSchedulesForRequest(), state.nextRuns);
        });

        $scheduleForm.on('change', '.bjlg-schedule-item [data-field="components"], .bjlg-schedule-item [data-field="encrypt"], .bjlg-schedule-item [data-field="incremental"], .bjlg-schedule-item [data-field="day"], .bjlg-schedule-item [data-field="time"], .bjlg-schedule-item [data-field="post_checks"], .bjlg-schedule-item [data-field="secondary_destinations"]', function() {
            updateScheduleSummaryForItem($(this).closest('.bjlg-schedule-item'));
            updateState(collectSchedulesForRequest(), state.nextRuns);
        });

        $scheduleForm.on('input', '.bjlg-schedule-item [data-field="label"], .bjlg-schedule-item textarea[data-field]', function() {
            updateScheduleSummaryForItem($(this).closest('.bjlg-schedule-item'));
            updateState(collectSchedulesForRequest(), state.nextRuns);
        });

        // Ajout d'une planification
        $scheduleForm.on('click', '.bjlg-add-schedule', function(event) {
            event.preventDefault();
            resetScheduleFeedback();
            const $item = cloneTemplate();
            const prefix = 'new_' + (++newScheduleCounter);
            assignFieldPrefix($item, prefix);
            setScheduleId($item, '');
            populateScheduleItem($item, defaultScheduleData, defaultNextRunSummary, scheduleItems().length);
            $item.insertBefore($template);
            renderScheduleFeedback('info', 'Nouvelle planification ajoutée. Enregistrez pour la synchroniser.', []);
            updateState(collectSchedulesForRequest(), state.nextRuns);
        });

        // Suppression d'une planification
        $scheduleForm.on('click', '.bjlg-remove-schedule', function(event) {
            event.preventDefault();
            resetScheduleFeedback();
            const $item = $(this).closest('.bjlg-schedule-item');
            if ($item.hasClass('bjlg-schedule-item--template')) {
                return;
            }
            if (scheduleItems().length <= 1) {
                renderScheduleFeedback('error', 'Vous devez conserver au moins une planification active.', []);
                return;
            }
            $item.slideUp(150, function() {
                $(this).remove();
                updateState(collectSchedulesForRequest(), state.nextRuns);
            });
        });

        // Exécution manuelle d'une planification
        $scheduleForm.on('click', '.bjlg-run-schedule-now', function(event) {
            event.preventDefault();
            resetScheduleFeedback();
            const $button = $(this);
            const $item = $button.closest('.bjlg-schedule-item');
            const scheduleId = ($item.find('[data-field="id"]').val() || '').toString();
            submitRunSchedule(scheduleId, $button, $item);
        });

        // Soumission du formulaire
        $scheduleForm.on('submit', function(event) {
            event.preventDefault();
            resetScheduleFeedback();

            const schedules = collectSchedulesForRequest();
            if (!schedules.length) {
                renderScheduleFeedback('error', 'Aucune planification valide fournie.', []);
                return;
            }

            const payload = {
                action: 'bjlg_save_schedule_settings',
                nonce: bjlg_ajax.nonce,
                schedules: JSON.stringify(schedules)
            };

            const $submitButton = $scheduleForm.find('button[type="submit"]').first();
            $submitButton.prop('disabled', true);

            $.ajax({
                url: bjlg_ajax.ajax_url,
                method: 'POST',
                data: payload
            }).done(function(response) {
                const data = response && typeof response === 'object' ? response.data || {} : {};

                if (response && response.success) {
                    const schedulesData = Array.isArray(data.schedules) ? data.schedules : [];
                    const nextRuns = data.next_runs && typeof data.next_runs === 'object' ? data.next_runs : {};

                    renderScheduleFeedback('success', typeof data.message === 'string' ? data.message : 'Planifications enregistrées !', []);

                    rebuildScheduleItems(schedulesData, nextRuns);
                    rebuildOverview(schedulesData, nextRuns);
                    updateState(schedulesData, nextRuns);
                    return;
                }

                const payloadData = Object.keys(data).length ? data : (response && response.data) || response;
                const message = payloadData && typeof payloadData.message === 'string'
                    ? payloadData.message
                    : 'Impossible d\'enregistrer les planifications.';
                const details = normalizeErrorList(payloadData && (payloadData.errors || payloadData.validation_errors || payloadData.field_errors));
                renderScheduleFeedback('error', message, details);
                if (payloadData && payloadData.next_runs) {
                    updateOverviewFromNextRuns(payloadData.next_runs);
                }
            }).fail(function(jqXHR) {
                let message = 'Erreur de communication avec le serveur.';
                let details = [];

                if (jqXHR && jqXHR.responseJSON) {
                    const data = jqXHR.responseJSON.data || jqXHR.responseJSON;
                    if (data && typeof data.message === 'string') {
                        message = data.message;
                    }
                    details = normalizeErrorList(data && (data.errors || data.validation_errors || data.field_errors));
                }

                renderScheduleFeedback('error', message, details);
            }).always(function() {
                $submitButton.prop('disabled', false);
            });
        });

        const $overview = $('#bjlg-schedule-overview');
        if ($overview.length) {
            $overview.on('click', '.bjlg-schedule-action', function(event) {
                event.preventDefault();
                resetScheduleFeedback();

                const $button = $(this);
                const action = ($button.data('action') || '').toString();
                const scheduleId = ($button.data('schedule-id') || '').toString();

                if (action === 'run') {
                    submitRunSchedule(scheduleId, $button);
                    return;
                }

                if (action === 'toggle') {
                    const targetState = ($button.data('target-state') || '').toString();
                    toggleScheduleState(scheduleId, targetState, $button);
                    return;
                }

                if (action === 'duplicate') {
                    duplicateScheduleRequest(scheduleId, $button);
                }
            });
        }

        if ($timeline.length) {
            $timeline.on('click', '[data-role="timeline-view"]', function(event) {
                event.preventDefault();
                const view = ($(this).data('view') || '').toString();
                if (!view || !Object.prototype.hasOwnProperty.call(timelineRanges, view)) {
                    return;
                }
                if (state.timelineView === view) {
                    return;
                }
                state.timelineView = view;
                renderTimeline();
            });
        }
    })();

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
