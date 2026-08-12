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
 * JavaScript for the apps interface.
 *
 * @module     local_la/apps
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/ajax',
    'core/modal_save_cancel',
    'core/modal_events',
    'core/notification'
], function($, Ajax, ModalSaveCancel, ModalEvents, Notification) {
    var WIDGET_SELECTOR = '[data-action="toggle-app-widget"]';
    var WIDGET_BODY_SELECTOR = '[data-region="app-widget-body"]';
    var DELETE_WIDGET_SELECTOR = '[data-action="delete-widget"]';
    var MAXIMIZE_TOGGLE_SELECTOR = '[data-action="toggle-widget-maximize"]';
    var MAXIMIZE_LABEL_SELECTOR = '[data-region="widget-maximize-label"]';
    var REFRESH_TOGGLE_SELECTOR = '[data-action="toggle-widget-refresh"]';
    var REFRESH_LABEL_SELECTOR = '[data-region="widget-refresh-label"]';
    var IGNORE_SELECTOR = 'a, button, input, select, textarea, [role="menuitem"]';
    var REFRESH_INTERVAL = 10000;
    var VALUE_CLASSES = 'la-app-value-lg la-app-value-xl';
    var refreshtimer = null;
    var initialized = false;
    var strings = {
        start: '',
        stop: '',
        maximize: '',
        minimize: '',
        'delete': '',
        deletewidget: '',
        deletewidgetconfirm: ''
    };

    var refreshWidget = function(card) {
        var appid = Number(card.data('appId') || 0);
        var widgetkey = String(card.data('widgetKey') || '');

        if (!appid || !widgetkey || card.data('refreshing')) {
            return false;
        }

        card.data('refreshing', true);

        return Ajax.call([{
            methodname: 'local_la_get_app_widget',
            args: {
                appid: appid,
                widgetkey: widgetkey
            }
        }])[0].then(function(response) {
            card.find(WIDGET_BODY_SELECTOR).html(response.html || '');
            card.removeClass(VALUE_CLASSES);
            if (response.valueclass) {
                card.addClass(response.valueclass);
            }
            return response;
        }).catch(Notification.exception).then(function() {
            card.data('refreshing', false);
            return true;
        });
    };

    var refreshActiveWidgets = function() {
        $(WIDGET_SELECTOR + '.is-auto-refreshing').each(function() {
            refreshWidget($(this));
        });
    };

    var saveWidgetState = function(card, state, enabled) {
        var appid = Number(card.data('appId') || 0);
        var widgetkey = String(card.data('widgetKey') || '');

        if (!appid || !widgetkey) {
            return false;
        }

        return Ajax.call([{
            methodname: 'local_la_update_app_widget_state',
            args: {
                appid: appid,
                widgetkey: widgetkey,
                state: state,
                enabled: enabled
            }
        }])[0].catch(Notification.exception);
    };

    var setAutoRefresh = function(card, enabled) {
        card.toggleClass('is-auto-refreshing', enabled);
        card.find(REFRESH_TOGGLE_SELECTOR).attr('aria-pressed', enabled ? 'true' : 'false');
        card.find(REFRESH_LABEL_SELECTOR).text(enabled ? strings.stop : strings.start);
        saveWidgetState(card, 'autorefresh', enabled);

        if (enabled) {
            refreshWidget(card);
        }
    };

    var setMaximized = function(card, enabled) {
        card.toggleClass('is-maximized', enabled);
        card.find(MAXIMIZE_TOGGLE_SELECTOR).attr('aria-pressed', enabled ? 'true' : 'false');
        card.find(MAXIMIZE_LABEL_SELECTOR).text(enabled ? strings.minimize : strings.maximize);
        saveWidgetState(card, 'fullwidth', enabled);
    };

    var removeWidget = function(card, appid, widgetkey) {
        return Ajax.call([{
            methodname: 'local_la_delete_app_widget',
            args: {
                appid: appid,
                widgetkey: widgetkey
            }
        }])[0].then(function() {
            card.remove();
            return true;
        }).catch(Notification.exception);
    };

    var deleteWidget = function(card) {
        var appid = Number(card.data('appId') || 0);
        var widgetkey = String(card.data('widgetKey') || '');

        if (!appid || !widgetkey) {
            return;
        }

        ModalSaveCancel.create({
            title: strings.deletewidget,
            body: strings.deletewidgetconfirm
        }).then(function(modal) {
            modal.setSaveButtonText(strings.delete);

            modal.getRoot().on(ModalEvents.save, function() {
                removeWidget(card, appid, widgetkey);
            });

            modal.show();

            return modal;
        }).catch(Notification.exception);
    };

    var toggleWidget = function(card) {
        var active = !card.hasClass('is-active');

        card.toggleClass('is-active', active);
        card.attr('aria-expanded', active ? 'true' : 'false');
        saveWidgetState(card, 'active', active);

        if (active) {
            refreshWidget(card);
        }
    };

    var bindWidgets = function() {
        $(document).on('click', WIDGET_SELECTOR, function(event) {
            if ($(event.target).closest(IGNORE_SELECTOR).length) {
                return;
            }

            toggleWidget($(this));
        });

        $(document).on('keydown', WIDGET_SELECTOR, function(event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            event.preventDefault();
            toggleWidget($(this));
        });

        $(document).on('click', REFRESH_TOGGLE_SELECTOR, function(event) {
            event.preventDefault();
            var card = $(this).closest(WIDGET_SELECTOR);

            setAutoRefresh(card, !card.hasClass('is-auto-refreshing'));
        });

        $(document).on('click', MAXIMIZE_TOGGLE_SELECTOR, function(event) {
            event.preventDefault();
            var card = $(this).closest(WIDGET_SELECTOR);

            setMaximized(card, !card.hasClass('is-maximized'));
        });

        $(document).on('click', DELETE_WIDGET_SELECTOR, function(event) {
            event.preventDefault();
            var card = $(this).closest(WIDGET_SELECTOR);

            deleteWidget(card);
        });
    };

    return {
        init: function(labels) {
            if (initialized) {
                return;
            }

            initialized = true;

            if (labels) {
                strings.start = labels.start || strings.start;
                strings.stop = labels.stop || strings.stop;
                strings.maximize = labels.maximize || strings.maximize;
                strings.minimize = labels.minimize || strings.minimize;
                strings.delete = labels.delete || strings.delete;
                strings.deletewidget = labels.deletewidget || strings.deletewidget;
                strings.deletewidgetconfirm = labels.deletewidgetconfirm || strings.deletewidgetconfirm;
            }

            bindWidgets();
            if (!refreshtimer) {
                refreshtimer = window.setInterval(refreshActiveWidgets, REFRESH_INTERVAL);
            }
        }
    };
});
