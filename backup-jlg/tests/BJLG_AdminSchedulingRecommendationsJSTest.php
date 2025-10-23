<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BJLG_AdminSchedulingRecommendationsJSTest extends TestCase
{
    public function test_summarize_forecast_generates_badges_and_tips(): void
    {
        $script = <<<'JAVASCRIPT'
const fs = require('fs');
const vm = require('vm');

function createJQueryStub() {
    let stub;
    const dataStore = new Map();
    const attrStore = new Map();
    const handler = {
        get(target, prop) {
            if (prop === 'length') {
                return 0;
            }
            if (prop === Symbol.iterator) {
                return function* iterator() {};
            }
            if (prop === 'toArray') {
                return function toArray() { return []; };
            }
            const chainable = new Set([
                'append', 'appendTo', 'attr', 'prop', 'removeAttr', 'addClass', 'removeClass', 'toggleClass',
                'data', 'empty', 'hide', 'show', 'text', 'val', 'html', 'find', 'closest', 'on', 'off', 'trigger', 'each'
            ]);
            if (chainable.has(prop)) {
                if (prop === 'data') {
                    return function data(key, value) {
                        if (arguments.length === 2) {
                            dataStore.set(key, value);
                            return stub;
                        }
                        return dataStore.get(key);
                    };
                }
                if (prop === 'attr') {
                    return function attr(key, value) {
                        if (arguments.length === 2) {
                            attrStore.set(key, value);
                            return stub;
                        }
                        return attrStore.get(key);
                    };
                }
                if (prop === 'removeAttr') {
                    return function removeAttr(key) {
                        attrStore.delete(key);
                        return stub;
                    };
                }
                if (prop === 'text') {
                    return function text(value) {
                        if (typeof value === 'undefined') {
                            return attrStore.get('text') || '';
                        }
                        attrStore.set('text', value);
                        return stub;
                    };
                }
                if (prop === 'val') {
                    return function val(value) {
                        if (typeof value === 'undefined') {
                            return dataStore.get('val');
                        }
                        dataStore.set('val', value);
                        return stub;
                    };
                }
                return function chain() { return stub; };
            }
            if (prop === 'length') {
                return 0;
            }
            if (prop === 'css') {
                return function css() { return stub; };
            }
            if (prop === 'eq') {
                return function eq() { return stub; };
            }
            return stub;
        },
        apply(target, thisArg, args) {
            if (args && args.length && typeof args[0] === 'function') {
                try {
                    args[0](stub);
                } catch (error) {
                    // ignore
                }
            }
            return stub;
        }
    };
    stub = new Proxy(function jqueryProxy() {}, handler);
    function jQuery(arg) {
        if (typeof arg === 'function') {
            try {
                arg(stub);
            } catch (error) {
                // ignore
            }
            return stub;
        }
        return stub;
    }
    jQuery.ajax = function ajax() {
        return {
            done() { return this; },
            fail() { return this; },
            always() { return this; }
        };
    };
    jQuery.fn = {};
    jQuery.extend = function extend() { return stub; };
    return jQuery;
}

const jquery = createJQueryStub();
const sandbox = {
    window: {},
    document: {
        createDocumentFragment() {
            return {
                appendChild() {}
            };
        }
    },
    console: console,
    setTimeout(fn) { if (typeof fn === 'function') { fn(); } return 0; },
    clearTimeout() {},
    jQuery: jquery,
    $: jquery,
    bjlg_ajax: { ajax_url: '', nonce: '' },
};

sandbox.window = sandbox;
sandbox.window.wp = { i18n: { __: (text) => text } };
sandbox.MINUTE_IN_SECONDS = 60;
sandbox.HOUR_IN_SECONDS = 3600;
sandbox.DAY_IN_SECONDS = 86400;

vm.createContext(sandbox);
const code = fs.readFileSync(process.argv[2], 'utf8');
vm.runInContext(code, sandbox);
const utils = sandbox.window.__BJLG_SCHEDULING_TEST__;
if (!utils || typeof utils.summarizeForecast !== 'function') {
    throw new Error('Test utilities not exposed');
}
const summary = utils.summarizeForecast({
    estimated_load: {
        load_level: 'high',
        peak_concurrent: 3,
        total_seconds: 10800,
        density_percent: 82,
        total_hours: 3
    },
    conflicts: [{ label: 'Alpha vs Beta' }],
    ideal_windows: [{ label: '04:00 - 05:00', duration_label: '60 min' }],
    advice: [{ severity: 'warning', message: 'Charge élevée détectée.' }],
    suggested_adjustments: { label: 'Archive complète nocturne' }
});
process.stdout.write(JSON.stringify(summary));
JAVASCRIPT;

        $scriptPath = tempnam(sys_get_temp_dir(), 'bjlg_js_test_');
        if ($scriptPath === false) {
            $this->fail('Unable to create temporary script file.');
        }

        file_put_contents($scriptPath, $script);

        $jsFile = realpath(__DIR__ . '/../assets/js/admin-scheduling.js');
        $command = sprintf(
            'node %s %s',
            escapeshellarg($scriptPath),
            escapeshellarg((string) $jsFile)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        @unlink($scriptPath);

        $this->assertSame(0, $exitCode, 'Node execution failed');

        $result = json_decode(implode("\n", $output), true);
        $this->assertIsArray($result, 'Expected JSON output from Node test');
        $this->assertNotEmpty($result['badges']);
        $this->assertSame('high', $result['loadLevel']);
        $this->assertNotEmpty($result['tips']);
        $this->assertNotEmpty($result['scenario']);
    }
}
