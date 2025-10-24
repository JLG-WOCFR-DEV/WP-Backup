(function(window) {
    const modulePromises = {};

    window.bjlgLoadModule = function(moduleName) {
        if (!moduleName || typeof moduleName !== 'string') {
            return Promise.resolve();
        }

        const normalized = moduleName.toLowerCase();

        if (modulePromises[normalized]) {
            return modulePromises[normalized];
        }

        const modules = (typeof window.bjlg_ajax === 'object' && window.bjlg_ajax && window.bjlg_ajax.modules)
            ? window.bjlg_ajax.modules
            : {};

        const scriptUrl = modules && modules[normalized] ? modules[normalized] : null;

        if (!scriptUrl) {
            modulePromises[normalized] = Promise.resolve();
            return modulePromises[normalized];
        }

        modulePromises[normalized] = new Promise(function(resolve, reject) {
            const existing = document.querySelector('script[data-bjlg-module="' + normalized + '"]');
            if (existing) {
                if (existing.getAttribute('data-bjlg-loaded') === 'true') {
                    resolve();
                } else {
                    existing.addEventListener('load', function() { resolve(); });
                    existing.addEventListener('error', function(event) { reject(event); });
                }
                return;
            }

            const script = document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.dataset.bjlgModule = normalized;
            script.addEventListener('load', function() {
                script.setAttribute('data-bjlg-loaded', 'true');
                resolve();
            });
            script.addEventListener('error', function() {
                reject(new Error('Failed to load module "' + normalized + '"'));
            });
            document.head.appendChild(script);
        });

        return modulePromises[normalized];
    };
})(window);

window.bjlgEnsureChart = (function() {
    let promise = null;

    return function bjlgEnsureChart() {
        if (typeof window.Chart !== 'undefined') {
            return Promise.resolve();
        }

        if (promise) {
            return promise;
        }

        const chartUrl = (typeof window.bjlg_ajax === 'object' && window.bjlg_ajax)
            ? window.bjlg_ajax.chart_url
            : '';

        if (!chartUrl) {
            promise = Promise.reject(new Error('Chart.js URL is not defined.'));
            return promise;
        }

        promise = new Promise(function(resolve, reject) {
            const existing = document.querySelector('script[data-bjlg-module="chartjs"]');
            if (existing) {
                existing.addEventListener('load', function() {
                    resolve();
                });
                existing.addEventListener('error', function() {
                    reject(new Error('Failed to load Chart.js'));
                });
                return;
            }

            const script = document.createElement('script');
            script.src = chartUrl;
            script.async = true;
            script.dataset.bjlgModule = 'chartjs';
            script.addEventListener('load', function() {
                resolve();
            });
            script.addEventListener('error', function() {
                reject(new Error('Failed to load Chart.js'));
            });
            document.head.appendChild(script);
        });

        return promise;
    };
})();

(function setupUnhandledRejectionGuard(global) {
    if (!global || typeof global.addEventListener !== 'function') {
        return;
    }

    const suppressedMessage = 'A listener indicated an asynchronous response by returning true, but the message channel closed before a response was received';

    global.addEventListener('unhandledrejection', function(event) {
        if (!event) {
            return;
        }

        let message = '';

        if (event.reason && typeof event.reason === 'object' && typeof event.reason.message === 'string') {
            message = event.reason.message;
        } else if (typeof event.reason === 'string') {
            message = event.reason;
        }

        if (message && message.indexOf(suppressedMessage) !== -1) {
            event.preventDefault();
        }
    });
})(window);

