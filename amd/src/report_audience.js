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
 * JavaScript for the report audience interface.
 *
 * @module     local_la/report_audience
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/form-autocomplete', 'core/notification'], function($, Ajax, FormAutocomplete, Notification) {
    var AUDIENCE_AUTOCOMPLETE_SELECTOR = '.la-audience-autocomplete';
    var AUDIENCE_CARD_SELECTOR = '[data-region="audience-card"]';
    var AUDIENCE_FORM_CARD_SELECTOR = '[data-region="audience-form-card"]';
    var AUDIENCE_MENU_ITEM_SELECTOR = '.audience-menu-item';
    var AUDIENCE_EMPTY_STATE_SELECTOR = '[data-region="audience-empty-state"]';
    var AUDIENCE_CONTAINER_SELECTOR = '.reportbuilder-audiences-container';

    var setMenuItemDisabled = function(type, disabled) {
        var item = $(AUDIENCE_MENU_ITEM_SELECTOR + '[data-audience-type="' + type + '"]');

        item.toggleClass('disabled text-muted', disabled);

        if (disabled) {
            item.attr('aria-disabled', 'true');
            item.attr('tabindex', '-1');
        } else {
            item.removeAttr('aria-disabled');
            item.removeAttr('tabindex');
        }
    };

    var initAutocomplete = function(select) {
        var field = $(select);
        var source = String(field.data('autocompleteSource') || '');

        if (field.data('autocompleteInitialised')) {
            return;
        }

        field.data('autocompleteInitialised', true);
        FormAutocomplete.enhance(
            field,
            false,
            source || false,
            String(field.data('autocompletePlaceholder') || 'Search'),
            false,
            true,
            '',
            false,
            {
                layout: 'local_la/components/autocomplete/layout',
                items: 'local_la/components/autocomplete/selection_items'
            }
        );
    };

    var updateEmptyState = function() {
        var emptyState = $(AUDIENCE_EMPTY_STATE_SELECTOR);

        if (!emptyState.length) {
            return;
        }

        if ($(AUDIENCE_CARD_SELECTOR + ':not(.d-none), ' + AUDIENCE_FORM_CARD_SELECTOR + ':not(.d-none)').length) {
            emptyState.addClass('d-none');
        } else {
            emptyState.removeClass('d-none');
        }
    };

    var showAudienceForm = function(type) {
        var container = $(AUDIENCE_CONTAINER_SELECTOR).first();
        var form = $(AUDIENCE_FORM_CARD_SELECTOR).filter('[data-audience-type="' + type + '"]').first();
        var card = $(AUDIENCE_CARD_SELECTOR).filter('[data-audience-type="' + type + '"]').first();

        if (!form.length) {
            return;
        }

        setMenuItemDisabled(type, true);

        if (card.length) {
            form.insertBefore(card);
            card.addClass('d-none');
        } else if (container.length) {
            form.appendTo(container);
        }

        form.removeClass('d-none');
        updateEmptyState();
    };

    var hideAudienceForm = function(type) {
        var form = $(AUDIENCE_FORM_CARD_SELECTOR).filter('[data-audience-type="' + type + '"]').first();
        var card = $(AUDIENCE_CARD_SELECTOR).filter('[data-audience-type="' + type + '"]').first();

        form.addClass('d-none');
        card.removeClass('d-none');

        if (!card.length) {
            setMenuItemDisabled(type, false);
        }

        updateEmptyState();
    };

    var deleteAudience = function(button) {
        var type = String(button.data('audienceType') || '');
        var reportid = Number(button.data('reportId') || 0);
        var form = $(AUDIENCE_FORM_CARD_SELECTOR).filter('[data-audience-type="' + type + '"]').first();
        var card = $(AUDIENCE_CARD_SELECTOR).filter('[data-audience-type="' + type + '"]').first();

        if (!type) {
            return;
        }

        if (card.length) {
            Ajax.call([{
                methodname: 'local_la_delete_audience',
                args: {
                    reportid: reportid,
                    type: type
                }
            }])[0].then(function() {
                card.remove();
                form.addClass('d-none');
                setMenuItemDisabled(type, false);
                updateEmptyState();
            }).catch(Notification.exception);
            return;
        }

        form.addClass('d-none');
        setMenuItemDisabled(type, false);
        updateEmptyState();
    };

    var saveAudience = function(form) {
        var audienceform = $(form);
        var type = String(audienceform.find('[name="audience_type"]').val() || '');
        var reportid = Number(audienceform.closest(AUDIENCE_FORM_CARD_SELECTOR).data('reportId') || 0);
        var instanceids = audienceform.find('[name="audience_instanceid[]"]').val() || [];

        Ajax.call([{
            methodname: 'local_la_save_audience',
            args: {
                reportid: reportid,
                type: type,
                instanceids: $.map(instanceids, function(value) {
                    return Number(value || 0);
                })
            }
        }])[0].then(function() {
            window.location.reload();
        }).catch(Notification.exception);
    };

    return {
        init: function() {
            $(AUDIENCE_AUTOCOMPLETE_SELECTOR).each(function() {
                initAutocomplete(this);
            });

            updateEmptyState();

            $(document).on('click', '[data-action="show-audience-form"]', function(event) {
                event.preventDefault();

                if ($(this).hasClass('disabled')) {
                    return;
                }

                showAudienceForm(String($(this).data('audienceType') || ''));
            });

            $(document).on('click', '[data-action="cancel-audience-form"]', function(event) {
                event.preventDefault();
                hideAudienceForm(String($(this).closest(AUDIENCE_FORM_CARD_SELECTOR).data('audienceType') || ''));
            });

            $(document).on('click', '[data-action="delete-audience"]', function(event) {
                event.preventDefault();
                deleteAudience($(this));
            });

            $(document).on('submit', AUDIENCE_FORM_CARD_SELECTOR + ' form', function(event) {
                event.preventDefault();
                saveAudience(this);
            });
        }
    };
});
