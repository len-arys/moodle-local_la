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
 * JavaScript for the home interface.
 *
 * @module     local_la/home
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/config',
    'core/ajax',
    'local_la/library_actions'
], function($, Config, Ajax, LibraryActions) {
    const init = function() {
        const form = document.getElementById('la-ai-report-form');
        const input = document.getElementById('la-ai-report-prompt');
        const messages = document.getElementById('la-ai-report-messages');
        const spacer = document.getElementById('la-ai-report-spacer');
        const chat = document.getElementById('la-ai-report-chat');
        const intro = document.getElementById('la-ai-report-container');
        const historybutton = document.getElementById('la-ai-report-history');
        const waveswrap = document.getElementById('la-ai-report-waves-wrap');
        const waves = document.getElementById('la-ai-report-waves');
        const scrollbutton = document.getElementById('la-ai-scroll-latest');
        const clearhistory = document.getElementById('la-ai-clear-history');
        const contextchips = document.getElementById('la-ai-context-chips');
        const placeholderdots = document.getElementById('la-ai-prompt-dots');

        const initLaWaves = function(canvas) {
            if (!canvas) {
                return;
            }

            const context = canvas.getContext('2d');
            if (!context) {
                return;
            }

            const height = 150;
            const reducedmotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const waveconfigs = [
                {amplitude: 68, wavelength: 100, speed: 0.00035, phase: 0, width: 3, opacity: 1},
                {amplitude: 64, wavelength: 48, speed: -0.00030, phase: 1.2, width: 2, opacity: 0.9},
                {amplitude: 58, wavelength: 27, speed: 0.00045, phase: 2.4, width: 1, opacity: 0.75},
                {amplitude: 46, wavelength: 68, speed: -0.00038, phase: 3.6, width: 0.75, opacity: 0.65},
            ];
            let width = 0;
            let gradient;

            const resize = function() {
                const devicepixelratio = window.devicePixelRatio || 1;
                width = Math.min(600, canvas.parentElement ? canvas.parentElement.clientWidth : 600);
                canvas.width = Math.round(width * devicepixelratio);
                canvas.height = Math.round(height * devicepixelratio);
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';
                context.setTransform(devicepixelratio, 0, 0, devicepixelratio, 0, 0);

                gradient = context.createLinearGradient(0, 0, width, 0);
                gradient.addColorStop(0, 'rgba(63, 109, 249, 0.5)');
                gradient.addColorStop(0.5, 'rgba(255, 193, 7, 0.5)');
                gradient.addColorStop(1, 'rgba(63, 109, 249, 0.5)');
            };

            const draw = function(time) {
                context.clearRect(0, 0, width, height);

                waveconfigs.forEach(function(wave) {
                    context.beginPath();
                    for (let x = 0; x <= width; x += 5) {
                        const fade = Math.pow(Math.sin(Math.PI * x / width), 2);
                        const y = height / 2 + Math.sin(x / wave.wavelength + time * wave.speed + wave.phase) *
                            wave.amplitude * fade;

                        if (x === 0) {
                            context.moveTo(x, y);
                        } else {
                            context.lineTo(x, y);
                        }
                    }

                    context.globalAlpha = wave.opacity;
                    context.lineWidth = wave.width;
                    context.strokeStyle = gradient;
                    context.stroke();
                });

                context.globalAlpha = 1;
                if (!reducedmotion && canvas.isConnected && canvas.parentElement &&
                        !canvas.parentElement.classList.contains('d-none')) {
                    window.requestAnimationFrame(draw);
                }
            };

            resize();
            window.addEventListener('resize', resize);
            draw(0);
        };

        initLaWaves(waves);

        if (!form || !input) {
            return;
        }

        form.addEventListener('click', function(event) {
            if (event.target.closest('.la-home-context-menu')) {
                event.stopPropagation();
            }
        });

        const sendbutton = form.querySelector('.la-home-prompt-send');
        const sendbuttonlabel = sendbutton ? sendbutton.getAttribute('aria-label') : '';
        const placeholders = [
            form.getAttribute('data-placeholder-1'),
            form.getAttribute('data-placeholder-2'),
            form.getAttribute('data-placeholder-3'),
        ].filter(Boolean);
        let placeholderindex = 0;
        let pending = false;
        let request = null;
        let loadingmessage = null;

        const openInstallReview = function(definition, title) {
            if (!definition) {
                return;
            }

            LibraryActions.openGeneratedInstallReview(definition, title || '');
        };

        const appendInstallButton = function(message, definition, title, viewurl) {
            if (!message || !definition) {
                return;
            }

            const wrap = document.createElement('div');
            wrap.className = 'la-ai-report-review-card mt-2 mb-2';

            const icon = document.createElement('div');
            icon.className = 'la-ai-report-review-icon';
            icon.insertAdjacentHTML('beforeend', '<i class="fa-regular fa-file-lines" aria-hidden="true"></i>');
            wrap.appendChild(icon);

            const text = document.createElement('div');
            text.className = 'la-ai-report-review-text';
            const name = document.createElement('strong');
            name.textContent = title || form.dataset.successText;
            text.appendChild(name);
            const description = document.createElement('div');
            description.textContent = form.dataset.reportDescription;
            text.appendChild(description);
            wrap.appendChild(text);

            const button = document.createElement(viewurl ? 'a' : 'button');
            if (!viewurl) {
                button.type = 'button';
            } else {
                button.href = viewurl;
            }
            button.className = 'btn btn-outline-secondary btn-sm ms-auto';
            button.textContent = viewurl ? form.dataset.viewText : form.dataset.reviewText;
            if (!viewurl) {
                button.addEventListener('click', function() {
                    openInstallReview(definition, title);
                });
            }
            wrap.appendChild(button);

            const time = Array.from(message.children).find(function(child) {
                return child.classList.contains('la-ai-report-message-time');
            });
            message.insertBefore(wrap, time || null);
        };

        const updatePlaceholderDots = function() {
            if (placeholderdots) {
                placeholderdots.classList.toggle('d-none', input.value.trim() !== '');
            }
        };

        const setPlaceholder = function(index) {
            if (!placeholders.length) {
                return;
            }

            placeholderindex = index % placeholders.length;
            if (!input.value.trim()) {
                input.setAttribute('placeholder', placeholders[placeholderindex]);
            }

            if (placeholderdots) {
                placeholderdots.querySelectorAll('span').forEach(function(dot, dotindex) {
                    dot.classList.toggle('active', dotindex === placeholderindex);
                });
            }

            updatePlaceholderDots();
        };

        setPlaceholder(0);
        window.setInterval(function() {
            setPlaceholder(placeholderindex + 1);
        }, 10000);

        if (placeholderdots) {
            placeholderdots.addEventListener('click', function() {
                setPlaceholder(placeholderindex + 1);
            });
        }

        const isChatVisible = function() {
            return chat && !chat.classList.contains('d-none');
        };

        const updateScrollButton = function() {
            if (!scrollbutton) {
                return;
            }

            const nearbottom = window.innerHeight + window.scrollY >= document.body.scrollHeight - 260;
            scrollbutton.classList.toggle('d-none', !isChatVisible() || nearbottom);
        };

        const scrollMessages = function(position) {
            if (!messages) {
                return;
            }

            const lastmessage = messages.lastElementChild;
            if (!lastmessage) {
                return;
            }

            window.setTimeout(function() {
                if (position === 'start') {
                    lastmessage.scrollIntoView({block: 'start'});
                    return;
                }

                (spacer || lastmessage).scrollIntoView({block: 'end'});
                updateScrollButton();
            }, 0);
        };

        const showChat = function(position) {
            if (chat) {
                chat.classList.remove('d-none');
            }

            if (intro) {
                intro.classList.add('d-none');
            }

            if (waveswrap) {
                waveswrap.classList.add('d-none');
            }

            if (position) {
                scrollMessages(position);
            }

            updateScrollButton();
        };

        const setPending = function(pending) {
            form.classList.toggle('is-pending', pending);
            form.setAttribute('aria-busy', pending ? 'true' : 'false');
            input.disabled = pending;
            if (sendbutton) {
                sendbutton.setAttribute('aria-label', pending ? form.dataset.stopText : sendbuttonlabel);
            }
        };

        const getSelectedReport = function() {
            const selected = form.querySelector('input[name="la_ai_report_context"]:checked');
            if (!selected) {
                return null;
            }

            return {
                id: selected.value,
                name: selected.closest('.la-home-context-option').querySelector('span').textContent.trim(),
            };
        };

        const getSelectedTables = function() {
            return Array.from(form.querySelectorAll('input[name="la_ai_table_context[]"]:checked')).map(function(selected) {
                return selected.value;
            });
        };

        const getTableMode = function() {
            const selected = form.querySelector('input[name="la_ai_table_mode"]:checked');
            return selected && selected.value === 'only' ? 'only' : 'context';
        };

        const getPromptContext = function() {
            const report = getSelectedReport();
            const tables = getSelectedTables();

            return {
                report: report,
                tables: tables,
                tablemode: getTableMode(),
            };
        };

        const uncheckTable = function(tablename) {
            form.querySelectorAll('input[name="la_ai_table_context[]"]').forEach(function(input) {
                if (input.value === tablename) {
                    input.checked = false;
                }
            });
        };

        const createRemoveButton = function(label) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'la-home-context-chip-remove';
            button.setAttribute('aria-label', label);
            button.innerHTML = '&times;';
            return button;
        };

        const renderContextChips = function() {
            if (!contextchips) {
                return;
            }

            contextchips.innerHTML = '';

            const report = getSelectedReport();
            if (report) {
                const chip = document.createElement('span');
                chip.className = 'la-home-context-chip';
                chip.insertAdjacentHTML('beforeend', '<i class="fa-solid fa-bars" aria-hidden="true"></i>');
                const label = document.createElement('span');
                label.className = 'la-home-context-chip-label';
                label.textContent = report.name;
                chip.appendChild(label);

                const remove = createRemoveButton(form.dataset.removeReportText);
                remove.addEventListener('click', function() {
                    const selected = form.querySelector('input[name="la_ai_report_context"]:checked');
                    if (selected) {
                        selected.checked = false;
                    }
                    renderContextChips();
                });
                chip.appendChild(remove);
                contextchips.appendChild(chip);
            }

            const tables = getSelectedTables();
            if (tables.length) {
                const wrap = document.createElement('div');
                wrap.className = 'dropup';

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'la-home-context-chip';
                button.setAttribute('data-toggle', 'dropdown');
                button.setAttribute('aria-expanded', 'false');
                const label = document.createElement('span');
                label.className = 'la-home-context-chip-label';
                label.textContent = tables.length + ' ' +
                    (tables.length === 1 ? form.dataset.tableText : form.dataset.tablesText) +
                    (getTableMode() === 'only' ? ' ' + form.dataset.onlyText : '');
                button.appendChild(label);
                button.insertAdjacentHTML('beforeend', '<i class="fa fa-chevron-down ms-1" aria-hidden="true"></i>');
                wrap.appendChild(button);

                const menu = document.createElement('div');
                menu.className = 'dropdown-menu la-home-context-menu la-home-selected-tables-menu';
                tables.forEach(function(tablename) {
                    const item = document.createElement('div');
                    item.className = 'dropdown-item la-home-context-selected-item';

                    const label = document.createElement('span');
                    label.textContent = tablename;
                    item.appendChild(label);

                    const remove = createRemoveButton(form.dataset.removeTableText);
                    remove.addEventListener('click', function(event) {
                        event.stopPropagation();
                        uncheckTable(tablename);
                        renderContextChips();
                    });
                    item.appendChild(remove);
                    menu.appendChild(item);
                });
                wrap.appendChild(menu);
                contextchips.appendChild(wrap);
            }
        };

        const getTime = function() {
            return new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        };

        const appendMessage = function(type, text, showtime) {
            if (!messages || !text) {
                return null;
            }

            const message = document.createElement('div');
            message.className = 'la-ai-report-message la-ai-report-message-' + type;
            const content = document.createElement('div');
            addLinkedText(content, text);
            message.appendChild(content);

            if (showtime) {
                const time = document.createElement('div');
                time.className = 'la-ai-report-message-time';
                time.textContent = getTime();
                message.appendChild(time);
            }

            messages.appendChild(message);
            scrollMessages('end');

            return message;
        };

        const appendContext = function(context) {
            if (!messages || (!context.report && !context.tables.length)) {
                return;
            }

            const addItem = function(name, type, icon) {
                const item = document.createElement('div');
                item.className = 'la-ai-report-context-item';

                const iconwrap = document.createElement('div');
                iconwrap.className = 'la-ai-report-context-icon';
                iconwrap.insertAdjacentHTML('beforeend', '<i class="fa ' + icon + '" aria-hidden="true"></i>');
                item.appendChild(iconwrap);

                const text = document.createElement('div');
                const title = document.createElement('strong');
                title.textContent = name;
                text.appendChild(title);
                text.appendChild(document.createElement('div')).textContent = type;
                item.appendChild(text);

                messages.appendChild(item);
            };

            if (context.report) {
                addItem(context.report.name, form.dataset.reportText, 'fa-solid fa-bars');
            }

            context.tables.forEach(function(table) {
                addItem(table, context.tablemode === 'only' ? form.dataset.tableOnlyText : form.dataset.tableText,
                    'fa-solid fa-table-cells-large');
            });

            scrollMessages('end');
        };

        const addLinkedText = function(node, text) {
            const parts = String(text).split(/(https?:\/\/[^\s<]+)/g);
            parts.forEach(function(part) {
                if (/^https?:\/\//.test(part)) {
                    const link = document.createElement('a');
                    link.href = part;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = part;
                    node.appendChild(link);
                    return;
                }

                node.appendChild(document.createTextNode(part));
            });
        };

        const setMessage = function(message, text, showtime) {
            if (!message) {
                return;
            }

            message.innerHTML = '';
            addLinkedText(message.appendChild(document.createElement('div')), text);

            if (showtime) {
                message.appendChild(document.createElement('div')).className = 'la-ai-report-message-time';
                message.lastChild.textContent = getTime();
            }

            scrollMessages('end');
        };

        const finishRequest = function() {
            request = null;
            loadingmessage = null;
            pending = false;
            setPending(false);
            input.focus();
        };

        const cancelRequest = function() {
            if (request) {
                request.abort();
            }

            if (loadingmessage) {
                setMessage(loadingmessage, form.dataset.stoppedText, true);
                loadingmessage.classList.remove('la-ai-report-message-loading');
                loadingmessage.classList.add('la-ai-report-message-assistant');
            }

            finishRequest();
        };

        const submitPrompt = function() {
            const prompt = input.value.trim();

            if (pending) {
                cancelRequest();
                return;
            }

            if (!prompt) {
                input.focus();
                return;
            }

            const context = getPromptContext();
            const emptyhistory = document.getElementById('la-ai-report-empty-history');
            if (emptyhistory) {
                emptyhistory.remove();
            }
            showChat();
            pending = true;
            appendContext(context);
            appendMessage('user', prompt);
            input.value = '';
            loadingmessage = appendMessage('loading', form.dataset.loadingText);
            setPending(true);

            request = $.ajax(Config.wwwroot + '/lib/ajax/service.php?sesskey=' + Config.sesskey + '&info=local_la_generate_report', {
                type: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                processData: false,
                data: JSON.stringify([{
                    index: 0,
                    methodname: 'local_la_generate_report',
                    args: {
                        prompt: prompt,
                        reportid: context.report ? parseInt(context.report.id, 10) : 0,
                        tables: context.tables,
                        tablemode: context.tablemode,
                    },
                }]),
            }).done(function(responses) {
                if (responses && responses.error) {
                    if (loadingmessage) {
                        loadingmessage.classList.remove('la-ai-report-message-loading');
                        loadingmessage.classList.add('la-ai-report-message-assistant');
                        setMessage(loadingmessage,
                            (responses.exception && responses.exception.message) || form.dataset.errorText, true);
                    }
                    return;
                }

                const response = responses && responses[0] ? responses[0] : {};
                const data = response.data || {};

                if (response.error) {
                    if (loadingmessage) {
                        loadingmessage.classList.remove('la-ai-report-message-loading');
                        loadingmessage.classList.add('la-ai-report-message-assistant');
                        setMessage(loadingmessage,
                            (response.exception && response.exception.message) || form.dataset.errorText, true);
                    }
                    return;
                }

                if (loadingmessage) {
                    loadingmessage.classList.remove('la-ai-report-message-loading');
                    loadingmessage.classList.add('la-ai-report-message-assistant');
                    setMessage(loadingmessage, data.message || form.dataset.successText, true);
                    if (data.installable && data.definition) {
                        appendInstallButton(loadingmessage, data.definition, data.title, data.viewurl || '');
                    }
                }
            }).fail(function(xhr, status, error) {
                if (status === 'abort') {
                    return;
                }

                if (loadingmessage) {
                    loadingmessage.classList.remove('la-ai-report-message-loading');
                    loadingmessage.classList.add('la-ai-report-message-assistant');
                    setMessage(loadingmessage, error || form.dataset.errorText, true);
                }
            }).always(function(xhr, status) {
                if (status !== 'abort') {
                    finishRequest();
                }
            });
        };

        document.addEventListener('click', function(event) {
            const button = event.target.closest('[data-action="ai-install-report"]');
            if (!button) {
                return;
            }

            event.preventDefault();
            openInstallReview(window.atob(button.getAttribute('data-definition') || ''), button.getAttribute('data-title') || '');
        });

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            submitPrompt();
        });

        if (historybutton) {
            historybutton.addEventListener('click', function() {
                showChat('start');
            });
        }

        if (scrollbutton) {
            scrollbutton.addEventListener('click', function() {
                scrollMessages('end');
            });
        }

        if (clearhistory) {
            clearhistory.addEventListener('click', function() {
                clearhistory.disabled = true;
                Ajax.call([{
                    methodname: 'local_la_clear_ai_history',
                    args: {},
                }])[0].then(function() {
                    if (messages) {
                        messages.innerHTML = '';
                    }
                    updateScrollButton();
                    clearhistory.disabled = false;
                    return true;
                }).catch(function() {
                    clearhistory.disabled = false;
                });
            });
        }

        form.querySelectorAll('input[name="la_ai_report_context"], input[name="la_ai_table_context[]"], input[name="la_ai_table_mode"]').forEach(function(input) {
            input.addEventListener('change', renderContextChips);
        });
        renderContextChips();

        window.addEventListener('scroll', updateScrollButton);

        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                submitPrompt();
            }
        });

        input.addEventListener('input', updatePlaceholderDots);
        updatePlaceholderDots();

    };

    return {init: init};
});
