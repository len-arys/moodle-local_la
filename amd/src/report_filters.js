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
 * JavaScript for the report filters interface.
 *
 * @module     local_la/report_filters
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/form-autocomplete'], function($, Ajax, Notification, FormAutocomplete) {
    var FILTER_OPERATOR_SELECTOR = '[data-region="filter-operator"]';
    var USER_AUTOCOMPLETE_SELECTOR = '.la-report-user-autocomplete';
    var MULTISELECT_SELECTOR = '[data-region="multiselect"]';
    var SYNTHETIC_FORM_SELECTOR = '[data-region="synthetic-form"]';
    var SEARCH_DEFAULT_SELECTOR = '[data-action="set-search-default"]';
    var FILTER_METHODS = {
        courses: 'local_la_get_courses'
    };

    var syncAllFilterStates = function(context) {
        $(context || document).find(FILTER_OPERATOR_SELECTOR).each(function() {
            toggleFilterInput(this);
        });
    };

    var clearUserAutocompleteSelection = function(container) {
        var field = $(container).find(USER_AUTOCOMPLETE_SELECTOR).first();

        if (!field.length) {
            return;
        }

        field.find('option:selected').prop('selected', false);
        field.trigger('change');

        var input = field.closest('.la-report-users-autocomplete-wrap').find('.form-autocomplete-input .form-control').first();
        if (input.length) {
            input.val('');
        }
    };

    var getSelectedCountLabel = function(picker, count) {
        var emptylabel = String($(picker).data('emptyLabel') || $(picker).attr('data-empty-label') || '');
        var selectedsuffix = String($(picker).data('selectedSuffix') || $(picker).attr('data-selected-suffix') || '');

        return count ? count + ' ' + selectedsuffix : emptylabel;
    };

    var renderMultiselectSelection = function(picker) {
        var select = $(picker).find('[data-region="multiselect-select"]').first();
        var label = $(picker).find('[data-region="multiselect-label"]').first();
        var selected = select.find('option:selected');
        label.text(getSelectedCountLabel(picker, selected.length));
    };

    var setMultiselectLoadingLabel = function(picker) {
        var label = $(picker).find('[data-region="multiselect-label"]').first();
        var loadinglabel = String($(picker).data('loadingLabel') || $(picker).attr('data-loading-label') || '');

        if (!label.length || loadinglabel === '') {
            return;
        }

        label.text(loadinglabel);
    };

    var syncMultiselectChecks = function(picker) {
        var select = $(picker).find('[data-region="multiselect-select"]').first();
        var selected = {};

        select.find('option:selected').each(function() {
            selected[String($(this).val())] = true;
        });

        $(picker).find('[data-region="multiselect-checkbox"]').each(function() {
            $(this).prop('checked', !!selected[String($(this).val())]);
        });

        $(picker).find('[data-region="multiselect-group-checkbox"]').each(function() {
            var group = $(this).closest('.la-report-multiselect-group');
            var children = group.find('[data-region="multiselect-checkbox"]');
            var checkedcount = children.filter(':checked').length;
            var checked = children.length > 0 && checkedcount === children.length;
            var partial = checkedcount > 0 && checkedcount < children.length;

            $(this).prop('checked', checked);
            $(this).prop('indeterminate', partial);
        });
    };

    var renderMultiselectOptions = function(picker, groups) {
        var optionscontainer = $(picker).find('[data-region="multiselect-options"]').first();
        var html = '';

        $.each(groups || [], function(index, group) {
            html += '<div class="la-report-multiselect-group">';
            html += '<label class="la-report-multiselect-group-label">';
            html += '<input type="checkbox" data-region="multiselect-group-checkbox">';
            html += '<span title="' + $('<div>').text(group.label || '').html() + '">' + $('<div>').text(group.label || '').html() + '</span>';
            html += '</label>';
            html += '<button type="button" class="la-report-multiselect-group-toggle" data-region="multiselect-group-toggle" aria-expanded="true" aria-label="Toggle category">';
            html += '<span class="la-report-multiselect-group-toggle-icon" aria-hidden="true"></span>';
            html += '</button>';
            html += '<div class="la-report-multiselect-group-items" data-region="multiselect-group-items">';

            $.each(group.options || [], function(optionindex, option) {
                html += '<label class="la-report-multiselect-option">';
                html += '<input type="checkbox" value="' + $('<div>').text(option.value || '').html() + '" data-region="multiselect-checkbox">';
                html += '<span title="' + $('<div>').text(option.name || '').html() + '">' + $('<div>').text(option.name || '').html() + '</span>';
                html += '</label>';
            });

            html += '</div>';
            html += '</div>';
        });

        optionscontainer.html(html);
        syncMultiselectChecks(picker);
    };

    var loadMultiselectOptions = function(picker) {
        var container = $(picker);
        var reportid = Number(container.data('reportId') || container.attr('data-report-id') || 0);

        if (!reportid) {
            return $.Deferred().resolve().promise();
        }

        if (container.data('optionsLoaded')) {
            return $.Deferred().resolve(container.data('optionGroups') || []).promise();
        }

        if (container.data('optionsLoading')) {
            return container.data('optionsLoading');
        }

        var request = Ajax.call([{
            methodname: FILTER_METHODS.courses,
            args: {
                reportid: reportid
            }
        }])[0].then(function(response) {
            var groups = response.groups || [];
            container.data('optionGroups', groups);
            container.data('optionsLoaded', true);
            renderMultiselectOptions(container, groups);
            renderMultiselectSelection(container);
            return groups;
        }).catch(Notification.exception).always(function() {
            container.removeData('optionsLoading');
            renderMultiselectSelection(container);
        });

        container.data('optionsLoading', request);

        return request;
    };

    var filterMultiselectOptions = function(picker, query) {
        var normalised = String(query || '').toLowerCase();

        $(picker).find('.la-report-multiselect-option').each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggleClass('d-none', normalised !== '' && text.indexOf(normalised) === -1);
        });

        $(picker).find('.la-report-multiselect-group').each(function() {
            var hasvisible = $(this).find('.la-report-multiselect-option:not(.d-none)').length > 0;
            $(this).toggleClass('d-none', !hasvisible);
        });
    };

    var getVisibleMultiselectCheckboxes = function(context) {
        return $(context).find('.la-report-multiselect-option').not('.d-none').find('[data-region="multiselect-checkbox"]');
    };

    var clearMultiselectSelection = function(container) {
        var picker = $(container).find(MULTISELECT_SELECTOR).first();
        var select = picker.find('[data-region="multiselect-select"]').first();

        if (!picker.length || !select.length) {
            return;
        }

        select.find('option:selected').prop('selected', false);
        syncMultiselectChecks(picker);
        renderMultiselectSelection(picker);
    };

    var toggleMultiselectState = function(container, enabled) {
        var picker = $(container).find(MULTISELECT_SELECTOR).first();
        var trigger = picker.find('[data-region="multiselect-trigger"]').first();
        var search = picker.find('[data-region="multiselect-search"]').first();
        var select = picker.find('[data-region="multiselect-select"]').first();

        if (!picker.length) {
            return;
        }

        picker.toggleClass('is-disabled', !enabled);
        trigger.prop('disabled', !enabled);
        search.prop('disabled', !enabled);
        select.prop('disabled', !enabled);

        if (!enabled) {
            picker.find('[data-region="multiselect-menu"]').addClass('d-none');
        }
    };

    var toggleUserAutocompleteState = function(container, enabled) {
        var wrapper = $(container).find('.la-report-users-autocomplete-wrap').first();
        var input = wrapper.find('.form-autocomplete-input .form-control').first();
        var downarrow = wrapper.find('.form-autocomplete-downarrow').first();

        if (!wrapper.length) {
            return;
        }

        wrapper.toggleClass('is-disabled', !enabled);

        if (input.length) {
            input.prop('disabled', !enabled);
            input.prop('readonly', !enabled);
            input.attr('aria-disabled', enabled ? 'false' : 'true');
        }

        if (downarrow.length) {
            downarrow.attr('aria-disabled', enabled ? 'false' : 'true');
            downarrow.attr('tabindex', enabled ? '0' : '-1');
        }
    };

    var toggleFilterInput = function(select) {
        var operator = String($(select).val() || 'any');
        var container = $(select).closest('.filter');
        var value = container.find('[data-region="filter-value"]').first();
        var range = container.find('[data-region="filter-range"]').first();
        var valueinputs = value.find('input, select, textarea');
        var showvalue = ['any', 'empty', 'notempty', 'range'].indexOf(operator) === -1;

        if (!showvalue) {
            clearUserAutocompleteSelection(container);
            clearMultiselectSelection(container);
        }

        valueinputs.prop('disabled', !showvalue);
        toggleUserAutocompleteState(container, showvalue);
        toggleMultiselectState(container, showvalue);
        range.toggleClass('d-none', operator !== 'range');
    };

    var initUserAutocomplete = function(select) {
        var field = $(select);

        if (field.data('autocompleteInitialised')) {
            return;
        }

        field.data('autocompleteInitialised', true);
        $.when(FormAutocomplete.enhance(
            field,
            false,
            'core_user/form_user_selector',
            String(field.data('autocompletePlaceholder') || 'Search users'),
            false,
            true,
            String(field.data('noSelectionString') || ''),
            false,
            {
                layout: 'local_la/components/autocomplete/layout',
                items: 'local_la/components/autocomplete/selection_items'
            }
        )).done(function() {
            syncAllFilterStates(field.closest('.filter'));
        });
    };

    var bindMultiselects = function() {
        $(MULTISELECT_SELECTOR).each(function() {
            renderMultiselectSelection(this);
        });

        $(document).on('click', '[data-region="multiselect-trigger"]', function(e) {
            var picker = $(this).closest(MULTISELECT_SELECTOR);
            var menu = picker.find('[data-region="multiselect-menu"]').first();

            if ($(this).prop('disabled')) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            $(MULTISELECT_SELECTOR).not(picker).find('[data-region="multiselect-menu"]').addClass('d-none');

            if (menu.hasClass('d-none')) {
                if (!picker.data('optionsLoaded')) {
                    setMultiselectLoadingLabel(picker);
                }
                loadMultiselectOptions(picker).then(function() {
                    syncMultiselectChecks(picker);
                    menu.removeClass('d-none');
                });
            } else {
                menu.addClass('d-none');
            }
        });

        $(document).on('input', '[data-region="multiselect-search"]', function() {
            filterMultiselectOptions($(this).closest(MULTISELECT_SELECTOR), $(this).val());
        });

        $(document).on('change', '[data-region="multiselect-group-checkbox"]', function() {
            var group = $(this).closest('.la-report-multiselect-group');
            var checked = $(this).prop('checked');
            var children = getVisibleMultiselectCheckboxes(group);

            $(this).prop('indeterminate', false);

            children.each(function() {
                $(this).prop('checked', checked).trigger('change');
            });
        });

        $(document).on('click', '[data-region="multiselect-group-toggle"]', function(e) {
            var toggle = $(this);
            var group = toggle.closest('.la-report-multiselect-group');
            var items = group.find('[data-region="multiselect-group-items"]').first();
            var expanded = toggle.attr('aria-expanded') === 'true';

            e.preventDefault();
            e.stopPropagation();

            toggle.attr('aria-expanded', expanded ? 'false' : 'true');
            items.toggleClass('d-none', expanded);
        });

        $(document).on('change', '[data-region="multiselect-checkbox"]', function() {
            var picker = $(this).closest(MULTISELECT_SELECTOR);
            var select = picker.find('[data-region="multiselect-select"]').first();
            var value = String($(this).val() || '');
            var selected = $(this).prop('checked');
            var option = select.find('option').filter(function() {
                return String($(this).val()) === value;
            }).first();

            if (!option.length) {
                option = $('<option>').val(value).text($(this).siblings('span').text()).appendTo(select);
            }

            option.prop('selected', selected);
            syncMultiselectChecks(picker);
            renderMultiselectSelection(picker);
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest(MULTISELECT_SELECTOR).length) {
                $(MULTISELECT_SELECTOR).find('[data-region="multiselect-menu"]').addClass('d-none');
            }
        });
    };

    var bindReportFilters = function() {
        $(USER_AUTOCOMPLETE_SELECTOR).each(function() {
            initUserAutocomplete(this);
        });

        bindMultiselects();

        syncAllFilterStates(document);

        $(document).on('change', FILTER_OPERATOR_SELECTOR, function() {
            toggleFilterInput(this);
        });

        $(document).on('click', SEARCH_DEFAULT_SELECTOR, function(event) {
            var button = $(this);

            event.preventDefault();

            Ajax.call([{
                methodname: 'local_la_save_search_default',
                args: {
                    id: Number(button.data('reportid') || 0),
                    key: String(button.data('key') || '')
                }
            }])[0].then(function() {
                window.location.reload();
                return true;
            }).catch(Notification.exception);
        });
    };

    var syncSyntheticCustomFields = function(form) {
        var selected = $(form).find('[data-region="synthetic-preset"]:checked').val();
        $(form).find('[data-region="synthetic-custom-fields"]').toggleClass('d-none', selected !== 'custom');
    };

    var syncSyntheticMetric = function(form) {
        $(form).find('.la-report-synthetic-metric-option').each(function() {
            $(this).toggleClass('active', $(this).find('input:checked').length > 0);
        });
    };

    var bindSyntheticControls = function() {
        $(SYNTHETIC_FORM_SELECTOR).each(function() {
            syncSyntheticCustomFields(this);
            syncSyntheticMetric(this);
        });

        $(document).on('change', '[data-region="synthetic-preset"]', function() {
            syncSyntheticCustomFields($(this).closest(SYNTHETIC_FORM_SELECTOR));
        });

        $(document).on('change', '.la-report-synthetic-metric-option input', function() {
            syncSyntheticMetric($(this).closest(SYNTHETIC_FORM_SELECTOR));
        });

        $(document).on('click', '[data-region="synthetic-cancel"]', function(e) {
            e.preventDefault();
            $(this).closest('.dropdown-menu').removeClass('show');
            $(this).closest('.dropdown').find('[data-toggle="dropdown"]').attr('aria-expanded', 'false').removeClass('show');
        });

    };

    var applySyntheticHeatmap = function(context) {
        $(context || document).find('table tbody tr').each(function() {
            var cells = $(this).find('[data-synthetic-value]');
            var max = 0;

            cells.each(function() {
                max = Math.max(max, Number($(this).attr('data-synthetic-value') || 0));
            });

            cells.each(function() {
                var marker = $(this);
                var cell = marker.closest('td, th');
                var value = Number(marker.attr('data-synthetic-value') || 0);
                var ratio = max > 0 ? (value / max) : 0;
                var alpha = ratio > 0 ? (0.14 + (ratio * 0.78)) : 0;

                cell.removeClass('la-synthetic-cell la-synthetic-cell-strong');
                cell.css('--la-synthetic-alpha', '');

                if (value <= 0) {
                    return;
                }

                cell.addClass('la-synthetic-cell');
                cell.css('--la-synthetic-alpha', String(Math.min(alpha, 0.92)));

                if (ratio >= 0.78) {
                    cell.addClass('la-synthetic-cell-strong');
                }
            });
        });
    };

    return {
        init: function() {
            $(document).on('click', '.la-report-filters-dropdown, .la-report-synthetic-dropdown', function(event) {
                event.stopPropagation();
            });
            bindReportFilters();
            bindSyntheticControls();
            applySyntheticHeatmap(document);
        }
    };
});
