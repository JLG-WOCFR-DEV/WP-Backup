/* global wp, BJLGBlockStatusSettings */
(function(wp) {
    if (!wp || !wp.blocks) {
        return;
    }

    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { useState, useEffect, Fragment, useCallback } = wp.element;
    const { PanelBody, ToggleControl, Spinner, Button, Notice } = wp.components;
    const { InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
    const apiFetch = wp.apiFetch;

    const settings = window.BJLGBlockStatusSettings || {};

    if (apiFetch) {
        if (settings.root && apiFetch.createRootURLMiddleware && !window.__BJLGBlockStatusRootMiddleware) {
            window.__BJLGBlockStatusRootMiddleware = true;
            apiFetch.use(apiFetch.createRootURLMiddleware(settings.root));
        }

        if (settings.nonce && apiFetch.createNonceMiddleware && !window.__BJLGBlockStatusNonceMiddleware) {
            window.__BJLGBlockStatusNonceMiddleware = true;
            apiFetch.use(apiFetch.createNonceMiddleware(settings.nonce));
        }
    }

    const getInitialState = () => {
        const snapshot = settings.snapshot || null;
        if (snapshot && snapshot.ok) {
            return {
                status: 'ready',
                data: snapshot,
                error: null,
            };
        }

        if (snapshot && snapshot.error) {
            return {
                status: 'error',
                data: null,
                error: snapshot.error,
            };
        }

        return {
            status: 'idle',
            data: null,
            error: null,
        };
    };

    const formatAlertType = (type) => {
        switch (type) {
            case 'warning':
                return 'warning';
            case 'error':
            case 'danger':
                return 'error';
            case 'success':
                return 'success';
            default:
                return 'info';
        }
    };

    const SummaryStat = ({ label, value, meta }) => {
        let displayValue = value;
        if (typeof value === 'number') {
            displayValue = value.toLocaleString();
        }
        if (!displayValue && displayValue !== 0) {
            displayValue = __('N/A', 'backup-jlg');
        }

        return wp.element.createElement('div', { className: 'bjlg-block-status__stat' },
            wp.element.createElement('span', { className: 'bjlg-block-status__stat-label' }, label),
            wp.element.createElement('span', { className: 'bjlg-block-status__stat-value' }, displayValue),
            meta ? wp.element.createElement('span', { className: 'bjlg-block-status__stat-meta' }, meta) : null
        );
    };

    const Alerts = ({ alerts }) => {
        if (!alerts || alerts.length === 0) {
            return null;
        }

        return wp.element.createElement('div', {
            className: 'bjlg-block-status__alerts',
            role: 'status',
            'aria-live': 'polite',
            'aria-atomic': 'true'
        },
            alerts.map((alert, index) => wp.element.createElement('div', {
                key: alert.id || index,
                className: 'bjlg-block-status__alert bjlg-block-status__alert--' + formatAlertType(alert.type),
            },
            wp.element.createElement('div', { className: 'bjlg-block-status__alert-body' },
                wp.element.createElement('strong', null, alert.title || ''),
                alert.message ? wp.element.createElement('p', null, alert.message) : null
            ),
            alert.action && alert.action.url && alert.action.label
                ? wp.element.createElement('a', {
                    className: 'bjlg-block-status__alert-link',
                    href: alert.action.url,
                    target: '_blank',
                    rel: 'noopener noreferrer',
                }, alert.action.label)
                : null
            ))
        );
    };

    const RecentBackups = ({ backups }) => {
        if (!backups) {
            return null;
        }

        if (!backups.length) {
            return wp.element.createElement('p', { className: 'bjlg-block-status__empty' }, __('Aucune sauvegarde récente disponible.', 'backup-jlg'));
        }

        return wp.element.createElement('ul', { className: 'bjlg-block-status__backup-list' },
            backups.map((backup) => wp.element.createElement('li', {
                key: backup.id || backup.filename,
                className: 'bjlg-block-status__backup-item',
            },
            wp.element.createElement('span', { className: 'bjlg-block-status__backup-name' }, backup.filename),
            wp.element.createElement('span', { className: 'bjlg-block-status__backup-meta' },
                [backup.created_at_relative, backup.size].filter(Boolean).join(' · ')
            )
        ))
        );
    };

    const Actions = ({ actions }) => {
        if (!actions || !actions.backup) {
            return null;
        }

        const buttons = [];

        if (actions.backup && actions.backup.url) {
            buttons.push(
                wp.element.createElement('a', {
                    key: 'backup',
                    className: 'bjlg-block-status__button',
                    href: actions.backup.url,
                    target: '_blank',
                    rel: 'noopener noreferrer',
                }, actions.backup.label || __('Lancer une sauvegarde', 'backup-jlg'))
            );
        }

        if (actions.restore && actions.restore.url) {
            buttons.push(
                wp.element.createElement('a', {
                    key: 'restore',
                    className: 'bjlg-block-status__button bjlg-block-status__button--secondary',
                    href: actions.restore.url,
                    target: '_blank',
                    rel: 'noopener noreferrer',
                }, actions.restore.label || __('Restaurer une archive', 'backup-jlg'))
            );
        }

        if (!buttons.length) {
            return null;
        }

        return wp.element.createElement('div', { className: 'bjlg-block-status__actions' }, buttons);
    };

    const StatusPreview = ({ data, attributes }) => {
        const summary = data.summary || {};

        return wp.element.createElement(Fragment, null,
            wp.element.createElement('header', { className: 'bjlg-block-status__header' },
                wp.element.createElement('h3', { className: 'bjlg-block-status__title' }, __('Vue d’ensemble des sauvegardes', 'backup-jlg')),
                data.generated_at ? wp.element.createElement('span', { className: 'bjlg-block-status__timestamp' },
                    wp.i18n.sprintf(__('Actualisé le %s', 'backup-jlg'), data.generated_at)
                ) : null
            ),
            wp.element.createElement('div', {
                className: 'bjlg-block-status__summary',
                role: 'status',
                'aria-live': 'polite',
                'aria-atomic': 'true'
            },
                wp.element.createElement(SummaryStat, {
                    label: __('Dernière sauvegarde', 'backup-jlg'),
                    value: summary.history_last_backup,
                    meta: summary.history_last_backup_relative,
                }),
                wp.element.createElement(SummaryStat, {
                    label: __('Prochaine sauvegarde planifiée', 'backup-jlg'),
                    value: summary.scheduler_next_run,
                    meta: summary.scheduler_next_run_relative,
                }),
                wp.element.createElement(SummaryStat, {
                    label: __('Archives stockées', 'backup-jlg'),
                    value: typeof summary.storage_backup_count !== 'undefined' ? summary.storage_backup_count : null,
                    meta: summary.storage_total_size_human,
                })
            ),
            attributes.showLaunchButton ? wp.element.createElement(Actions, { actions: data.actions }) : null,
            attributes.showAlerts ? wp.element.createElement(Alerts, { alerts: data.alerts }) : null,
            attributes.showRecentBackups ? wp.element.createElement('div', { className: 'bjlg-block-status__recent' },
                wp.element.createElement('h4', { className: 'bjlg-block-status__section-title' }, __('Dernières archives', 'backup-jlg')),
                wp.element.createElement(RecentBackups, { backups: data.backups })
            ) : null
        );
    };

    registerBlockType('backup-jlg/status', {
        edit: function Edit(props) {
            const { attributes, setAttributes } = props;
            const [state, setState] = useState(getInitialState);

            const blockProps = useBlockProps({
                className: 'bjlg-block-status bjlg-block-status--editor',
            });

            if (state.data && state.data.ok) {
                blockProps['data-bjlg-status'] = JSON.stringify(state.data);
            }

            const fetchSnapshot = useCallback(() => {
                if (!apiFetch || !settings.endpoint) {
                    setState({
                        status: 'error',
                        data: null,
                        error: { message: settings.i18n ? settings.i18n.error : __('Impossible de charger les données.', 'backup-jlg') },
                    });
                    return;
                }

                setState((prev) => ({ ...prev, status: 'loading', error: null }));

                const controller = typeof AbortController === 'function' ? new AbortController() : null;
                const request = { path: settings.endpoint };
                if (controller) {
                    request.signal = controller.signal;
                }

                apiFetch(request)
                    .then((response) => {
                        if (!response) {
                            throw new Error('empty_response');
                        }
                        if (response.ok === false && response.error) {
                            setState({ status: 'error', data: null, error: response.error });
                            return;
                        }
                        setState({ status: 'ready', data: response, error: null });
                    })
                    .catch((error) => {
                        if (error && error.name === 'AbortError') {
                            return;
                        }
                        setState({
                            status: 'error',
                            data: null,
                            error: {
                                message: settings.i18n ? settings.i18n.error : __('Une erreur est survenue lors du chargement.', 'backup-jlg'),
                                details: error && error.message ? error.message : undefined,
                            },
                        });
                    });

                return () => {
                    if (controller) {
                        controller.abort();
                    }
                };
            }, []);

            useEffect(() => {
                if (state.status === 'idle') {
                    return fetchSnapshot();
                }
                return undefined;
            }, [state.status, fetchSnapshot]);

            const inspector = wp.element.createElement(InspectorControls, null,
                wp.element.createElement(PanelBody, { title: __('Options du bloc', 'backup-jlg'), initialOpen: true },
                    wp.element.createElement(ToggleControl, {
                        label: __('Afficher le bouton “Lancer une sauvegarde”', 'backup-jlg'),
                        checked: attributes.showLaunchButton,
                        onChange: (value) => setAttributes({ showLaunchButton: !!value }),
                    }),
                    wp.element.createElement(ToggleControl, {
                        label: __('Afficher les alertes', 'backup-jlg'),
                        checked: attributes.showAlerts,
                        onChange: (value) => setAttributes({ showAlerts: !!value }),
                    }),
                    wp.element.createElement(ToggleControl, {
                        label: __('Afficher les dernières archives', 'backup-jlg'),
                        checked: attributes.showRecentBackups,
                        onChange: (value) => setAttributes({ showRecentBackups: !!value }),
                    })
                )
            );

            let content;

            if (state.status === 'loading') {
                content = wp.element.createElement('div', { className: 'bjlg-block-status__placeholder' },
                    wp.element.createElement(Spinner, null),
                    wp.element.createElement('p', null, settings.i18n ? settings.i18n.loading : __('Chargement…', 'backup-jlg'))
                );
            } else if (state.status === 'error') {
                const message = state.error && state.error.message
                    ? state.error.message
                    : (settings.i18n ? settings.i18n.error : __('Impossible de charger les données.', 'backup-jlg'));

                content = wp.element.createElement('div', { className: 'bjlg-block-status__error' },
                    wp.element.createElement(Notice, { status: 'error', isDismissible: false }, message),
                    wp.element.createElement(Button, {
                        variant: 'secondary',
                        onClick: fetchSnapshot,
                    }, __('Réessayer', 'backup-jlg'))
                );
            } else if (state.data && state.data.ok) {
                content = wp.element.createElement(StatusPreview, { data: state.data, attributes });
            } else if (state.data && state.data.error) {
                const message = state.data.error.message
                    ? state.data.error.message
                    : (settings.i18n ? settings.i18n.forbidden : __('Accès refusé.', 'backup-jlg'));
                content = wp.element.createElement('div', { className: 'bjlg-block-status__notice' }, message);
            } else {
                content = wp.element.createElement('div', { className: 'bjlg-block-status__placeholder' },
                    wp.element.createElement(Button, { variant: 'secondary', onClick: fetchSnapshot }, __('Charger les données', 'backup-jlg'))
                );
            }

            return wp.element.createElement(Fragment, null, inspector,
                wp.element.createElement('div', blockProps, content)
            );
        },
        save: function Save() {
            return null;
        },
    });
})(window.wp);
