jQuery(function($) {
    'use strict';

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
        every_five_minutes: 'Toutes les 5 minutes',
        every_fifteen_minutes: 'Toutes les 15 minutes',
        hourly: 'Toutes les heures',
        twice_daily: 'Deux fois par jour',
        daily: 'Journalière',
        weekly: 'Hebdomadaire',
        monthly: 'Mensuelle',
        custom: 'Expression Cron'
    };

    const cronPresets = [
        { expression: '0 * * * *', label: 'Toutes les heures', description: 'Déclenchement à chaque début d\'heure' },
        { expression: '0 */6 * * *', label: 'Toutes les 6 heures', description: 'Exécution toutes les six heures' },
        { expression: '30 2 * * *', label: 'Chaque jour à 02:30', description: 'Sauvegarde nocturne quotidienne' },
        { expression: '0 3 * * mon-fri', label: 'Jours ouvrés à 03:00', description: 'Du lundi au vendredi à 03:00' },
        { expression: '0 22 * * sun', label: 'Dimanche 22:00', description: 'Chaque dimanche soir' }
    ];

    const cronScenarios = [
        {
            id: 'pre_deploy',
            label: 'Snapshot pré-déploiement',
            description: 'Rafraîchit la base, les extensions et les thèmes toutes les 10 minutes pendant une fenêtre de changement.',
            expression: '*/10 * * * *',
            adjustments: {
                label: 'Snapshot pré-déploiement',
                components: ['db', 'plugins', 'themes'],
                incremental: false,
                encrypt: true,
                post_checks: ['checksum', 'dry_run']
            }
        },
        {
            id: 'nightly_full',
            label: 'Archive complète nocturne',
            description: 'Capture intégrale chaque nuit à 02:30 avec chiffrement et vérification.',
            expression: '30 2 * * *',
            adjustments: {
                label: 'Archive nocturne',
                components: ['db', 'plugins', 'themes', 'uploads'],
                incremental: false,
                encrypt: true,
                post_checks: ['checksum']
            }
        },
        {
            id: 'weekly_media',
            label: 'Médias hebdomadaires',
            description: 'Synchronise spécifiquement les médias chaque dimanche à 04:00 en incrémental.',
            expression: '0 4 * * sun',
            adjustments: {
                label: 'Médias hebdomadaires',
                components: ['uploads'],
                incremental: true,
                encrypt: false,
                post_checks: []
            }
        }
    ];

    const cronMonthTokens = [
        { value: 'jan', label: 'Janvier (jan)' },
        { value: 'feb', label: 'Février (feb)' },
        { value: 'mar', label: 'Mars (mar)' },
        { value: 'apr', label: 'Avril (apr)' },
        { value: 'may', label: 'Mai (may)' },
        { value: 'jun', label: 'Juin (jun)' },
        { value: 'jul', label: 'Juillet (jul)' },
        { value: 'aug', label: 'Août (aug)' },
        { value: 'sep', label: 'Septembre (sep)' },
        { value: 'oct', label: 'Octobre (oct)' },
        { value: 'nov', label: 'Novembre (nov)' },
        { value: 'dec', label: 'Décembre (dec)' }
    ];

    const cronDayTokens = [
        { value: 'sun', label: 'Dimanche (sun)' },
        { value: 'mon', label: 'Lundi (mon)' },
        { value: 'tue', label: 'Mardi (tue)' },
        { value: 'wed', label: 'Mercredi (wed)' },
        { value: 'thu', label: 'Jeudi (thu)' },
        { value: 'fri', label: 'Vendredi (fri)' },
        { value: 'sat', label: 'Samedi (sat)' }
    ];

    const cronFieldTokens = {
        0: [
            { value: '0', label: 'Minute 00' },
            { value: '15', label: 'Minute 15' },
            { value: '30', label: 'Minute 30' },
            { value: '45', label: 'Minute 45' },
            { value: '*/5', label: 'Toutes les 5 minutes' },
            { value: '*/15', label: 'Toutes les 15 minutes' }
        ],
        1: [
            { value: '*', label: 'Chaque heure' },
            { value: '*/2', label: 'Toutes les 2 heures' },
            { value: '*/4', label: 'Toutes les 4 heures' },
            { value: '*/6', label: 'Toutes les 6 heures' },
            { value: '*/12', label: 'Toutes les 12 heures' }
        ],
        2: [
            { value: '1', label: '1er jour du mois' },
            { value: '1,15', label: '1er et 15 du mois' },
            { value: '*/2', label: 'Un jour sur deux' },
            { value: '*/7', label: 'Tous les 7 jours' }
        ],
        3: cronMonthTokens,
        4: cronDayTokens
    };

    const cronMonthSet = new Set(cronMonthTokens.map(function(token) { return token.value; }));
    const cronDaySet = new Set(cronDayTokens.map(function(token) { return token.value; }));
    const cronFieldLabels = {
        0: 'Minutes',
        1: 'Heures',
        2: 'Jour du mois',
        3: 'Mois',
        4: 'Jour de semaine'
    };

    const cronDayDisplay = {
        sun: 'dimanche',
        mon: 'lundi',
        tue: 'mardi',
        wed: 'mercredi',
        thu: 'jeudi',
        fri: 'vendredi',
        sat: 'samedi'
    };

    const cronFieldCount = 5;
    const cronAllowedPattern = /^[\d\*\-,\/A-Za-z\s]+$/;
    const cronHistoryStorageKey = 'bjlg_cron_history_v1';
    const cronHistoryMaxEntries = 8;

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

    const cronAssistant = typeof bjlg_ajax.cron_assistant === 'object' && bjlg_ajax.cron_assistant
        ? bjlg_ajax.cron_assistant
        : {};
    const cronExamples = Array.isArray(cronAssistant.examples) ? cronAssistant.examples : [];
    const cronLabels = cronAssistant.labels && typeof cronAssistant.labels === 'object' ? cronAssistant.labels : {};
    const cronPreviewCache = new Map();
    let cronPreviewTimer = null;
    let cronPreviewRequest = null;

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

    function canUseLocalStorage() {
        try {
            return typeof window !== 'undefined' && typeof window.localStorage !== 'undefined';
        } catch (error) {
            return false;
        }
    }

    function normalizeCronExpression(expression) {
        return (expression || '').toString().replace(/\s+/g, ' ').trim();
    }

    function loadCronHistoryEntries() {
        if (!canUseLocalStorage()) {
            return [];
        }
        try {
            const raw = window.localStorage.getItem(cronHistoryStorageKey);
            if (typeof raw !== 'string' || raw === '') {
                return [];
            }
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return [];
            }
            return parsed.map(function(entry) {
                if (entry && typeof entry === 'object') {
                    return {
                        expression: normalizeCronExpression(entry.expression),
                        lastUsed: typeof entry.lastUsed === 'number' ? entry.lastUsed : Date.now()
                    };
                }
                return { expression: normalizeCronExpression(entry), lastUsed: Date.now() };
            }).filter(function(entry) {
                return entry.expression && cronAllowedPattern.test(entry.expression);
            });
        } catch (error) {
            return [];
        }
    }

    function saveCronHistoryEntries(entries) {
        if (!canUseLocalStorage()) {
            return;
        }
        try {
            window.localStorage.setItem(cronHistoryStorageKey, JSON.stringify(entries));
        } catch (error) {
            // Ignore storage failures (private mode, quota exceeded, etc.)
        }
    }

    function clearCronHistoryEntries() {
        if (!canUseLocalStorage()) {
            return;
        }
        try {
            window.localStorage.removeItem(cronHistoryStorageKey);
        } catch (error) {
            // Ignore storage failures
        }
    }

    function upsertCronHistoryExpression(expression) {
        const normalized = normalizeCronExpression(expression);
        if (!normalized || !cronAllowedPattern.test(normalized) || !canUseLocalStorage()) {
            return null;
        }
        const entries = loadCronHistoryEntries();
        const filtered = entries.filter(function(entry) {
            return entry.expression !== normalized;
        });
        filtered.unshift({ expression: normalized, lastUsed: Date.now() });
        const trimmed = filtered.slice(0, cronHistoryMaxEntries);
        saveCronHistoryEntries(trimmed);
        return trimmed;
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
            case 'every_five_minutes':
                return 5 * 60 * 1000;
            case 'every_fifteen_minutes':
                return 15 * 60 * 1000;
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

    function clampDayOfMonth(value) {
        if (value === undefined || value === null) {
            return null;
        }
        const parsed = parseInt(value, 10);
        if (!Number.isFinite(parsed)) {
            return null;
        }
        return Math.min(Math.max(parsed, 1), 31);
    }

    function getScheduleDayOfMonth(schedule) {
        if (schedule && Object.prototype.hasOwnProperty.call(schedule, 'day_of_month')) {
            const normalized = clampDayOfMonth(schedule.day_of_month);
            if (normalized !== null) {
                return normalized;
            }
        }

        const fallback = clampDayOfMonth(defaultScheduleData.day_of_month);
        if (fallback !== null) {
            return fallback;
        }

        return 1;
    }

    function getDaysInMonth(year, monthIndex) {
        return new Date(year, monthIndex + 1, 0).getDate();
    }

    function resolveMonthlyOccurrence(baseDate, dayOfMonth, hour, minute) {
        const occurrence = new Date(baseDate.getTime());
        occurrence.setHours(hour, minute, 0, 0);
        occurrence.setDate(1);
        const year = occurrence.getFullYear();
        const monthIndex = occurrence.getMonth();
        const daysInMonth = getDaysInMonth(year, monthIndex);
        occurrence.setDate(Math.min(dayOfMonth, daysInMonth));
        occurrence.setHours(hour, minute, 0, 0);
        return occurrence;
    }

    function computeFallbackNextTimestamp(schedule, referenceSeconds) {
        const recurrence = (schedule && schedule.recurrence ? schedule.recurrence : 'disabled').toString();
        if (recurrence === 'disabled') {
            return null;
        }

        const reference = Number.isFinite(referenceSeconds) ? referenceSeconds : Math.floor(Date.now() / 1000);
        const referenceDate = new Date(reference * 1000);
        const timeParts = parseTimeParts(schedule);

        if (recurrence === 'every_five_minutes' || recurrence === 'every_fifteen_minutes') {
            const intervalMinutes = recurrence === 'every_five_minutes' ? 5 : 15;
            const occurrence = new Date(referenceDate.getTime());
            occurrence.setHours(timeParts.hour, timeParts.minute, 0, 0);
            while (occurrence.getTime() / 1000 <= reference) {
                occurrence.setMinutes(occurrence.getMinutes() + intervalMinutes);
            }
            return Math.floor(occurrence.getTime() / 1000);
        }

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
            const dayOfMonth = getScheduleDayOfMonth(schedule);
            let monthlyOccurrence = resolveMonthlyOccurrence(referenceDate, dayOfMonth, timeParts.hour, timeParts.minute);
            if (monthlyOccurrence.getTime() / 1000 <= reference) {
                const nextBase = addMonths(monthlyOccurrence, 1);
                monthlyOccurrence = resolveMonthlyOccurrence(nextBase, dayOfMonth, timeParts.hour, timeParts.minute);
            }
            return Math.floor(monthlyOccurrence.getTime() / 1000);
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
            const timeParts = parseTimeParts(schedule);
            const dayOfMonth = getScheduleDayOfMonth(schedule);
            if (recurrence === 'monthly') {
                occurrence = resolveMonthlyOccurrence(occurrence, dayOfMonth, timeParts.hour, timeParts.minute);
            }
            const interval = getIntervalMs(recurrence);
            let guard = 0;

            if (occurrence < start && (interval > 0 || recurrence === 'monthly')) {
                while (occurrence < start && guard < 200) {
                    if (recurrence === 'monthly') {
                        const nextBase = addMonths(occurrence, 1);
                        occurrence = resolveMonthlyOccurrence(nextBase, dayOfMonth, timeParts.hour, timeParts.minute);
                    } else {
                        occurrence = new Date(occurrence.getTime() + interval);
                    }
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
                    const nextBase = addMonths(occurrence, 1);
                    occurrence = resolveMonthlyOccurrence(nextBase, dayOfMonth, timeParts.hour, timeParts.minute);
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
        const $monthly = $item.find('.bjlg-schedule-monthly-options');
        const $time = $item.find('.bjlg-schedule-time-options');
        const $custom = $item.find('.bjlg-schedule-custom-options');

        if ($weekly.length) {
            if (recurrence === 'weekly') {
                $weekly.show().attr('aria-hidden', 'false');
            } else {
                $weekly.hide().attr('aria-hidden', 'true');
            }
        }

        if ($monthly.length) {
            if (recurrence === 'monthly') {
                $monthly.show().attr('aria-hidden', 'false');
            } else {
                $monthly.hide().attr('aria-hidden', 'true');
            }
        }

        if ($time.length) {
            if (recurrence === 'disabled' || recurrence === 'custom') {
                $time.hide().attr('aria-hidden', 'true');
            } else {
                $time.show().attr('aria-hidden', 'false');
            }
        }

        if ($custom.length) {
            if (recurrence === 'custom') {
                $custom.show().attr('aria-hidden', 'false');
                initCronAssistant($item);
            } else {
                $custom.hide().attr('aria-hidden', 'true');
                resetCronAssistant($item);
            }
        }

        if (recurrence === 'custom') {
            refreshCronAssistant($item, true);
        } else {
            clearCronAssistant($item);
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
        return value.split(/[\r\n,]+/).map(function(entry) {
            return entry.trim();
        }).filter(Boolean);
    }

    function patternsToTextarea(value) {
        if (Array.isArray(value)) {
            return value.join('\n');
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

    function getDefaultCronFieldValue(index) {
        switch (index) {
            case 0:
                return '0';
            case 1:
                return '*';
            default:
                return '*';
        }
    }

    function parseCronFields(expression) {
        const trimmed = (expression || '').toString().trim();
        if (!trimmed) {
            return [];
        }
        return trimmed.split(/\s+/).slice(0, cronFieldCount);
    }

    function applyCronToken($input, fieldIndex, tokenValue) {
        if (!$input || !$input.length) {
            return;
        }
        const index = parseInt(fieldIndex, 10);
        if (Number.isNaN(index) || index < 0 || index >= cronFieldCount) {
            return;
        }
        const value = (tokenValue || '').toString();
        if (!value) {
            return;
        }

        const currentFields = parseCronFields($input.val());
        const fields = [];
        for (let i = 0; i < cronFieldCount; i += 1) {
            fields[i] = typeof currentFields[i] !== 'undefined'
                ? currentFields[i]
                : getDefaultCronFieldValue(i);
        }
        fields[index] = value;

        const updated = fields.join(' ').replace(/\s+/g, ' ').trim();
        $input.val(updated).trigger('input').trigger('change');
        $input.focus();
    }

    function buildCronTokenPanel($container, $item) {
        if (!$container || !$container.length || $container.data('initialized')) {
            return;
        }

        const $input = $item.find('[data-field="custom_cron"]');
        if (!$input.length) {
            return;
        }

        const fragment = $(document.createDocumentFragment());
        Object.keys(cronFieldTokens).forEach(function(key) {
            const fieldIndex = parseInt(key, 10);
            const tokens = cronFieldTokens[fieldIndex];
            if (!Array.isArray(tokens) || !tokens.length) {
                return;
            }

            const $group = $('<div/>', {
                class: 'bjlg-cron-assistant__tokens-group',
                'data-cron-token-group': fieldIndex
            });
            const label = cronFieldLabels[fieldIndex] || ('Champ #' + (fieldIndex + 1));
            $('<span/>', { class: 'bjlg-cron-assistant__tokens-title', text: label }).appendTo($group);

            const $buttons = $('<div/>', { class: 'bjlg-cron-assistant__tokens-buttons' });
            tokens.forEach(function(token) {
                if (!token || typeof token !== 'object') {
                    return;
                }
                const value = (token.value || '').toString();
                if (!value) {
                    return;
                }
                const buttonLabel = (token.label || value).toString();
                $('<button/>', {
                    type: 'button',
                    class: 'bjlg-cron-token',
                    text: buttonLabel,
                    'data-cron-token': value,
                    'data-cron-token-field': fieldIndex,
                }).appendTo($buttons);
            });

            if ($buttons.children().length) {
                $group.append($buttons);
                fragment.append($group);
            }
        });

        if (!fragment.children().length) {
            return;
        }

        $container.empty().append(fragment);
        $container.on('click', '[data-cron-token]', function(event) {
            event.preventDefault();
            const $button = $(this);
            applyCronToken($input, $button.attr('data-cron-token-field'), $button.attr('data-cron-token'));
        });

        $container.data('initialized', '1');
    }

    function getCronFieldIndexFromCaret($input) {
        if (!$input || !$input.length) {
            return 0;
        }
        const element = $input.get(0);
        if (!element) {
            return 0;
        }
        const value = (element.value || '').toString();
        if (!value) {
            return 0;
        }
        const caret = typeof element.selectionStart === 'number' ? element.selectionStart : value.length;
        const beforeCaret = value.slice(0, caret);
        const segments = beforeCaret.split(/\s+/).filter(function(entry) { return entry !== ''; });
        let index = segments.length - 1;
        if (/\s$/.test(beforeCaret)) {
            index += 1;
        }
        if (index < 0) {
            index = 0;
        }
        if (index >= cronFieldCount) {
            index = cronFieldCount - 1;
        }
        return index;
    }

    function getCronFieldBoundaries(value) {
        const bounds = [];
        const raw = (value || '').toString();
        const length = raw.length;
        let inToken = false;
        let tokenStart = 0;

        for (let i = 0; i < length; i += 1) {
            const char = raw.charAt(i);
            if (/\s/.test(char)) {
                if (inToken) {
                    bounds.push({ start: tokenStart, end: i });
                    inToken = false;
                    if (bounds.length >= cronFieldCount) {
                        break;
                    }
                }
            } else if (!inToken) {
                tokenStart = i;
                inToken = true;
            }
        }

        if (inToken && bounds.length < cronFieldCount) {
            bounds.push({ start: tokenStart, end: length });
        }

        return bounds;
    }

    function focusCronFieldSegment($input, index) {
        if (!$input || !$input.length) {
            return;
        }
        const element = $input.get(0);
        if (!element) {
            return;
        }
        const bounds = getCronFieldBoundaries(element.value || '');
        let start = element.value ? element.value.length : 0;
        let end = start;
        if (bounds[index]) {
            start = bounds[index].start;
            end = bounds[index].end;
        }
        window.requestAnimationFrame(function() {
            element.focus();
            if (typeof element.setSelectionRange === 'function') {
                element.setSelectionRange(start, end);
            }
        });
    }

    function highlightCronTokenGroups(helper, activeIndex) {
        if (!helper || !helper.tokens || !helper.tokens.length) {
            return;
        }
        helper.tokens.find('[data-cron-token-group]').each(function() {
            const $group = $(this);
            const fieldIndex = parseInt($group.attr('data-cron-token-group'), 10);
            if (!Number.isNaN(fieldIndex) && fieldIndex === activeIndex) {
                $group.addClass('is-active');
            } else {
                $group.removeClass('is-active');
            }
        });
    }

    function renderCronFieldGuidance(helper, expression, activeIndex, $input) {
        if (!helper || !helper.guidance || !helper.guidance.length) {
            return;
        }

        const fields = parseCronFields(expression);
        const normalized = [];
        for (let i = 0; i < cronFieldCount; i += 1) {
            normalized[i] = typeof fields[i] !== 'undefined' ? fields[i] : '';
        }

        const fragment = $(document.createDocumentFragment());
        normalized.forEach(function(value, index) {
            const label = cronFieldLabels[index] || ('Champ #' + (index + 1));
            const $field = $('<button/>', {
                type: 'button',
                class: 'bjlg-cron-assistant__field',
                'data-cron-guidance-index': index
            });
            if (index === activeIndex) {
                $field.addClass('is-active');
            }
            $('<span/>', { class: 'bjlg-cron-assistant__field-label', text: label }).appendTo($field);
            const display = value ? value : 'À compléter';
            const valueClass = value ? 'bjlg-cron-assistant__field-value' : 'bjlg-cron-assistant__field-value bjlg-cron-assistant__field-empty';
            $('<span/>', { class: valueClass, text: display }).appendTo($field);
            fragment.append($field);
        });

        helper.guidance.empty().append(fragment);

        if (!helper.guidance.data('listeners')) {
            helper.guidance.on('click', '[data-cron-guidance-index]', function(event) {
                event.preventDefault();
                const index = parseInt($(this).attr('data-cron-guidance-index'), 10);
                if (Number.isNaN(index)) {
                    return;
                }
                focusCronFieldSegment($input, index);
            });
            helper.guidance.data('listeners', '1');
        }
    }

    function markActiveScenario(helper, expression) {
        if (!helper || !helper.scenarios || !helper.scenarios.length) {
            return;
        }
        const trimmed = (expression || '').toString().trim();
        helper.scenarios.find('[data-cron-scenario]').each(function() {
            const $button = $(this);
            const scenarioExpression = ($button.attr('data-cron-scenario-expression') || '').toString();
            if (trimmed && trimmed === scenarioExpression) {
                $button.addClass('is-active');
            } else {
                $button.removeClass('is-active');
            }
        });
    }

    function applyCronScenario($item, scenario) {
        if (!$item || !scenario || typeof scenario !== 'object') {
            return;
        }
        const $input = $item.find('[data-field="custom_cron"]');
        if (!$input.length) {
            return;
        }

        const expression = (scenario.expression || '').toString();
        if (expression) {
            $input.val(expression).trigger('input').trigger('change');
        }

        const $recurrence = $item.find('[data-field="recurrence"]');
        if ($recurrence.length && $recurrence.val() !== 'custom') {
            const current = ($recurrence.val() || '').toString();
            $item.find('[data-field="previous_recurrence"]').val(current);
            $recurrence.val('custom').trigger('change');
        }

        const adjustments = scenario.adjustments || {};
        const $labelInput = $item.find('[data-field="label"]');
        if ($labelInput.length) {
            const currentLabel = ($labelInput.val() || '').toString().trim();
            const desiredLabel = (adjustments.label || scenario.label || '').toString();
            if (!currentLabel && desiredLabel) {
                $labelInput.val(desiredLabel).trigger('input');
            }
        }

        if (Array.isArray(adjustments.components)) {
            const desiredComponents = new Set(adjustments.components.map(function(entry) { return entry.toString(); }));
            $item.find('[data-field="components"]').each(function() {
                const $checkbox = $(this);
                const value = ($checkbox.val() || '').toString();
                $checkbox.prop('checked', desiredComponents.has(value));
            }).trigger('change');
        }

        if (typeof adjustments.incremental === 'boolean') {
            const $incremental = $item.find('[data-field="incremental"]');
            if ($incremental.length) {
                $incremental.prop('checked', adjustments.incremental).trigger('change');
            }
        }

        if (typeof adjustments.encrypt === 'boolean') {
            const $encrypt = $item.find('[data-field="encrypt"]');
            if ($encrypt.length) {
                $encrypt.prop('checked', adjustments.encrypt).trigger('change');
            }
        }

        if (Array.isArray(adjustments.post_checks)) {
            const desiredChecks = new Set(adjustments.post_checks.map(function(entry) { return entry.toString(); }));
            $item.find('[data-field="post_checks"]').each(function() {
                const $checkbox = $(this);
                const value = ($checkbox.val() || '').toString();
                $checkbox.prop('checked', desiredChecks.has(value));
            }).trigger('change');
        }

        const helper = ensureCronAssistant($item);
        if (helper) {
            markActiveScenario(helper, expression);
            const elements = getCronFieldElements($input);
            if (elements) {
                setCronPanelVisibility(elements, true);
            }
        }

        updateCronAssistantContext($input);
        refreshCronAssistant($item, true);
    }

    function buildCronScenarioPanel($container, $item) {
        if (!$container || !$container.length || $container.data('initialized')) {
            return;
        }
        if (!Array.isArray(cronScenarios) || !cronScenarios.length) {
            $container.hide();
            return;
        }

        const fragment = $(document.createDocumentFragment());
        cronScenarios.forEach(function(scenario) {
            if (!scenario || typeof scenario !== 'object') {
                return;
            }
            const expression = (scenario.expression || '').toString();
            const label = (scenario.label || expression).toString();
            const description = (scenario.description || '').toString();
            const $button = $('<button/>', {
                type: 'button',
                class: 'bjlg-cron-scenario',
                'data-cron-scenario': scenario.id || label,
                'data-cron-scenario-expression': expression
            });
            $('<span/>', { class: 'bjlg-cron-scenario__title', text: label }).appendTo($button);
            if (description) {
                $('<span/>', { class: 'bjlg-cron-scenario__description', text: description }).appendTo($button);
            }
            $button.on('click', function(event) {
                event.preventDefault();
                applyCronScenario($item, scenario);
            });
            fragment.append($button);
        });

        $container.empty().append(fragment).data('initialized', '1');
    }

    function updateCronAssistantContext($input) {
        if (!$input || !$input.length) {
            return;
        }
        const $item = $input.closest('.bjlg-schedule-item');
        const helper = ensureCronAssistant($item);
        if (!helper) {
            return;
        }
        const expression = ($input.val() || '').toString();
        const activeIndex = getCronFieldIndexFromCaret($input);
        renderCronFieldGuidance(helper, expression, activeIndex, $input);
        highlightCronTokenGroups(helper, activeIndex);
        markActiveScenario(helper, expression);
    }

    function isWildcardCronField(value) {
        const normalized = (value || '').toString().trim().toLowerCase();
        return normalized === '*' || normalized === '?'
            || normalized.indexOf('*/') === 0
            || normalized === '0-59'
            || normalized === '0-23';
    }

    function estimateMinuteInterval(value) {
        const normalized = (value || '').toString().trim().toLowerCase();
        if (!normalized) {
            return null;
        }

        if (normalized === '*' || normalized === '*/1' || normalized === '*/01' || normalized === '0-59') {
            return 1;
        }

        let match = normalized.match(/^\*\/(\d+)$/);
        if (!match) {
            match = normalized.match(/^0\/(\d+)$/);
        }
        if (!match) {
            match = normalized.match(/^[0-5]?\d-[0-5]?\d\/(\d+)$/);
        }
        if (match) {
            const step = parseInt(match[1], 10);
            if (!Number.isNaN(step) && step > 0) {
                return step;
            }
        }

        if (normalized.indexOf(',') !== -1) {
            const values = normalized.split(',').map(function(entry) {
                const parsed = parseInt(entry, 10);
                return Number.isNaN(parsed) ? null : parsed;
            }).filter(function(entry) { return entry !== null; });

            if (values.length > 1) {
                values.sort(function(a, b) { return a - b; });
                let minDiff = 60;
                for (let i = 1; i < values.length; i += 1) {
                    minDiff = Math.min(minDiff, values[i] - values[i - 1]);
                }
                const wrapDiff = (values[0] + 60) - values[values.length - 1];
                minDiff = Math.min(minDiff, wrapDiff);
                if (minDiff > 0 && minDiff < 60) {
                    return minDiff;
                }
            }
        }

        return null;
    }

    function analyzeCronExpression(expression) {
        const result = { severity: 'success', messages: [] };
        const trimmed = (expression || '').toString().trim();
        if (!trimmed) {
            return result;
        }

        if (!cronAllowedPattern.test(trimmed)) {
            result.severity = 'error';
            result.messages.push('Certains caractères ne sont pas pris en charge.');
            return result;
        }

        const fields = trimmed.split(/\s+/);
        if (fields.length !== cronFieldCount) {
            result.severity = 'error';
            result.messages.push('Utilisez exactement cinq champs (minute, heure, jour, mois, jour de semaine).');
            return result;
        }

        const minuteField = fields[0];
        const hourField = fields[1];
        const dayOfMonthField = fields[2];
        const dayOfWeekField = fields[4];

        const minuteInterval = estimateMinuteInterval(minuteField);
        const isHourWildcard = isWildcardCronField(hourField);

        if (minuteInterval !== null && minuteInterval < 5 && isHourWildcard) {
            const label = minuteInterval === 1 ? 'toutes les minutes' : 'toutes les ' + minuteInterval + ' minutes';
            result.messages.push('L’expression exécute la sauvegarde ' + label + '. Vérifiez que l’environnement peut absorber cette cadence.');
        }

        const isDayOfMonthRestricted = !isWildcardCronField(dayOfMonthField) && dayOfMonthField !== '*';
        const isDayOfWeekRestricted = !isWildcardCronField(dayOfWeekField) && dayOfWeekField !== '*';

        if (isDayOfMonthRestricted && isDayOfWeekRestricted) {
            result.messages.push('Les champs « jour du mois » et « jour de semaine » sont tous deux filtrés. La sauvegarde ne s’exécutera que lorsque les deux conditions sont réunies.');
        }

        if (result.messages.length) {
            result.severity = result.messages.some(function(message) {
                return /ne s’exécutera/.test(message);
            }) ? 'warning' : 'warning';
        }

        return result;
    }

    function renderCronLocalWarnings($item, expression) {
        const helper = getCronAssistantHelper($item);
        if (!helper || !helper.container.length) {
            return 'success';
        }

        const $warnings = helper.container.find('[data-cron-warnings]');
        if (!$warnings.length) {
            return 'success';
        }

        const analysis = analyzeCronExpression(expression);
        $warnings.empty();

        if (!expression || !analysis.messages.length) {
            return 'success';
        }

        const cssClass = analysis.severity === 'error' ? 'bjlg-cron-warning--error' : 'bjlg-cron-warning--warning';
        analysis.messages.forEach(function(message) {
            $('<p/>', {
                class: 'bjlg-cron-warning ' + cssClass,
                text: message
            }).appendTo($warnings);
        });

        return analysis.severity;
    }

    function getCronAssistantHelper($item) {
        if (!$item || !$item.length) {
            return null;
        }
        const $assistant = $item.find('[data-cron-assistant]');
        if (!$assistant.length) {
            return null;
        }
        return {
            container: $assistant,
            tokens: $assistant.find('[data-cron-tokens]'),
            guidance: $assistant.find('[data-cron-guidance]'),
            scenarios: $assistant.find('[data-cron-scenarios]'),
            history: $assistant.find('[data-cron-history]'),
            historyList: $assistant.find('[data-cron-history-list]'),
            historyEmpty: $assistant.find('[data-cron-history-empty]'),
            historyClear: $assistant.find('[data-cron-history-clear]'),
            examples: $assistant.find('[data-cron-examples]'),
            preview: $assistant.find('[data-cron-preview]'),
            list: $assistant.find('[data-cron-preview-list]'),
            status: $assistant.find('[data-cron-status]'),
            empty: $assistant.find('[data-cron-empty]'),
        };
    }

    function renderCronHistory(helper, entries) {
        if (!helper || !helper.history || !helper.history.length) {
            return;
        }

        if (!canUseLocalStorage()) {
            helper.history.hide();
            return;
        }

        const listEntries = Array.isArray(entries) ? entries : loadCronHistoryEntries();
        const sorted = listEntries.slice().sort(function(a, b) {
            return (b && b.lastUsed ? b.lastUsed : 0) - (a && a.lastUsed ? a.lastUsed : 0);
        }).filter(function(entry) {
            return entry && entry.expression;
        });

        if (helper.historyList && helper.historyList.length) {
            helper.historyList.empty();
        }

        if (!sorted.length) {
            if (helper.historyEmpty && helper.historyEmpty.length) {
                helper.historyEmpty.removeAttr('hidden').show();
            }
            if (helper.historyClear && helper.historyClear.length) {
                helper.historyClear.prop('disabled', true);
            }
            helper.history.show();
            return;
        }

        sorted.forEach(function(entry) {
            const expression = entry.expression;
            const $chip = $('<button/>', {
                type: 'button',
                class: 'bjlg-cron-history__chip',
                text: expression,
                'data-cron-history-expression': expression,
                'aria-label': 'Réutiliser l’expression « ' + expression + ' »'
            });
            if (helper.historyList && helper.historyList.length) {
                $('<div/>', {
                    class: 'bjlg-cron-history__item',
                    role: 'listitem'
                }).append($chip).appendTo(helper.historyList);
            }
        });

        if (helper.historyEmpty && helper.historyEmpty.length) {
            helper.historyEmpty.attr('hidden', 'hidden').hide();
        }
        if (helper.historyClear && helper.historyClear.length) {
            helper.historyClear.prop('disabled', false);
        }
        helper.history.show();
    }

    function initializeCronHistory(helper, $item) {
        if (!helper || !helper.history || !helper.history.length || helper.history.data('initialized')) {
            return;
        }

        if (!canUseLocalStorage()) {
            helper.history.hide();
            helper.history.data('initialized', '1');
            return;
        }

        helper.history.data('initialized', '1');
        renderCronHistory(helper);

        if (helper.historyList && helper.historyList.length && !helper.historyList.data('listeners')) {
            helper.historyList.on('click', '[data-cron-history-expression]', function(event) {
                event.preventDefault();
                const expression = ($(this).attr('data-cron-history-expression') || '').toString();
                if (!expression) {
                    return;
                }
                const $input = $item.find('[data-field="custom_cron"]');
                if ($input.length) {
                    $input.val(expression).trigger('input').trigger('change');
                    $input.focus();
                }
            });
            helper.historyList.data('listeners', '1');
        }

        if (helper.historyClear && helper.historyClear.length && !helper.historyClear.data('listeners')) {
            helper.historyClear.on('click', function(event) {
                event.preventDefault();
                clearCronHistoryEntries();
                renderCronHistory(helper, []);
            });
            helper.historyClear.data('listeners', '1');
        }
    }

    function ensureCronAssistant($item) {
        const helper = getCronAssistantHelper($item);
        if (!helper) {
            return null;
        }

        initializeCronHistory(helper, $item);

        if (!helper.examples.length || helper.examples.data('initialized')) {
            if (helper.status.length && typeof helper.status.data('default') === 'undefined') {
                helper.status.data('default', helper.status.text());
            }
            return helper;
        }

        helper.examples.data('initialized', '1');
        if (helper.status.length && typeof helper.status.data('default') === 'undefined') {
            helper.status.data('default', helper.status.text());
        }
        cronExamples.forEach(function(example) {
            if (!example || typeof example !== 'object') {
                return;
            }
            const expression = (example.expression || '').toString();
            if (!expression) {
                return;
            }
            const label = (example.label || expression).toString();
            const $button = $('<button/>', {
                type: 'button',
                class: 'bjlg-cron-example',
                text: label,
            });
            if (cronLabels.apply_example) {
                $button.attr('aria-label', cronLabels.apply_example.replace('%s', label));
            }
            $button.on('click', function() {
                const $input = $item.find('[data-field="custom_cron"]');
                $input.val(expression).trigger('input').trigger('change');
                $input.focus();
            });
            helper.examples.append($button);
        });

        buildCronTokenPanel(helper.tokens, $item);
        buildCronScenarioPanel(helper.scenarios, $item);
        initializeCronHistory(helper, $item);

        return helper;
    }

    function renderCronPreviewState($item, payload) {
        const helper = getCronAssistantHelper($item);
        if (!helper) {
            return;
        }

        const $input = $item.find('[data-field="custom_cron"]');
        const $status = helper.status;
        const $preview = helper.preview;
        const $list = helper.list;
        const $empty = helper.empty;

        const expression = payload && payload.expression ? payload.expression : '';
        if ($input.length) {
            updateCronAssistantContext($input);
        }
        const localSeverity = renderCronLocalWarnings($item, expression);

        if (!payload || !payload.expression) {
            if ($preview.length) {
                $preview.attr('hidden', 'hidden');
            }
            if ($list.length) {
                $list.empty();
            }
            if ($status.length) {
                const message = cronLabels.empty || $status.data('default') || $status.text() || '';
                $status.text(message)
                    .removeClass('bjlg-cron-status--success bjlg-cron-status--warning bjlg-cron-status--error');
            }
            if ($empty.length) {
                $empty.show();
            }
            if ($input.length) {
                $input
                    .removeClass('bjlg-field-error bjlg-cron-input--success bjlg-cron-input--warning bjlg-cron-input--error')
                    .removeAttr('aria-invalid');
            }
            return;
        }

        if ($empty.length) {
            $empty.hide();
        }

        if (payload.loading) {
            if ($status.length) {
                $status.text(cronLabels.loading || 'Analyse en cours…')
                    .removeClass('bjlg-cron-status--success bjlg-cron-status--warning bjlg-cron-status--error');
            }
            if ($preview.length) {
                $preview.attr('hidden', 'hidden');
            }
            if ($list.length) {
                $list.empty();
            }
            if ($input.length) {
                $input.removeClass('bjlg-field-error').removeAttr('aria-invalid');
            }
            return;
        }

        const severity = payload.severity || (Array.isArray(payload.errors) && payload.errors.length ? 'error' : (Array.isArray(payload.warnings) && payload.warnings.length ? 'warning' : 'success'));
        const severityRank = { success: 0, warning: 1, error: 2 };
        const combinedSeverity = severityRank[localSeverity] > severityRank[severity] ? localSeverity : severity;
        let message = typeof payload.message === 'string' ? payload.message : '';

        if (!message) {
            if (combinedSeverity === 'error' && Array.isArray(payload.errors) && payload.errors.length) {
                message = payload.errors[0];
            } else if (Array.isArray(payload.warnings) && payload.warnings.length) {
                message = payload.warnings[0];
            } else if (combinedSeverity === 'error' && cronLabels.error) {
                message = cronLabels.error;
            }
        }

        if ($status.length) {
            $status
                .text(message || '')
                .removeClass('bjlg-cron-status--success bjlg-cron-status--warning bjlg-cron-status--error');
            if (message) {
                $status.addClass('bjlg-cron-status--' + combinedSeverity);
            }
        }

        if ($input.length) {
            $input.removeClass('bjlg-cron-input--success bjlg-cron-input--warning bjlg-cron-input--error');
            if (combinedSeverity === 'error') {
                $input.addClass('bjlg-field-error bjlg-cron-input--error').attr('aria-invalid', 'true');
            } else {
                $input.removeClass('bjlg-field-error').removeAttr('aria-invalid');
                if (combinedSeverity === 'warning') {
                    $input.addClass('bjlg-cron-input--warning');
                } else {
                    $input.addClass('bjlg-cron-input--success');
                }
            }
        }

        const runs = Array.isArray(payload.next_runs) ? payload.next_runs : [];
        if ($preview.length) {
            if (runs.length) {
                $preview.removeAttr('hidden');
            } else {
                $preview.attr('hidden', 'hidden');
            }
        }
        if ($list.length) {
            $list.empty();
            runs.forEach(function(run) {
                if (!run || typeof run !== 'object') {
                    return;
                }
                const $itemElement = $('<li/>');
                const formatted = (run.formatted || run.label || '').toString();
                const relative = (run.relative || '').toString();
                const iso = (run.iso || '').toString();
                if (formatted) {
                    $('<time/>', { datetime: iso, text: formatted }).appendTo($itemElement);
                }
                if (relative) {
                    $('<span/>', { class: 'bjlg-cron-assistant__relative', text: relative }).appendTo($itemElement);
                }
                if (!formatted && !relative && run.raw) {
                    $itemElement.text((run.raw || '').toString());
                }
                $list.append($itemElement);
            });
        }

        if (expression && combinedSeverity !== 'error') {
            const updatedHistory = upsertCronHistoryExpression(expression);
            if (updatedHistory) {
                renderCronHistory(helper, updatedHistory);
            }
        }
    }

    function clearCronAssistant($item) {
        renderCronPreviewState($item, null);
    }

    function queueCronPreview($item, expression) {
        if (cronPreviewTimer) {
            clearTimeout(cronPreviewTimer);
        }
        cronPreviewTimer = setTimeout(function() {
            requestCronPreview($item, expression);
        }, 250);
    }

    function requestCronPreview($item, expression) {
        if (cronPreviewTimer) {
            clearTimeout(cronPreviewTimer);
            cronPreviewTimer = null;
        }

        ensureCronAssistant($item);
        renderCronPreviewState($item, { expression: expression, loading: true });

        if (cronPreviewRequest) {
            cronPreviewRequest.abort();
            cronPreviewRequest = null;
        }

        if (cronPreviewCache.has(expression)) {
            const cached = cronPreviewCache.get(expression);
            if (cached) {
                renderCronPreviewState($item, cached);
            }
            return;
        }

        cronPreviewRequest = $.ajax({
            url: bjlg_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'bjlg_preview_cron_expression',
                nonce: bjlg_ajax.nonce,
                expression: expression
            }
        }).done(function(response) {
            const data = response && typeof response === 'object' ? response.data || {} : {};
            const payload = $.extend({ expression: expression }, data);
            if (response && response.success) {
                payload.severity = payload.severity || (Array.isArray(payload.warnings) && payload.warnings.length ? 'warning' : 'success');
                cronPreviewCache.set(expression, payload);
                renderCronPreviewState($item, payload);
            } else {
                payload.severity = payload.severity || 'error';
                if (!payload.message && typeof data.message === 'string') {
                    payload.message = data.message;
                }
                renderCronPreviewState($item, payload);
            }
        }).fail(function(jqXHR) {
            if (jqXHR && jqXHR.statusText === 'abort') {
                return;
            }
            const data = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON.data || jqXHR.responseJSON : {};
            const payload = {
                expression: expression,
                severity: 'error',
                message: data && typeof data.message === 'string' ? data.message : (cronLabels.error || 'Erreur lors de l’analyse de l’expression.'),
            };
            renderCronPreviewState($item, payload);
        }).always(function() {
            cronPreviewRequest = null;
        });
    }

    function refreshCronAssistant($item, immediate) {
        ensureCronAssistant($item);
        const recurrence = ($item.find('[data-field="recurrence"]').val() || '').toString();
        if (recurrence !== 'custom') {
            clearCronAssistant($item);
            return;
        }

        const $input = $item.find('[data-field="custom_cron"]');
        const expression = ($input.val() || '').toString().trim();

        if (!expression) {
            clearCronAssistant($item);
            return;
        }

        if (immediate) {
            requestCronPreview($item, expression);
        } else {
            queueCronPreview($item, expression);
        }
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

    function getCronFieldElements($input) {
        const $field = $input.closest('[data-cron-field]');
        if (!$field.length) {
            return null;
        }
        const $panel = $field.find('[data-cron-helper]').first();
        const $previewList = $field.find('[data-cron-preview-list]').first();
        const $warnings = $field.find('[data-cron-warnings]').first();
        const $toggle = $field.find('.bjlg-cron-helper-toggle').first();

        return {
            field: $field,
            panel: $panel,
            previewList: $previewList,
            warnings: $warnings,
            toggle: $toggle
        };
    }

    function getCronDefaultMessage($list) {
        if (!$list || !$list.length) {
            return '';
        }
        const stored = $list.attr('data-default-message');
        return typeof stored === 'string' ? stored : '';
    }

    function setCronPanelVisibility(elements, visible) {
        if (!elements) {
            return;
        }
        const $panel = elements.panel;
        const $toggle = elements.toggle;

        if ($panel && $panel.length) {
            if (visible) {
                $panel.removeClass('bjlg-hidden');
            } else {
                $panel.addClass('bjlg-hidden');
            }
        }

        if ($toggle && $toggle.length) {
            const showLabel = ($toggle.attr('data-label-show') || '').toString();
            const hideLabel = ($toggle.attr('data-label-hide') || '').toString();
            if (visible) {
                $toggle.attr('aria-expanded', 'true');
                if (hideLabel) {
                    $toggle.text(hideLabel);
                }
            } else {
                $toggle.attr('aria-expanded', 'false');
                if (showLabel) {
                    $toggle.text(showLabel);
                }
            }
        }
    }

    function setCronPreviewLoading(elements) {
        if (!elements) {
            return;
        }
        if (elements.previewList && elements.previewList.length) {
            elements.previewList.empty().append(
                $('<li/>', { class: 'description', text: 'Analyse de l’expression…' })
            );
        }
        if (elements.warnings && elements.warnings.length) {
            elements.warnings.empty();
        }
    }

    function renderCronWarnings(elements, messages, level) {
        if (!elements || !elements.warnings || !elements.warnings.length) {
            return;
        }
        const $warnings = elements.warnings;
        $warnings.empty();

        const entries = [];
        if (Array.isArray(messages)) {
            messages.forEach(function(message) {
                if (typeof message === 'string') {
                    const trimmed = message.trim();
                    if (trimmed) {
                        entries.push(trimmed);
                    }
                }
            });
        }

        if (!entries.length) {
            return;
        }

        const baseClass = level === 'error' ? 'bjlg-cron-warning--error' : 'bjlg-cron-warning--warning';
        entries.forEach(function(entry) {
            $('<p/>', {
                class: 'bjlg-cron-warning ' + baseClass,
                text: entry
            }).appendTo($warnings);
        });
    }

    function clearCronPreview(elements) {
        if (!elements) {
            return;
        }
        if (elements.previewList && elements.previewList.length) {
            const defaultMessage = getCronDefaultMessage(elements.previewList);
            elements.previewList.empty();
            if (defaultMessage) {
                $('<li/>', { class: 'description', text: defaultMessage }).appendTo(elements.previewList);
            }
        }
        if (elements.warnings && elements.warnings.length) {
            elements.warnings.empty();
        }
    }

    function renderCronPreviewData(elements, data) {
        if (!elements || !elements.previewList || !elements.previewList.length) {
            return;
        }

        const occurrences = Array.isArray(data && data.occurrences) ? data.occurrences : [];
        const warnings = Array.isArray(data && data.warnings) ? data.warnings : [];

        elements.previewList.empty();

        if (!occurrences.length) {
            elements.previewList.append(
                $('<li/>', { class: 'description', text: 'Aucune occurrence calculée.' })
            );
        } else {
            occurrences.forEach(function(entry) {
                const formatted = entry && entry.formatted ? entry.formatted.toString() : '';
                const relative = entry && entry.relative ? entry.relative.toString() : '';
                const $item = $('<li/>', { class: 'bjlg-cron-preview-item' });
                if (formatted) {
                    $('<span/>', { class: 'bjlg-cron-preview-date', text: formatted }).appendTo($item);
                }
                if (relative) {
                    $('<span/>', { class: 'bjlg-cron-preview-relative', text: '≈ ' + relative }).appendTo($item);
                }
                elements.previewList.append($item);
            });
        }

        renderCronWarnings(elements, warnings, warnings.length ? 'warning' : 'info');

        if (warnings.length) {
            setCronPanelVisibility(elements, true);
        }
    }

    function renderCronPreviewError(elements, message, details) {
        if (!elements || !elements.previewList || !elements.previewList.length) {
            return;
        }

        const messages = [];
        if (typeof message === 'string' && message.trim()) {
            messages.push(message.trim());
        }
        if (Array.isArray(details)) {
            details.forEach(function(entry) {
                if (typeof entry === 'string') {
                    const trimmed = entry.trim();
                    if (trimmed && messages.indexOf(trimmed) === -1) {
                        messages.push(trimmed);
                    }
                }
            });
        }

        const display = messages.length ? messages[0] : 'Analyse impossible.';
        elements.previewList.empty().append(
            $('<li/>', { class: 'description', text: display })
        );

        renderCronWarnings(elements, messages, 'error');
        setCronPanelVisibility(elements, true);
    }

    function recurrenceIsCustom($input) {
        const $item = $input.closest('.bjlg-schedule-item');
        if (!$item.length) {
            return true;
        }
        const recurrence = ($item.find('[data-field="recurrence"]').val() || '').toString();
        return recurrence === 'custom';
    }

    function initCronAssistant($scope) {
        if (!$scope || !$scope.length) {
            return;
        }

        $scope.find('.bjlg-cron-input').each(function() {
            const $input = $(this);
            if ($input.data('cronAssistantReady')) {
                return;
            }
            $input.data('cronAssistantReady', true);

            const $item = $input.closest('.bjlg-schedule-item');
            const elements = getCronFieldElements($input);
            if (!elements) {
                return;
            }

            setCronPanelVisibility(elements, false);
            clearCronPreview(elements);
            updateCronAssistantContext($input);

            if (elements.toggle && elements.toggle.length) {
                elements.toggle.on('click', function(event) {
                    event.preventDefault();
                    const $button = $(this);
                    const expanded = $button.attr('aria-expanded') === 'true';
                    setCronPanelVisibility(elements, !expanded);
                    if (!expanded) {
                        updateCronAssistantContext($input);
                        refreshCronAssistant($item, true);
                    }
                });
            }

            if (elements.field && elements.field.length) {
                elements.field.on('click', '.bjlg-cron-example', function(event) {
                    event.preventDefault();
                    const value = ($(this).attr('data-expression') || '').toString();
                    if (!value) {
                        return;
                    }
                    $input.val(value).trigger('input');
                    setCronPanelVisibility(elements, true);
                    refreshCronAssistant($item, true);
                });
            }

            const refreshContext = function() {
                updateCronAssistantContext($input);
            };

            $input.on('input', function() {
                refreshContext();
                refreshCronAssistant($item, false);
            });

            $input.on('focus click keyup mouseup', refreshContext);

            $input.on('blur', function() {
                if (($input.val() || '').toString().trim() === '') {
                    clearCronPreview(elements);
                }
            });

            if (($input.val() || '').toString().trim() !== '' && recurrenceIsCustom($input)) {
                refreshCronAssistant($item, false);
            }
        });
    }

    function resetCronAssistant($scope) {
        if (!$scope || !$scope.length) {
            return;
        }
        $scope.find('.bjlg-cron-input').each(function() {
            const $input = $(this);
            const elements = getCronFieldElements($input);
            if (!elements) {
                return;
            }
            clearCronPreview(elements);
            setCronPanelVisibility(elements, false);
        });
    }

    function buildSummaryGroupsFromData(data) {
        const recurrence = (data.recurrence || 'disabled').toString();
        const frequencyBadges = [];
        if (recurrence === 'custom') {
            const expression = (data.custom_cron || '').toString().trim();
            const label = expression || 'Expression requise';
            const classes = ['bjlg-badge-recurrence', 'bjlg-badge-recurrence-custom'];
            if (!expression) {
                classes.push('bjlg-badge-state-off');
            }
            frequencyBadges.push({
                label: label,
                color: expression ? '#f59e0b' : '#f43f5e',
                classes: classes
            });
        } else {
            const label = recurrenceLabels[recurrence] || recurrence || '—';
            const classes = ['bjlg-badge-recurrence'];
            let color = '#0ea5e9';
            if (recurrence === 'disabled') {
                color = '#4b5563';
                classes.push('bjlg-badge-state-off');
            }
            frequencyBadges.push({ label: label, color: color, classes: classes });

            if (recurrence === 'daily' || recurrence === 'weekly' || recurrence === 'monthly' || recurrence === 'twice_daily') {
                const timeLabel = (data.time || '').toString();
                if (timeLabel) {
                    frequencyBadges.push({ label: 'Heure ' + timeLabel, color: '#3b82f6', classes: ['bjlg-badge-time'] });
                }
            }
        }

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
            { title: 'Fréquence', badges: frequencyBadges.length ? frequencyBadges : [{ label: '—', color: '#4b5563', classes: ['bjlg-badge-recurrence'] }] },
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
        const dayOfMonthRaw = ($item.find('[data-field="day_of_month"]').val() || '').toString();
        const customCronRaw = ($item.find('[data-field="custom_cron"]').val() || '').toString();
        let dayOfMonth = clampDayOfMonth(dayOfMonthRaw);
        if (dayOfMonth === null) {
            dayOfMonth = getScheduleDayOfMonth({});
        }
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

        const customCronValue = recurrence === 'custom' ? customCronRaw.toString() : '';
        const trimmedCron = customCronValue.trim();

        const data = {
            id: id,
            label: label,
            recurrence: recurrence,
            previous_recurrence: previousRecurrence,
            day: day,
            day_of_month: dayOfMonth,
            time: time,
            custom_cron: recurrence === 'custom' ? (forSummary ? trimmedCron : customCronValue) : '',
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
        $item.find('[data-field="custom_cron"]').val(schedule && schedule.custom_cron ? schedule.custom_cron : '');
        $item.find('[data-field="day_of_month"]').val(getScheduleDayOfMonth(schedule));

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

        initCronAssistant($item);
        toggleScheduleRows($item);
        ensureCronAssistant($item);
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

    $scheduleForm.on('change', '.bjlg-schedule-item [data-field="components"], .bjlg-schedule-item [data-field="encrypt"], .bjlg-schedule-item [data-field="incremental"], .bjlg-schedule-item [data-field="day"], .bjlg-schedule-item [data-field="day_of_month"], .bjlg-schedule-item [data-field="time"], .bjlg-schedule-item [data-field="custom_cron"], .bjlg-schedule-item [data-field="post_checks"], .bjlg-schedule-item [data-field="secondary_destinations"]', function() {
        const $item = $(this).closest('.bjlg-schedule-item');
        if ($(this).is('[data-field="custom_cron"]')) {
            refreshCronAssistant($item, true);
        }
        updateScheduleSummaryForItem($item);
        updateState(collectSchedulesForRequest(), state.nextRuns);
    });

    $scheduleForm.on('input', '.bjlg-schedule-item [data-field="label"], .bjlg-schedule-item textarea[data-field], .bjlg-schedule-item [data-field="custom_cron"]', function() {
        const $item = $(this).closest('.bjlg-schedule-item');
        if ($(this).is('[data-field="custom_cron"]')) {
            refreshCronAssistant($item, false);
        }
        updateScheduleSummaryForItem($item);
        updateState(collectSchedulesForRequest(), state.nextRuns);
    });

    scheduleItems().each(function() {
        setupCronAssistantForItem($(this));
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
});
