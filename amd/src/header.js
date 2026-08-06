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
 * JavaScript for the header interface.
 *
 * @module     local_la/header
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/modal', 'core/notification', 'core/str'], function($, Ajax, Modal, Notification, Str) {
    var SEARCH_SCOPE_SELECTOR = '[data-region="la-search-scope"]';
    var SEARCH_FORM_SELECTOR = '[data-region="la-search-form"]';
    var MODAL_FORM_SELECTOR = '[data-region="la-header-search-modal-form"]';
    var COMPANY_NAME_SELECTOR = '[data-region="la-company-name"]';

    var syncCompanyName = function() {
        $(COMPANY_NAME_SELECTOR).each(function() {
            var name = $(this);
            var fullName = String(name.data('fullName') || '');
            var displayName = window.scrollY > 0 && fullName === 'lenArys' ? 'lA' : fullName;

            if (name.text() !== displayName) {
                name.text(displayName);
            }
        });
    };

    var isTextInput = function(element) {
        var tagName = String(element.tagName || '').toLowerCase();

        return tagName === 'input' || tagName === 'textarea' || tagName === 'select' || element.isContentEditable;
    };

    var loadModalResults = function(modal, type, query) {
        return Ajax.call([{
            methodname: 'local_la_header_search',
            args: {
                type: type,
                query: query
            }
        }])[0].then(function(response) {
            var size = response.size || '';

            modal.setTitle(response.title || '');
            modal.setBody(response.html || '');
            if (size) {
                modal.getRoot().find('.modal-dialog').removeClass('modal-s modal-m modal-l modal-xl');
                modal.getRoot().find('.modal-dialog').addClass('modal-' + size);
            }
            modal.getRoot().find('.modal-footer').remove();
            return modal;
        }).catch(Notification.exception);
    };

    var openSearchModal = function(type, query, loading) {
        Modal.create({
            title: '',
            body: '<div class="text-muted">' + loading + '</div>'
        }).then(function(modal) {
            modal.show();
            loadModalResults(modal, type, query);

            modal.getRoot().on('submit', MODAL_FORM_SELECTOR, function(event) {
                event.preventDefault();
                loadModalResults(modal, type, String($(this).find('[name="query"]').val() || ''));
            });

            return modal;
        }).catch(Notification.exception);
    };

    var updateSearchScopeButton = function(input) {
        var radio = $(input);
        var menu = radio.closest('[data-region="la-search-menu"]');
        var button = menu.find('[data-region="la-search-scope-button"]').first();
        var icon = String(radio.data('icon') || 'fa-users');
        var label = String(radio.data('label') || $.trim(radio.closest('.form-check').find('label').text()));

        if (!button.length) {
            return;
        }

        button.find('[data-region="la-search-scope-icon"]').attr('class', 'icon fa ' + icon + ' fa-fw');
        button.find('[data-region="la-search-scope-label"]').text(label);
    };

    var getSystemDarkMode = function() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    };

    var syncDarkMode = function(enabled) {
        $('body').toggleClass('theme-dark', enabled);
    };

    var initDarkMode = function() {
        if ($('link[href*="darkstyles.css"]:not([media])').length) {
            syncDarkMode(true);
            return;
        }

        if (!$('link[media="(prefers-color-scheme: dark)"]').length || !window.matchMedia) {
            return;
        }

        var media = window.matchMedia('(prefers-color-scheme: dark)');
        var listener = function() {
            syncDarkMode(getSystemDarkMode());
        };

        listener();

        if (media.addEventListener) {
            media.addEventListener('change', listener);
        } else if (media.addListener) {
            media.addListener(listener);
        }
    };

    return {
        init: function() {
            initDarkMode();
            syncCompanyName();
            $(window).off('scroll.localLaCompanyName').on('scroll.localLaCompanyName', syncCompanyName);

            $(document).on('change', SEARCH_SCOPE_SELECTOR, function() {
                updateSearchScopeButton(this);
            });

            $(document).on('submit', SEARCH_FORM_SELECTOR, function(event) {
                var form = $(this);
                var selected = form.find(SEARCH_SCOPE_SELECTOR + ':checked');
                var scope = selected.val();
                var query = String(form.find('[name="query"]').val() || '');
                var type = String(selected.data('searchType') || '');

                if (type) {
                    event.preventDefault();
                    Str.get_string('loading', 'local_la').then(function(loading) {
                        openSearchModal(type, query, String(form.data('loading') || loading));
                        return loading;
                    }).catch(Notification.exception);
                    return;
                }

                if (!scope) {
                    return;
                }

                event.preventDefault();
                window.location.href = String(selected.data('appendQuery') || '0') === '1' ?
                    scope + encodeURIComponent(query) :
                    scope;
            });

            $(document).on('keydown', function(event) {
                if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey ||
                        isTextInput(event.target) || $('.modal.show').length) {
                    return;
                }

                event.preventDefault();
                $(SEARCH_FORM_SELECTOR).first().find('[name="query"]').trigger('focus');
            });

            $(SEARCH_SCOPE_SELECTOR + ':checked').each(function() {
                updateSearchScopeButton(this);
            });
        }
    };
});
