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

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_la\local\filters;
use local_la\local\helper;
use local_la\local\report as report_helper;
use local_la\local\repository;
use local_la\local\table as table_helper;
use local_la\local\url;

/**
 * External API for header search.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search extends external_api {
    /** Maximum number of search results. */
    private const RESULT_LIMIT = 100;

    /**
     * Search parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'type' => new external_value(PARAM_ALPHA, 'Search type'),
            'query' => new external_value(PARAM_TEXT, 'Search query', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute search.
     *
     * @param string $type
     * @param string $query
     * @return array
     */
    public static function execute(string $type, string $query = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'type' => $type,
            'query' => $query,
        ]);
        self::validate_context(context_system::instance());
        require_login();
        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if ($params['type'] === 'courses') {
            return self::get_response(
                $params['type'],
                get_string('searchcourses', 'local_la'),
                $params['query'],
                self::get_courses($params['query'])
            );
        }

        return self::get_response(
            'users',
            get_string('searchusers', 'local_la'),
            $params['query'],
            self::get_users($params['query'])
        );
    }

    /**
     * Build response.
     *
     * @param string $type
     * @param string $title
     * @param string $query
     * @param array $items
     * @return array
     */
    protected static function get_response(string $type, string $title, string $query, array $items): array {
        global $PAGE;

        $output = $PAGE->get_renderer('local_la');

        return [
            'title' => $title,
            'size' => 'l',
            'html' => $output->render_from_template('local_la/modal/header_search', [
                'type' => $type,
                'query' => $query,
                'items' => $items,
                'hasitems' => !empty($items),
                'haslimitmessage' => count($items) >= self::RESULT_LIMIT,
                'limitmessage' => get_string('headersearchlimitmessage', 'local_la', '<strong>' . self::RESULT_LIMIT . '</strong>'),
                'notfound' => get_string('notfound', 'local_la'),
            ]),
        ];
    }

    /**
     * Search users.
     *
     * @param string $query
     * @return array
     */
    protected static function get_users(string $query): array {
        global $DB, $OUTPUT;

        $menuconfig = self::get_overview_menu_config('users');
        $reports = repository::get_all_reports();
        $params = [];
        $where = 'u.deleted = 0 AND u.confirmed = 1';

        if (trim($query) !== '') {
            $search = '%' . $DB->sql_like_escape(trim($query)) . '%';
            $params += ['search1' => $search, 'search2' => $search, 'search3' => $search];
            $where .= ' AND (' .
                $DB->sql_like('u.firstname', ':search1', false) . ' OR ' .
                $DB->sql_like('u.lastname', ':search2', false) . ' OR ' .
                $DB->sql_like('u.email', ':search3', false) . ')';
        }

        $fields = \core_user\fields::for_userpic()->including('email')->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT {$fields}
                  FROM {user} u
                 WHERE {$where}
              ORDER BY u.firstname, u.lastname";

        $items = [];
        foreach ($DB->get_records_sql($sql, $params, 0, self::RESULT_LIMIT) as $user) {
            $menugroups = self::get_menu_groups($menuconfig, $reports, $user);
            $items[] = [
                'isuser' => true,
                'url' => (new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false),
                'name' => fullname($user),
                'email' => $user->email,
                'avatar' => $OUTPUT->user_picture($user, ['size' => 36, 'link' => false]),
                'linksid' => 'la-header-search-links-users-' . (int) $user->id,
                'hasmenugroups' => !empty($menugroups),
                'menugroups' => $menugroups,
            ];
        }

        return $items;
    }

    /**
     * Search courses.
     *
     * @param string $query
     * @return array
     */
    protected static function get_courses(string $query): array {
        global $DB;

        $menuconfig = self::get_overview_menu_config('courses');
        $reports = repository::get_all_reports();
        $params = ['siteid' => SITEID];
        $where = 'c.id <> :siteid AND c.visible = 1';

        if (trim($query) !== '') {
            $search = '%' . $DB->sql_like_escape(trim($query)) . '%';
            $params += ['search1' => $search, 'search2' => $search, 'search3' => $search];
            $where .= ' AND (' .
                $DB->sql_like('c.fullname', ':search1', false) . ' OR ' .
                $DB->sql_like('c.shortname', ':search2', false) . ' OR ' .
                $DB->sql_like('cc.name', ':search3', false) . ')';
        }

        $sql = "SELECT c.id, c.fullname, c.shortname, cc.name AS categoryname
                  FROM {course} c
                  JOIN {course_categories} cc ON cc.id = c.category
                 WHERE {$where}
              ORDER BY c.fullname";

        $items = [];
        foreach ($DB->get_records_sql($sql, $params, 0, self::RESULT_LIMIT) as $course) {
            $menugroups = self::get_menu_groups($menuconfig, $reports, $course);
            $items[] = [
                'iscourse' => true,
                'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'name' => format_string($course->fullname),
                'shortname' => format_string($course->shortname),
                'category' => format_string($course->categoryname),
                'linksid' => 'la-header-search-links-courses-' . (int) $course->id,
                'hasmenugroups' => !empty($menugroups),
                'menugroups' => $menugroups,
            ];
        }

        return $items;
    }

    /**
     * Get overview report menu config.
     *
     * @param string $shortname
     * @return array
     */
    protected static function get_overview_menu_config(string $shortname): array {
        global $DB;

        $record = $DB->get_record('local_la_report', ['shortname' => $shortname], 'report_params', IGNORE_MISSING);
        if (!$record) {
            return [];
        }

        $params = report_helper::decode_params((string) $record->report_params);
        return $params['menu']['items'] ?? [];
    }

    /**
     * Build grouped menu items for one record.
     *
     * @param array $menuconfig
     * @param array $reports
     * @param \stdClass $row
     * @return array
     */
    protected static function get_menu_groups(array $menuconfig, array $reports, \stdClass $row): array {
        $groups = [];

        foreach ($menuconfig as $groupconfig) {
            $items = [];

            foreach (($groupconfig['items'] ?? []) as $itemconfig) {
                if (($itemconfig['type'] ?? '') === 'preset') {
                    $items = array_merge($items, table_helper::get_menu_preset_items((string) ($itemconfig['preset'] ?? ''), $row));
                    continue;
                }

                $item = self::get_report_menu_item($itemconfig, $reports, $row);
                if (!empty($item)) {
                    $items[] = $item;
                }
            }

            if (!empty($items)) {
                $groups[] = [
                    'header' => (string) ($groupconfig['header'] ?? ''),
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    /**
     * Build one report menu item.
     *
     * @param array $itemconfig
     * @param array $reports
     * @param \stdClass $row
     * @return array
     */
    protected static function get_report_menu_item(array $itemconfig, array $reports, \stdClass $row): array {
        $reportname = (string) ($itemconfig['report'] ?? '');
        $filter = (string) ($itemconfig['filter'] ?? '');

        if (($itemconfig['type'] ?? '') !== 'report' || $reportname === '' || $filter === '' || empty($reports[$reportname])) {
            return [];
        }

        $sourcecolumn = (string) ($itemconfig['column'] ?? $filter);
        $value = $row->{$sourcecolumn} ?? null;
        if ($value === null || $value === '') {
            return [];
        }

        $names = filters::get_param_names($filter);
        return [
            'label' => $reports[$reportname]['name'],
            'url' => url::report_tab_url((int) $reports[$reportname]['id'], [
                'tab' => 'view',
                $names['operator'] => 'equal',
                $names['value'] => $value,
            ])->out(false),
        ];
    }

    /**
     * Search return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'size' => new external_value(PARAM_ALPHANUMEXT, 'Modal size'),
            'html' => new external_value(PARAM_RAW, 'Modal body HTML'),
        ]);
    }
}
