jQuery(function($) {
    'use strict';

    var root = document.getElementById('bjlg-history-timeline');
    if (!root) {
        return;
    }

    var wpGlobal = window.wp || {};
    var element = wpGlobal.element || {};
    var components = wpGlobal.components || {};
    var i18n = wpGlobal.i18n || {};

    if (typeof element.createElement !== 'function') {
        return;
    }

    var createElement = element.createElement;
    var useMemo = typeof element.useMemo === 'function' ? element.useMemo : function(factory) { return factory(); };
    var useState = typeof element.useState === 'function' ? element.useState : function(initial) {
        var state = initial;
        return [state, function(value) { state = value; }];
    };
    var useEffect = typeof element.useEffect === 'function' ? element.useEffect : function() {};
    var Fragment = element.Fragment || 'div';
    var RawHTML = element.RawHTML || function(props) {
        return createElement('span', {
            dangerouslySetInnerHTML: { __html: props && props.children ? props.children : '' }
        });
    };

    var __ = typeof i18n.__ === 'function' ? function(text, domain) { return i18n.__(text, domain || 'backup-jlg'); } : function(text) { return text; };
    var sprintf = typeof i18n.sprintf === 'function' ? i18n.sprintf : function(format) {
        var args = Array.prototype.slice.call(arguments, 1);
        var index = 0;
        return format.replace(/%s/g, function() {
            var value = args[index];
            index += 1;
            return typeof value === 'undefined' ? '' : value;
        });
    };

    var Card = components.Card || Fragment;
    var CardBody = components.CardBody || Fragment;
    var CardHeader = components.CardHeader || Fragment;
    var CardDivider = components.CardDivider || Fragment;
    var Button = components.Button || function(props) {
        var attrs = Object.assign({}, props);
        if (attrs.variant) {
            delete attrs.variant;
        }
        var className = (attrs.className ? attrs.className + ' ' : '') + 'button';
        attrs.className = className;
        if (attrs.children) {
            delete attrs.children;
        }
        return createElement('button', attrs, props && props.children ? props.children : null);
    };
    var DropdownMenu = components.DropdownMenu || null;
    var MenuGroup = components.MenuGroup || Fragment;
    var MenuItem = components.MenuItem || function(props) {
        return createElement('button', {
            type: 'button',
            className: 'button',
            onClick: props && props.onClick
        }, props && props.children ? props.children : null);
    };
    var SearchControl = components.SearchControl || null;
    var Badge = components.Badge || null;

    function parseJSON(raw, fallback) {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return fallback;
        }
        try {
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    }

    var entries = parseJSON(root.getAttribute('data-history'), []);
    if (!Array.isArray(entries)) {
        entries = [];
    }

    var statusFilters = parseJSON(root.getAttribute('data-status-filters'), []);
    if (!Array.isArray(statusFilters) || !statusFilters.length) {
        statusFilters = [{ value: 'all', label: __('Tous les statuts', 'backup-jlg') }];
    }

    var actionFilters = parseJSON(root.getAttribute('data-action-filters'), []);
    if (!Array.isArray(actionFilters) || !actionFilters.length) {
        actionFilters = [{ value: 'all', label: __('Toutes les actions', 'backup-jlg') }];
    }

    var defaultStatus = 'all';
    var defaultAction = 'all';

    statusFilters.forEach(function(filter) {
        if (filter && filter.value === 'all') {
            defaultStatus = filter.value;
        }
    });
    actionFilters.forEach(function(filter) {
        if (filter && filter.value === 'all') {
            defaultAction = filter.value;
        }
    });

    var rangeOptions = [
        { value: '24h', label: __('24 h', 'backup-jlg'), seconds: 24 * 60 * 60 },
        { value: '7d', label: __('7 jours', 'backup-jlg'), seconds: 7 * 24 * 60 * 60 },
        { value: '30d', label: __('30 jours', 'backup-jlg'), seconds: 30 * 24 * 60 * 60 },
        { value: 'all', label: __('Tout afficher', 'backup-jlg'), seconds: null }
    ];

    function formatNumber(value) {
        var numeric = typeof value === 'number' ? value : parseFloat(value);
        if (!Number.isFinite(numeric)) {
            return value || '0';
        }
        try {
            return numeric.toLocaleString();
        } catch (error) {
            return String(numeric);
        }
    }

    function describeRelativeDay(timestampMs) {
        if (!timestampMs) {
            return '';
        }
        var now = new Date();
        var target = new Date(timestampMs);
        var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var startTarget = new Date(target.getFullYear(), target.getMonth(), target.getDate());
        var diff = startToday.getTime() - startTarget.getTime();
        var dayMs = 24 * 60 * 60 * 1000;
        var days = Math.round(diff / dayMs);
        if (days === 0) {
            return __('Aujourd’hui', 'backup-jlg');
        }
        if (days === 1) {
            return __('Hier', 'backup-jlg');
        }
        if (days > 1) {
            return sprintf(__('Il y a %s jours', 'backup-jlg'), days);
        }
        return '';
    }

    function HistoryTimeline() {
        var _useState = useState(defaultStatus);
        var statusFilter = _useState[0];
        var setStatusFilter = _useState[1];

        var _useState2 = useState(defaultAction);
        var actionFilter = _useState2[0];
        var setActionFilter = _useState2[1];

        var _useState3 = useState('7d');
        var rangeFilter = _useState3[0];
        var setRangeFilter = _useState3[1];

        var _useState4 = useState('');
        var searchQuery = _useState4[0];
        var setSearchQuery = _useState4[1];

        useEffect(function() {
            if (typeof window.bjlgHistory === 'undefined') {
                window.bjlgHistory = {};
            }
            window.bjlgHistory.getFilters = function() {
                return {
                    status: statusFilter,
                    action: actionFilter,
                    range: rangeFilter,
                    query: searchQuery
                };
            };
        }, [statusFilter, actionFilter, rangeFilter, searchQuery]);

        var filteredEntries = useMemo(function() {
            var nowMs = Date.now();
            var lowerQuery = searchQuery ? searchQuery.toLocaleLowerCase() : '';
            var selectedRange = rangeOptions.find(function(option) { return option.value === rangeFilter; });
            var maxDiff = selectedRange ? selectedRange.seconds : null;

            return entries.filter(function(entry) {
                if (!entry || typeof entry !== 'object') {
                    return false;
                }

                if (statusFilter && statusFilter !== 'all' && entry.status !== statusFilter && entry.status_intent !== statusFilter) {
                    return false;
                }

                if (actionFilter && actionFilter !== 'all' && entry.action_key !== actionFilter) {
                    return false;
                }

                if (lowerQuery) {
                    var haystack = [entry.action, entry.details_text, entry.user, entry.site]
                        .filter(Boolean)
                        .map(function(value) { return String(value).toLocaleLowerCase(); })
                        .join(' ');
                    if (haystack.indexOf(lowerQuery) === -1) {
                        return false;
                    }
                }

                if (maxDiff && entry.timestamp_unix) {
                    var diffSeconds = (nowMs - (entry.timestamp_unix * 1000)) / 1000;
                    if (diffSeconds > maxDiff) {
                        return false;
                    }
                }

                return true;
            });
        }, [statusFilter, actionFilter, rangeFilter, searchQuery]);

        var groupedEntries = useMemo(function() {
            var groups = new Map();
            filteredEntries.forEach(function(entry) {
                var key = entry.day_key || (entry.timestamp ? entry.timestamp.substring(0, 10) : '');
                if (!groups.has(key)) {
                    groups.set(key, {
                        key: key || 'unknown',
                        label: entry.day_label || entry.day_key || __('Jour inconnu', 'backup-jlg'),
                        items: []
                    });
                }
                groups.get(key).items.push(entry);
            });

            var ordered = Array.from(groups.values());
            ordered.forEach(function(group) {
                group.items.sort(function(a, b) {
                    var timeA = a.timestamp_unix || 0;
                    var timeB = b.timestamp_unix || 0;
                    if (timeA === timeB) {
                        return 0;
                    }
                    return timeA > timeB ? -1 : 1;
                });
                var first = group.items[0];
                group.relative = first && first.timestamp_unix ? describeRelativeDay(first.timestamp_unix * 1000) : '';
            });

            ordered.sort(function(a, b) {
                var aTime = a.items[0] && a.items[0].timestamp_unix ? a.items[0].timestamp_unix : 0;
                var bTime = b.items[0] && b.items[0].timestamp_unix ? b.items[0].timestamp_unix : 0;
                if (aTime === bTime) {
                    return 0;
                }
                return aTime > bTime ? -1 : 1;
            });

            return ordered;
        }, [filteredEntries]);

        var activeRange = rangeOptions.find(function(option) { return option.value === rangeFilter; }) || rangeOptions[0];

        var searchControl = SearchControl
            ? createElement(SearchControl, {
                value: searchQuery,
                onChange: function(value) { setSearchQuery(value || ''); },
                placeholder: __('Rechercher une action…', 'backup-jlg'),
                __nextHasNoMarginBottom: true
            })
            : createElement('input', {
                type: 'search',
                className: 'bjlg-history__search-input',
                value: searchQuery,
                placeholder: __('Rechercher une action…', 'backup-jlg'),
                onChange: function(event) { setSearchQuery(event && event.target ? event.target.value : ''); }
            });

        var rangeToggle = DropdownMenu
            ? createElement(DropdownMenu, {
                icon: 'calendar',
                label: __('Filtrer par période', 'backup-jlg'),
                toggleProps: {
                    variant: 'secondary',
                    className: 'bjlg-history__range-toggle',
                    'aria-label': __('Filtrer la chronologie par période', 'backup-jlg')
                }
            }, function(_ref) {
                var onClose = _ref.onClose;
                return createElement(MenuGroup, null, rangeOptions.map(function(option) {
                    return createElement(MenuItem, {
                        key: option.value,
                        icon: option.value === rangeFilter ? 'saved' : undefined,
                        onClick: function() {
                            setRangeFilter(option.value);
                            if (typeof onClose === 'function') {
                                onClose();
                            }
                        }
                    }, option.label);
                }));
            })
            : null;

        var statusButtons = createElement('div', { className: 'bjlg-history__filter-group' }, statusFilters.map(function(filter) {
            var isActive = filter.value === statusFilter;
            return createElement(Button, {
                key: filter.value,
                variant: isActive ? 'primary' : 'secondary',
                className: 'bjlg-history__filter-button' + (isActive ? ' is-active' : ''),
                onClick: function() { setStatusFilter(filter.value); }
            }, sprintf('%1$s (%2$s)', filter.label || '', formatNumber(filter.count || 0)));
        }));

        var actionButtons = createElement('div', { className: 'bjlg-history__filter-group' }, actionFilters.map(function(filter) {
            var isActive = filter.value === actionFilter;
            return createElement(Button, {
                key: filter.value,
                variant: isActive ? 'primary' : 'secondary',
                className: 'bjlg-history__filter-button' + (isActive ? ' is-active' : ''),
                onClick: function() { setActionFilter(filter.value); }
            }, sprintf('%1$s (%2$s)', filter.label || '', formatNumber(filter.count || 0)));
        }));

        var timelineContent;
        if (!groupedEntries.length) {
            timelineContent = createElement('div', { className: 'bjlg-history__empty-card' },
                __('Aucune action ne correspond aux filtres actuels.', 'backup-jlg')
            );
        } else {
            timelineContent = groupedEntries.map(function(group) {
                return createElement('section', {
                    key: group.key,
                    className: 'bjlg-history__timeline-group'
                }, [
                    createElement('header', { className: 'bjlg-history__timeline-header' }, [
                        createElement('h4', null, group.label || ''),
                        group.relative ? createElement('span', { className: 'bjlg-history__timeline-relative' }, group.relative) : null
                    ]),
                    createElement('ol', { className: 'bjlg-history__timeline-list' }, group.items.map(function(item, index) {
                        var statusBadge = Badge
                            ? createElement(Badge, { status: item.status_intent || 'info' }, item.status_label || '')
                            : createElement('span', { className: 'bjlg-history__status-badge bjlg-history__status-badge--' + (item.status_intent || 'info') }, item.status_label || '');

                        var siteMeta;
                        if (item.site) {
                            siteMeta = item.site_url
                                ? createElement('a', { href: item.site_url, className: 'bjlg-history__meta-link' }, item.site)
                                : createElement('span', { className: 'bjlg-history__meta-text' }, item.site);
                        }

                        return createElement('li', {
                            key: (item.id ? String(item.id) : group.key + '-' + index),
                            className: 'bjlg-history-item bjlg-history-item--' + (item.status_intent || 'info')
                        }, [
                            createElement('div', { className: 'bjlg-history-item__time' }, item.timestamp_label || ''),
                            createElement('article', { className: 'bjlg-history-item__body' }, [
                                createElement('header', { className: 'bjlg-history-item__header' }, [
                                    createElement('span', { className: 'bjlg-history-item__action' }, item.action || ''),
                                    statusBadge
                                ]),
                                item.relative ? createElement('p', { className: 'bjlg-history-item__relative' }, item.relative) : null,
                                createElement('div', { className: 'bjlg-history-item__details' },
                                    createElement(RawHTML, null, item.details_html || '')
                                ),
                                createElement('footer', { className: 'bjlg-history-item__meta' }, [
                                    item.user ? createElement('span', { className: 'bjlg-history-item__meta-text' }, sprintf(__('Utilisateur : %s', 'backup-jlg'), item.user)) : null,
                                    siteMeta,
                                    item.ip ? createElement('span', { className: 'bjlg-history-item__meta-text' }, sprintf(__('IP : %s', 'backup-jlg'), item.ip)) : null
                                ])
                            ])
                        ]);
                    }))
                ]);
            });
        }

        return createElement(Card, { className: 'bjlg-history-card' }, [
            createElement(CardHeader, null,
                createElement('div', { className: 'bjlg-history-card__header' }, [
                    createElement('div', { className: 'bjlg-history-card__title' }, [
                        createElement('h3', null, __('Chronologie des actions', 'backup-jlg')),
                        createElement('p', { className: 'bjlg-history-card__subtitle' }, sprintf(__('Entrées affichées : %s', 'backup-jlg'), formatNumber(filteredEntries.length)))
                    ]),
                    createElement('div', { className: 'bjlg-history-card__controls' }, [
                        searchControl,
                        createElement('div', { className: 'bjlg-history-card__filters' }, [statusButtons, actionButtons]),
                        rangeToggle ? createElement('div', { className: 'bjlg-history-card__range' }, [
                            createElement('span', { className: 'bjlg-history-card__range-label' }, activeRange ? activeRange.label : ''),
                            rangeToggle
                        ]) : null
                    ])
                ])
            ),
            components.CardDivider ? createElement(CardDivider, null) : null,
            createElement(CardBody, null, timelineContent)
        ]);
    }

    var render = typeof element.createRoot === 'function'
        ? function(node, component) {
            element.createRoot(node).render(component);
        }
        : (typeof element.render === 'function'
            ? function(node, component) { element.render(component, node); }
            : function() {});

    render(root, createElement(HistoryTimeline));
});
