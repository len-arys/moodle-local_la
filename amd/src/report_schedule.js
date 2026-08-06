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
 * JavaScript for the report schedule interface.
 *
 * @module     local_la/report_schedule
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/ajax',
    'local_la/modal_save_cancel',
    'local_la/modal_delete_cancel',
    'core/modal_events',
    'core/notification',
    'core/str',
    'core/toast',
    'editor_tiny/editor'
], function($, Ajax, ModalSaveCancel, ModalDeleteCancel, ModalEvents, Notification, Str, Toast, TinyEditor) {
    var OPEN_SELECTOR = '[data-action="open-schedule-modal"]';
    var TOGGLE_SELECTOR = '[data-action="toggle-schedule"]';
    var EDIT_SELECTOR = '[data-action="schedule-edit"]';
    var SEND_SELECTOR = '[data-action="schedule-send"]';
    var DELETE_SELECTOR = '[data-action="schedule-delete"]';
    var NOTIFICATION_KEY = 'local_la_schedule_notification';

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

    var reloadWithNotification = function(message) {
        window.sessionStorage.setItem(NOTIFICATION_KEY, message);
        window.location.reload();
    };

    var showReloadNotification = function() {
        var message = window.sessionStorage.getItem(NOTIFICATION_KEY);

        if (message) {
            window.sessionStorage.removeItem(NOTIFICATION_KEY);
            Toast.add(message);
        }
    };

    var getFormData = function(form) {
        var date = form.find('[name^="timescheduled["]');
        var editor = TinyEditor.getInstanceForElementId('la-schedule-body');

        if (editor) {
            editor.save();
        }

        return {
            reportid: Number(form.data('reportId') || 0),
            scheduleid: Number(form.data('scheduleId') || 0),
            name: String(form.find('[name="name"]').val() || ''),
            format: String(form.find('[name="format"]').val() || 'csv'),
            timestart: new Date(
                Number(date.filter('[name="timescheduled[year]"]').val() || 0),
                Number(date.filter('[name="timescheduled[month]"]').val() || 1) - 1,
                Number(date.filter('[name="timescheduled[day]"]').val() || 1),
                Number(date.filter('[name="timescheduled[hour]"]').val() || 0),
                Number(date.filter('[name="timescheduled[minute]"]').val() || 0)
            ).getTime() / 1000,
            recurrence: String(form.find('[name="recurrence"]').val() || 'none'),
            subject: String(form.find('[name="subject"]').val() || ''),
            body: String(form.find('[name="body"]').val() || ''),
            audiences: form.find('[name="audiences[]"]:checked').map(function() {
                return String($(this).val() || '');
            }).get(),
            emptyreport: String(form.find('[name="emptyreport"]').val() || 'send')
        };
    };

    var openScheduleModal = function(reportid, scheduleid) {
        Str.get_strings([
            {key: scheduleid ? 'editscheduledetails' : 'newschedule', component: 'local_la'},
            {key: 'loading', component: 'local_la'},
            {key: 'save', component: 'core'}
        ]).then(function(strings) {
            return ModalSaveCancel.create().then(function(modal) {
                modal.setSaveButtonText(strings[2]);
                setLoading(modal, strings[0], strings[1]);
                modal.show();

                Ajax.call([{
                    methodname: 'local_la_schedule_modal',
                    args: {
                        reportid: reportid,
                        scheduleid: scheduleid || 0
                    }
                }])[0].then(function(response) {
                    modal.setTitle(response.title || '');
                    modal.setBody(response.html || '');
                    modal.getRoot().find('.modal-dialog').addClass('modal-lg');
                    showFooter(modal);

                    var body = document.getElementById('la-schedule-body');
                    if (body) {
                        TinyEditor.setupForTarget(body, JSON.parse(response.editoroptions || '{}')).catch(Notification.exception);
                    }
                }).catch(Notification.exception);

                modal.getRoot().on(ModalEvents.save, function(event) {
                    event.preventDefault();
                    Ajax.call([{
                        methodname: 'local_la_save_schedule',
                        args: getFormData(modal.getRoot().find('.la-schedule-form').first())
                    }])[0].then(function() {
                        window.location.reload();
                    }).catch(Notification.exception);
                });

                modal.getRoot().on(ModalEvents.hidden, function() {
                    var editor = TinyEditor.getInstanceForElementId('la-schedule-body');
                    if (editor) {
                        editor.remove();
                    }
                });

                return modal;
            });
        }).catch(Notification.exception);
    };

    var confirmScheduleAction = function(options) {
        options.modal.create({
            title: options.title,
            body: escapeHtml(options.message)
        }).then(function(modal) {
            if (options.confirmLabel && modal.setSaveButtonText) {
                modal.setSaveButtonText(options.confirmLabel);
            }
            if (options.confirmLabel && modal.setDeleteButtonText) {
                modal.setDeleteButtonText(options.confirmLabel);
            }

            modal.getRoot().on(options.event, function(event) {
                event.preventDefault();
                Ajax.call([{
                    methodname: options.method,
                    args: {scheduleid: options.scheduleid}
                }])[0].then(function() {
                    reloadWithNotification(options.successMessage);
                }).catch(Notification.exception);
            });

            modal.show();
            return modal;
        }).catch(Notification.exception);
    };

    var getScheduleName = function(link) {
        return $.trim(link.closest('tr').find('[data-region="schedule-name"]').text()) ||
            String(link.data('scheduleName') || '');
    };

    return {
        init: function() {
            showReloadNotification();

            $(document).on('click', OPEN_SELECTOR, function(event) {
                event.preventDefault();
                openScheduleModal(Number($(this).data('reportId') || 0), 0);
            });

            $(document).on('change', TOGGLE_SELECTOR, function() {
                Ajax.call([{
                    methodname: 'local_la_toggle_schedule',
                    args: {
                        scheduleid: Number($(this).data('scheduleId') || 0),
                        status: $(this).is(':checked') ? 1 : 0
                    }
                }])[0].catch(Notification.exception);
            });

            $(document).on('click', EDIT_SELECTOR, function(event) {
                event.preventDefault();
                openScheduleModal(Number($(this).data('reportId') || 0), Number($(this).data('scheduleId') || 0));
            });

            $(document).on('click', SEND_SELECTOR, function(event) {
                event.preventDefault();

                var link = $(this);
                var name = getScheduleName(link);

                Str.get_strings([
                    {key: 'sendschedule', component: 'local_la'},
                    {key: 'confirm', component: 'core'},
                    {key: 'confirmschedulesend', component: 'local_la', param: name},
                    {key: 'schedulequeued', component: 'local_la'}
                ]).then(function(strings) {
                    confirmScheduleAction({
                        modal: ModalSaveCancel,
                        event: ModalEvents.save,
                        method: 'local_la_send_schedule',
                        scheduleid: Number(link.data('scheduleId') || 0),
                        title: strings[0],
                        confirmLabel: strings[1],
                        message: strings[2],
                        successMessage: strings[3]
                    });
                    return strings;
                }).catch(Notification.exception);
            });

            $(document).on('click', DELETE_SELECTOR, function(event) {
                event.preventDefault();

                var link = $(this);
                var name = getScheduleName(link);

                Str.get_strings([
                    {key: 'deleteschedule', component: 'local_la'},
                    {key: 'delete', component: 'core'},
                    {key: 'confirmscheduledelete', component: 'local_la', param: name},
                    {key: 'scheduledeleted', component: 'local_la'}
                ]).then(function(strings) {
                    confirmScheduleAction({
                        modal: ModalDeleteCancel,
                        event: ModalEvents.delete,
                        method: 'local_la_delete_schedule',
                        scheduleid: Number(link.data('scheduleId') || 0),
                        title: strings[0],
                        confirmLabel: strings[1],
                        message: strings[2],
                        successMessage: strings[3]
                    });
                    return strings;
                }).catch(Notification.exception);
            });
        }
    };
});
