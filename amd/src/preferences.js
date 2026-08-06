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
 * JavaScript for the preferences interface.
 *
 * @module     local_la/preferences
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/modal_factory', 'core/notification', 'core/str'], function($, Ajax, Modal, Notification, Str) {
    var TOGGLE_LICENSE_KEY = '[data-action="toggle-license-key"]';
    var LOG_DETAILS_ACTION = '[data-action="show-log-details"]';

    var bindLicenseKeyToggle = function() {
        $(document).on('click', TOGGLE_LICENSE_KEY, function(event) {
            event.preventDefault();

            var button = $(this);
            var item = button.closest('li');
            var shortValue = item.find('[data-region="license-key-short"]');
            var fullValue = item.find('[data-region="license-key-full"]');
            var showFull = fullValue.hasClass('d-none');

            shortValue.toggleClass('d-none', showFull);
            fullValue.toggleClass('d-none', !showFull);
            button.text(showFull ? button.data('label-hide') : button.data('label-show'));
        });
    };

    var bindLogDetails = function() {
        $(document).on('click', LOG_DETAILS_ACTION, function(event) {
            event.preventDefault();

            var logId = parseInt($(this).data('log-id'), 10) || 0;
            if (!logId) {
                return;
            }

            Str.get_string('loading', 'local_la').then(function(loading) {
                return Modal.create({
                    title: '',
                    body: '<div class="text-muted">' + loading + '</div>'
                }).then(function(modal) {
                    modal.getRoot().find('.modal-dialog').addClass('modal-xl');
                    modal.show();

                    Ajax.call([{
                        methodname: 'local_la_log_details',
                        args: {
                            logid: logId
                        }
                    }])[0].then(function(response) {
                        modal.setTitle(response.title || '');
                        modal.setBody(response.html || '');
                        modal.getRoot().find('.modal-footer').remove();
                    }).catch(Notification.exception);

                    return modal;
                });
            }).catch(Notification.exception);
        });
    };

    return {
        init: function() {
            bindLicenseKeyToggle();
            bindLogDetails();
        }
    };
});
