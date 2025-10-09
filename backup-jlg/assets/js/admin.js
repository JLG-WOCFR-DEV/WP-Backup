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
    const tabModulesMap = ajaxData.tab_modules || {};
    const loadedModules = new Set();

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

    const loadModulesForTab = function(tabKey) {
        const modules = [];
        if (tabModulesMap[tabKey]) {
            tabModulesMap[tabKey].forEach(function(name) {
                modules.push(name);
            });
        }

        const $panel = $('.bjlg-tab-panel[data-tab="' + tabKey + '"]');
        if ($panel.length) {
            const attr = $panel.attr('data-bjlg-modules');
            if (attr) {
                modules.push.apply(modules, parseModuleAttribute(attr));
            }
        }

        requestModules(modules);
    };

    const $wrap = $('.bjlg-wrap');
    if ($wrap.length) {
        const $tabs = $wrap.find('.nav-tab-wrapper .nav-tab');
        const $panels = $wrap.find('.bjlg-tab-panel');
        const $tabContainer = $wrap.find('.bjlg-tab-content');

        const activateTab = function(tabKey, updateUrl) {
            if (!tabKey) {
                return;
            }

            $tabs.each(function() {
                const $tab = $(this);
                const isActive = $tab.data('tab') === tabKey;
                $tab.toggleClass('nav-tab-active', isActive)
                    .attr('aria-selected', isActive ? 'true' : 'false')
                    .attr('tabindex', isActive ? '0' : '-1');

                if (isActive) {
                    $tab.attr('aria-current', 'page');
                } else {
                    $tab.removeAttr('aria-current');
                }
            });

            $panels.each(function() {
                const $panel = $(this);
                const matches = $panel.data('tab') === tabKey;
                if (matches) {
                    $panel.removeAttr('hidden')
                        .attr('aria-hidden', 'false');
                } else {
                    $panel.attr('hidden', 'hidden')
                        .attr('aria-hidden', 'true');
                }
            });

            if ($tabContainer.length) {
                $tabContainer.attr('data-active-tab', tabKey);
            }

            const $activePanel = $panels.filter(function() {
                return $(this).data('tab') === tabKey;
            });

            if ($activePanel.length) {
                const panelElement = $activePanel.get(0);
                if (panelElement && typeof panelElement.focus === 'function') {
                    try {
                        panelElement.focus({ preventScroll: true });
                    } catch (error) {
                        panelElement.focus();
                    }
                }
            }

            loadModulesForTab(tabKey);

            if (updateUrl && window.history && typeof window.history.replaceState === 'function') {
                try {
                    const currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('tab', tabKey);
                    window.history.replaceState({}, '', currentUrl.toString());
                } catch (error) {
                    const baseUrl = window.location.href.split('#')[0];
                    const hasQuery = baseUrl.indexOf('?') !== -1;
                    let newUrl;

                    if (hasQuery) {
                        if (baseUrl.indexOf('tab=') !== -1) {
                            newUrl = baseUrl.replace(/([?&])tab=[^&#]*/, '$1tab=' + encodeURIComponent(tabKey));
                        } else {
                            newUrl = baseUrl + '&tab=' + encodeURIComponent(tabKey);
                        }
                    } else {
                        newUrl = baseUrl + '?tab=' + encodeURIComponent(tabKey);
                    }

                    window.history.replaceState({}, '', newUrl);
                }
            }
        };

        $tabs.on('click', function(event) {
            const tabKey = $(this).data('tab');
            if (!tabKey) {
                return;
            }
            event.preventDefault();
            activateTab(tabKey, true);
        });

        const initialTab = $tabContainer.attr('data-active-tab') || ($tabs.filter('.nav-tab-active').data('tab')) || $tabs.first().data('tab');
        if (initialTab) {
            activateTab(initialTab, false);
        }
    }

    if ($('.bjlg-dashboard-overview').length) {
        requestModules(['dashboard']);
    }

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
});
