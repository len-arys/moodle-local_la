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
 * JavaScript for the report data interface.
 *
 * @module     local_la/report_data
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/ajax',
    'core/modal_factory',
    'local_la/modal_save_cancel',
    'core/modal_events',
    'core/notification',
    'core/str',
    'core/toast'
], function($, Ajax, Modal, ModalSaveCancel, ModalEvents, Notification, Str, Toast) {
    var ROW_ACTIONS_SELECTOR = '[data-region="row-actions"]';
    var SELECT_ROW_SELECTOR = '[data-region="select-row"]';
    var SELECT_ALL_ROWS_SELECTOR = '[data-region="select-all-rows"]';
    var HIGHLIGHT_ACTION_SELECTOR = '[data-action="apply-highlight-color"]';
    var HIGHLIGHT_STORAGE_PREFIX = 'la_report_row_colors_';
    var HIGHLIGHT_CLASSES = [
        'la-report-row-highlight-yellow',
        'la-report-row-highlight-blue',
        'la-report-row-highlight-green',
        'la-report-row-highlight-red',
        'la-report-row-highlight-orange'
    ];
    var HIGHLIGHT_OPTIONS = [
        {value: 'yellow', key: 'yellow', className: 'la-report-row-highlight-yellow', swatch: '#ffe066'},
        {value: 'blue', key: 'blue', className: 'la-report-row-highlight-blue', swatch: '#74c0fc'},
        {value: 'green', key: 'green', className: 'la-report-row-highlight-green', swatch: '#8ce99a'},
        {value: 'red', key: 'red', className: 'la-report-row-highlight-red', swatch: '#ffa8a8'},
        {value: 'orange', key: 'orange', className: 'la-report-row-highlight-orange', swatch: '#ffc078'},
        {value: 'none', key: 'none', className: ''}
    ];
    var cachedStrings = null;

    var getStrings = function() {
        if (cachedStrings !== null) {
            return $.Deferred().resolve(cachedStrings).promise();
        }

        return Str.get_strings([
            {key: 'selectrowtohighlight', component: 'local_la'},
            {key: 'highlightselectedrows', component: 'local_la'},
            {key: 'selectrowtosummarize', component: 'local_la'},
            {key: 'selectrowtofindpatterns', component: 'local_la'},
            {key: 'selectrowtoprint', component: 'local_la'},
            {key: 'unabletoopenprintpreview', component: 'local_la'},
            {key: 'printselectedrows', component: 'local_la'},
            {key: 'selectrowtoshare', component: 'local_la'},
            {key: 'yellow', component: 'local_la'},
            {key: 'blue', component: 'local_la'},
            {key: 'green', component: 'local_la'},
            {key: 'red', component: 'local_la'},
            {key: 'orange', component: 'local_la'},
            {key: 'none', component: 'local_la'}
        ]).then(function(values) {
            cachedStrings = {
                selectrowtohighlight: values[0],
                highlightselectedrows: values[1],
                selectrowtosummarize: values[2],
                selectrowtofindpatterns: values[3],
                selectrowtoprint: values[4],
                unabletoopenprintpreview: values[5],
                printselectedrows: values[6],
                selectrowtoshare: values[7],
                colors: {
                    yellow: values[8],
                    blue: values[9],
                    green: values[10],
                    red: values[11],
                    orange: values[12],
                    none: values[13]
                }
            };

            return cachedStrings;
        });
    };

    var getHighlightStorageKey = function(reportid) {
        return HIGHLIGHT_STORAGE_PREFIX + String(reportid || '');
    };

    var getRowColors = function(reportid) {
        var payload;

        try {
            payload = window.localStorage.getItem(getHighlightStorageKey(reportid));
        } catch (error) {
            return {};
        }

        if (!payload) {
            return {};
        }

        try {
            payload = JSON.parse(payload);
        } catch (error) {
            return {};
        }

        return payload && typeof payload === 'object' ? payload : {};
    };

    var saveRowColors = function(reportid, items) {
        try {
            window.localStorage.setItem(getHighlightStorageKey(reportid), JSON.stringify(items));
        } catch (error) {
            return;
        }
    };

    var applyHighlightedRows = function(reportid) {
        var colors = getRowColors(reportid);
        var classnames = {};

        HIGHLIGHT_OPTIONS.forEach(function(option) {
            if (option.value !== 'none' && option.className) {
                classnames[option.value] = option.className;
            }
        });

        classnames.warning = 'la-report-row-highlight-yellow';
        classnames.info = 'la-report-row-highlight-blue';
        classnames.success = 'la-report-row-highlight-green';
        classnames.danger = 'la-report-row-highlight-red';

        $(SELECT_ROW_SELECTOR).each(function() {
            var checkbox = $(this);
            var row = checkbox.closest('tr');
            var rowid = String(checkbox.val() || '');
            var color = String(colors[rowid] || '');
            var classname = classnames[color] || '';

            row.removeClass(HIGHLIGHT_CLASSES.join(' '));

            if (classname !== '') {
                row.addClass(classname);
            }
        });
    };

    var getSelectedRowIds = function() {
        return $(SELECT_ROW_SELECTOR + ':checked').map(function() {
            return String($(this).val() || '');
        }).get().filter(function(id) {
            return id !== '';
        });
    };

    var getSelectedRows = function() {
        return $(SELECT_ROW_SELECTOR + ':checked').map(function() {
            var payload = String($(this).attr('data-row') || '{}');

            try {
                return JSON.parse(payload);
            } catch (error) {
                return null;
            }
        }).get().filter(function(row) {
            return row !== null;
        });
    };

    var getHighlightModalBody = function(strings) {
        return '<div class="la-report-highlight-grid">' + HIGHLIGHT_OPTIONS.map(function(option) {
            var swatchstyle = 'background:' + String(option.swatch || '#fff') + ';';
            var label = strings.colors[option.value] || option.value;

            return '<button type="button" class="btn btn-outline-secondary text-start d-flex align-items-center gap-2 la-report-highlight-option" ' +
                'data-action="apply-highlight-color" data-color="' + option.value + '">' +
                '<span class="d-inline-block rounded-circle border" style="' + swatchstyle +
                ' border-color: rgba(0, 0, 0, 0.12); width: 1rem; height: 1rem;"></span>' +
                '<span>' + escapeHtml(label) + '</span>' +
                '</button>';
        }).join('') + '</div>';
    };

    var openHighlightPicker = function(reportid, strings) {
        var selectedids = getSelectedRowIds();

        if (!selectedids.length) {
            window.alert(strings.selectrowtohighlight);
            return;
        }

        Modal.create({
            title: strings.highlightselectedrows,
            body: getHighlightModalBody(strings)
        }).then(function(modal) {
            modal.show();

            modal.getRoot().on('click', HIGHLIGHT_ACTION_SELECTOR, function(event) {
                var color = String($(event.currentTarget).data('color') || 'none');
                var colors = getRowColors(reportid);

                event.preventDefault();

                selectedids.forEach(function(rowid) {
                    if (color === 'none') {
                        delete colors[rowid];
                    } else {
                        colors[rowid] = color;
                    }
                });

                saveRowColors(reportid, colors);
                applyHighlightedRows(reportid);
                modal.hide();
            });

            return modal;
        });
    };

    var escapeHtml = function(value) {
        return $('<div>').text(String(value || '')).html();
    };

    var normaliseCellText = function(value) {
        return $.trim(String(value || '').replace(/\s+/g, ' '));
    };

    var getReportTitle = function() {
        return normaliseCellText($('main h2').first().text());
    };

    var openUserStoryModal = function(reportid, strings) {
        var rows = getSelectedRows();

        if (!rows.length) {
            window.alert(strings.selectrowtosummarize);
            return;
        }

        openRowsAnalysisModal(reportid, 'summary', rows);
    };

    var openPatternModal = function(reportid, strings) {
        var rows = getSelectedRows();

        if (!rows.length) {
            window.alert(strings.selectrowtofindpatterns);
            return;
        }

        openRowsAnalysisModal(reportid, 'patterns', rows);
    };

    var printSelectedRows = function(strings) {
        var selected = $(SELECT_ROW_SELECTOR + ':checked');
        var table = selected.closest('table').first().clone();
        var selectedindexes = selected.map(function() {
            return $(this).closest('tr').index();
        }).get();
        var headers;
        var removableindexes = [];
        var printwindow;
        var documenthtml;

        if (!selected.length || !table.length) {
            window.alert(strings.selectrowtoprint);
            return;
        }

        table.find('tbody tr').each(function(index) {
            if (selectedindexes.indexOf(index) === -1 || $(this).hasClass('emptyrow')) {
                $(this).remove();
            }
        });

        headers = table.find('thead th');
        headers.each(function(index) {
            var header = $(this);
            var text = $.trim(header.text());

            if (header.find(SELECT_ALL_ROWS_SELECTOR).length || text === '') {
                removableindexes.push(index);
            }
        });

        removableindexes.reverse().forEach(function(index) {
            table.find('tr').each(function() {
                $(this).find('th, td').eq(index).remove();
            });
        });

        printwindow = window.open('', '_blank', 'width=1024,height=720');

        if (!printwindow) {
            window.alert(strings.unabletoopenprintpreview);
            return;
        }

        documenthtml = '<!doctype html><html><head><title>' + escapeHtml(strings.printselectedrows) + '</title>' +
            '<style>body{font-family:Arial,sans-serif;padding:24px;}table{width:100%;border-collapse:collapse;}' +
            'th,td{border:1px solid #d0d7de;padding:10px;text-align:left;vertical-align:top;}' +
            'thead th{background:#f6f8fa;}a{color:inherit;text-decoration:none;}.emptyrow{display:none;}</style>' +
            '</head><body>' + $('<div>').append(table).html() + '</body></html>';

        printwindow.document.open();
        printwindow.document.write(documenthtml);
        printwindow.document.close();
        printwindow.focus();
        printwindow.print();
    };

    var getSelectedTableData = function() {
        var selected = $(SELECT_ROW_SELECTOR + ':checked');
        var table = selected.closest('table').first();
        var removableindexes = [];
        var headers = [];
        var rows = [];

        if (!selected.length || !table.length) {
            return {headers: [], rows: []};
        }

        table.find('thead th').each(function(index) {
            var header = $(this);
            var text = normaliseCellText(header.text());

            if (header.find(SELECT_ALL_ROWS_SELECTOR).length || text === '') {
                removableindexes.push(index);
                return;
            }

            headers.push(text);
        });

        selected.each(function() {
            var cells = [];

            $(this).closest('tr').children('th, td').each(function(index) {
                if (removableindexes.indexOf(index) !== -1) {
                    return;
                }

                cells.push(normaliseCellText($(this).text()));
            });

            rows.push(cells.slice(0, headers.length));
        });

        return {headers: headers, rows: rows};
    };

    var getSharePreviewTable = function(data) {
        return '<div class="table-responsive la-share-report-preview-wrap mb-3">' +
            '<table class="table table-borderless align-middle mb-0 la-share-report-preview">' +
            '<tbody>' + data.rows.map(function(row) {
                return '<tr><td class="la-share-report-check" aria-hidden="true">' +
                    '<i class="fa-solid fa-check"></i></td>' + row.map(function(cell) {
                    return '<td>' + escapeHtml(cell) + '</td>';
                }).join('') + '</tr>';
            }).join('') + '</tbody>' +
            '</table>' +
            '</div>';
    };

    var getShareModalBody = function(data, strings) {
        return '<form class="la-share-report-form" autocomplete="off">' +
            getSharePreviewTable(data) +
            '<div class="la-share-report-fields">' +
            '<input id="la-share-to" name="to" type="text" class="form-control la-share-report-control" ' +
            'placeholder="' + escapeHtml(strings.to) + '" aria-label="' + escapeHtml(strings.to) + '" required>' +
            '<input id="la-share-subject" name="subject" type="text" class="form-control la-share-report-control" ' +
            'placeholder="' + escapeHtml(strings.subject) + '" aria-label="' + escapeHtml(strings.subject) + '" ' +
            'required>' +
            '<textarea id="la-share-body" name="body" class="form-control la-share-report-control la-share-report-body" ' +
            'rows="2" placeholder="' + escapeHtml(strings.messageplaceholder) + '" ' +
            'aria-label="' + escapeHtml(strings.body) + '" required></textarea>' +
            '</div>' +
            '</form>';
    };

    var openShareModal = function(reportid) {
        var data = getSelectedTableData();
        var reporttitle = getReportTitle();

        if (!data.rows.length) {
            getStrings().then(function(strings) {
                window.alert(strings.selectrowtoshare);
                return strings;
            });
            return;
        }

        Str.get_strings([
            {key: 'sharereport', component: 'local_la', param: reporttitle},
            {key: 'send', component: 'local_la'},
            {key: 'recipientemails', component: 'local_la'},
            {key: 'subject', component: 'local_la'},
            {key: 'body', component: 'local_la'},
            {key: 'sharemessageplaceholder', component: 'local_la'},
            {key: 'reportsent', component: 'local_la'}
        ]).then(function(values) {
            var strings = {
                title: values[0],
                send: values[1],
                to: values[2],
                subject: values[3],
                body: values[4],
                messageplaceholder: values[5],
                sent: values[6]
            };

            return ModalSaveCancel.create({
                title: strings.title,
                body: getShareModalBody(data, strings)
            }).then(function(modal) {
                modal.setSaveButtonText(strings.send);
                modal.getRoot().find('.modal-dialog').addClass('modal-lg');

                modal.getRoot().on(ModalEvents.save, function(event) {
                    var form = modal.getRoot().find('.la-share-report-form').first();

                    event.preventDefault();

                    Ajax.call([{
                        methodname: 'local_la_share_report',
                        args: {
                            reportid: Number(reportid || 0),
                            to: String(form.find('[name="to"]').val() || ''),
                            subject: String(form.find('[name="subject"]').val() || ''),
                            body: String(form.find('[name="body"]').val() || ''),
                            headers: data.headers,
                            rows: data.rows
                        }
                    }])[0].then(function() {
                        modal.hide();
                        Toast.add(strings.sent);
                    }).catch(Notification.exception);
                });

                modal.show();
                return modal;
            });
        }).catch(Notification.exception);
    };

    var openRowsAnalysisModal = function(reportid, action, rows) {
        Ajax.call([{
            methodname: 'local_la_analyze_report_rows',
            args: {
                reportid: Number(reportid || 0),
                action: action,
                rowsjson: JSON.stringify(rows)
            }
        }])[0].then(function(response) {
            return Modal.create({
                title: response.title,
                body: response.html
            }).then(function(modal) {
                modal.show();
                modal.getRoot().find('.modal-dialog').addClass('modal-lg');
                return modal;
            });
        }).catch(Notification.exception);
    };

    var bindRowActions = function() {
        $(ROW_ACTIONS_SELECTOR).each(function() {
            var reportid = String($(this).data('reportId') || '');

            if (reportid !== '') {
                applyHighlightedRows(reportid);
            }
        });

        $(document).on('change', SELECT_ALL_ROWS_SELECTOR, function() {
            $(SELECT_ROW_SELECTOR).prop('checked', $(this).is(':checked'));
        });

        $(document).on('change', SELECT_ROW_SELECTOR, function() {
            var rows = $(SELECT_ROW_SELECTOR);
            var checked = rows.filter(':checked');

            $(SELECT_ALL_ROWS_SELECTOR).prop('checked', rows.length > 0 && rows.length === checked.length);
        });

        $(document).on('change', ROW_ACTIONS_SELECTOR, function() {
            var select = $(this);
            var action = String($(this).val() || '');
            var reportid = String($(this).data('reportId') || '');

            if (action === 'print') {
                getStrings().then(function(strings) {
                    printSelectedRows(strings);
                    return strings;
                });
            } else if (action === 'share' && reportid !== '') {
                openShareModal(reportid);
            } else if (action === 'highlight' && reportid !== '') {
                getStrings().then(function(strings) {
                    openHighlightPicker(reportid, strings);
                    return strings;
                });
            } else if (action === 'analyze') {
                if (String(select.attr('data-patterns-available') || '0') === '1') {
                    getStrings().then(function(strings) {
                        openPatternModal(reportid, strings);
                        return strings;
                    });
                } else {
                    Notification.addNotification({
                        type: 'warning',
                        message: String(select.attr('data-patterns-message') || '')
                    });
                }
            } else if (action === 'userstory') {
                if (String(select.attr('data-insights-available') || '0') === '1') {
                    getStrings().then(function(strings) {
                        openUserStoryModal(reportid, strings);
                        return strings;
                    });
                } else {
                    Notification.addNotification({
                        type: 'warning',
                        message: String(select.attr('data-insights-message') || '')
                    });
                }
            }

            select.val('');
        });
    };

    return {
        init: function() {
            bindRowActions();
        }
    };
});
