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
        const config = (window.bjlg_ajax && window.bjlg_ajax.network) ? window.bjlg_ajax.network : null;
        const endpoints = config && config.endpoints ? config.endpoints : {};
        const restNonce = window.bjlg_ajax ? window.bjlg_ajax.rest_nonce : '';
        const restBackups = window.bjlg_ajax ? window.bjlg_ajax.rest_backups : '';
        const bootstrap = parseJSONSafe(root.getAttribute('data-sites'), null);
        const i18n = window.wp && window.wp.i18n ? window.wp.i18n : {};
        const __ = typeof i18n.__ === 'function' ? i18n.__ : function(str) { return str; };
        const _n = typeof i18n._n === 'function'
            ? i18n._n
            : function(single, plural, number) {
                return number === 1 ? single : plural;
            };
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

        const state = {
            period: 'week',
            status: 'all',
            search: '',
            page: 1,
            perPage: 25,
            blogId: null,
        };

        let sitesPayload = bootstrap;
        let historyPayload = null;
        let isLoading = false;
        let lastError = '';
        let searchTimer = null;

        const hideFallback = function() {
            if (!fallback) {
                return;
            }
            fallback.setAttribute('hidden', 'hidden');
            fallback.classList.add('is-hidden');
        };

        const formatNumber = function(value, decimals) {
            const numeric = typeof value === 'number' ? value : parseFloat(value);
            if (!isFinite(numeric)) {
                return '0';
            }

            if (window.Intl && window.Intl.NumberFormat) {
                return new window.Intl.NumberFormat(undefined, { maximumFractionDigits: decimals, minimumFractionDigits: decimals }).format(numeric);
            }

            const pow = Math.pow(10, decimals);
            return String(Math.round(numeric * pow) / pow);
        };

        const formatRelativeDate = function(iso) {
            if (!iso) {
                return __('Jamais', 'backup-jlg');
            }

            const timestamp = Date.parse(iso);
            if (Number.isNaN(timestamp)) {
                return iso;
            }

            const deltaSeconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));

            if (deltaSeconds < 60) {
                return __('Il y a quelques secondes', 'backup-jlg');
            }

            if (deltaSeconds < 3600) {
                const minutes = Math.floor(deltaSeconds / 60);
                return sprintf(_n('Il y a %s minute', 'Il y a %s minutes', minutes, 'backup-jlg'), minutes);
            }

            if (deltaSeconds < 86400) {
                const hours = Math.floor(deltaSeconds / 3600);
                return sprintf(_n('Il y a %s heure', 'Il y a %s heures', hours, 'backup-jlg'), hours);
            }

            const days = Math.floor(deltaSeconds / 86400);
            return sprintf(_n('Il y a %s jour', 'Il y a %s jours', days, 'backup-jlg'), days);
        };

        const appendQuery = function(url, params) {
            if (!url) {
                return '';
            }

            const searchParams = new window.URLSearchParams();
            Object.keys(params).forEach(function(key) {
                const value = params[key];
                if (value === null || typeof value === 'undefined' || value === '') {
                    return;
                }
                searchParams.append(key, value);
            });

            const separator = url.indexOf('?') === -1 ? '?' : '&';
            const query = searchParams.toString();

            return query ? url + separator + query : url;
        };

        const fetchJSON = function(url, options) {
            const settings = Object.assign({
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-WP-Nonce': restNonce,
                },
            }, options || {});

            return window.fetch(url, settings).then(function(response) {
                if (!response.ok) {
                    return response.json().catch(function() {
                        return {};
                    }).then(function(body) {
                        const message = body && body.message ? body.message : __('Une erreur est survenue.', 'backup-jlg');
                        const error = new Error(message);
                        error.status = response.status;
                        throw error;
                    });
                }

                return response.json();
            });
        };

        const renderMessage = function(container, message, type) {
            const paragraph = document.createElement('p');
            paragraph.className = 'bjlg-network-dashboard__message' + (type ? ' is-' + type : '');
            paragraph.textContent = message;
            container.appendChild(paragraph);
        };

        const buildStatusBadge = function(status) {
            const badge = document.createElement('span');
            badge.className = 'bjlg-network-dashboard__status-badge bjlg-network-dashboard__status-badge--' + status;
            if (status === 'failing') {
                badge.textContent = __('En alerte', 'backup-jlg');
            } else if (status === 'healthy') {
                badge.textContent = __('Actif', 'backup-jlg');
            } else {
                badge.textContent = __('Inactif', 'backup-jlg');
            }

            return badge;
        };

        const triggerBackup = function(site, button) {
            if (!restBackups) {
                return;
            }

            const siteId = site.id;
            if (!siteId) {
                return;
            }

            button.disabled = true;
            const initialText = button.textContent;
            button.textContent = __('Planification…', 'backup-jlg');

            const url = appendQuery(restBackups, { site_id: siteId });
            fetchJSON(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce,
                },
                body: JSON.stringify({ type: 'full' }),
            }).then(function() {
                button.textContent = __('Sauvegarde planifiée', 'backup-jlg');
                window.setTimeout(function() {
                    button.textContent = initialText;
                    button.disabled = false;
                }, 1500);
                loadData();
            }).catch(function(error) {
                button.textContent = initialText;
                button.disabled = false;
                window.console.error('Backup trigger failed', error);
                window.alert(__('Impossible de déclencher la sauvegarde distante.', 'backup-jlg'));
            });
        };

        const renderSummary = function(container, payload) {
            container.innerHTML = '';

            if (!payload || !payload.totals) {
                renderMessage(container, __('Aucune donnée réseau à afficher.', 'backup-jlg'), 'info');
                return;
            }

            const totals = payload.totals;
            const summaryList = document.createElement('ul');
            summaryList.className = 'bjlg-network-dashboard__summary-list';

            const summaryItems = [
                {
                    label: __('Actions enregistrées', 'backup-jlg'),
                    value: formatNumber(totals.total_actions || 0, 0),
                },
                {
                    label: __('Succès', 'backup-jlg'),
                    value: formatNumber(totals.successful || 0, 0),
                },
                {
                    label: __('Échecs', 'backup-jlg'),
                    value: formatNumber(totals.failed || 0, 0),
                },
            ];

            summaryItems.forEach(function(item) {
                const li = document.createElement('li');
                li.className = 'bjlg-network-dashboard__summary-item';

                const value = document.createElement('span');
                value.className = 'bjlg-network-dashboard__summary-value';
                value.textContent = item.value;
                li.appendChild(value);

                const label = document.createElement('span');
                label.className = 'bjlg-network-dashboard__summary-label';
                label.textContent = item.label;
                li.appendChild(label);

                summaryList.appendChild(li);
            });

            container.appendChild(summaryList);
        };

        const renderHistory = function(container, payload) {
            container.innerHTML = '';

            const heading = document.createElement('h3');
            heading.className = 'bjlg-network-dashboard__section-title';
            heading.textContent = __('Historique récent', 'backup-jlg');
            container.appendChild(heading);

            if (!payload || !Array.isArray(payload.history) || payload.history.length === 0) {
                renderMessage(container, __('Aucun événement récent.', 'backup-jlg'), 'info');
                return;
            }

            const list = document.createElement('ul');
            list.className = 'bjlg-network-dashboard__history-list';

            payload.history.forEach(function(entry) {
                if (!entry || typeof entry !== 'object') {
                    return;
                }

                const item = document.createElement('li');
                item.className = 'bjlg-network-dashboard__history-item';

                const header = document.createElement('div');
                header.className = 'bjlg-network-dashboard__history-header';

                const action = document.createElement('span');
                action.className = 'bjlg-network-dashboard__history-action';
                action.textContent = entry.action_type || '';
                header.appendChild(action);

                const badge = buildStatusBadge(entry.status || 'info');
                badge.classList.add('bjlg-network-dashboard__history-status');
                header.appendChild(badge);

                const siteLabel = document.createElement('span');
                siteLabel.className = 'bjlg-network-dashboard__history-site';
                const siteId = entry.blog_id ? parseInt(entry.blog_id, 10) : 0;
                siteLabel.textContent = siteId > 0
                    ? sprintf(__('Site #%s', 'backup-jlg'), siteId)
                    : __('Événement réseau', 'backup-jlg');
                header.appendChild(siteLabel);

                item.appendChild(header);

                if (entry.details) {
                    const details = document.createElement('p');
                    details.className = 'bjlg-network-dashboard__history-details';
                    details.textContent = entry.details;
                    item.appendChild(details);
                }

                const timestamp = document.createElement('span');
                timestamp.className = 'bjlg-network-dashboard__history-time';
                timestamp.textContent = formatRelativeDate(entry.timestamp);
                item.appendChild(timestamp);

                list.appendChild(item);
            });

            container.appendChild(list);
        };

        const renderSitesTable = function(container, payload) {
            container.innerHTML = '';

            const heading = document.createElement('h3');
            heading.className = 'bjlg-network-dashboard__section-title';
            heading.textContent = __('Sites supervisés', 'backup-jlg');
            container.appendChild(heading);

            if (!payload || !Array.isArray(payload.sites) || payload.sites.length === 0) {
                renderMessage(container, __('Aucun site disponible pour cette sélection.', 'backup-jlg'), 'info');
                return;
            }

            const table = document.createElement('table');
            table.className = 'bjlg-network-dashboard__table';

            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            [
                __('Site', 'backup-jlg'),
                __('Statut', 'backup-jlg'),
                __('Actions', 'backup-jlg'),
                __('Échecs', 'backup-jlg'),
                __('Dernière activité', 'backup-jlg'),
                __('Actions distantes', 'backup-jlg'),
            ].forEach(function(label) {
                const th = document.createElement('th');
                th.textContent = label;
                headerRow.appendChild(th);
            });
            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');

            payload.sites.forEach(function(site) {
                if (!site || typeof site !== 'object') {
                    return;
                }

                const activity = site.activity || {};
                const row = document.createElement('tr');

                const nameCell = document.createElement('td');
                const nameLink = document.createElement('a');
                nameLink.textContent = site.name || sprintf(__('Site #%s', 'backup-jlg'), site.id);
                nameLink.href = site.url || '#';
                nameLink.target = '_blank';
                nameLink.rel = 'noopener noreferrer';
                nameCell.appendChild(nameLink);
                row.appendChild(nameCell);

                const statusCell = document.createElement('td');
                statusCell.appendChild(buildStatusBadge(site.status || 'idle'));
                row.appendChild(statusCell);

                const totalCell = document.createElement('td');
                totalCell.textContent = formatNumber(activity.total_actions || 0, 0);
                row.appendChild(totalCell);

                const failedCell = document.createElement('td');
                failedCell.textContent = formatNumber(activity.failed || 0, 0);
                row.appendChild(failedCell);

                const lastCell = document.createElement('td');
                lastCell.textContent = formatRelativeDate(activity.last_activity || '');
                row.appendChild(lastCell);

                const actionsCell = document.createElement('td');
                actionsCell.className = 'bjlg-network-dashboard__site-actions';

                const openButton = document.createElement('a');
                openButton.className = 'button button-secondary';
                openButton.href = site.admin_url || '#';
                openButton.target = '_blank';
                openButton.rel = 'noopener noreferrer';
                openButton.textContent = __('Ouvrir', 'backup-jlg');
                actionsCell.appendChild(openButton);

                const historyButton = document.createElement('button');
                historyButton.type = 'button';
                historyButton.className = 'button';
                historyButton.textContent = __('Historique', 'backup-jlg');
                historyButton.addEventListener('click', function() {
                    state.blogId = site.id;
                    state.page = 1;
                    loadData();
                });
                actionsCell.appendChild(historyButton);

                if (restBackups) {
                    const backupButton = document.createElement('button');
                    backupButton.type = 'button';
                    backupButton.className = 'button button-primary';
                    backupButton.textContent = __('Sauvegarder', 'backup-jlg');
                    backupButton.addEventListener('click', function() {
                        triggerBackup(site, backupButton);
                    });
                    actionsCell.appendChild(backupButton);
                }

                row.appendChild(actionsCell);
                tbody.appendChild(row);
            });

            table.appendChild(tbody);
            container.appendChild(table);

            if (payload.pagination && payload.pagination.pages > 1) {
                const pager = document.createElement('div');
                pager.className = 'bjlg-network-dashboard__pagination';

                const prev = document.createElement('button');
                prev.type = 'button';
                prev.className = 'button';
                prev.textContent = __('Précédent', 'backup-jlg');
                prev.disabled = state.page <= 1;
                prev.addEventListener('click', function() {
                    if (state.page > 1) {
                        state.page--;
                        loadData();
                    }
                });
                pager.appendChild(prev);

                const pageInfo = document.createElement('span');
                pageInfo.className = 'bjlg-network-dashboard__page-info';
                pageInfo.textContent = sprintf(__('Page %1$s sur %2$s', 'backup-jlg'), state.page, payload.pagination.pages);
                pager.appendChild(pageInfo);

                const next = document.createElement('button');
                next.type = 'button';
                next.className = 'button';
                next.textContent = __('Suivant', 'backup-jlg');
                next.disabled = state.page >= payload.pagination.pages;
                next.addEventListener('click', function() {
                    if (state.page < payload.pagination.pages) {
                        state.page++;
                        loadData();
                    }
                });
                pager.appendChild(next);

                container.appendChild(pager);
            }
        };

        const renderFilters = function(container) {
            const controls = document.createElement('div');
            controls.className = 'bjlg-network-dashboard__filters';

            const periodSelect = document.createElement('select');
            periodSelect.className = 'bjlg-network-dashboard__filter';
            [
                { value: 'week', label: __('7 derniers jours', 'backup-jlg') },
                { value: 'month', label: __('30 derniers jours', 'backup-jlg') },
                { value: 'year', label: __('12 derniers mois', 'backup-jlg') },
            ].forEach(function(option) {
                const opt = document.createElement('option');
                opt.value = option.value;
                opt.textContent = option.label;
                if (state.period === option.value) {
                    opt.selected = true;
                }
                periodSelect.appendChild(opt);
            });
            periodSelect.addEventListener('change', function() {
                state.period = periodSelect.value;
                state.page = 1;
                loadData();
            });
            controls.appendChild(periodSelect);

            const statusSelect = document.createElement('select');
            statusSelect.className = 'bjlg-network-dashboard__filter';
            [
                { value: 'all', label: __('Tous les statuts', 'backup-jlg') },
                { value: 'healthy', label: __('Actifs', 'backup-jlg') },
                { value: 'idle', label: __('Inactifs', 'backup-jlg') },
                { value: 'failing', label: __('En alerte', 'backup-jlg') },
            ].forEach(function(option) {
                const opt = document.createElement('option');
                opt.value = option.value;
                opt.textContent = option.label;
                if (state.status === option.value) {
                    opt.selected = true;
                }
                statusSelect.appendChild(opt);
            });
            statusSelect.addEventListener('change', function() {
                state.status = statusSelect.value;
                state.page = 1;
                loadData();
            });
            controls.appendChild(statusSelect);

            const searchInput = document.createElement('input');
            searchInput.type = 'search';
            searchInput.className = 'bjlg-network-dashboard__filter';
            searchInput.placeholder = __('Rechercher un site…', 'backup-jlg');
            searchInput.value = state.search;
            searchInput.addEventListener('input', function() {
                const value = searchInput.value;
                if (searchTimer) {
                    window.clearTimeout(searchTimer);
                }
                searchTimer = window.setTimeout(function() {
                    state.search = value;
                    state.page = 1;
                    loadData();
                }, 300);
            });
            controls.appendChild(searchInput);

            if (state.blogId) {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'bjlg-network-dashboard__filter-chip';
                chip.textContent = sprintf(__('Filtré sur le site #%s', 'backup-jlg'), state.blogId);
                chip.addEventListener('click', function() {
                    state.blogId = null;
                    loadData();
                });
                controls.appendChild(chip);
            }

            const refreshButton = document.createElement('button');
            refreshButton.type = 'button';
            refreshButton.className = 'button';
            refreshButton.textContent = __('Actualiser', 'backup-jlg');
            refreshButton.addEventListener('click', function() {
                loadData();
            });
            controls.appendChild(refreshButton);

            container.appendChild(controls);
        };

        const render = function() {
            if (!panel) {
                return;
            }

            panel.innerHTML = '';

            if (!networkEnabled) {
                renderMessage(panel, __('Le mode réseau est désactivé pour Backup JLG.', 'backup-jlg'), 'warning');
                return;
            }

            const dashboard = document.createElement('div');
            dashboard.className = 'bjlg-network-dashboard';

            renderFilters(dashboard);
            renderSummary(dashboard, sitesPayload);
            renderSitesTable(dashboard, sitesPayload);
            renderHistory(dashboard, historyPayload);

            if (isLoading) {
                const loadingNotice = document.createElement('p');
                loadingNotice.className = 'bjlg-network-dashboard__loading';
                loadingNotice.textContent = __('Chargement des données réseau…', 'backup-jlg');
                dashboard.appendChild(loadingNotice);
            } else if (lastError) {
                renderMessage(dashboard, lastError, 'error');
            }

            panel.appendChild(dashboard);
        };

        const loadData = function() {
            if (!networkEnabled || !endpoints.sites || !endpoints.history) {
                render();
                return;
            }

            isLoading = true;
            render();

            const siteQuery = {
                period: state.period,
                status: state.status,
                search: state.search,
                page: state.page,
                per_page: state.perPage,
            };

            if (state.blogId) {
                siteQuery.blog_id = state.blogId;
            }

            const historyQuery = {
                period: state.period,
                limit: 25,
            };

            if (state.blogId) {
                historyQuery.blog_id = state.blogId;
            }

            const sitesUrl = appendQuery(endpoints.sites, siteQuery);
            const historyUrl = appendQuery(endpoints.history, historyQuery);

            Promise.all([
                fetchJSON(sitesUrl),
                fetchJSON(historyUrl),
            ]).then(function(responses) {
                sitesPayload = responses[0];
                historyPayload = responses[1];
                lastError = '';
            }).catch(function(error) {
                lastError = error && error.message ? error.message : __('Impossible de charger les données réseau.', 'backup-jlg');
            }).finally(function() {
                isLoading = false;
                render();
            });
        };

        if (networkEnabled) {
            hideFallback();
            render();
            loadData();
        } else {
            render();
        }
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

    $(window).on('resize', function() {
        if (window.innerWidth > 960) {
            setSidebarExpanded(false);
        }
    });
});

