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

namespace local_la\output\report;

defined('MOODLE_INTERNAL') || die();

use local_la\local\helper;
use local_la\local\url;
use local_la\table\audit_table;

/**
 * Report audit logs tab context.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auditlogs {
    /**
     * Build tab context.
     *
     * @param \stdClass $report
     * @return array
     */
    public static function get_context(\stdClass $report): array {
        if (!helper::has_feature('audit_logs')) {
            return [
                'reportid' => (int) $report->id,
                'needsupgrade' => true,
                'upgradecopy' => get_string('auditlogsupgrade', 'local_la', helper::get_plan_label('pro')),
                'billingurl' => url::preferences(['tab' => 'billing']),
            ];
        }

        $search = optional_param('audit_search', '', PARAM_RAW_TRIMMED);
        $table = new audit_table((int) $report->id, $search);
        $table->load();

        ob_start();
        $table->out(50, false);
        $tablehtml = ob_get_clean();

        return [
            'reportid' => (int) $report->id,
            'audienceurl' => url::report_tab((int) $report->id, ['tab' => 'audience']),
            'search' => $search,
            'table' => $tablehtml === false ? '' : $tablehtml,
        ];
    }
}
