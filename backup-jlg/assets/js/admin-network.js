jQuery(function($) {
    'use strict';

    const $app = $('#bjlg-network-admin-app');
    if (!$app.length) {
        return;
    }

    const wpGlobal = window.wp || {};
    const i18n = wpGlobal.i18n || null;
    const __ = function(text, domain) {
        if (i18n && typeof i18n.__ === 'function') {
            return i18n.__(text, domain || 'backup-jlg');
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

    const ajaxData = window.bjlg_ajax || {};
    const networkConfig = ajaxData.network || {};
    const restRoot = typeof ajaxData.rest_root === 'string' ? ajaxData.rest_root : (window.wpApiSettings && window.wpApiSettings.root) || '/wp-json/';
    const restNamespace = typeof ajaxData.rest_namespace === 'string' ? ajaxData.rest_namespace : 'backup-jlg/v1';
    const restNonce = typeof ajaxData.rest_nonce === 'string' ? ajaxData.rest_nonce : '';

    const buildEndpoint = function(path) {
        const root = restRoot.replace(/\/?$/, '/');
        const cleanPath = path.replace(/^\/+/, '');

        return root + cleanPath;
    };

    const endpoints = networkConfig.endpoints || {};
    const endpointSchedules = endpoints.schedules || buildEndpoint(restNamespace + '/settings/schedule');
    const endpointHistory = endpoints.history || buildEndpoint(restNamespace + '/history');
    const endpointStats = endpoints.stats || buildEndpoint(restNamespace + '/stats');

    const parseJSON = function(raw, fallback) {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return fallback;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    };

    const sitesDataset = $app.attr('data-sites') || '[]';
    const sites = parseJSON(sitesDataset, []);
    const siteMap = {};
    sites.forEach(function(site) {
        if (site && typeof site.id !== 'undefined') {
            siteMap[String(site.id)] = site;
        }
    });

    const $panel = $app.find('.bjlg-network-app__panel');
    const $loading = $panel.find('.bjlg-network-app__loading');
    const $dashboard = $panel.find('[data-role="network-dashboard"]');
    const $error = $panel.find('.bjlg-network-app__error');
    const $fallback = $app.find('.bjlg-network-app__fallback');

    const lists = {
        schedules: $panel.find('[data-role="schedules-list"]'),
        incidents: $panel.find('[data-role="incidents-list"]'),
        quotas: $panel.find('[data-role="quotas-list"]'),
    };

    const empties = {
        schedules: $panel.find('[data-empty="schedules"]'),
        incidents: $panel.find('[data-empty="incidents"]'),
        quotas: $panel.find('[data-empty="quotas"]'),
    };

    const fields = {
        totalSites: $panel.find('[data-field="total-sites"]'),
        nextRunLabel: $panel.find('[data-field="next-run-label"]'),
        nextRunRelative: $panel.find('[data-field="next-run-relative"]'),
        incidentCount: $panel.find('[data-field="incident-count"]'),
        quotaUsage: $panel.find('[data-field="quota-usage"]'),
        schedulesDescription: $panel.find('[data-field="schedules-description"]'),
        incidentsDescription: $panel.find('[data-field="incidents-description"]'),
        quotasDescription: $panel.find('[data-field="quotas-description"]'),
    };

    const fetchJson = function(url, params) {
        const endpoint = new URL(url, window.location.origin);
        if (params && typeof params === 'object') {
            Object.keys(params).forEach(function(key) {
                const value = params[key];
                if (value !== undefined && value !== null && value !== '') {
                    endpoint.searchParams.append(key, value);
                }
            });
        }

        const options = {
            headers: {
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        };

        if (restNonce) {
            options.headers['X-WP-Nonce'] = restNonce;
        }

        return fetch(endpoint.toString(), options).then(function(response) {
            if (response.ok) {
                return response.json();
            }

            return response.json().catch(function() {
                return {};
            }).then(function(data) {
                const message = (data && data.message) ? data.message : __('Une erreur est survenue lors du chargement des données réseau.', 'backup-jlg');
                const error = new Error(message);
                error.response = response;
                throw error;
            });
        });
    };

    const formatNumber = function(value) {
        const numeric = typeof value === 'number' ? value : parseFloat(value);
        if (!Number.isFinite(numeric)) {
            return value === undefined || value === null ? '' : String(value);
        }
        return numeric.toLocaleString(undefined);
    };

    const formatPercent = function(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const numeric = typeof value === 'number' ? value : parseFloat(value);
        if (!Number.isFinite(numeric)) {
            return '—';
        }
        return numeric.toFixed(1).replace(/\.0$/, '') + '%';
    };

    const formatBytes = function(bytes) {
        const numeric = typeof bytes === 'number' ? bytes : parseFloat(bytes);
        if (!Number.isFinite(numeric) || numeric <= 0) {
            return '—';
        }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let index = 0;
        let value = numeric;
        while (value >= 1024 && index < units.length - 1) {
            value /= 1024;
            index += 1;
        }
        return value.toFixed(value < 10 && index > 0 ? 1 : 0) + ' ' + units[index];
    };

    const resolveSite = function(siteId, fallbackSite) {
        if (fallbackSite && typeof fallbackSite === 'object') {
            return fallbackSite;
        }
        if (siteMap[String(siteId)]) {
            return siteMap[String(siteId)];
        }
        return {
            id: siteId,
            name: sprintf(__('Site #%s', 'backup-jlg'), String(siteId)),
            admin_url: '',
        };
    };

    const clearList = function($list) {
        if ($list && $list.length) {
            $list.empty();
        }
    };

    const toggleEmptyState = function($list, $empty, hasItems) {
        if (!$empty.length) {
            return;
        }
        if (hasItems) {
            $empty.attr('hidden', 'hidden').hide();
        } else {
            $empty.removeAttr('hidden').show();
        }
    };

    const renderSchedules = function(data) {
        const $list = lists.schedules;
        if (!$list.length) {
            return;
        }
        clearList($list);

        const summary = data && data.summary ? data.summary : {};
        const upcoming = Array.isArray(summary.upcoming) ? summary.upcoming : [];

        upcoming.forEach(function(event) {
            const site = resolveSite(event.site_id, event.site);
            const $item = $('<li class="bjlg-network-list__item"></li>');
            const $title = $('<div class="bjlg-network-list__title"></div>');
            $title.text(event.label || __('Planification', 'backup-jlg'));
            $item.append($title);

            const metaParts = [];
            if (site && site.name) {
                metaParts.push(site.name);
            }
            if (event.next_run_formatted) {
                metaParts.push(event.next_run_formatted);
            }
            if (event.next_run_relative && event.next_run_relative !== event.next_run_formatted) {
                metaParts.push(event.next_run_relative);
            }

            if (metaParts.length) {
                const $meta = $('<div class="bjlg-network-list__meta"></div>');
                $meta.text(metaParts.join(' • '));
                $item.append($meta);
            }

            if (site && site.admin_url) {
                const $actions = $('<div class="bjlg-network-list__actions"></div>');
                $('<a class="button button-small" target="_blank" rel="noopener"></a>')
                    .attr('href', site.admin_url)
                    .text(__('Ouvrir', 'backup-jlg'))
                    .appendTo($actions);
                $item.append($actions);
            }

            $list.append($item);
        });

        toggleEmptyState($list, empties.schedules, upcoming.length > 0);

        if (fields.schedulesDescription.length) {
            fields.schedulesDescription.text(
                upcoming.length
                    ? sprintf(__('Prochaines sauvegardes détectées : %s', 'backup-jlg'), formatNumber(upcoming.length))
                    : __('Aucune sauvegarde planifiée détectée sur les sites supervisés.', 'backup-jlg')
            );
        }

        if (fields.nextRunLabel.length) {
            if (upcoming.length) {
                const first = upcoming[0];
                const site = resolveSite(first.site_id, first.site);
                const label = first.next_run_formatted || first.label || '';
                fields.nextRunLabel.text(label !== '' ? label : __('Planifiée', 'backup-jlg'));
                if (fields.nextRunRelative.length) {
                    const relativeParts = [];
                    if (site && site.name) {
                        relativeParts.push(site.name);
                    }
                    if (first.next_run_relative) {
                        relativeParts.push(first.next_run_relative);
                    }
                    fields.nextRunRelative.text(relativeParts.join(' • '));
                }
            } else {
                fields.nextRunLabel.text('—');
                if (fields.nextRunRelative.length) {
                    fields.nextRunRelative.text('');
                }
            }
        }
    };

    const renderIncidents = function(data) {
        const $list = lists.incidents;
        if (!$list.length) {
            return;
        }
        clearList($list);

        const summary = data && data.summary ? data.summary : {};
        const incidents = Array.isArray(summary.incidents) ? summary.incidents : [];

        incidents.forEach(function(item) {
            const entry = item.entry || {};
            const site = resolveSite(item.site_id, item.site);
            const $item = $('<li class="bjlg-network-list__item"></li>');
            const titleParts = [];
            if (entry.action_type) {
                titleParts.push(entry.action_type);
            }
            if (entry.details) {
                titleParts.push(entry.details);
            }
            const $title = $('<div class="bjlg-network-list__title"></div>');
            $title.text(titleParts.length ? titleParts.join(' • ') : __('Événement en échec', 'backup-jlg'));
            $item.append($title);

            const metaParts = [];
            if (site && site.name) {
                metaParts.push(site.name);
            }
            if (entry.timestamp) {
                metaParts.push(entry.timestamp);
            }
            if (item.timestamp && fields.incidentsDescription.length) {
                // nothing extra
            }
            if (entry.status) {
                metaParts.push(entry.status);
            }
            if (metaParts.length) {
                const $meta = $('<div class="bjlg-network-list__meta"></div>');
                $meta.text(metaParts.join(' • '));
                $item.append($meta);
            }

            if (site && site.admin_url) {
                const $actions = $('<div class="bjlg-network-list__actions"></div>');
                $('<a class="button button-small" target="_blank" rel="noopener"></a>')
                    .attr('href', site.admin_url)
                    .text(__('Voir le site', 'backup-jlg'))
                    .appendTo($actions);
                $item.append($actions);
            }

            $list.append($item);
        });

        toggleEmptyState($list, empties.incidents, incidents.length > 0);

        if (fields.incidentCount.length) {
            fields.incidentCount.text(formatNumber(incidents.length));
        }

        if (fields.incidentsDescription.length) {
            fields.incidentsDescription.text(
                incidents.length
                    ? sprintf(__('Derniers incidents recensés : %s', 'backup-jlg'), formatNumber(incidents.length))
                    : __('Aucun incident n’a été détecté récemment.', 'backup-jlg')
            );
        }
    };

    const renderQuotas = function(data) {
        const $list = lists.quotas;
        if (!$list.length) {
            return;
        }
        clearList($list);

        const summary = data && data.summary ? data.summary : {};
        const quota = summary.quota || {};
        const hotspots = Array.isArray(quota.hotspots) ? quota.hotspots : [];

        hotspots.forEach(function(entry) {
            const site = resolveSite(entry.site_id, entry.site);
            const $item = $('<li class="bjlg-network-list__item"></li>');
            const label = entry.destination_name || entry.destination_id || __('Destination distante', 'backup-jlg');
            $('<div class="bjlg-network-list__title"></div>').text(label).appendTo($item);

            const metaParts = [];
            if (site && site.name) {
                metaParts.push(site.name);
            }
            metaParts.push(formatPercent(entry.usage_percent));
            if (entry.used_bytes !== null && entry.quota_bytes !== null) {
                metaParts.push(sprintf(__('Utilisation : %1$s / %2$s', 'backup-jlg'), formatBytes(entry.used_bytes), formatBytes(entry.quota_bytes)));
            }

            $('<div class="bjlg-network-list__meta"></div>').text(metaParts.join(' • ')).appendTo($item);

            if (site && site.admin_url) {
                const $actions = $('<div class="bjlg-network-list__actions"></div>');
                $('<a class="button button-small" target="_blank" rel="noopener"></a>')
                    .attr('href', site.admin_url)
                    .text(__('Ouvrir', 'backup-jlg'))
                    .appendTo($actions);
                $item.append($actions);
            }

            $list.append($item);
        });

        toggleEmptyState($list, empties.quotas, hotspots.length > 0);

        if (fields.quotaUsage.length) {
            const usage = quota.usage_percent !== undefined ? quota.usage_percent : null;
            fields.quotaUsage.text(formatPercent(usage));
        }

        if (fields.quotasDescription.length) {
            if (hotspots.length) {
                fields.quotasDescription.text(
                    sprintf(__('Destinations les plus sollicitées : %s', 'backup-jlg'), formatNumber(hotspots.length))
                );
            } else {
                fields.quotasDescription.text(__('Aucune destination ne dépasse les seuils configurés.', 'backup-jlg'));
            }
        }
    };

    const renderSummary = function(schedulesData, historyData, statsData) {
        const totalSites = schedulesData && schedulesData.summary ? schedulesData.summary.total_sites : null;
        if (fields.totalSites.length) {
            fields.totalSites.text(totalSites !== null ? formatNumber(totalSites) : '0');
        }

        if (statsData && statsData.summary && statsData.summary.quota) {
            const quota = statsData.summary.quota;
            if (fields.quotaUsage.length && quota.usage_percent !== undefined) {
                fields.quotaUsage.text(formatPercent(quota.usage_percent));
            }
        }

        if (historyData && historyData.summary && Array.isArray(historyData.summary.incidents)) {
            if (fields.incidentCount.length) {
                fields.incidentCount.text(formatNumber(historyData.summary.incidents.length));
            }
        }
    };

    const finalize = function() {
        $loading.hide();
        $dashboard.removeAttr('hidden');
        if ($fallback.length) {
            $fallback.hide();
        }
    };

    const handleError = function(message) {
        $loading.hide();
        if ($error.length) {
            $error.text(message || __('Impossible de récupérer les informations réseau.', 'backup-jlg'));
            $error.show();
        }
        if ($fallback.length) {
            $fallback.show();
        }
    };

    const fetchNetworkData = function() {
        return Promise.all([
            fetchJson(endpointSchedules, { context: 'network' }),
            fetchJson(endpointHistory, { context: 'network', status: 'failure', limit: 25 }),
            fetchJson(endpointStats, { context: 'network' })
        ]);
    };

    fetchNetworkData().then(function(results) {
        const schedulesData = results[0] || {};
        const historyData = results[1] || {};
        const statsData = results[2] || {};

        renderSchedules(schedulesData);
        renderIncidents(historyData);
        renderQuotas(statsData);
        renderSummary(schedulesData, historyData, statsData);

        finalize();
    }).catch(function(error) {
        const message = error && error.message ? error.message : __('Une erreur est survenue lors du chargement des données réseau.', 'backup-jlg');
        handleError(message);
    });
});
