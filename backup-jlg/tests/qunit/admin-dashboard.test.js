/* global QUnit, jQuery */
(function(QUnit, $) {
    'use strict';

    QUnit.module('Admin Dashboard Queue Actions', {
        beforeEach: function() {
            window.bjlg_ajax = window.bjlg_ajax || {};
            window.bjlg_ajax.ajax_url = '/ajax-endpoint';
            window.bjlg_ajax.nonce = 'nonce-value';

            const fixture = document.getElementById('qunit-fixture');
            fixture.innerHTML = ''
                + '<div class="bjlg-dashboard-overview" data-bjlg-dashboard="{&quot;queues&quot;:{}}">'
                + '  <div id="bjlg-dashboard-live-region" aria-live="polite"></div>'
                + '  <div class="bjlg-queues">'
                + '    <div class="bjlg-queue-card" data-queue="notifications">'
                + '      <ul data-role="entries"></ul>'
                + '    </div>'
                + '  </div>'
                + '</div>';
        },
        afterEach: function() {
            // Restore ajax if a test altered it.
            if (this._originalAjax) {
                $.ajax = this._originalAjax;
                this._originalAjax = null;
            }
            if (this._originalPrompt) {
                window.prompt = this._originalPrompt;
                this._originalPrompt = null;
            }
        }
    });

    QUnit.test('acknowledge button issues ajax request', function(assert) {
        assert.expect(3);
        const done = assert.async();

        const $overview = $('#qunit-fixture .bjlg-dashboard-overview');
        const $button = $('<button/>', {
            type: 'button',
            'data-queue-action': 'acknowledge-notification',
            'data-entry-id': 'entry-123'
        }).appendTo($overview);

        const originalAjax = $.ajax;
        this._originalAjax = originalAjax;

        $.ajax = function(options) {
            assert.strictEqual(options.data.action, 'bjlg_notification_ack', 'Expected action sent.');
            assert.strictEqual(options.data.entry_id, 'entry-123', 'Entry id propagated.');

            const deferred = {
                doneCallbacks: [],
                failCallbacks: [],
                alwaysCallbacks: [],
                done: function(callback) {
                    this.doneCallbacks.push(callback);
                    return this;
                },
                fail: function(callback) {
                    this.failCallbacks.push(callback);
                    return this;
                },
                always: function(callback) {
                    this.alwaysCallbacks.push(callback);
                    return this;
                }
            };

            setTimeout(function() {
                deferred.doneCallbacks.forEach(function(callback) {
                    callback({ success: true, data: {} });
                });
                deferred.alwaysCallbacks.forEach(function(callback) {
                    callback();
                });
            }, 0);

            return deferred;
        };

        $button.trigger('click');

        setTimeout(function() {
            assert.ok(true, 'Ajax callback executed.');
            done();
        }, 10);
    });

    QUnit.test('resolve button prompts for notes and posts them', function(assert) {
        assert.expect(4);
        const done = assert.async();

        const $overview = $('#qunit-fixture .bjlg-dashboard-overview');
        const $button = $('<button/>', {
            type: 'button',
            'data-queue-action': 'resolve-notification',
            'data-entry-id': 'entry-456'
        }).appendTo($overview);

        const originalAjax = $.ajax;
        const originalPrompt = window.prompt;
        this._originalAjax = originalAjax;
        this._originalPrompt = originalPrompt;

        window.prompt = function(message, defaultValue) {
            assert.ok(message.indexOf('notes') !== -1, 'Prompt message displayed.');
            assert.strictEqual(defaultValue, '', 'Prompt default empty.');
            return 'Manual resolution';
        };

        $.ajax = function(options) {
            assert.strictEqual(options.data.action, 'bjlg_notification_resolve', 'Resolve action sent.');
            assert.strictEqual(options.data.notes, 'Manual resolution', 'Notes forwarded.');

            const deferred = {
                doneCallbacks: [],
                failCallbacks: [],
                alwaysCallbacks: [],
                done: function(callback) {
                    this.doneCallbacks.push(callback);
                    return this;
                },
                fail: function(callback) {
                    this.failCallbacks.push(callback);
                    return this;
                },
                always: function(callback) {
                    this.alwaysCallbacks.push(callback);
                    return this;
                }
            };

            setTimeout(function() {
                deferred.doneCallbacks.forEach(function(callback) {
                    callback({ success: true, data: {} });
                });
                deferred.alwaysCallbacks.forEach(function(callback) {
                    callback();
                });
            }, 0);

            return deferred;
        };

        $button.trigger('click');

        setTimeout(function() {
            done();
        }, 10);
    });
})(QUnit, jQuery);
