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

use local_la\table\schedule_table;

/**
 * Report schedules tab context.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedules {
    /**
     * Build tab context.
     *
     * @param \stdClass $report
     * @return array
     */
    public static function get_context(\stdClass $report): array {
        $search = optional_param('schedule_search', '', PARAM_RAW_TRIMMED);
        $table = new schedule_table((int) $report->id, $search);
        $table->load();

        ob_start();
        $table->out(50, false);
        $tablehtml = ob_get_clean();

        return [
            'reportid' => (int) $report->id,
            'can_manage' => true,
            'search' => $search,
            'table' => $tablehtml === false ? '' : $tablehtml,
        ];
    }
}
