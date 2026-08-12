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
 * JavaScript for the library actions interface.
 *
 * @module     local_la/library_actions
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/ajax',
    'core/modal',
    'core/modal_save_cancel',
    'core/modal_events',
    'core/notification',
    'core/config',
    'core/str'
], function($, Ajax, Modal, ModalSaveCancel, ModalEvents, Notification, Config, Str) {
    var SELECTOR = '[data-action="delete-library-item"]';
    var TOGGLE_SELECTOR = '[data-action="toggle-library-report"]';
    var LIBRARY_ACTION_SELECTOR = '[data-action="library-report-action"]';
    var SQL_SELECTOR = '[data-action="show-sql-modal"]';
    var APP_PARAMS_SELECTOR = '[data-action="show-app-params-modal"]';
    var SQL_STATUS_SELECTOR = '[data-action="toggle-sql-status"]';
    var REPORT_SUMMARY_SELECTOR = '[data-action="toggle-report-summary"]';
    var REPORT_PREVIEW_SELECTOR = '[data-action="open-report-preview"]';
    var MARKETPLACE_INSTALL_SELECTOR = '[data-action="marketplace-install-review"]';
    var MARKETPLACE_UPDATE_SELECTOR = '[data-action="marketplace-update-review"]';
    var MARKETPLACE_APP_INSTALL_SELECTOR = '[data-action="marketplace-app-install-review"]';
    var MARKETPLACE_APP_UPDATE_SELECTOR = '[data-action="marketplace-app-update-review"]';
    var MARKETPLACE_SELECTOR = '[data-region="la-marketplace-items"]';
    var strings = {
        loading: '',
        unabletoloaddata: ''
    };

    Str.get_strings([
        {key: 'loading', component: 'local_la'},
        {key: 'unabletoloaddata', component: 'local_la'}
    ]).then(function(values) {
        strings.loading = values[0];
        strings.unabletoloaddata = values[1];
        return values;
    }).catch(Notification.exception);

    var escapeHtml = function(value) {
        return $('<div>').text(String(value || '')).html();
    };

    var getLoadingBody = function() {
        return '<div class="text-muted">' + escapeHtml(strings.loading) + '</div>';
    };

    var getUnableToLoadBody = function() {
        return '<div class="alert alert-danger mb-0">' + escapeHtml(strings.unabletoloaddata) + '</div>';
    };

    var runLibraryAction = function(action, reportid, reportkey) {
        return Ajax.call([{
            methodname: 'local_la_library',
            args: {
                action: action,
                reportid: reportid,
                reportkey: reportkey || ''
            }
        }])[0];
    };

    var reloadAfterLibraryAction = function(action, reportid, reportkey) {
        return runLibraryAction(action, reportid, reportkey).then(function() {
            window.location.reload();
            return true;
        }).catch(Notification.exception);
    };

    var bindLibraryActions = function() {
        $(document).on('click', LIBRARY_ACTION_SELECTOR, function(event) {
            event.preventDefault();

            var link = $(this);
            var action = String(link.data('libraryAction') || '');
            var reportid = Number(link.data('reportid') || 0);
            var reportkey = String(link.data('reportKey') || '');

            if (!action || (!reportid && !reportkey)) {
                return;
            }

            runLibraryAction(action, reportid, reportkey).then(function(response) {
                var newreportid = Number(response.reportid || 0);

                if (action === 'duplicate' && newreportid) {
                    window.location.href = Config.wwwroot + '/local/la/report.php?id=' + newreportid;
                    return response;
                }

                window.location.reload();
                return response;
            }).catch(Notification.exception);
        });
    };

    var bindDeleteConfirmation = function() {
        $(document).on('click', SELECTOR, function(event) {
            event.preventDefault();
            event.stopPropagation();

            var link = $(this);
            var title = link.data('title');
            var message = link.data('message');
            var reportid = Number(link.data('reportid') || 0);
            var action = String(link.data('libraryAction') || 'delete');
            var buttonlabel = String(link.data('confirmButtonLabel') || 'Delete');

            ModalSaveCancel.create({
                title: title,
                body: message
            }).then(function(modal) {
                modal.setSaveButtonText(buttonlabel);

                modal.getRoot().on(ModalEvents.save, function() {
                    reloadAfterLibraryAction(action, reportid, '');
                });

                modal.show();

                return modal;
            }).catch(Notification.exception);
        });
    };

    var disableReport = function(toggle, action, reportid, onFailure) {
        return runLibraryAction(action, reportid, '').then(function() {
            window.location.reload();
            return true;
        }).catch(function(error) {
            onFailure();
            toggle.prop('checked', true);
            Notification.exception(error);
            return false;
        });
    };

    var bindReportToggle = function() {
        $(document).on('change', TOGGLE_SELECTOR, function(event) {
            var toggle = $(this);
            var reportid = Number(toggle.data('reportid') || 0);
            var enableaction = String(toggle.data('enableAction') || 'addreport');
            var disableaction = String(toggle.data('disableAction') || 'delete');
            var title = String(toggle.data('title') || '');
            var message = String(toggle.data('message') || '');
            var buttonlabel = String(toggle.data('confirmButtonLabel') || 'Delete');

            if (!reportid) {
                return;
            }

            if (toggle.is(':checked')) {
                runLibraryAction(enableaction, reportid, '').then(function() {
                    window.location.reload();
                    return true;
                }).catch(function(error) {
                    toggle.prop('checked', false);
                    Notification.exception(error);
                    return false;
                });
                return;
            }

            event.preventDefault();

            ModalSaveCancel.create({
                title: title,
                body: message
            }).then(function(modal) {
                var confirmed = false;

                modal.setSaveButtonText(buttonlabel);

                modal.getRoot().on(ModalEvents.save, function() {
                    confirmed = true;
                    disableReport(toggle, disableaction, reportid, function() {
                        confirmed = false;
                    });
                });

                modal.getRoot().on(ModalEvents.hidden, function() {
                    if (!confirmed) {
                        toggle.prop('checked', true);
                    }
                });

                modal.show();

                return modal;
            }).catch(Notification.exception);
        });
    };

    var setModalSaveLabel = function(modal) {
        return Str.get_string('save', 'core').then(function(save) {
            modal.setSaveButtonText(save);
            return save;
        }).catch(Notification.exception);
    };

    var loadSqlModal = function(modal, reportid, title) {
        return Ajax.call([{
            methodname: 'local_la_library_sql',
            args: {
                reportid: reportid
            }
        }])[0].then(function(response) {
            modal.setTitle(response.title || title);
            modal.setBody(response.html || '');
            modal.getRoot().find('.modal-dialog').addClass('modal-xl');
            return response;
        });
    };

    var syncReportToggles = function() {
        $(TOGGLE_SELECTOR).each(function() {
            var toggle = $(this);
            toggle.prop('checked', String(toggle.data('initialChecked') || '0') === '1');
        });
    };

    var bindSqlModal = function() {
        $(document).on('click', SQL_SELECTOR, function(event) {
            event.preventDefault();

            var link = $(this);
            var reportid = Number(link.data('reportid') || 0);
            var title = String(link.data('title') || 'SQL');

            if (!reportid) {
                return;
            }

            ModalSaveCancel.create({
                title: title,
                body: getLoadingBody()
            }).then(function(modal) {
                setModalSaveLabel(modal);
                modal.show();

                modal.getRoot().on(ModalEvents.save, function(event) {
                    event.preventDefault();
                    saveSqlStatuses(modal);
                });

                return loadSqlModal(modal, reportid, title);
            }).catch(Notification.exception);
        });
    };

    var saveSqlStatuses = function(modal) {
        var root = modal.getRoot();
        var requests = [];

        root.find(SQL_STATUS_SELECTOR).each(function() {
            var checkbox = $(this);
            var name = String(checkbox.data('sqlName') || '');
            var status = checkbox.is(':checked');
            var initial = String(checkbox.attr('data-initial-status') || '0') === '1';

            if (!name || status === initial) {
                return;
            }

            requests.push({
                checkbox: checkbox,
                status: status,
                promise: Ajax.call([{
                    methodname: 'local_la_update_sql_status',
                    args: {
                        name: name,
                        status: status
                    }
                }])[0]
            });
        });

        if (!requests.length) {
            modal.hide();
            return;
        }

        root.find(SQL_STATUS_SELECTOR).prop('disabled', true);

        Promise.all(requests.map(function(request) {
            return request.promise.then(function(response) {
                request.checkbox.attr('data-initial-status', request.status ? '1' : '0');
                request.checkbox.closest('tr').next('tr')
                    .find('[data-region="sql-timeactivated"]')
                    .text(response.timeactivated || '');
                return response;
            });
        })).then(function() {
            modal.hide();
            return true;
        }).catch(function(error) {
            root.find(SQL_STATUS_SELECTOR).prop('disabled', false);
            Notification.exception(error);
            return false;
        });
    };

    var loadAppParams = function(modal, appid, title) {
        return Ajax.call([{
            methodname: 'local_la_library_app_params',
            args: {
                appid: appid
            }
        }])[0].then(function(response) {
            modal.setTitle(response.title || title);
            modal.setBody(response.html || '');
            modal.getRoot().find('.modal-dialog').addClass('modal-xl');
            return response;
        });
    };

    var bindAppParamsModal = function() {
        $(document).on('click', APP_PARAMS_SELECTOR, function(event) {
            event.preventDefault();

            var link = $(this);
            var appid = Number(link.data('appid') || 0);
            var title = String(link.data('title') || 'Params');

            if (!appid) {
                return;
            }

            Modal.create({
                title: title,
                body: getLoadingBody()
            }).then(function(modal) {
                modal.show();
                return loadAppParams(modal, appid, title);
            }).catch(Notification.exception);
        });
    };

    var loadReportPreview = function(modal, report, title) {
        return Ajax.call([{
            methodname: 'local_la_get_report',
            args: {
                report: report,
                filters: '{}',
                columns: '[]',
                metrics: '[]',
                title: title,
                summary: '{}',
                showsubheader: 0,
                showfullreporturl: 0
            }
        }])[0].then(function(response) {
            if (response.title) {
                modal.setTitle(response.title);
            }

            modal.setBody(response.html || '');
            modal.getRoot().find('.modal-dialog').addClass('modal-xl');
            return modal;
        }).catch(function() {
            modal.setBody(getUnableToLoadBody());
            return false;
        });
    };

    var bindReportPreview = function() {
        $(document).on('click', REPORT_PREVIEW_SELECTOR, function(event) {
            event.preventDefault();

            var link = $(this);
            var title = String(link.data('title') || $.trim(link.text()) || 'Preview');
            var report = String(link.attr('data-report') || '');

            if (!report) {
                return;
            }

            Modal.create({
                title: title,
                body: getLoadingBody()
            }).then(function(modal) {
                modal.show();
                return loadReportPreview(modal, report, title);
            }).catch(Notification.exception);
        });
    };

    var getModalSaveButton = function(modal) {
        var root = modal.getRoot();
        var button = root.find('[data-action="save"]').first();

        return button.length ? button : root.find('.modal-footer .btn-primary').last();
    };

    var setupMarketplaceInstallFooter = function(modal, termskey, savekey) {
        Str.get_strings([
            {key: savekey || 'install', component: 'local_la'},
            {key: termskey || 'acceptreportterms', component: 'local_la'}
        ]).then(function(strings) {
            var root = modal.getRoot();
            var footer = root.find('.modal-footer');
            var savebutton = getModalSaveButton(modal);
            var checkboxid = 'la-marketplace-install-terms-' + Date.now();
            var wrapper = $('<div>').addClass('form-check me-auto');
            var checkbox = $('<input>')
                .attr({
                    type: 'checkbox',
                    id: checkboxid,
                    'data-action': 'accept-marketplace-install'
                })
                .addClass('form-check-input');
            var label = $('<label>').attr('for', checkboxid).addClass('form-check-label').text(strings[1]);

            modal.setSaveButtonText(strings[0]);
            savebutton.prop('disabled', true);
            footer.prepend(wrapper.append(checkbox, label));
            root.on('change', '[data-action="accept-marketplace-install"], [data-action="accept-marketplace-sql"]', function() {
                var termsaccepted = root.find('[data-action="accept-marketplace-install"]').is(':checked');
                var sqlcheckboxes = root.find('[data-action="accept-marketplace-sql"]');

                if ($(this).is('[data-action="accept-marketplace-install"]')) {
                    sqlcheckboxes.prop('checked', termsaccepted).prop('disabled', termsaccepted);
                }

                var sqlaccepted = root.find('[data-action="accept-marketplace-sql"]').length ===
                    root.find('[data-action="accept-marketplace-sql"]:checked').length;

                savebutton.prop('disabled', !(termsaccepted && sqlaccepted));
            });

            return strings;
        }).catch(Notification.exception);
    };

    var revealMarketplaceValidation = function(modal) {
        var root = modal.getRoot();
        var alerts = root.find('[data-region="marketplace-validation-alert"]');

        if (!alerts.length) {
            return false;
        }

        root.find('#la-marketplace-install-sql').removeClass('collapsed');
        root.find('[href="#la-marketplace-install-sql-container"]')
            .removeClass('collapsed')
            .attr('aria-expanded', 'true');
        root.find('#la-marketplace-install-sql-container').addClass('show');
        alerts.removeClass('d-none');

        var firstalert = alerts.first();
        var firstfailedrule = root.find('[data-validation-failed="1"]').first();
        var scrolltarget = firstfailedrule.length ? firstfailedrule : firstalert;

        if (scrolltarget.length && scrolltarget[0].scrollIntoView) {
            scrolltarget[0].scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }

        firstalert.trigger('focus');
        return true;
    };

    var loadInstallReview = function(modal, request, title, state) {
        return Ajax.call([request])[0].then(function(response) {
            state.installkey = String(response.token || state.installkey || '');
            modal.setTitle(response.title || title);
            modal.setBody(response.html || '');
            modal.getRoot().find('.modal-dialog').addClass('modal-xl');
            modal.getRoot().find('[data-action="accept-marketplace-install"]').trigger('change');
            return response;
        });
    };

    var installMarketplaceItem = function(installaction, installkey) {
        return runLibraryAction(installaction, 0, installkey).then(function(response) {
            var reportid = Number(response.reportid || 0);

            if (reportid) {
                window.location.href = Config.wwwroot + '/local/la/report.php?id=' + reportid;
                return response;
            }

            window.location.reload();
            return response;
        }).catch(Notification.exception);
    };

    var openInstallReview = function(title, request, installaction, installkey, termskey, savekey) {
        var state = {installkey: installkey};

        return ModalSaveCancel.create({
            title: title,
            body: getLoadingBody()
        }).then(function(modal) {
            setupMarketplaceInstallFooter(modal, termskey, savekey);
            modal.show();

            modal.getRoot().on(ModalEvents.save, function(event) {
                event.preventDefault();
                if (getModalSaveButton(modal).prop('disabled') || !state.installkey) {
                    return;
                }

                if (revealMarketplaceValidation(modal)) {
                    return;
                }

                installMarketplaceItem(installaction, state.installkey);
            });

            return loadInstallReview(modal, request, title, state);
        }).catch(Notification.exception);
    };

    var openGeneratedInstallReview = function(definition, title) {
        openInstallReview(title || '', {
            methodname: 'local_la_generated_install_modal',
            args: {
                definition: typeof definition === 'string' ? definition : JSON.stringify(definition)
            }
        }, 'installgenerated', '', 'acceptreportterms');
    };

    var bindMarketplaceReview = function(selector, methodname, argname, libraryaction, termskey, savekey) {
        $(document).on('click', selector, function(event) {
            event.preventDefault();

            var link = $(this);
            var itemkey = String(link.data('reportKey') || '');

            if (!itemkey) {
                return;
            }

            var args = {};
            args[argname] = itemkey;

            openInstallReview(String(link.data('title') || ''), {
                methodname: methodname,
                args: args
            }, libraryaction, itemkey, termskey, savekey);
        });
    };

    var bindMarketplaceInstallReview = function() {
        bindMarketplaceReview(MARKETPLACE_INSTALL_SELECTOR, 'local_la_marketplace_install_modal',
            'reportkey', 'installreport', 'acceptreportterms');
        bindMarketplaceReview(MARKETPLACE_UPDATE_SELECTOR, 'local_la_marketplace_install_modal',
            'reportkey', 'updatereport', 'acceptreportterms', 'update');
        bindMarketplaceReview(MARKETPLACE_APP_INSTALL_SELECTOR, 'local_la_marketplace_app_install_modal',
            'appkey', 'installapp', 'acceptappterms');
        bindMarketplaceReview(MARKETPLACE_APP_UPDATE_SELECTOR, 'local_la_marketplace_app_install_modal',
            'appkey', 'updateapp', 'acceptappterms', 'update');
    };

    var bindReportSummaryToggle = function() {
        $(document).on('click', REPORT_SUMMARY_SELECTOR, function(event) {
            event.preventDefault();

            var summary = $('[data-region="report-summary"]').first();

            if (!summary.length) {
                return;
            }

            summary.toggleClass('d-none');
        });
    };

    var loadMarketplaceItems = function() {
        var container = $(MARKETPLACE_SELECTOR).first();

        if (!container.length) {
            return false;
        }

        return Ajax.call([{
            methodname: 'local_la_get_marketplace',
            args: {
                plan: String(container.data('plan') || 'all'),
                type: String(container.data('type') || 'reports'),
                search: String(container.data('search') || ''),
                sort: String(container.data('sort') || 'name')
            }
        }])[0].then(function(response) {
            container.html(response.html || '');
            return response;
        }).catch(function() {
            container.html(getUnableToLoadBody());
            return false;
        });
    };

    return {
        init: function() {
            syncReportToggles();
            bindLibraryActions();
            bindDeleteConfirmation();
            bindReportToggle();
            bindSqlModal();
            bindAppParamsModal();
            bindReportPreview();
            bindMarketplaceInstallReview();
            bindReportSummaryToggle();
            loadMarketplaceItems();
        },
        openGeneratedInstallReview: openGeneratedInstallReview
    };
});
