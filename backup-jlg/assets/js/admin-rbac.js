(function(window) {
    if (!window.wp || !window.wp.element || !window.wp.components) {
        return;
    }

    const elementLibrary = window.wp.element;
    const { createElement: el, Fragment, useEffect, useMemo, useRef, useState } = elementLibrary;
    const components = window.wp.components;
    const apiFetch = window.wp.apiFetch;
    const i18n = window.wp.i18n || {};
    const __ = typeof i18n.__ === 'function' ? i18n.__ : function(str) { return str; };
    const sprintf = typeof i18n.sprintf === 'function' ? i18n.sprintf : function(format) {
        const args = Array.prototype.slice.call(arguments, 1);
        let index = 0;
        return format.replace(/%s/g, function() {
            const value = args[index];
            index += 1;
            return typeof value === 'undefined' ? '' : value;
        });
    };

    const {
        Card,
        CardBody,
        CardHeader,
        Button,
        Notice,
        Spinner,
        Flex,
        FlexBlock,
        FlexItem,
        ComboboxControl,
        __experimentalDivider: Divider = function DividerFallback() { return null; },
    } = components;

    let nonceApplied = false;
    const ensureNonceMiddleware = function() {
        if (nonceApplied || !apiFetch || typeof apiFetch.use !== 'function' || typeof apiFetch.createNonceMiddleware !== 'function') {
            return;
        }

        if (!window.bjlg_ajax || !window.bjlg_ajax.rest_nonce) {
            return;
        }

        apiFetch.use(apiFetch.createNonceMiddleware(window.bjlg_ajax.rest_nonce));
        nonceApplied = true;
    };

    const normalizeMap = function(rawMap, contextKeys) {
        const normalized = {};
        contextKeys.forEach(function(key) {
            const value = rawMap && typeof rawMap[key] === 'string' ? rawMap[key] : '';
            normalized[key] = value;
        });

        return normalized;
    };

    const buildOptions = function(choices) {
        const options = [];
        if (!choices || typeof choices !== 'object') {
            return options;
        }

        if (choices.roles) {
            Object.keys(choices.roles).forEach(function(roleKey) {
                options.push({
                    value: roleKey,
                    label: sprintf(__('Rôle · %s', 'backup-jlg'), choices.roles[roleKey] || roleKey),
                });
            });
        }

        if (choices.capabilities) {
            Object.keys(choices.capabilities).forEach(function(cap) {
                const label = choices.capabilities[cap] || cap;
                if (label === cap) {
                    options.push({ value: cap, label: sprintf(__('Capacité · %s', 'backup-jlg'), cap) });
                } else {
                    options.push({ value: cap, label: sprintf(__('Capacité · %s', 'backup-jlg'), label) });
                }
            });
        }

        return options;
    };

    const RBACPanel = function RBACPanel(props) {
        const root = props.root;
        const contexts = useMemo(function() {
            const raw = root.getAttribute('data-rbac-contexts');
            try {
                const parsed = JSON.parse(raw || '[]');
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    return parsed;
                }
            } catch (error) {}
            return {};
        }, [root]);

        const contextKeys = useMemo(function() {
            return Object.keys(contexts);
        }, [contexts]);

        const [templates, setTemplates] = useState(function() {
            try {
                const parsed = JSON.parse(root.getAttribute('data-rbac-templates') || '{}');
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (error) {
                return {};
            }
        });

        const [choices, setChoices] = useState(function() {
            try {
                const parsed = JSON.parse(root.getAttribute('data-rbac-choices') || '{}');
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (error) {
                return {};
            }
        });

        const [remoteMap, setRemoteMap] = useState(function() {
            try {
                const parsed = JSON.parse(root.getAttribute('data-rbac-map') || '{}');
                return normalizeMap(parsed && typeof parsed === 'object' ? parsed : {}, contextKeys);
            } catch (error) {
                return normalizeMap({}, contextKeys);
            }
        });

        const [map, setMap] = useState(remoteMap);
        const [isLoading, setIsLoading] = useState(false);
        const [isSaving, setIsSaving] = useState(false);
        const [notice, setNotice] = useState('');
        const [errorMessage, setErrorMessage] = useState('');
        const [selectedTemplate, setSelectedTemplate] = useState('');
        const noticeRef = useRef(null);
        const scope = root.getAttribute('data-rbac-scope') || 'site';
        const sectionKey = root.getAttribute('data-section-key') || 'rbac';
        const endpoint = root.getAttribute('data-rbac-endpoint') || '';

        const optionList = useMemo(function() {
            return buildOptions(choices);
        }, [choices]);

        const isDirty = useMemo(function() {
            return JSON.stringify(map) !== JSON.stringify(remoteMap);
        }, [map, remoteMap]);

        const resetNotice = function() {
            setNotice('');
            setErrorMessage('');
        };

        const fetchConfiguration = function() {
            if (!endpoint || !apiFetch) {
                return;
            }

            ensureNonceMiddleware();
            setIsLoading(true);
            resetNotice();

            apiFetch({
                url: endpoint + (endpoint.indexOf('?') === -1 ? '?scope=' + encodeURIComponent(scope) : '&scope=' + encodeURIComponent(scope)),
                method: 'GET',
            }).then(function(response) {
                const nextChoices = response && response.choices ? response.choices : choices;
                const nextTemplates = response && response.templates ? response.templates : templates;
                const nextMap = normalizeMap(response && response.map ? response.map : {}, contextKeys);
                setChoices(nextChoices || {});
                setTemplates(nextTemplates || {});
                setRemoteMap(nextMap);
                setMap(nextMap);
            }).catch(function(error) {
                setErrorMessage(error && error.message ? error.message : __('Impossible de charger la configuration RBAC.', 'backup-jlg'));
            }).finally(function() {
                setIsLoading(false);
            });
        };

        useEffect(function() {
            fetchConfiguration();
            const sectionHandler = function(event) {
                if (!event || !event.detail || event.detail.section !== sectionKey) {
                    return;
                }
                if (typeof root.focus === 'function') {
                    setTimeout(function() {
                        root.focus({ preventScroll: true });
                    }, 20);
                }
            };
            document.addEventListener('bjlg:section-activated', sectionHandler);

            return function() {
                document.removeEventListener('bjlg:section-activated', sectionHandler);
            };
        }, []);

        useEffect(function() {
            if (!noticeRef.current) {
                return;
            }
            if (notice) {
                try {
                    noticeRef.current.focus({ preventScroll: true });
                } catch (error) {
                    noticeRef.current.focus();
                }
            }
        }, [notice]);

        const applyTemplate = function(templateKey) {
            if (!templateKey || !templates || !templates[templateKey]) {
                return;
            }

            const template = templates[templateKey];
            const templateMap = normalizeMap(template.map || {}, contextKeys);
            setMap(templateMap);
            setNotice(sprintf(__('Template « %s » appliqué. Pensez à enregistrer.', 'backup-jlg'), template.label || templateKey));
            setErrorMessage('');
        };

        const handleSave = function() {
            if (!endpoint || !apiFetch) {
                return;
            }

            ensureNonceMiddleware();
            setIsSaving(true);
            setErrorMessage('');
            setNotice('');

            apiFetch({
                url: endpoint,
                method: 'POST',
                data: {
                    scope: scope,
                    map: map,
                },
            }).then(function(response) {
                const nextMap = normalizeMap(response && response.map ? response.map : map, contextKeys);
                setRemoteMap(nextMap);
                setMap(nextMap);
                setNotice(__('Permissions enregistrées.', 'backup-jlg'));
            }).catch(function(error) {
                setErrorMessage(error && error.message ? error.message : __('Impossible d’enregistrer les permissions.', 'backup-jlg'));
            }).finally(function() {
                setIsSaving(false);
            });
        };

        const handleFieldChange = function(contextKey, value) {
            setMap(function(prev) {
                const next = Object.assign({}, prev);
                next[contextKey] = value || '';
                return next;
            });
            resetNotice();
        };

        const templateOptions = useMemo(function() {
            const options = [{ label: __('Choisir un modèle…', 'backup-jlg'), value: '' }];
            Object.keys(templates).forEach(function(key) {
                const template = templates[key];
                options.push({ value: key, label: template && template.label ? template.label : key });
            });
            return options;
        }, [templates]);

        return el(Fragment, {},
            el('div', { className: 'bjlg-rbac-notices', 'aria-live': 'polite' },
                notice ? el(Notice, {
                    status: 'success',
                    isDismissible: true,
                    onRemove: function() { setNotice(''); },
                    className: 'bjlg-rbac-notice',
                    ref: noticeRef,
                    tabIndex: -1,
                }, notice) : null,
                errorMessage ? el(Notice, {
                    status: 'error',
                    isDismissible: true,
                    onRemove: function() { setErrorMessage(''); },
                    className: 'bjlg-rbac-notice',
                }, errorMessage) : null
            ),
            el(Card, { className: 'bjlg-rbac-card' },
                el(CardHeader, {},
                    el('strong', null, __('Modèles rapides', 'backup-jlg'))
                ),
                el(CardBody, {},
                    el(Flex, { align: 'flex-end', className: 'bjlg-rbac-template-bar' },
                        el(FlexBlock, {},
                            el('label', { className: 'bjlg-rbac-template-label', htmlFor: 'bjlg-rbac-template' }, __('Appliquer un modèle préconfiguré', 'backup-jlg')),
                            el(ComboboxControl, {
                                id: 'bjlg-rbac-template',
                                value: selectedTemplate,
                                options: templateOptions,
                                onChange: function(value) {
                                    setSelectedTemplate(value);
                                    if (value) {
                                        applyTemplate(value);
                                    }
                                },
                            })
                        ),
                        el(FlexItem, {},
                            el(Button, {
                                variant: 'secondary',
                                onClick: function() {
                                    setMap(remoteMap);
                                    setSelectedTemplate('');
                                    resetNotice();
                                },
                            }, __('Réinitialiser', 'backup-jlg'))
                        )
                    )
                )
            ),
            Divider ? el(Divider, { margin: '24px 0' }) : null,
            el('div', { className: 'bjlg-rbac-grid' },
                contextKeys.map(function(contextKey) {
                    const definition = contexts[contextKey] || {};
                    const value = map[contextKey] || '';
                    return el(Card, { key: contextKey, className: 'bjlg-rbac-item' },
                        el(CardHeader, {},
                            el('span', { className: 'bjlg-rbac-context-label' }, definition.label || contextKey)
                        ),
                        el(CardBody, {},
                            el('p', { className: 'bjlg-rbac-context-description' }, definition.description || ''),
                            el(ComboboxControl, {
                                value: value,
                                options: optionList,
                                onChange: function(nextValue) {
                                    handleFieldChange(contextKey, nextValue);
                                },
                                label: __('Rôle ou capacité', 'backup-jlg'),
                                placeholder: __('Commencez à saisir un rôle…', 'backup-jlg'),
                            })
                        )
                    );
                })
            ),
            el('div', { className: 'bjlg-rbac-actions' },
                el(Button, {
                    variant: 'primary',
                    onClick: handleSave,
                    disabled: !isDirty || isSaving,
                }, isSaving ? el(Spinner, { className: 'bjlg-inline-spinner' }) : __('Enregistrer les permissions', 'backup-jlg')),
                el(Button, {
                    variant: 'tertiary',
                    onClick: fetchConfiguration,
                    disabled: isSaving,
                }, __('Recharger', 'backup-jlg'))
            ),
            isLoading ? el('div', { className: 'bjlg-rbac-loading', role: 'status', 'aria-live': 'polite' },
                el(Spinner, null),
                el('span', { className: 'bjlg-rbac-loading__label' }, __('Chargement des permissions…', 'backup-jlg'))
            ) : null
        );
    };

    const mountComponent = function(node, element) {
        if (typeof elementLibrary.createRoot === 'function') {
            const existing = node.__bjlgRoot;
            if (existing && typeof existing.render === 'function') {
                existing.render(element);
                return;
            }
            const root = elementLibrary.createRoot(node);
            root.render(element);
            node.__bjlgRoot = root;
            return;
        }

        if (typeof elementLibrary.render === 'function') {
            elementLibrary.render(element, node);
        }
    };

    const renderRBACPanels = function() {
        const nodes = document.querySelectorAll('.bjlg-rbac-app');
        if (!nodes || !nodes.length) {
            return;
        }

        nodes.forEach(function(node) {
            if (node.dataset.bjlgRendered === 'true') {
                return;
            }

            node.dataset.bjlgRendered = 'true';
            mountComponent(node, el(RBACPanel, { root: node }));
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderRBACPanels);
    } else {
        renderRBACPanels();
    }

    window.bjlgRenderRBAC = renderRBACPanels;
})(window);
