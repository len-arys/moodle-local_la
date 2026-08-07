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
 * JavaScript for the report details interface.
 *
 * @module     local_la/report_details
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/ajax',
    'local_la/modal_save_cancel',
    'core/modal_events',
    'core/notification',
    'core/str',
    'editor_tiny/editor'
], function($, Ajax, ModalSaveCancel, ModalEvents, Notification, Str, TinyEditor) {
    var SELECTOR = '[data-action="edit-report-details"]';
    var EDITOR_ID = 'la-report-details-description';

    var escapeHtml = function(text) {
        return $('<div>').text(text).html();
    };

    var setLoading = function(modal, title, loading) {
        modal.setTitle(title);
        modal.setBody('<div class="text-muted">' + escapeHtml(loading) + '</div>');
        modal.getRoot().find('.modal-footer').addClass('d-none');
    };

    var showFooter = function(modal) {
        modal.getRoot().find('.modal-footer').removeClass('d-none');
    };

    var openModal = function(reportid) {
        Str.get_strings([
            {key: 'editreportdetails', component: 'local_la'},
            {key: 'loading', component: 'local_la'},
            {key: 'save', component: 'core'}
        ]).then(function(strings) {
            return ModalSaveCancel.create().then(function(modal) {
                modal.setSaveButtonText(strings[2]);
                setLoading(modal, strings[0], strings[1]);
                modal.show();

                Ajax.call([{
                    methodname: 'local_la_report_details_modal',
                    args: {reportid: reportid}
                }])[0].then(function(response) {
                    modal.setTitle(response.title || '');
                    modal.setBody(response.html || '');
                    modal.getRoot().find('.modal-dialog').addClass('modal-lg');
                    showFooter(modal);

                    var description = document.getElementById(EDITOR_ID);
                    if (description) {
                        TinyEditor.setupForTarget(description, JSON.parse(response.editoroptions || '{}')).catch(Notification.exception);
                    }
                }).catch(Notification.exception);

                modal.getRoot().on(ModalEvents.save, function(event) {
                    var form = modal.getRoot().find('.la-report-details-form').first();
                    var editor = TinyEditor.getInstanceForElementId(EDITOR_ID);

                    event.preventDefault();
                    if (editor) {
                        editor.save();
                    }

                    Ajax.call([{
                        methodname: 'local_la_save_report_details',
                        args: {
                            reportid: Number(form.data('reportId') || 0),
                            name: String(form.find('[name="name"]').val() || ''),
                            description: String(form.find('[name="description"]').val() || ''),
                            tags: String(form.find('[name="tags"]').val() || '')
                        }
                    }])[0].then(function() {
                        window.location.reload();
                    }).catch(Notification.exception);
                });

                modal.getRoot().on(ModalEvents.hidden, function() {
                    var editor = TinyEditor.getInstanceForElementId(EDITOR_ID);
                    if (editor) {
                        editor.remove();
                    }
                });

                return modal;
            });
        }).catch(Notification.exception);
    };

    return {
        init: function() {
            $(document).on('click', SELECTOR, function(event) {
                var reportid = Number($(this).data('reportid') || 0);

                event.preventDefault();
                if (reportid) {
                    openModal(reportid);
                }
            });
        }
    };
});
