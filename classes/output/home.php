<?php
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

namespace local_la\output;

defined('MOODLE_INTERNAL') || die();

use renderer_base;
use renderable;
use templatable;
use local_la\local\helper;
use local_la\local\repository;
use local_la\local\validator;
use local_la\local\url;

/**
 * Home page renderable.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class home implements renderable, templatable {
    /**
     * Export template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB, $PAGE, $USER;

        $reports = repository::get_user_reports(3);

        foreach ($reports as $report) {
            $report->url = url::report((int) $report->id);
        }

        $promptreports = array_values(array_map(static fn($report) => [
            'id' => (int) $report['id'],
            'name' => (string) $report['name'],
        ], repository::get_all_reports()));
        $tables = array_filter($DB->get_tables(), static fn($table) => validator::validate_table($table));
        sort($tables);
        $defaulttables = self::get_default_tables($tables);
        $prompttables = array_map(static fn($table) => [
            'name' => $table,
            'selected' => in_array($table, $defaulttables, true),
        ], $tables);

        $history = array_reverse($DB->get_records('local_la_ai', ['userid' => $USER->id], 'timecreated DESC', '*', 0, 50));
        $messages = [];

        foreach ($history as $item) {
            $messages[] = [
                'text' => (string) $item->prompt,
                'class' => 'la-ai-report-message-user',
                'contextitems' => self::get_context_items((string) ($item->context ?? '')),
            ];
            $response = (string) $item->response;
            $messages[] = [
                'text' => $response,
                'html' => self::link_urls($response),
                'class' => 'la-ai-report-message-assistant',
                'time' => userdate((int) $item->timecreated, get_string('strftimetime', 'langconfig')),
                'installable' => $item->status === 'success' && !empty($item->definition),
                'definition' => base64_encode((string) ($item->definition ?? '')),
                'title' => self::get_definition_title((string) ($item->definition ?? '')),
                'viewurl' => self::get_definition_report_url((string) ($item->definition ?? '')),
            ];
        }

        if (empty($messages)) {
            $messages[] = [
                'text' => get_string('nochathistory', 'local_la'),
                'class' => 'la-ai-report-message-assistant',
                'emptyhistory' => true,
            ];
        }

        $showagent = helper::is_billing_admin((int) $USER->id) && empty(get_user_preferences('local_la_agent_done', 0));
        if ($showagent) {
            $PAGE->requires->js_call_amd('local_la/agent', 'init');
        }

        return [
            'header' => $output->render_from_template('local_la/header', renderer::get_header_context('home')),
            'libraryurl' => url::library(),
            'reports' => $reports,
            'has_reports' => !empty($reports),
            'messages' => $messages,
            'promptreports' => $promptreports,
            'prompttables' => $prompttables,
            'show_agent' => $showagent,
            'agent' => [
                'slides' => self::get_agent_slides(),
            ],
        ];
    }

    /**
     * Get first-run agent slides.
     *
     * @return array
     */
    protected static function get_agent_slides(): array {
        return [
            [
                'active' => true,
                'visualclass' => 'la-welcome-visual-reports',
                'visualicon' => 'fa-solid fa-chart-column',
                'eyebrow' => get_string('welcomeslide1eyebrow', 'local_la'),
                'title' => get_string('welcomeslide1title', 'local_la'),
                'items' => [
                    self::get_agent_item('welcomeslide1item1'),
                    self::get_agent_item('welcomeslide1item2'),
                    self::get_agent_item('welcomeslide1item3'),
                    self::get_agent_item('welcomeslide1item4'),
                    self::get_agent_item('welcomeslide1item5'),
                    self::get_agent_item('welcomeslide1item6'),
                ],
            ],
            [
                'visualclass' => 'la-welcome-visual-control',
                'visualicon' => 'fa-solid fa-user-shield',
                'eyebrow' => get_string('welcomeslide2eyebrow', 'local_la'),
                'title' => get_string('welcomeslide2title', 'local_la'),
                'items' => [
                    self::get_agent_item('welcomeslide2item1'),
                    self::get_agent_item('welcomeslide2item2'),
                    self::get_agent_item('welcomeslide2item3'),
                    self::get_agent_item('welcomeslide2item4'),
                    self::get_agent_item('welcomeslide2item5'),
                ],
            ],
            [
                'visualclass' => 'la-welcome-visual-ai',
                'visualicon' => 'fa-solid fa-wand-magic-sparkles',
                'eyebrow' => get_string('welcomeslide3eyebrow', 'local_la'),
                'title' => get_string('welcomeslide3title', 'local_la'),
                'items' => [
                    self::get_agent_item('welcomeslide3item1'),
                    self::get_agent_item('welcomeslide3item2'),
                    self::get_agent_item('welcomeslide3item3'),
                    self::get_agent_item('welcomeslide3item4', true, 'installreports'),
                    self::get_agent_item('welcomeslide3item5', true),
                ],
            ],
        ];
    }

    /**
     * Get one agent checklist item.
     *
     * @param string $key
     * @param bool $todo
     * @param string $task
     * @return array
     */
    protected static function get_agent_item(string $key, bool $todo = false, string $task = ''): array {
        return [
            'text' => get_string($key, 'local_la'),
            'todo' => $todo,
            'checked' => !$todo,
            'task' => $task,
        ];
    }

    /**
     * Escape text and turn URLs into links.
     *
     * @param string $text
     * @return string
     */
    protected static function link_urls(string $text): string {
        $html = s($text);
        return preg_replace(
            '~(https?://[^\s<]+)~',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $html,
        );
    }

    /**
     * Report title from a saved generated definition.
     *
     * @param string $definition
     * @return string
     */
    protected static function get_definition_title(string $definition): string {
        $data = json_decode($definition, true);
        return is_array($data) ? (string) ($data['name'] ?? '') : '';
    }

    /**
     * Installed report URL for a saved generated definition.
     *
     * @param string $definition
     * @return string
     */
    protected static function get_definition_report_url(string $definition): string {
        $data = json_decode($definition, true);
        if (!is_array($data) || empty($data['shortname'])) {
            return '';
        }

        $reports = repository::get_all_reports();
        $report = $reports[(string) $data['shortname']] ?? null;

        return !empty($report['id']) ? url::report((int) $report['id']) : '';
    }

    /**
     * Format saved prompt context for chat display.
     *
     * @param string $json
     * @return array
     */
    protected static function get_context_items(string $json): array {
        $context = json_decode($json, true);
        if (!is_array($context)) {
            return [];
        }

        $items = [];
        if (!empty($context['report']['name'])) {
            $items[] = [
                'name' => (string) $context['report']['name'],
                'type' => get_string('report'),
                'icon' => 'fa-solid fa-bars',
            ];
        }

        $tabletype = ($context['tablemode'] ?? '') === 'only' ?
            get_string('tableonly', 'local_la') :
            get_string('table', 'local_la');
        foreach ($context['tables'] ?? [] as $table) {
            if (!empty($table['name'])) {
                $items[] = [
                    'name' => (string) $table['name'],
                    'type' => $tabletype,
                    'icon' => 'fa-solid fa-table-cells-large',
                ];
            }
        }

        return $items;
    }

    /**
     * Get default AI context tables.
     *
     * @param array $availabletables
     * @return array
     */
    protected static function get_default_tables(array $availabletables): array {
        $available = array_fill_keys($availabletables, true);
        $defaults = [
            'local_la_time_page',
            'local_la_time_total',
            'local_la_time_day',
            'local_la_time_hour',
            'role',
            'role_assignments',
            'grade_grades',
            'grade_items',
            'enrol',
            'user_enrolments',
            'course',
            'course_categories',
            'context',
            'course_completions',
            'course_modules',
            'modules',
            'course_modules_completion',
            'user',
            'user_lastaccess',
        ];

        return array_values(array_filter($defaults, static fn($table) => isset($available[$table])));
    }

}
