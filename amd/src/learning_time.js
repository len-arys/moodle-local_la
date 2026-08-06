// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * JavaScript for the learning time interface.
 *
 * @module     local_la/learning_time
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax'], function(Ajax) {
    var LEADER_KEY = 'local_la_learning_time_leader';
    var TAB_KEY = 'local_la_learning_time_tab';
    var TICK_INTERVAL_MS = 5000;

    var getTabId = function() {
        var tabId;

        try {
            tabId = window.sessionStorage.getItem(TAB_KEY);
            if (!tabId) {
                tabId = Math.random().toString(36).slice(2) + String(Date.now());
                window.sessionStorage.setItem(TAB_KEY, tabId);
            }
        } catch (error) {
            tabId = Math.random().toString(36).slice(2) + String(Date.now());
        }

        return tabId;
    };

    var createTracker = function(config) {
        var tracker = {
            page: config.page || {},
            intervalMs: Math.max(15000, Number(config.interval || 30) * 1000),
            idleMs: Math.max(30000, Number(config.idletimeout || 90) * 1000),
            debug: !!config.debug,
            tabId: getTabId(),
            lastActivityAt: Date.now(),
            lastTickAt: Date.now(),
            accumulatedMs: 0,
            sentVisit: false,
            sending: false,
            focused: document.hasFocus(),
            visible: !document.hidden
        };

        tracker.log = function(message, payload) {
            if (!tracker.debug || !window.console || !window.console.log) {
                return;
            }

            if (typeof payload === 'undefined') {
                window.console.log('[local_la learning_time] ' + message);
                return;
            }

            window.console.log('[local_la learning_time] ' + message, payload);
        };

        tracker.isActive = function() {
            return tracker.visible &&
                tracker.focused &&
                (Date.now() - tracker.lastActivityAt) <= tracker.idleMs;
        };

        tracker.getLeader = function() {
            try {
                return JSON.parse(window.localStorage.getItem(LEADER_KEY) || '{}');
            } catch (error) {
                return {};
            }
        };

        tracker.setLeader = function(expiresAt) {
            try {
                window.localStorage.setItem(LEADER_KEY, JSON.stringify({
                    id: tracker.tabId,
                    expires: expiresAt
                }));
            } catch (error) {
                return;
            }
        };

        tracker.isLeader = function() {
            var leader = tracker.getLeader();

            return leader.id === tracker.tabId && Number(leader.expires || 0) > Date.now();
        };

        tracker.claimLeadership = function() {
            var expiresAt = Date.now() + tracker.intervalMs;
            var leader = tracker.getLeader();

            if (!tracker.visible || !tracker.focused) {
                tracker.log('skip leadership claim because tab is not visible/focused', {
                    visible: tracker.visible,
                    focused: tracker.focused
                });
                return tracker.isLeader();
            }

            if (!leader.id || Number(leader.expires || 0) <= Date.now() || leader.id === tracker.tabId) {
                tracker.setLeader(expiresAt);
                tracker.log('leadership claimed', {
                    tabId: tracker.tabId,
                    expiresAt: expiresAt
                });
            }

            return tracker.isLeader();
        };

        tracker.markActivity = function() {
            tracker.lastActivityAt = Date.now();
        };

        tracker.captureElapsed = function() {
            var now = Date.now();
            var delta = now - tracker.lastTickAt;

            tracker.lastTickAt = now;
            tracker.claimLeadership();

            if (delta < 0 || delta > tracker.intervalMs * 2 || !tracker.isLeader() || !tracker.isActive()) {
                return;
            }

            tracker.accumulatedMs += delta;
            tracker.log('elapsed time counted', {
                delta: delta,
                accumulatedMs: tracker.accumulatedMs
            });
        };

        tracker.buildArgs = function(seconds) {
            return {
                token: String(config.token || ''),
                trackedseconds: Number(seconds || 0)
            };
        };

        tracker.buildRequestBody = function(seconds, isNewVisit) {
            return JSON.stringify([{
                index: 0,
                methodname: 'local_la_track_learning_time',
                args: tracker.buildArgs(seconds)
            }]);
        };

        tracker.sendExit = function(seconds, isNewVisit) {
            var body;
            var blob;

            if (!tracker.isLeader() || (!isNewVisit && seconds <= 0)) {
                tracker.log('skip exit send', {
                    isLeader: tracker.isLeader(),
                    seconds: seconds,
                    isNewVisit: isNewVisit
                });
                return false;
            }

            if (!config.ajaxurl) {
                tracker.log('skip exit send because ajaxurl is missing');
                return false;
            }

            body = tracker.buildRequestBody(seconds, isNewVisit);
            tracker.log('send exit heartbeat', {
                seconds: seconds,
                isNewVisit: isNewVisit
            });

            if (navigator.sendBeacon) {
                blob = new Blob([body], {type: 'application/json'});

                if (navigator.sendBeacon(config.ajaxurl, blob)) {
                    if (isNewVisit) {
                        tracker.sentVisit = true;
                    }

                    return true;
                }
            }

            if (window.fetch) {
                window.fetch(config.ajaxurl, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (isNewVisit) {
                    tracker.sentVisit = true;
                }

                return true;
            }

            return false;
        };

        tracker.send = function(seconds, isNewVisit) {
            if (tracker.sending || (!isNewVisit && seconds <= 0)) {
                tracker.log('skip send', {
                    sending: tracker.sending,
                    seconds: seconds,
                    isNewVisit: isNewVisit
                });
                return;
            }

            tracker.sending = true;
            tracker.log('send heartbeat', tracker.buildArgs(seconds));

            Ajax.call([{
                methodname: 'local_la_track_learning_time',
                args: tracker.buildArgs(seconds)
            }])[0].then(function() {
                if (isNewVisit) {
                    tracker.sentVisit = true;
                }
                tracker.log('heartbeat success', {
                    seconds: seconds,
                    isNewVisit: isNewVisit
                });
            }).catch(function() {
                tracker.log('heartbeat failed', {
                    seconds: seconds,
                    isNewVisit: isNewVisit
                });
                if (!isNewVisit && seconds > 0) {
                    tracker.accumulatedMs += seconds * 1000;
                }
            }).then(function() {
                tracker.sending = false;
            }, function() {
                tracker.sending = false;
            });
        };

        tracker.flush = function(forceVisit) {
            var seconds = Math.floor(tracker.accumulatedMs / 1000);
            var sendVisit = !!forceVisit && !tracker.sentVisit;

            if (!sendVisit && seconds <= 0) {
                tracker.log('skip flush because nothing accumulated', {
                    accumulatedMs: tracker.accumulatedMs,
                    sentVisit: tracker.sentVisit
                });
                return;
            }

            if (seconds > 0) {
                tracker.accumulatedMs -= seconds * 1000;
            }

            tracker.log('flush', {
                seconds: seconds,
                sendVisit: sendVisit,
                accumulatedMs: tracker.accumulatedMs
            });
            tracker.send(seconds, sendVisit);
        };

        tracker.flushExit = function(forceVisit) {
            var seconds = Math.floor(tracker.accumulatedMs / 1000);
            var sendVisit = !!forceVisit && !tracker.sentVisit;

            if (!sendVisit && seconds <= 0) {
                tracker.log('skip exit flush because nothing accumulated', {
                    accumulatedMs: tracker.accumulatedMs,
                    sentVisit: tracker.sentVisit
                });
                return;
            }

            if (seconds > 0) {
                tracker.accumulatedMs -= seconds * 1000;
            }

            if (!tracker.sendExit(seconds, sendVisit) && seconds > 0) {
                tracker.accumulatedMs += seconds * 1000;
            }
        };

        tracker.tick = function() {
            tracker.captureElapsed();

            if (!tracker.sentVisit) {
                tracker.flush(true);
                return;
            }

            if (tracker.accumulatedMs >= tracker.intervalMs) {
                tracker.flush(false);
            }
        };

        return tracker;
    };

    return {
        init: function(config) {
            var tracker;
            var events;

            if (!config || !config.token || !config.page || !config.page.name) {
                return;
            }

            tracker = createTracker(config);
            tracker.log('init', {
                page: tracker.page,
                intervalMs: tracker.intervalMs,
                idleMs: tracker.idleMs,
                tabId: tracker.tabId
            });
            events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart'];

            events.forEach(function(eventName) {
                window.addEventListener(eventName, tracker.markActivity, {passive: true});
            });

            window.addEventListener('focus', function() {
                tracker.focused = true;
                tracker.markActivity();
                tracker.claimLeadership();
                tracker.log('window focus');
            });

            window.addEventListener('blur', function() {
                tracker.captureElapsed();
                tracker.focused = false;
                tracker.log('window blur');
                tracker.flush(false);
            });

            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    tracker.captureElapsed();
                }
                tracker.visible = !document.hidden;
                tracker.log('visibility changed', {visible: tracker.visible});

                if (!tracker.visible) {
                    tracker.flushExit(false);
                    return;
                }

                tracker.markActivity();
                tracker.claimLeadership();
            });

            window.addEventListener('pagehide', function() {
                tracker.captureElapsed();
                tracker.log('pagehide');
                tracker.flushExit(false);
            });

            window.addEventListener('beforeunload', function() {
                tracker.captureElapsed();
                tracker.log('beforeunload');
                tracker.flushExit(false);
            });

            window.setInterval(tracker.tick, TICK_INTERVAL_MS);
            tracker.claimLeadership();
            tracker.flush(true);
        }
    };
});
