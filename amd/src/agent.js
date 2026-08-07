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
 * JavaScript for the agent interface.
 *
 * @module     local_la/agent
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, Ajax, Notification, Str) {
    var ROOT = '[data-region="agent-guide"]';
    var SLIDE = '[data-region="agent-slide"]';
    var DOTS = '[data-region="agent-dots"] span';

    var strings = {
        checking: '',
        checkingdesc: '',
        loading: '',
        loadingdesc: '',
        installing: '',
        installingdesc: '',
        complete: '',
        completedesc: '',
        failed: '',
        pending: '',
        installingitem: '',
        installed: '',
        alreadyinstalled: '',
        faileditem: '',
        showdetails: '',
        hidedetails: ''
    };

    var loadStrings = function() {
        return Str.get_strings([
            {key: 'agentcheckinglicense', component: 'local_la'},
            {key: 'agentcheckinglicensedesc', component: 'local_la'},
            {key: 'agentloadingreports', component: 'local_la'},
            {key: 'agentloadingreportsdesc', component: 'local_la'},
            {key: 'agentinstallingreports', component: 'local_la'},
            {key: 'agentinstallingreportsdesc', component: 'local_la'},
            {key: 'agentcomplete', component: 'local_la'},
            {key: 'agentcompletedesc', component: 'local_la'},
            {key: 'agentfailed', component: 'local_la'},
            {key: 'agentpending', component: 'local_la'},
            {key: 'agentinstalling', component: 'local_la'},
            {key: 'agentinstalled', component: 'local_la'},
            {key: 'agentalreadyinstalled', component: 'local_la'},
            {key: 'agentfaileditem', component: 'local_la'},
            {key: 'agentshowdetails', component: 'local_la'},
            {key: 'agenthidedetails', component: 'local_la'}
        ]).then(function(values) {
            strings.checking = values[0];
            strings.checkingdesc = values[1];
            strings.loading = values[2];
            strings.loadingdesc = values[3];
            strings.installing = values[4];
            strings.installingdesc = values[5];
            strings.complete = values[6];
            strings.completedesc = values[7];
            strings.failed = values[8];
            strings.pending = values[9];
            strings.installingitem = values[10];
            strings.installed = values[11];
            strings.alreadyinstalled = values[12];
            strings.faileditem = values[13];
            strings.showdetails = values[14];
            strings.hidedetails = values[15];
            return true;
        }).catch(Notification.exception);
    };

    var showSlide = function(root, index) {
        var slides = root.find(SLIDE);
        var dots = root.find(DOTS);
        var last = slides.length - 1;

        slides.each(function(position) {
            var active = position === index;
            $(this).toggleClass('is-active', active).prop('hidden', !active);
        });

        dots.each(function(position) {
            $(this).toggleClass('is-active', position === index);
        });

        root.find('[data-action="agent-prev"]').prop('disabled', index === 0);
        root.find('[data-action="agent-next"]').toggleClass('d-none', index === last);
        root.find('[data-action="agent-complete"]').toggleClass('d-none', index !== last);
        root.data('index', index);
    };

    var completeAgent = function(root, redirect) {
        Ajax.call([{
            methodname: 'local_la_agent_complete',
            args: {}
        }])[0].then(function() {
            if (redirect) {
                window.location.href = root.data('library-url');
                return true;
            }

            root.remove();
            $('body').removeClass('la-welcome-open');
            return true;
        }).catch(Notification.exception);
    };

    var setSetupText = function(root, title, subtitle) {
        root.find('[data-region="agent-setup-title"]').text(title);
        root.find('[data-region="agent-setup-subtitle"]').text(subtitle || '');
    };

    var setSetupError = function(root, message, details) {
        var subtitle = root.find('[data-region="agent-setup-subtitle"]').empty().text(message || '');

        if (!details) {
            return;
        }

        subtitle
            .append($('<br>'))
            .append($('<button>')
                .attr('type', 'button')
                .attr('class', 'btn btn-link p-0 la-agent-details-toggle')
                .attr('data-action', 'agent-details-toggle')
                .text(strings.showdetails))
            .append($('<pre>')
                .attr('class', 'la-agent-details d-none')
                .text(details));
    };

    var setReportStatus = function(row, checkicon, statusicon, label) {
        row.children('i').first().attr('class', checkicon).attr('aria-hidden', 'true');
        row.find('[data-region="agent-report-status"]')
            .empty()
            .attr('title', label)
            .attr('aria-label', label)
            .append($('<i>').attr('class', statusicon).attr('aria-hidden', 'true'))
            .append($('<span>').attr('class', 'visually-hidden').text(label));
    };

    var renderReports = function(root, reports) {
        var list = root.find('[data-region="agent-setup-list"]').empty();

        reports.forEach(function(report) {
            var row = $('<li>')
                .attr('data-shortname', report.shortname)
                .append($('<i>').attr('class', 'fa-regular fa-square').attr('aria-hidden', 'true'))
                .append($('<span>')
                    .append($('<strong>').text(report.name))
                    .append($('<br>'))
                    .append($('<small>').text(report.info || '')))
                .append($('<small>').attr('data-region', 'agent-report-status'));

            setReportStatus(row, 'fa-regular fa-square', 'fa-regular fa-circle', strings.pending);

            if (report.installed) {
                setReportStatus(row, 'fa-regular fa-square-check', 'fa-solid fa-circle-check', strings.alreadyinstalled);
            }

            list.append(row);
        });
    };

    var installReports = function(root, reports) {
        var pending = reports.filter(function(report) {
            return !report.installed;
        });
        var request = $.Deferred().resolve().promise();

        pending.forEach(function(report) {
            request = request.then(function() {
                var row = root.find('[data-shortname="' + report.shortname + '"]');
                setReportStatus(row, 'fa-solid fa-spinner fa-spin', 'fa-solid fa-spinner fa-spin', strings.installingitem);

                return Ajax.call([{
                    methodname: 'local_la_agent_install_report',
                    args: {
                        shortname: report.shortname
                    }
                }])[0].then(function() {
                    setReportStatus(row, 'fa-regular fa-square-check', 'fa-regular fa-circle-check', strings.installed);
                    return true;
                }).catch(function(error) {
                    setReportStatus(row, 'fa-regular fa-circle-xmark', 'fa-solid fa-circle-xmark', strings.faileditem);
                    throw error;
                });
            });
        });

        return request;
    };

    var runAgentSetup = function(root) {
        root.find('.la-welcome-card').addClass('is-setup');
        root.find('.la-welcome-slides, [data-region="agent-dots"], [data-action="agent-prev"], [data-action="agent-next"], [data-action="agent-complete"]')
            .addClass('d-none');
        root.find('[data-region="agent-setup"]').removeClass('d-none');
        setSetupText(root, strings.checking, strings.checkingdesc);

        Ajax.call([{
            methodname: 'local_la_agent_check_setup',
            args: {}
        }])[0].then(function(result) {
            if (!result.success) {
                setSetupText(root, strings.failed);
                setSetupError(root, result.message || strings.checkingdesc, result.details || '');
                return $.Deferred().reject().promise();
            }

            setSetupText(root, strings.loading, strings.loadingdesc);
            return Ajax.call([{
                methodname: 'local_la_agent_get_reports',
                args: {}
            }])[0];
        }).then(function(result) {
            var reports = result.reports || [];
            renderReports(root, reports);
            setSetupText(root, strings.installing, strings.installingdesc);
            return installReports(root, reports);
        }).then(function() {
            setSetupText(root, strings.complete, strings.completedesc);
            root.find('[data-action="agent-finish"]').removeClass('d-none');
            return true;
        }).catch(function(error) {
            if (error) {
                Notification.exception(error);
                setSetupText(root, strings.failed, error.message || strings.checkingdesc);
            }
        });
    };

    var shouldInstallReports = function(root) {
        return root.find('[data-task="installreports"] i').hasClass('fa-square-check');
    };

    var bind = function(root) {
        root.on('click', '[data-action="agent-prev"]', function() {
            var index = parseInt(root.data('index'), 10) || 0;
            showSlide(root, Math.max(0, index - 1));
        });

        root.on('click', '[data-action="agent-next"]', function() {
            var index = parseInt(root.data('index'), 10) || 0;
            var last = root.find(SLIDE).length - 1;
            showSlide(root, Math.min(last, index + 1));
        });

        root.on('click', '[data-action="agent-skip"]', function() {
            completeAgent(root);
        });

        root.on('click', '[data-action="agent-complete"]', function() {
            if (shouldInstallReports(root)) {
                runAgentSetup(root);
                return;
            }

            completeAgent(root);
        });

        root.on('click', '[data-action="agent-finish"]', function() {
            completeAgent(root, true);
        });

        root.on('click', '[data-action="agent-check"]', function() {
            var icon = $(this).find('i').first();
            icon.toggleClass('fa-square fa-square-check');
        });

        root.on('click', '[data-action="agent-details-toggle"]', function() {
            var button = $(this);
            var details = button.siblings('.la-agent-details').first();
            var show = details.hasClass('d-none');

            details.toggleClass('d-none', !show);
            button.text(show ? strings.hidedetails : strings.showdetails);
        });
    };

    return {
        init: function() {
            var root = $(ROOT).first();
            if (!root.length) {
                return;
            }

            $('body').addClass('la-welcome-open');
            loadStrings().then(function() {
                bind(root);
                showSlide(root, 0);
                return true;
            });
        }
    };
});
