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

jQuery(function($) {
    'use strict';

    const ajaxData = (typeof window.bjlg_ajax === 'object' && window.bjlg_ajax) ? window.bjlg_ajax : {};
    const sectionModulesMap = ajaxData.section_modules || ajaxData.tab_modules || {};
    const loadedModules = new Set();

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

        if (currentSection === sectionKey && !fromTab) {
            return;
        }

        currentSection = sectionKey;

        const panels = document.querySelectorAll('.bjlg-shell-section');
        panels.forEach(function(panel) {
            const matches = panel.getAttribute('data-section') === sectionKey;
            if (matches) {
                panel.removeAttribute('hidden');
                panel.setAttribute('aria-hidden', 'false');
                panel.setAttribute('tabindex', '0');
            } else {
                panel.setAttribute('hidden', 'hidden');
                panel.setAttribute('aria-hidden', 'true');
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
        }), navContainer);

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
                    var requestBody;
                    var URLSearchParamsCtor = typeof window.URLSearchParams === 'function' ? window.URLSearchParams : null;
                    var FormDataCtor = typeof window.FormData === 'function' ? window.FormData : null;

                    if (URLSearchParamsCtor) {
                        requestBody = new URLSearchParamsCtor();
                        requestBody.append('action', 'bjlg_update_onboarding_progress');
                        requestBody.append('nonce', ajaxData.onboarding_nonce || '');
                        payload.forEach(function(stepId) {
                            requestBody.append('completed[]', stepId);
                        });
                    } else if (FormDataCtor) {
                        requestBody = new FormDataCtor();
                        requestBody.append('action', 'bjlg_update_onboarding_progress');
                        requestBody.append('nonce', ajaxData.onboarding_nonce || '');
                        payload.forEach(function(stepId) {
                            requestBody.append('completed[]', stepId);
                        });
                    } else {
                        requestBody = {
                            action: 'bjlg_update_onboarding_progress',
                            nonce: ajaxData.onboarding_nonce || '',
                            completed: payload,
                        };
                    }

                    if (
                        apiFetch &&
                        typeof apiFetch === 'function' &&
                        (
                            (URLSearchParamsCtor && requestBody instanceof URLSearchParamsCtor) ||
                            (FormDataCtor && requestBody instanceof FormDataCtor)
                        )
                    ) {
                        apiFetch({
                            url: ajaxData.ajax_url,
                            method: 'POST',
                            body: requestBody,
                        }).catch(function() {});
                        return;
                    }

                    var fetchFn = typeof window.fetch === 'function' ? window.fetch : null;
                    if (
                        fetchFn &&
                        (
                            (URLSearchParamsCtor && requestBody instanceof URLSearchParamsCtor) ||
                            (FormDataCtor && requestBody instanceof FormDataCtor)
                        )
                    ) {
                        fetchFn(ajaxData.ajax_url, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: requestBody,
                        }).catch(function() {});
                        return;
                    }

                    if (window.jQuery && typeof window.jQuery.post === 'function') {
                        window.jQuery.post(ajaxData.ajax_url, requestBody);
                    }
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

