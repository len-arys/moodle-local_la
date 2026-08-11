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
 * JavaScript for the report columns interface.
 *
 * @module     local_la/report_columns
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/modal', 'core/notification', 'core/str'], function($, Ajax, Modal, Notification, Str) {
    var COLUMN_LIST_SELECTOR = '[data-region="column-list"]';
    var COLUMN_ITEM_SELECTOR = '[data-column-key]';
    var BUILDER_ITEM_SELECTOR = '[data-region="builder-item"]';
    var BUILDER_SECTION_SELECTOR = '[data-region="builder-section"]';
    var SAVE_COLUMN_SETTINGS_SELECTOR = '[data-action="save-column-settings"]';
    var CANCEL_COLUMN_SETTINGS_SELECTOR = '[data-action="cancel-column-settings"]';

    var filterBuilderItems = function(input) {
        var field = $(input);
        var modal = field.closest('.modal');
        var query = $.trim(String(field.val() || '')).toLowerCase();

        modal.find(BUILDER_SECTION_SELECTOR).each(function() {
            var section = $(this);
            var sectionname = $.trim(String(
                section.find('[data-region="builder-section-name"]').first().text() || ''
            )).toLowerCase();
            var visiblecount = 0;

            section.find(BUILDER_ITEM_SELECTOR).each(function() {
                var item = $(this);
                var text = $.trim(item.text()).toLowerCase();
                var visible = query === '' || text.indexOf(query) !== -1 || sectionname.indexOf(query) !== -1;

                item.toggleClass('d-none', !visible);

                if (visible) {
                    visiblecount++;
                }
            });

            section.toggleClass('d-none', visiblecount === 0);
        });
    };

    var closeDropdown = function(element) {
        $(element).closest('.dropdown-menu').removeClass('show');
        $(element).closest('.dropdown').find('[data-bs-toggle="dropdown"]').attr('aria-expanded', 'false').removeClass('show');
    };

    var closeModal = function(element) {
        $(element).closest('.modal').find('.btn-close').first().trigger('click');
    };

    var reloadPage = function() {
        window.location.reload();
    };

    var escapeHtml = function(value) {
        return $('<div>').text(String(value || '')).html();
    };

    var handleAjaxError = function(error) {
        Notification.exception(error);
    };

    var saveAndReload = function(promise) {
        return promise.then(reloadPage).catch(handleAjaxError);
    };

    var moveModalFooter = function(root, selector) {
        var body = root.find('.modal-body').first();
        var content = root.find('.modal-content').first();

        if (!body.length || !content.length) {
            return;
        }

        content.children(selector).remove();
        body.find(selector).each(function() {
            var footer = $(this);
            footer.remove();
            body.after(footer);
        });
    };

    var getColumnKey = function(row) {
        return String(row.data('columnKey') || row.attr('data-column-key') || '');
    };

    var setColumnEditState = function(row, editing) {
        row.toggleClass('is-editing', editing).attr('draggable', editing ? 'false' : 'true');
        row.find('[data-region="column-display"]').toggleClass('d-none', editing);
        row.find('[data-region="column-edit"]').toggleClass('d-none', !editing);
    };

    var collectStandardColumns = function(form) {
        return form.find('[data-column-key]').map(function() {
            var row = $(this);

            return {
                key: getColumnKey(row),
                order: ($(this).index() + 1) * 10,
                name: String(row.find('[data-region="column-name-input"]').val() || ''),
                visible: row.find('input[name^="columns["][name$="[visible]"]').prop('checked') ? 1 : 0,
                enabled: String(row.find('[data-region="column-enabled-input"]').val() || '1') === '1' ? 1 : 0
            };
        }).get().filter(function(item) {
            return item.key !== '';
        });
    };

    var saveStandardColumns = function(form) {
        var reportid = Number(form.find('[name="id"]').val() || 0);

        if (!reportid) {
            return $.Deferred().reject(new Error('Missing report id')).promise();
        }

        return Ajax.call([{
            methodname: 'local_la_save_columns',
            args: {
                id: reportid,
                columns: collectStandardColumns(form)
            }
        }])[0];
    };

    var collectBuilderColumns = function(modal) {
        return modal.find(BUILDER_ITEM_SELECTOR).map(function() {
            var item = $(this);

            return {
                enabled: item.find('[data-region="builder-enabled"]').prop('checked') ? 1 : 0,
                entitykey: String(item.data('entityKey') || ''),
                field: String(item.data('field') || ''),
                name: String(item.find('[data-region="builder-name"]').val() || ''),
                type: String(item.find('[data-region="builder-type"]').val() || 'text'),
                formula: String(item.find('[data-region="builder-formula"]').val() || ''),
                condition: String(item.find('[data-region="builder-condition"]').val() || ''),
                visible: item.find('[data-region="builder-visible"]').prop('checked') ? 1 : 0,
                sortable: item.find('[data-region="builder-sortable"]').prop('checked') ? 1 : 0
            };
        }).get();
    };

    var setBuilderItemState = function(item, open) {
        var button = item.find('[data-action="builder-toggle-column"]').first();
        var icon = button.find('i').first();
        var settings = item.find('[data-region="builder-settings"]').first();

        button.attr('aria-expanded', open ? 'true' : 'false');
        icon.toggleClass('fa-plus', !open);
        icon.toggleClass('fa-minus', open);

        if (open) {
            settings.removeClass('d-none').hide().slideDown(120);
        } else {
            settings.stop(true, true).slideUp(120, function() {
                settings.addClass('d-none');
            });
        }
    };

    var openSettingsModal = function(reportid, key) {
        Str.get_strings([
            {key: 'settings', component: 'local_la'},
            {key: 'loading', component: 'local_la'},
            {key: 'unabletoloaddata', component: 'local_la'}
        ]).then(function(strings) {
            return Modal.create({
                title: strings[0],
                body: '<div class="text-muted">' + escapeHtml(strings[1]) + '</div>'
            }).then(function(modal) {
                modal.show();

                Ajax.call([{
                    methodname: 'local_la_get_column_settings',
                    args: {
                        id: reportid,
                        key: key
                    }
                }])[0].then(function(response) {
                    modal.setTitle(response.title || strings[0]);
                    modal.setBody(response.html || '');
                    modal.getRoot().find('.modal-dialog').removeClass('modal-sm modal-lg modal-xl');
                    modal.getRoot().find('.modal-dialog').addClass('modal-lg');
                    moveModalFooter(modal.getRoot(), '.la-column-settings-footer');

                    modal.getRoot().off('click.laColumnSettingsSave').on(
                        'click.laColumnSettingsSave', SAVE_COLUMN_SETTINGS_SELECTOR, function(event) {
                        var form = modal.getRoot().find('.la-column-settings-form').first();

                        event.preventDefault();

                        saveAndReload(Ajax.call([{
                            methodname: 'local_la_save_column_settings',
                            args: {
                                id: Number(form.find('[name="id"]').val() || 0),
                                key: String(form.find('[name="key"]').val() || ''),
                                column: {
                                    enabled: form.find(
                                        '[data-region="column-enabled"], [name$="[enabled]"]'
                                    ).prop('checked') ? 1 : 0,
                                    name: String(form.find('[data-region="column-name"], [name$="[name]"]').val() || ''),
                                    type: String(form.find('[data-region="column-type"], [name$="[type]"]').val() || 'text'),
                                    formula: String(form.find(
                                        '[data-region="column-formula"], [name$="[formula]"]'
                                    ).val() || ''),
                                    condition: String(form.find(
                                        '[data-region="column-condition"], [name$="[condition]"]'
                                    ).val() || ''),
                                    visible: form.find(
                                        '[data-region="column-visible"], [name$="[visible]"]'
                                    ).prop('checked') ? 1 : 0,
                                    sortable: form.find(
                                        '[data-region="column-sortable"], [name$="[sortable]"]'
                                    ).prop('checked') ? 1 : 0
                                }
                            }
                        }])[0]);
                    });

                    modal.getRoot().off('click.laColumnSettingsCancel').on(
                        'click.laColumnSettingsCancel', CANCEL_COLUMN_SETTINGS_SELECTOR, function(event) {
                        event.preventDefault();
                        modal.hide();
                    });

                    return modal;
                }).catch(function(error) {
                    modal.setBody('<div class="alert alert-danger mb-0">' + escapeHtml(strings[2]) + '</div>');
                    Notification.exception(error);
                });

                return modal;
            });
        }).catch(Notification.exception);
    };

    var bindColumnControls = function() {
        $(document).on('dragstart', COLUMN_LIST_SELECTOR + ' [data-column-key]', function(event) {
            if ($(this).attr('draggable') !== 'true') {
                event.preventDefault();
                return;
            }

            $(this).addClass('is-dragging');
            event.originalEvent.dataTransfer.effectAllowed = 'move';
        });

        $(document).on('dragend', COLUMN_LIST_SELECTOR + ' [data-column-key]', function() {
            $(this).removeClass('is-dragging');
        });

        $(document).on('dragover', COLUMN_LIST_SELECTOR, function(event) {
            var container = this;
            var dragging = $(container).find('.is-dragging').first();
            var siblings;
            var target;

            if (!dragging.length) {
                return;
            }

            event.preventDefault();
            siblings = $(container).find('[data-column-key]').not('.is-dragging');
            target = siblings.filter(function() {
                var rect = this.getBoundingClientRect();
                return event.originalEvent.clientY < rect.top + rect.height / 2;
            }).first();

            if (target.length) {
                dragging.insertBefore(target);
            } else {
                dragging.appendTo(container);
            }
        });

        $(document).on('click', '[data-action="edit-column"]', function(event) {
            var row = $(this).closest(COLUMN_ITEM_SELECTOR);
            var input = row.find('[data-region="column-name-input"]').first();

            event.preventDefault();
            setColumnEditState(row, true);
            input.data('originalValue', input.val());
            input.trigger('focus').trigger('select');
            closeDropdown(this);
        });

        $(document).on('click', '[data-action="column-settings"]', function(event) {
            var row = $(this).closest(COLUMN_ITEM_SELECTOR);
            var form = row.closest('form');
            var reportid = Number(form.find('[name="id"]').val() || 0);
            var key = getColumnKey(row);

            event.preventDefault();
            closeDropdown(this);

            if (!reportid || key === '') {
                return;
            }

            openSettingsModal(reportid, key);
        });

        $(document).on('click', '[data-action="cancel-column-edit"]', function(event) {
            var row = $(this).closest(COLUMN_ITEM_SELECTOR);
            var input = row.find('[data-region="column-name-input"]').first();

            event.preventDefault();
            input.val(String(input.data('originalValue') || input.val() || ''));
            setColumnEditState(row, false);
        });

        $(document).on('click', '[data-action="save-column-edit"]', function(event) {
            var row = $(this).closest(COLUMN_ITEM_SELECTOR);
            var input = row.find('[data-region="column-name-input"]').first();
            var display = row.find('[data-region="column-name-display"]').first();
            var value = $.trim(String(input.val() || ''));

            event.preventDefault();

            if (value === '') {
                input.trigger('focus');
                return;
            }

            input.val(value);
            display.text(value);
            setColumnEditState(row, false);
        });

        $(document).on('keydown', '[data-region="column-name-input"]', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $(this).closest(COLUMN_ITEM_SELECTOR).find('[data-action="save-column-edit"]').trigger('click');
            } else if (event.key === 'Escape') {
                event.preventDefault();
                $(this).closest(COLUMN_ITEM_SELECTOR).find('[data-action="cancel-column-edit"]').trigger('click');
            }
        });

        $(document).on('click', '[data-action="delete-column"]', function(event) {
            var row = $(this).closest(COLUMN_ITEM_SELECTOR);
            var input = row.find('[data-region="column-enabled-input"]').first();

            event.preventDefault();

            if (!row.length || !input.length) {
                return;
            }

            input.val('0');
            row.addClass('d-none');
            closeDropdown(this);
        });

        $(document).on('submit', '.la-report-columns-form', function(event) {
            var form = $(this);

            event.preventDefault();

            saveAndReload(saveStandardColumns(form));
        });

        $(document).on('click', '[data-region="columns-cancel"]', function(event) {
            event.preventDefault();
            closeDropdown(this);
        });

        $(document).on('click', '[data-action="builder-toggle-column"]', function(event) {
            var item = $(this).closest(BUILDER_ITEM_SELECTOR);
            var open = $(this).attr('aria-expanded') === 'true';

            event.preventDefault();
            setBuilderItemState(item, !open);
        });

        $(document).on('click', '[data-action="builder-toggle-comment"]', function(event) {
            var button = $(this);
            var item = button.closest(BUILDER_ITEM_SELECTOR);
            var comment = item.find('[data-region="builder-comment"]').first();
            var expanded = button.attr('aria-expanded') === 'true';

            event.preventDefault();

            if (!comment.length) {
                return;
            }

            button.attr('aria-expanded', expanded ? 'false' : 'true');
            comment.stop(true, true);

            if (expanded) {
                comment.slideUp(120, function() {
                    comment.addClass('d-none');
                });
            } else {
                comment.removeClass('d-none').hide().slideDown(120);
            }
        });

        $(document).on('click', '[data-action="toggle-column-advanced"]', function(event) {
            var button = $(this);
            var advanced = button.closest('[data-region="column-form"]').find('[data-region="column-advanced"]').first();
            var expanded = button.attr('aria-expanded') === 'true';

            event.preventDefault();

            if (!advanced.length) {
                return;
            }

            button.attr('aria-expanded', expanded ? 'false' : 'true');
            advanced.stop(true, true);

            if (expanded) {
                advanced.slideUp(120, function() {
                    advanced.addClass('d-none');
                });
            } else {
                advanced.removeClass('d-none').hide().slideDown(120);
            }
        });

        $(document).on('input', '[data-region="builder-search"]', function() {
            filterBuilderItems(this);
        });

        $(document).on('click', '[data-action="builder-cancel"]', function(event) {
            event.preventDefault();
            closeModal(this);
        });

        $(document).on('click', '[data-action="builder-save"]', function(event) {
            var modal = $(this).closest('.modal');
            var root = modal.find('[data-report-id]').first();
            var reportid = Number(root.data('reportId') || 0);
            var columns = collectBuilderColumns(modal);

            event.preventDefault();

            saveAndReload(Ajax.call([{
                methodname: 'local_la_save_builder',
                args: {
                    id: reportid,
                    columns: columns
                }
            }])[0]);
        });
    };

    return {
        init: function() {
            bindColumnControls();
        }
    };
});