jQuery(function($) {
    'use strict';

    const ajaxData = (typeof window.bjlg_ajax === 'object' && window.bjlg_ajax) ? window.bjlg_ajax : {};
    const sectionModulesMap = ajaxData.section_modules || ajaxData.tab_modules || {};
    const loadedModules = new Set();
    const panelAnnouncer = document.getElementById('bjlg-section-announcer');
    const wpA11y = (window.wp && window.wp.a11y) ? window.wp.a11y : null;
    const speak = wpA11y && typeof wpA11y.speak === 'function' ? wpA11y.speak.bind(wpA11y) : null;
    const i18n = (window.wp && window.wp.i18n) ? window.wp.i18n : {};
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
    const sectionLabels = {};

    const parseJSONSafe = function(raw, fallback) {
        if (typeof raw !== 'string' || raw === '') {
            return fallback;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    };

    const requestModules = function(modules) {
        if (!Array.isArray(modules) || !modules.length) {
            return;
        }

        modules.forEach(function(moduleName) {
            if (typeof moduleName !== 'string' || moduleName === '') {
                return;
            }

            const normalized = moduleName.toLowerCase();
            if (loadedModules.has(normalized)) {
                return;
            }

            loadedModules.add(normalized);

            if (typeof window.bjlgLoadModule === 'function') {
                window.bjlgLoadModule(normalized).catch(function() {
                    loadedModules.delete(normalized);
                });
            }
        });
    };

    const parseModuleAttribute = function(value) {
        if (typeof value !== 'string' || value.trim() === '') {
            return [];
        }

        return value.split(/[\s,]+/).map(function(entry) {
            return entry.trim();
        }).filter(function(entry) {
            return entry !== '';
        });
    };

    const loadModulesForSection = function(sectionKey) {
        const modules = [];
        const configured = sectionModulesMap[sectionKey];

        if (Array.isArray(configured)) {
            configured.forEach(function(name) {
                modules.push(name);
            });
        }

        const $panel = $('.bjlg-shell-section[data-section="' + sectionKey + '"]');
        if ($panel.length) {
            const attr = $panel.attr('data-bjlg-modules');
            if (attr) {
                modules.push.apply(modules, parseModuleAttribute(attr));
            }
        }

        requestModules(modules);
    };

    const shellElement = document.querySelector('.bjlg-admin-shell');
    const sidebarToggle = document.getElementById('bjlg-sidebar-toggle');
    const sidebarClose = document.getElementById('bjlg-sidebar-close');
    const sidebarLinks = document.querySelectorAll('.bjlg-sidebar__nav-link');
    const mainWrap = document.getElementById('bjlg-main-content');
    const appEl = document.getElementById('bjlg-admin-app');

    let updateTabSelection = null;
    let currentSection = '';

    const setSidebarExpanded = function(expanded) {
        if (!shellElement) {
            return;
        }

        const isOpen = !!expanded;
        shellElement.classList.toggle('is-sidebar-open', isOpen);

        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    };

    const toggleSidebar = function(force) {
        if (!shellElement) {
            return;
        }

        if (typeof force === 'boolean') {
            setSidebarExpanded(force);
            return;
        }

        const shouldOpen = !shellElement.classList.contains('is-sidebar-open');
        setSidebarExpanded(shouldOpen);
    };

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(event) {
            event.preventDefault();
            toggleSidebar();
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', function(event) {
            event.preventDefault();
            toggleSidebar(false);
        });
    }

    if (sidebarLinks.length) {
        Array.prototype.forEach.call(sidebarLinks, function(link) {
            link.addEventListener('click', function(event) {
                const targetSection = link.getAttribute('data-section');
                if (!targetSection) {
                    return;
                }

                event.preventDefault();
                toggleSidebar(false);
                setActiveSection(targetSection, true, false);
            });
        });
    }

    const setActiveSection = function(sectionKey, updateHistory, fromTab) {
        if (!sectionKey) {
            return;
        }

        const isInitialRender = currentSection === '';
        if (currentSection === sectionKey && !fromTab) {
            return;
        }

        currentSection = sectionKey;

        const panels = document.querySelectorAll('.bjlg-shell-section');
        let activePanel = null;
        let activeLabel = sectionLabels[sectionKey] || '';
        panels.forEach(function(panel) {
            const matches = panel.getAttribute('data-section') === sectionKey;
            if (matches) {
                panel.removeAttribute('hidden');
                panel.setAttribute('aria-hidden', 'false');
                panel.setAttribute('tabindex', '0');
                activePanel = panel;
                if (!activeLabel) {
                    const labelledby = panel.getAttribute('aria-labelledby');
                    if (labelledby) {
                        const labelElement = document.getElementById(labelledby);
                        if (labelElement) {
                            activeLabel = labelElement.textContent.trim();
                        }
                    }
                }
            } else {
                panel.setAttribute('hidden', 'hidden');
                panel.setAttribute('aria-hidden', 'true');
                panel.removeAttribute('tabindex');
            }
        });

        if (appEl) {
            appEl.setAttribute('data-active-section', sectionKey);
        }

        if (mainWrap) {
            mainWrap.setAttribute('data-active-section', sectionKey);
        }

        if (shellElement) {
            shellElement.setAttribute('data-active-section', sectionKey);
        }

        sidebarLinks.forEach(function(link) {
            const matches = link.getAttribute('data-section') === sectionKey;
            link.classList.toggle('is-active', matches);
            if (matches) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        loadModulesForSection(sectionKey);

        if (typeof window.bjlgRenderRBAC === 'function') {
            try {
                window.bjlgRenderRBAC();
            } catch (error) {
                // Ignore rendering errors to avoid blocking navigation.
            }
        }

        let sectionEvent = null;
        const eventDetail = { section: sectionKey, panel: activePanel };
        if (typeof window.CustomEvent === 'function') {
            sectionEvent = new window.CustomEvent('bjlg:section-activated', { detail: eventDetail });
        } else if (document.createEvent) {
            sectionEvent = document.createEvent('CustomEvent');
            if (sectionEvent && typeof sectionEvent.initCustomEvent === 'function') {
                sectionEvent.initCustomEvent('bjlg:section-activated', false, false, eventDetail);
            }
        }

        if (sectionEvent) {
            document.dispatchEvent(sectionEvent);
        }

        if (!fromTab && typeof updateTabSelection === 'function') {
            updateTabSelection(sectionKey);
        }

        if (updateHistory && window.history && typeof window.history.replaceState === 'function') {
            try {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('section', sectionKey);
                currentUrl.searchParams.delete('tab');
                window.history.replaceState({}, '', currentUrl.toString());
            } catch (error) {
                const baseUrl = window.location.href.split('#')[0];
                const hasQuery = baseUrl.indexOf('?') !== -1;
                let newUrl;

                if (baseUrl.indexOf('section=') !== -1) {
                    newUrl = baseUrl.replace(/([?&])section=[^&#]*/, '$1section=' + encodeURIComponent(sectionKey));
                } else if (hasQuery) {
                    newUrl = baseUrl + '&section=' + encodeURIComponent(sectionKey);
                } else {
                    newUrl = baseUrl + '?section=' + encodeURIComponent(sectionKey);
                }

                newUrl = newUrl.replace(/([?&])tab=[^&#]*/, '$1section=' + encodeURIComponent(sectionKey));
                window.history.replaceState({}, '', newUrl);
            }
        }

        setSidebarExpanded(false);

        if (panelAnnouncer) {
            panelAnnouncer.textContent = activeLabel || '';
        }

        if (!isInitialRender) {
            if (activePanel && typeof activePanel.focus === 'function') {
                try {
                    activePanel.focus({ preventScroll: true });
                } catch (error) {
                    activePanel.focus();
                }
            }

            if (speak && activeLabel) {
                speak(sprintf(__('Section activée : %s', 'backup-jlg'), activeLabel));
            }
        }
    };

    window.bjlgAdmin = window.bjlgAdmin || {};
    window.bjlgAdmin.setActiveSection = function(sectionKey, updateHistory) {
        setActiveSection(sectionKey, !!updateHistory, false);
    };
    window.bjlgAdmin.getActiveSection = function() {
        return currentSection;
    };
    window.bjlgAdmin.onSectionChange = function(callback) {
        if (typeof callback !== 'function') {
            return function noop() {};
        }

        const handler = function(event) {
            if (!event || !event.detail) {
                callback({});
                return;
            }
            callback(event.detail);
        };

        document.addEventListener('bjlg:section-activated', handler);

        return function unsubscribe() {
            document.removeEventListener('bjlg:section-activated', handler);
        };
    };

    window.bjlgSetActiveSection = function(sectionKey) {
        setActiveSection(sectionKey, true, false);
    };

    const mountSectionTabs = function(appElement) {
        if (!appElement || !window.wp || !window.wp.element || !window.wp.components) {
            return;
        }

        const sections = parseJSONSafe(appElement.getAttribute('data-bjlg-sections'), []);
        if (!sections.length) {
            return;
        }

        sections.forEach(function(section) {
            if (!section || typeof section.key === 'undefined') {
                return;
            }

            const key = String(section.key);
            if (key) {
                sectionLabels[key] = typeof section.label === 'string' ? section.label : '';
            }
        });

        const navContainer = document.getElementById('bjlg-admin-app-nav');
        if (!navContainer) {
            return;
        }

        const initialSection = appElement.getAttribute('data-active-section') || sections[0].key;

        const datasetModules = parseJSONSafe(appElement.getAttribute('data-bjlg-modules'), null);
        if (datasetModules && typeof datasetModules === 'object') {
            Object.keys(datasetModules).forEach(function(key) {
                sectionModulesMap[key] = datasetModules[key];
            });
        }

        const { createElement, render, useEffect, useState } = window.wp.element;
        const { TabPanel } = window.wp.components;

        const syncTabAccessibility = function() {
            const tabElements = navContainer.querySelectorAll('.components-tab-panel__tabs-item');

            tabElements.forEach(function(tabEl) {
                const tabId = tabEl.getAttribute('id');
                if (!tabId) {
                    return;
                }

                const match = tabId.match(/^tab-panel-\d+-(.+)$/);
                const sectionKey = match ? match[1] : '';
                if (!sectionKey) {
                    return;
                }

                const panel = document.querySelector('.bjlg-shell-section[data-section="' + sectionKey + '"]');
                if (!panel) {
                    return;
                }

                if (!panel.id) {
                    panel.id = 'bjlg-section-' + sectionKey;
                }

                const labelId = panel.getAttribute('data-bjlg-label-id');

                if (labelId) {
                    tabEl.setAttribute('aria-describedby', labelId);
                } else {
                    tabEl.removeAttribute('aria-describedby');
                }

                tabEl.setAttribute('aria-controls', panel.id);
            });
        };

        const AdminSectionTabs = function(props) {
            const [active, setActive] = useState(props.initialSection);

            useEffect(function() {
                if (typeof props.onChange === 'function') {
                    props.onChange(active, true);
                }
            }, [active]);

            useEffect(function() {
                if (typeof props.registerExternal === 'function') {
                    props.registerExternal(setActive);
                }
            }, [props.registerExternal]);

            useEffect(function() {
                if (typeof props.onRender === 'function') {
                    props.onRender();
                }
            });

            const tabs = props.sections.map(function(section) {
                return {
                    name: section.key,
                    title: section.label,
                    className: 'bjlg-react-tab',
                };
            });

            return createElement(TabPanel, {
                className: 'bjlg-react-tabpanel',
                activeClass: 'is-active',
                initialTab: props.initialSection,
                tabs: tabs,
                onSelect: function(tabName) {
                    setActive(tabName);
                },
            }, function() {
                return null;
            });
        };

        render(createElement(AdminSectionTabs, {
            sections: sections,
            initialSection: initialSection,
            onChange: function(sectionKey) {
                setActiveSection(sectionKey, true, true);
            },
            registerExternal: function(callback) {
                updateTabSelection = function(sectionKey) {
                    callback(sectionKey);
                };
            },
            onRender: function() {
                syncTabAccessibility();
            },
        }), navContainer);

        navContainer.removeAttribute('aria-hidden');
        syncTabAccessibility();
        setActiveSection(initialSection, false, true);
    };

    const mountOnboardingChecklist = function(rootElement, data) {
        if (!rootElement || !window.wp || !window.wp.element || !window.wp.components) {
            return;
        }

        const steps = Array.isArray(data.steps) ? data.steps : [];
        if (!steps.length) {
            rootElement.setAttribute('hidden', 'hidden');
            return;
        }

        const { createElement, render, useEffect, useState } = window.wp.element;
        const { Card, CardBody, Button, CheckboxControl } = window.wp.components;
        const i18n = window.wp.i18n || {};
        const __ = typeof i18n.__ === 'function' ? i18n.__ : function(str) { return str; };
        const sprintf = typeof i18n.sprintf === 'function' ? i18n.sprintf : function(format) {
            const args = Array.prototype.slice.call(arguments, 1);
            return format.replace(/%s/g, function() { return args.length ? args.shift() : ''; });
        };
        const apiFetch = window.wp.apiFetch;

        const buildOnboardingFormData = function(selectedSteps) {
            if (typeof window.FormData !== 'function') {
                return null;
            }

            const formData = new window.FormData();
            formData.append('action', 'bjlg_update_onboarding_progress');
            formData.append('nonce', ajaxData.onboarding_nonce || '');

            selectedSteps.forEach(function(stepId) {
                if (stepId) {
                    formData.append('completed[]', stepId);
                }
            });

            return formData;
        };

        const buildOnboardingParams = function(selectedSteps) {
            if (typeof window.URLSearchParams !== 'function') {
                return null;
            }

            const params = new window.URLSearchParams();
            params.append('action', 'bjlg_update_onboarding_progress');
            params.append('nonce', ajaxData.onboarding_nonce || '');

            selectedSteps.forEach(function(stepId) {
                params.append('completed[]', stepId);
            });

            return params;
        };

        const submitOnboardingProgress = function(selectedSteps) {
            if (!ajaxData || !ajaxData.ajax_url) {
                return;
            }

            const normalizedSteps = Array.isArray(selectedSteps) ? selectedSteps : [];
            const sanitizedSteps = normalizedSteps.reduce(function(list, stepId) {
                if (stepId === undefined || stepId === null) {
                    return list;
                }

                const value = String(stepId).trim();
                if (value !== '') {
                    list.push(value);
                }

                return list;
            }, []);

            const performFetch = function(requestBody) {
                if (typeof window.fetch === 'function') {
                    const body = requestBody || buildOnboardingParams(sanitizedSteps);
                    if (body) {
                        const isFormData = typeof window.FormData === 'function' && body instanceof window.FormData;
                        const options = {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: body,
                        };

                        if (!isFormData) {
                            options.headers = {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            };
                        }

                        window.fetch(ajaxData.ajax_url, options).catch(function() {});
                        return;
                    }
                }

                if (window.jQuery && typeof window.jQuery.ajax === 'function') {
                    window.jQuery.ajax({
                        url: ajaxData.ajax_url,
                        method: 'POST',
                        data: {
                            action: 'bjlg_update_onboarding_progress',
                            nonce: ajaxData.onboarding_nonce || '',
                            completed: sanitizedSteps,
                        },
                        traditional: false,
                    });
                }
            };

            if (apiFetch && typeof apiFetch === 'function') {
                const formData = buildOnboardingFormData(sanitizedSteps);
                if (formData) {
                    apiFetch({
                        url: ajaxData.ajax_url,
                        method: 'POST',
                        body: formData,
                        parse: false,
                    }).catch(function() {
                        performFetch(buildOnboardingFormData(sanitizedSteps));
                    });
                } else {
                    performFetch(null);
                }
                return;
            }

            performFetch(buildOnboardingFormData(sanitizedSteps));
        };

        const autoCompleted = new Set(steps.filter(function(step) { return step && step.completed; }).map(function(step) { return step.id; }));
        const initialManual = new Set((Array.isArray(data.completed) ? data.completed : []).filter(function(id) {
            return !autoCompleted.has(id);
        }));

        const Checklist = function() {
            const [manualCompleted, setManualCompleted] = useState(initialManual);

            const completedCount = steps.reduce(function(total, step) {
                if (!step || !step.id) {
                    return total;
                }

                if (autoCompleted.has(step.id) || manualCompleted.has(step.id)) {
                    return total + 1;
                }

                return total;
            }, 0);

            const totalSteps = steps.length;
            const progress = totalSteps > 0 ? Math.round((completedCount / totalSteps) * 100) : 0;

            useEffect(function() {
                if (!ajaxData || !ajaxData.ajax_url) {
                    return undefined;
                }

                const payload = Array.from(manualCompleted);
                const timeout = window.setTimeout(function() {
                    submitOnboardingProgress(payload);
                }, 400);

                return function() {
                    window.clearTimeout(timeout);
                };
            }, [manualCompleted]);

            const handleToggle = function(step, checked) {
                if (!step || !step.id || step.locked) {
                    return;
                }

                setManualCompleted(function(previous) {
                    const next = new Set(previous);
                    if (checked) {
                        next.add(step.id);
                    } else {
                        next.delete(step.id);
                    }

                    return next;
                });
            };

            const handleAction = function(step, event) {
                if (step && step.cta && step.cta.action === 'open-api-key') {
                    if (event && typeof event.preventDefault === 'function') {
                        event.preventDefault();
                    }

                    if (typeof window.bjlgSetActiveSection === 'function') {
                        window.bjlgSetActiveSection('integrations');
                    }

                    window.setTimeout(function() {
                        const target = document.getElementById('bjlg-create-api-key');
                        if (target && typeof target.scrollIntoView === 'function') {
                            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                        if (target && typeof target.focus === 'function') {
                            try {
                                target.focus({ preventScroll: true });
                            } catch (focusError) {
                                target.focus();
                            }
                        }
                    }, 150);
                }
            };

            return createElement(
                Card,
                { className: 'bjlg-onboarding-card' },
                createElement(
                    CardBody,
                    null,
                    createElement(
                        'div',
                        { className: 'bjlg-onboarding-card__header' },
                        createElement(
                            'div',
                            { className: 'bjlg-onboarding-card__header-main' },
                            createElement('h3', { className: 'bjlg-onboarding-card__title' }, __('Bien démarrer', 'backup-jlg')),
                            createElement('p', { className: 'bjlg-onboarding-card__subtitle' }, sprintf(__('Étapes complétées : %1$s/%2$s', 'backup-jlg'), completedCount, totalSteps))
                        ),
                        createElement(
                            'div',
                            { className: 'bjlg-onboarding-card__progress', role: 'group', 'aria-label': __('Progression', 'backup-jlg') },
                            createElement(
                                'div',
                                {
                                    className: 'bjlg-onboarding-card__progress-bar',
                                    role: 'progressbar',
                                    'aria-valuemin': 0,
                                    'aria-valuemax': 100,
                                    'aria-valuenow': progress,
                                    'aria-valuetext': progress + '%',
                                },
                                createElement('span', {
                                    className: 'bjlg-onboarding-card__progress-fill',
                                    style: { width: progress + '%' },
                                })
                            ),
                            createElement('span', { className: 'bjlg-onboarding-card__progress-value' }, progress + '%')
                        )
                    ),
                    steps.map(function(step) {
                        if (!step || !step.id) {
                            return null;
                        }

                        const isLocked = !!step.locked;
                        const isChecked = autoCompleted.has(step.id) || manualCompleted.has(step.id);
                        const classes = ['bjlg-onboarding-card__step'];

                        if (isLocked) {
                            classes.push('is-locked');
                        }

                        if (isChecked) {
                            classes.push('is-complete');
                        }

                        return createElement(
                            'div',
                            { key: step.id, className: classes.join(' ') },
                            createElement(
                                'div',
                                { className: 'bjlg-onboarding-card__step-main' },
                                createElement(CheckboxControl, {
                                    label: step.title || '',
                                    checked: isChecked,
                                    onChange: function(checked) {
                                        handleToggle(step, checked);
                                    },
                                    disabled: isLocked,
                                    __nextHasNoMarginBottom: true,
                                }),
                                step.description ? createElement('p', { className: 'bjlg-onboarding-card__description' }, step.description) : null,
                                (isLocked && !autoCompleted.has(step.id)) ? createElement('p', { className: 'bjlg-onboarding-card__hint' }, __('Terminez l’action associée pour valider cette étape.', 'backup-jlg')) : null
                            ),
                            step.cta ? createElement(
                                Button,
                                {
                                    isSecondary: true,
                                    href: step.cta.action ? undefined : (step.cta.href || '#'),
                                    target: step.cta.target || undefined,
                                    onClick: step.cta.action ? function(event) { handleAction(step, event); } : undefined,
                                },
                                step.cta.label || __('Ouvrir', 'backup-jlg')
                            ) : null
                        );
                    })
                )
            );
        };

        render(createElement(Checklist), rootElement);
    };

    if (appEl) {
        mountSectionTabs(appEl);

        const onboardingData = parseJSONSafe(appEl.getAttribute('data-bjlg-onboarding'), null);
        const onboardingRoot = document.getElementById('bjlg-onboarding-checklist');
        if (onboardingRoot && onboardingData) {
            mountOnboardingChecklist(onboardingRoot, onboardingData);
        }
    }

    if (appEl && !currentSection) {
        const initialSection = appEl.getAttribute('data-active-section');
        if (initialSection) {
            setActiveSection(initialSection, false, true);
        }
    }

    if ($('.bjlg-dashboard-overview').length) {
        requestModules(['dashboard']);
    }

    const updateEscalationModeUI = function() {
        const $modeInputs = $('input[name="escalation_mode"]');
        if (!$modeInputs.length) {
            return;
        }

        const mode = $modeInputs.filter(':checked').val();
        const $stages = $('.bjlg-escalation-stages');
        if ($stages.length) {
            const isStaged = mode === 'staged';
            $stages.toggleClass('is-active', isStaged);
            $stages.attr('aria-hidden', isStaged ? 'false' : 'true');

            if (window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function') {
                const i18n = window.wp.i18n || {};
                const __ = typeof i18n.__ === 'function' ? i18n.__ : function(str) { return str; };
                const message = mode === 'staged'
                    ? __('Mode séquentiel activé : configurez vos étapes d’escalade.', 'backup-jlg')
                    : __('Mode simple activé : toutes les escalades utilisent le même délai.', 'backup-jlg');
                window.wp.a11y.speak(message, 'polite');
            }
        }
    };

    $(document).on('change', 'input[name="escalation_mode"]', updateEscalationModeUI);
    updateEscalationModeUI();

    $('.bjlg-autoload-module').each(function() {
        const modules = parseModuleAttribute($(this).data('bjlgModules'));
        requestModules(modules);
    });

    (function initNetworkAdminView() {
        const root = document.getElementById('bjlg-network-admin-app');
        if (!root) {
            return;
        }

        const networkEnabled = root.getAttribute('data-network-enabled') === '1';
        const fallback = root.querySelector('.bjlg-network-app__fallback');
        const panel = root.querySelector('.bjlg-network-app__panel');

        const i18n = window.wp && window.wp.i18n ? window.wp.i18n : {};
        const __ = typeof i18n.__ === 'function' ? i18n.__ : function(str) { return str; };
        const sprintf = typeof i18n.sprintf === 'function'
            ? i18n.sprintf
            : function(format) {
                const args = Array.prototype.slice.call(arguments, 1);
                let index = 0;
                return String(format).replace(/%s/g, function() {
                    const replacement = args[index];
                    index++;
                    return typeof replacement === 'undefined' ? '' : replacement;
                });
            };
        const numberFormat = typeof i18n.numberFormat === 'function'
            ? i18n.numberFormat
            : function(value, precision) {
                const numeric = typeof value === 'number' ? value : parseFloat(value);
                if (!isFinite(numeric)) {
                    return '0.00';
                }

                const pow = Math.pow(10, precision);
                return String(Math.round(numeric * pow) / pow);
            };

        const networkConfig = (window.bjlg_ajax && window.bjlg_ajax.network) ? window.bjlg_ajax.network : null;
        let sites = parseJSONSafe(root.getAttribute('data-sites'), []);

        const hideFallback = function() {
            if (!fallback) {
                return;
            }
            fallback.setAttribute('hidden', 'hidden');
            fallback.classList.add('is-hidden');
        };

        const renderStatus = function(container, site) {
            container.innerHTML = '';

            if (!site) {
                const message = document.createElement('p');
                message.textContent = networkEnabled
                    ? __('Sélectionnez un site pour afficher ses informations.', 'backup-jlg')
                    : __('Le mode réseau est désactivé. Activez-le pour synchroniser les données.', 'backup-jlg');
                container.appendChild(message);

                return;
            }

            const statsList = document.createElement('dl');
            statsList.className = 'bjlg-network-app__stats';

            const addStat = function(label, value) {
                const term = document.createElement('dt');
                term.textContent = label;
                const description = document.createElement('dd');
                description.textContent = value;
                statsList.appendChild(term);
                statsList.appendChild(description);
            };

            const history = site.history || {};
            addStat(__('Actions enregistrées', 'backup-jlg'), String(history.total_actions || 0));
            addStat(__('Réussites', 'backup-jlg'), String(history.successful || 0));
            addStat(__('Échecs', 'backup-jlg'), String(history.failed || 0));

            const quota = site.quota || {};
            const quotaValue = typeof quota.used === 'number'
                ? sprintf(__('%s Mo', 'backup-jlg'), numberFormat(quota.used / (1024 * 1024), 2))
                : __('Inconnu', 'backup-jlg');

            addStat(__('Quota consommé', 'backup-jlg'), quotaValue);

            container.appendChild(statsList);
        };

        const mountInterface = function(list) {
            if (!panel) {
                return;
            }

            panel.innerHTML = '';

            const wrapper = document.createElement('div');
            wrapper.className = 'bjlg-network-app__layout';

            const selector = document.createElement('div');
            selector.className = 'bjlg-network-app__controls';

            const label = document.createElement('label');
            label.setAttribute('for', 'bjlg-network-site-select');
            label.textContent = __('Site du réseau', 'backup-jlg');
            selector.appendChild(label);

            const select = document.createElement('select');
            select.id = 'bjlg-network-site-select';
            select.className = 'bjlg-network-app__select';

            list.forEach(function(site, index) {
                const option = document.createElement('option');
                option.value = String(site.id);
                option.textContent = site.name || __('Site', 'backup-jlg') + ' #' + site.id;
                option.setAttribute('data-index', String(index));
                select.appendChild(option);
            });

            selector.appendChild(select);

            const openButton = document.createElement('a');
            openButton.className = 'button button-secondary';
            openButton.id = 'bjlg-network-open-dashboard';
            openButton.target = '_blank';
            openButton.rel = 'noopener noreferrer';
            openButton.textContent = __('Ouvrir le tableau de bord', 'backup-jlg');
            selector.appendChild(openButton);

            const status = document.createElement('div');
            status.id = 'bjlg-network-site-status';
            status.className = 'bjlg-network-app__status';

            wrapper.appendChild(selector);
            wrapper.appendChild(status);
            panel.appendChild(wrapper);

            const updateSelection = function(site) {
                if (openButton) {
                    openButton.href = site && site.admin_url ? site.admin_url : '#';
                    openButton.setAttribute('aria-disabled', site && site.admin_url ? 'false' : 'true');
                }
                renderStatus(status, site || null);
            };

            select.addEventListener('change', function() {
                const selectedIndex = select.selectedIndex;
                const site = list[selectedIndex];
                updateSelection(site);
            });

            if (list.length) {
                select.selectedIndex = 0;
                updateSelection(list[0]);
                hideFallback();
            } else {
                select.disabled = true;
                updateSelection(null);
            }
        };

        const updateSites = function(list) {
            if (!Array.isArray(list)) {
                return;
            }

            sites = list;
            mountInterface(sites);
        };

        mountInterface(sites);

        if (!networkEnabled) {
            return;
        }

        if (!networkConfig || !networkConfig.enabled || !networkConfig.endpoints || !networkConfig.endpoints.sites) {
            return;
        }

        if (!window.wp || !window.wp.apiFetch) {
            return;
        }

        window.wp.apiFetch({
            url: networkConfig.endpoints.sites,
            headers: {
                'X-WP-Nonce': bjlg_ajax.rest_nonce
            }
        }).then(function(response) {
            if (response && Array.isArray(response.sites)) {
                updateSites(response.sites);
            }
        }).catch(function() {
            // Leave fallback visible in case of errors.
        });
    })();

    (function setupContrastToggle() {
        const $button = $('#bjlg-contrast-toggle');
        const $context = $('.bjlg-wrap');

        if (!$context.length || !$button.length) {
            return;
        }

        const storageKey = 'bjlg-admin-theme';
        const defaultDarkLabel = $button.data('darkLabel') || $button.text();
        const defaultLightLabel = $button.data('lightLabel') || $button.text();

        const applyTheme = function(theme) {
            const normalized = theme === 'dark' ? 'dark' : 'light';
            $context.removeClass('is-dark is-light');
            $context.addClass(normalized === 'dark' ? 'is-dark' : 'is-light');
            $context.attr('data-bjlg-theme', normalized);

            const isDark = normalized === 'dark';
            $button.attr('aria-pressed', isDark ? 'true' : 'false');
            $button.text(isDark ? defaultLightLabel : defaultDarkLabel);
        };

        let storedTheme = null;
        try {
            storedTheme = window.localStorage.getItem(storageKey);
        } catch (error) {
            storedTheme = null;
        }

        applyTheme(storedTheme === 'dark' ? 'dark' : 'light');

        $button.on('click', function(event) {
            event.preventDefault();
            const currentTheme = ($context.attr('data-bjlg-theme') || 'light') === 'dark' ? 'dark' : 'light';
            const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
            applyTheme(nextTheme);

            try {
                window.localStorage.setItem(storageKey, nextTheme);
            } catch (error) {
                // Storage may be unavailable.
            }
        });
    })();

    function mountModernShell(rootElement, templatesElement, config) {
        if (!window.wp || !window.wp.element || !window.wp.components) {
            return;
        }

        const sections = Array.isArray(config.sections) ? config.sections : [];
        if (!sections.length) {
            return;
        }

        sections.forEach(function(section) {
            if (section && section.key) {
                sectionLabels[section.key] = section.label || '';
            }
        });

        const templateMap = new Map();
        templatesElement.querySelectorAll('section[data-section]').forEach(function(node) {
            if (!node || typeof node.getAttribute !== 'function') {
                return;
            }

            const key = node.getAttribute('data-section');
            if (!key) {
                return;
            }

            templateMap.set(key, node.innerHTML || '');

            const attr = node.getAttribute('data-bjlg-modules');
            if (attr) {
                const modules = parseModuleAttribute(attr);
                if (modules.length) {
                    if (!sectionModulesMap[key]) {
                        sectionModulesMap[key] = modules.slice();
                    } else {
                        sectionModulesMap[key] = sectionModulesMap[key].concat(modules);
                    }

                    sectionModulesMap[key] = Array.from(new Set(sectionModulesMap[key]));
                }
            }
        });

        const moduleMapping = Object.assign({}, sectionModulesMap, config.modules || {});
        const notices = Array.isArray(config.notices) ? config.notices : [];
        const summaryItems = Array.isArray(config.summary) ? config.summary : [];
        const reliability = (config.reliability && typeof config.reliability === 'object') ? config.reliability : {};
        const breadcrumbs = Array.isArray(config.breadcrumbs) ? config.breadcrumbs : [];
        const onboardingData = config.onboarding && typeof config.onboarding === 'object' && Array.isArray(config.onboarding.steps) && config.onboarding.steps.length
            ? config.onboarding
            : null;

        const elementApi = window.wp.element;
        const components = window.wp.components;

        if (!elementApi || !components) {
            return;
        }

        const { createElement, useEffect, useMemo, useRef, useState } = elementApi;
        const RawHTMLComponent = elementApi.RawHTML;
        const renderFn = typeof elementApi.render === 'function' ? elementApi.render : null;
        const createRoot = typeof elementApi.createRoot === 'function' ? elementApi.createRoot : null;
        const RawHTML = RawHTMLComponent || function RawHTML(props) {
            return createElement('div', { dangerouslySetInnerHTML: { __html: props && props.children ? props.children : '' } });
        };

        const { Button, Card, CardBody, CardHeader, CardFooter, Notice, TabPanel } = components;

        if ((!renderFn && !createRoot) || !Button || !Card || !CardBody || !TabPanel || !Notice) {
            return;
        }

        const statusRegion = document.getElementById('bjlg-admin-status');

        const moduleLoader = function(sectionKey) {
            const configured = moduleMapping[sectionKey];
            const modules = [];

            if (Array.isArray(configured)) {
                configured.forEach(function(name) {
                    if (typeof name === 'string' && name !== '') {
                        modules.push(name);
                    }
                });
            }

            requestModules(modules);
        };

        const getSectionLabel = function(key) {
            const match = sections.find(function(section) {
                return section && section.key === key;
            });

            return match && match.label ? match.label : '';
        };

        const ModernApp = function() {
            const fallbackKey = sections[0] ? sections[0].key : '';
            const initialSection = sections.some(function(section) { return section && section.key === config.activeSection; })
                ? config.activeSection
                : fallbackKey;

            const [activeSection, setActiveSection] = useState(initialSection);
            const [sidebarOpen, setSidebarOpen] = useState(false);
            const historyPreference = useRef(true);

            const handleSelect = function(sectionKey, shouldUpdateHistory) {
                if (!sectionKey || sectionKey === activeSection) {
                    return;
                }

                if (!sections.some(function(section) { return section && section.key === sectionKey; })) {
                    return;
                }

                historyPreference.current = shouldUpdateHistory !== false;
                setSidebarOpen(false);
                setActiveSection(sectionKey);
            };

            useEffect(function() {
                moduleLoader(activeSection);

                if (rootElement) {
                    rootElement.setAttribute('data-bjlg-active-section', activeSection);
                }

                const label = getSectionLabel(activeSection);
                if (statusRegion) {
                    statusRegion.textContent = label;
                }

                if (speak && label) {
                    speak(sprintf(__('Section activée : %s', 'backup-jlg'), label));
                }

                if (historyPreference.current) {
                    try {
                        if (window.history && typeof window.history.replaceState === 'function') {
                            const url = new URL(window.location.href);
                            url.searchParams.set('section', activeSection);
                            window.history.replaceState({}, '', url.toString());
                        }
                    } catch (error) {
                        const baseUrl = window.location.href.split('#')[0];
                        const hasQuery = baseUrl.indexOf('?') !== -1;
                        let newUrl = '';
                        if (baseUrl.indexOf('section=') !== -1) {
                            newUrl = baseUrl.replace(/([?&])section=[^&#]*/, '$1section=' + encodeURIComponent(activeSection));
                        } else if (hasQuery) {
                            newUrl = baseUrl + '&section=' + encodeURIComponent(activeSection);
                        } else {
                            newUrl = baseUrl + '?section=' + encodeURIComponent(activeSection);
                        }
                        window.history.replaceState({}, '', newUrl);
                    }
                } else {
                    historyPreference.current = true;
                }

                let sectionEvent = null;
                const detail = { section: activeSection, panel: rootElement };
                if (typeof window.CustomEvent === 'function') {
                    sectionEvent = new window.CustomEvent('bjlg:section-activated', { detail: detail });
                } else if (document.createEvent) {
                    sectionEvent = document.createEvent('CustomEvent');
                    if (sectionEvent && typeof sectionEvent.initCustomEvent === 'function') {
                        sectionEvent.initCustomEvent('bjlg:section-activated', false, false, detail);
                    }
                }

                if (sectionEvent) {
                    document.dispatchEvent(sectionEvent);
                }

                if (onboardingData) {
                    const checklistRoot = document.getElementById('bjlg-onboarding-checklist');
                    if (checklistRoot) {
                        mountOnboardingChecklist(checklistRoot, onboardingData);
                    }
                }
            }, [activeSection]);

            useEffect(function() {
                window.bjlgAdmin = window.bjlgAdmin || {};
                window.bjlgAdmin.setActiveSection = function(sectionKey, updateHistory) {
                    handleSelect(sectionKey, updateHistory !== false);
                };
                window.bjlgAdmin.getActiveSection = function() {
                    return activeSection;
                };
                window.bjlgAdmin.onSectionChange = function(callback) {
                    if (typeof callback !== 'function') {
                        return function noop() {};
                    }

                    const handler = function(event) {
                        if (!event || !event.detail) {
                            callback({});
                            return;
                        }

                        callback(event.detail);
                    };

                    document.addEventListener('bjlg:section-activated', handler);

                    return function unsubscribe() {
                        document.removeEventListener('bjlg:section-activated', handler);
                    };
                };
                window.bjlgSetActiveSection = function(sectionKey) {
                    handleSelect(sectionKey, true);
                };
            }, [activeSection]);

            const tabs = useMemo(function() {
                return sections.map(function(section) {
                    return {
                        name: section.key,
                        title: section.label,
                    };
                });
            }, [sections]);

            return createElement('div', { className: 'bjlg-modern-shell' + (sidebarOpen ? ' is-sidebar-open' : '') }, [
                createElement('div', { className: 'bjlg-modern-shell__toolbar' }, [
                    createElement(Button, {
                        variant: 'secondary',
                        className: 'bjlg-modern-shell__menu-toggle',
                        onClick: function() {
                            setSidebarOpen(function(open) { return !open; });
                        },
                        'aria-expanded': sidebarOpen ? 'true' : 'false',
                        'aria-controls': 'bjlg-modern-shell-sidebar',
                    }, __('Ouvrir la navigation', 'backup-jlg')),
                ]),
                createElement('div', { className: 'bjlg-modern-shell__layout' }, [
                    createElement('aside', {
                        id: 'bjlg-modern-shell-sidebar',
                        className: 'bjlg-modern-shell__sidebar',
                        'aria-label': __('Navigation principale', 'backup-jlg'),
                    }, [
                        createElement('div', { className: 'bjlg-modern-shell__sidebar-header' }, [
                            createElement('h2', null, __('Navigation', 'backup-jlg')),
                            createElement(Button, {
                                variant: 'tertiary',
                                className: 'bjlg-modern-shell__sidebar-close',
                                onClick: function() { setSidebarOpen(false); },
                            }, __('Fermer', 'backup-jlg')),
                        ]),
                        createElement('nav', { className: 'bjlg-modern-shell__nav' }, sections.map(function(section) {
                            if (!section || !section.key) {
                                return null;
                            }
                            const isActive = section.key === activeSection;
                            return createElement(Button, {
                                key: section.key,
                                className: 'bjlg-modern-shell__nav-link' + (isActive ? ' is-active' : ''),
                                variant: isActive ? 'primary' : 'secondary',
                                onClick: function() {
                                    handleSelect(section.key, true);
                                },
                                'aria-current': isActive ? 'page' : undefined,
                            }, section.label);
                        })),
                        summaryItems.length ? createElement(Card, { className: 'bjlg-modern-shell__summary-card' }, [
                            createElement(CardHeader, null, __('Résumé d’état', 'backup-jlg')),
                            createElement(CardBody, null,
                                createElement('ul', { className: 'bjlg-modern-shell__summary-list' }, summaryItems.map(function(item, index) {
                                    if (!item) {
                                        return null;
                                    }
                                    return createElement('li', { key: item.label || index }, [
                                        createElement('span', { className: 'bjlg-modern-shell__summary-label' }, item.label || ''),
                                        createElement('span', { className: 'bjlg-modern-shell__summary-value' }, item.value || ''),
                                        item.meta ? createElement('span', { className: 'bjlg-modern-shell__summary-meta' }, item.meta) : null,
                                    ]);
                                }))
                            ),
                            (reliability.level || typeof reliability.score === 'number') ? createElement(CardFooter, { className: 'bjlg-modern-shell__summary-footer' }, [
                                reliability.level ? createElement('span', { className: 'bjlg-modern-shell__summary-reliability' }, reliability.level) : null,
                                typeof reliability.score === 'number' ? createElement('span', { className: 'bjlg-modern-shell__summary-score' }, sprintf(__('%s /100', 'backup-jlg'), reliability.score)) : null,
                            ]) : null,
                        ]) : null,
                    ]),
                    createElement('div', { className: 'bjlg-modern-shell__content' }, [
                        notices.map(function(notice, index) {
                            if (!notice || !notice.message) {
                                return null;
                            }
                            const status = notice.status || 'info';
                            return createElement(Notice, {
                                key: 'bjlg-modern-notice-' + index,
                                status: status,
                                isDismissible: false,
                                className: 'bjlg-modern-shell__notice',
                            }, notice.message);
                        }),
                        breadcrumbs.length ? createElement('nav', { className: 'bjlg-modern-shell__breadcrumbs', 'aria-label': __('Fil d’Ariane', 'backup-jlg') },
                            createElement('ol', null, breadcrumbs.map(function(item, index) {
                                if (!item) {
                                    return null;
                                }
                                const isLast = index === breadcrumbs.length - 1;
                                if (!item.url || isLast) {
                                    return createElement('li', { key: item.label || index }, createElement('span', { 'aria-current': isLast ? 'page' : undefined }, item.label || ''));
                                }
                                return createElement('li', { key: item.label || index }, createElement('a', { href: item.url }, item.label || ''));
                            }))
                        ) : null,
                        createElement(TabPanel, {
                            className: 'bjlg-modern-shell__tabpanel',
                            activeClass: 'is-active',
                            tabs: tabs,
                            initialTab: activeSection,
                            onSelect: function(tabName) {
                                handleSelect(tabName, true);
                            },
                            key: activeSection,
                        }, function(tab) {
                            const content = templateMap.get(tab.name) || '';
                            return createElement(Card, { className: 'bjlg-modern-shell__panel-card', 'data-bjlg-panel': tab.name }, [
                                createElement(CardBody, null, createElement(RawHTML, null, content)),
                            ]);
                        }),
                    ]),
                ]),
            ]);
        };

        if (createRoot) {
            const root = createRoot(rootElement);
            root.render(createElement(ModernApp));
        } else if (renderFn) {
            renderFn(createElement(ModernApp), rootElement);
        }
    }

    (function initModernShell() {
        const rootElement = document.getElementById('bjlg-modern-admin-root');
        const templatesElement = document.getElementById('bjlg-modern-admin-templates');
        if (!rootElement || !templatesElement) {
            return;
        }

        const modernConfig = (typeof window.bjlgModernAdmin === 'object' && window.bjlgModernAdmin) ? window.bjlgModernAdmin : {};
        const sections = Array.isArray(modernConfig.sections) ? modernConfig.sections : [];
        if (!sections.length) {
            return;
        }

        mountModernShell(rootElement, templatesElement, modernConfig);
    })();

    $(window).on('resize', function() {
        if (window.innerWidth > 960) {
            setSidebarExpanded(false);
        }
    });
});

