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
 * JavaScript for the calendar interface.
 *
 * @module     local_la/calendar
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/str'], function($, Ajax, Str) {
    var CALENDAR_SELECTOR = '[data-region="la-calendar"]';
    var CALENDAR_LOAD_SELECTOR = '[data-action="calendar-load"]';
    var strings = {
        unabletoloadcalendardata: 'Unable to load calendar data.'
    };

    Str.get_strings([
        {key: 'unabletoloadcalendardata', component: 'local_la'}
    ]).then(function(results) {
        strings.unabletoloadcalendardata = String(results[0] || strings.unabletoloadcalendardata);
    }).catch(function() {
        return;
    });

    var escapeHtml = function(value) {
        return $('<div>').text(String(value || '')).html();
    };

    var getErrorMessage = function(error, fallback) {
        return String(error && (error.message || error.error || error.exception) || fallback);
    };

    var getRootData = function(root) {
        return {
            metric: String(root.attr('data-metric') || 'timesec'),
            scope: String(root.attr('data-scope') || ''),
            userid: Number(root.attr('data-userid') || 0),
            courseid: Number(root.attr('data-courseid') || 0),
            activityid: Number(root.attr('data-activityid') || 0),
            view: String(root.attr('data-view') || 'month'),
            year: Number(root.attr('data-year') || 0),
            month: Number(root.attr('data-month') || 0),
            day: Number(root.attr('data-day') || 0),
            name: String(root.attr('data-name') || ''),
            instanceid: Number(root.attr('data-instanceid') || 0)
        };
    };

    var buildRequest = function(trigger) {
        var root = trigger.closest(CALENDAR_SELECTOR);
        var state = getRootData(root);

        state.metric = String(trigger.attr('data-metric') || state.metric || 'timesec');
        state.scope = String(trigger.attr('data-scope') || state.scope || '');
        state.userid = Number(trigger.attr('data-userid') || state.userid || 0);
        state.courseid = Number(trigger.attr('data-courseid') || state.courseid || 0);
        state.activityid = Number(trigger.attr('data-activityid') || state.activityid || 0);
        state.view = String(trigger.attr('data-view') || state.view || 'month');
        state.year = Number(trigger.attr('data-year') || state.year || 0);
        state.month = Number(trigger.attr('data-month') || state.month || 0);
        state.day = Number(trigger.attr('data-day') || state.day || 0);
        state.name = String(trigger.attr('data-name') || state.name || '');
        state.instanceid = Number(trigger.attr('data-instanceid') || state.instanceid || 0);

        return {
            root: root,
            request: {
                methodname: 'local_la_get_calendar',
                args: state
            }
        };
    };

    var renderError = function(root, error) {
        root.removeClass('is-loading');
        root.append(
            '<div class="alert alert-warning mt-3 mb-0">' +
            escapeHtml(getErrorMessage(error, strings.unabletoloadcalendardata)) +
            '</div>'
        );
    };

    var bindCalendarLoads = function() {
        $(document).on('click', CALENDAR_LOAD_SELECTOR, function(event) {
            var trigger = $(this);
            var payload;
            var modalBody;

            if (!trigger.closest(CALENDAR_SELECTOR).length) {
                return;
            }

            event.preventDefault();
            payload = buildRequest(trigger);
            modalBody = payload.root.closest('.modal-body');

            payload.root.addClass('is-loading');

            Ajax.call([payload.request])[0].then(function(response) {
                if (response.title) {
                    modalBody.closest('.modal').find('.modal-title').text(response.title);
                }

                modalBody.html(response.html || '');
            }).catch(function(error) {
                renderError(payload.root, error);
            });
        });
    };

    return {
        init: function() {
            bindCalendarLoads();
        }
    };
});
