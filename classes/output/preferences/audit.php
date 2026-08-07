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

namespace local_la\output\preferences;

defined('MOODLE_INTERNAL') || die();

use local_la\local\helper;
use local_la\local\url;
use local_la\table\logs_table;

/**
 * Preferences audit tab context.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit {
    /**
     * Build tab context.
     *
     * @return array
     */
    public static function get_context(): array {
        if (!helper::has_feature('audit_logs')) {
            return [
                'needsupgrade' => true,
                'upgradecopy' => get_string('auditlogsupgrade', 'local_la', helper::get_plan_label('pro')),
                'billingurl' => url::preferences(['tab' => 'billing']),
            ];
        }

        $search = trim(optional_param('log_search', '', PARAM_TEXT));
        $action = trim(optional_param('log_action', '', PARAM_ALPHANUMEXT));
        $table = new logs_table($search, $action);
        $table->load();

        ob_start();
        $table->out(25, true);
        $html = ob_get_clean();

        return [
            'search' => s($search),
            'actions' => self::get_action_options($action),
            'table' => $html,
        ];
    }

    /**
     * Get audit action filter options.
     *
     * @param string $selected
     * @return array
     */
    protected static function get_action_options(string $selected): array {
        global $DB;

        $options = [[
            'value' => '',
            'name' => get_string('alllogs', 'local_la'),
            'selected' => $selected === '',
        ]];

        $actions = $DB->get_records_sql_menu(
            "SELECT DISTINCT action, action AS label
               FROM {local_la_logs}
              WHERE action <> ''
           ORDER BY action"
        );

        foreach ($actions as $action => $label) {
            $options[] = [
                'value' => s($action),
                'name' => s($label),
                'selected' => $selected === (string) $action,
            ];
        }

        return $options;
    }
}
