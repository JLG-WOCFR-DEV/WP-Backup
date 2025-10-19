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

    const cronPreviewCache = new Map();
    let cronPreviewRequest = null;
    let cronPreviewTimer = null;

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

    function scheduleCronPreview($input, immediate) {
        if (!$input || !$input.length) {
            return;
        }
        const elements = getCronFieldElements($input);
        if (!elements) {
            return;
        }

        const rawValue = ($input.val() || '').toString();
        const expression = rawValue.trim();

        if (!recurrenceIsCustom($input)) {
            resetCronAssistant($input.closest('.bjlg-schedule-item'));
            return;
        }

        if (expression === '') {
            clearCronPreview(elements);
            return;
        }

        if (cronPreviewTimer) {
            clearTimeout(cronPreviewTimer);
        }

        const runner = function() {
            requestCronPreview(expression, elements);
        };

        if (immediate) {
            runner();
        } else {
            cronPreviewTimer = setTimeout(runner, 320);
        }
    }

    function requestCronPreview(expression, elements) {
        if (!expression) {
            clearCronPreview(elements);
            return;
        }

        if (cronPreviewRequest && typeof cronPreviewRequest.abort === 'function') {
            cronPreviewRequest.abort();
        }

        if (cronPreviewCache.has(expression)) {
            renderCronPreviewData(elements, cronPreviewCache.get(expression));
            return;
        }

        setCronPreviewLoading(elements);

        cronPreviewRequest = $.ajax({
            url: bjlg_ajax.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'bjlg_preview_cron_expression',
                nonce: bjlg_ajax.nonce,
                expression: expression
            }
        }).done(function(response) {
            if (!response) {
                renderCronPreviewError(elements, 'Réponse inattendue du serveur.', []);
                return;
            }
            if (response.success) {
                const data = response.data || {};
                cronPreviewCache.set(expression, data);
                renderCronPreviewData(elements, data);
            } else {
                const payload = response.data || response || {};
                const message = payload && typeof payload.message === 'string' ? payload.message : 'Expression Cron invalide.';
                const details = normalizeErrorList(payload && (payload.errors || payload.validation_errors || payload.field_errors));
                renderCronPreviewError(elements, message, details);
            }
        }).fail(function(jqXHR, textStatus) {
            if (textStatus === 'abort') {
                return;
            }
            let message = 'Erreur de communication avec le serveur.';
            let details = [];
            if (jqXHR && jqXHR.responseJSON) {
                const data = jqXHR.responseJSON.data || jqXHR.responseJSON;
                if (data && typeof data.message === 'string') {
                    message = data.message;
                }
                details = normalizeErrorList(data && (data.errors || data.validation_errors || data.field_errors));
            }
            renderCronPreviewError(elements, message, details);
        }).always(function() {
            cronPreviewRequest = null;
        });
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

            const elements = getCronFieldElements($input);
            if (!elements) {
                return;
            }

            setCronPanelVisibility(elements, false);
            clearCronPreview(elements);

            if (elements.toggle && elements.toggle.length) {
                elements.toggle.on('click', function(event) {
                    event.preventDefault();
                    const $button = $(this);
                    const expanded = $button.attr('aria-expanded') === 'true';
                    setCronPanelVisibility(elements, !expanded);
                    if (!expanded) {
                        scheduleCronPreview($input, true);
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
                    scheduleCronPreview($input, true);
                });
            }

            $input.on('input', function() {
                scheduleCronPreview($input, false);
            });

            $input.on('blur', function() {
                if (($input.val() || '').toString().trim() === '') {
                    clearCronPreview(elements);
                }
            });

            if (($input.val() || '').toString().trim() !== '' && recurrenceIsCustom($input)) {
                scheduleCronPreview($input, false);
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
        updateScheduleSummaryForItem($(this).closest('.bjlg-schedule-item'));
        updateState(collectSchedulesForRequest(), state.nextRuns);
    });

    $scheduleForm.on('input', '.bjlg-schedule-item [data-field="label"], .bjlg-schedule-item textarea[data-field], .bjlg-schedule-item [data-field="custom_cron"]', function() {
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
});
