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
use local_la\table\access_table;

/**
 * Report access tab context.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Build tab context.
     *
     * @param \stdClass $report
     * @return array
     */
    public static function get_context(\stdClass $report): array {
        $search = optional_param('access_search', '', PARAM_RAW_TRIMMED);
        $table = new access_table((int) $report->id, $search);
        $table->load();

        ob_start();
        $table->out(50, false);
        $tablehtml = ob_get_clean();

        return [
            'reportid' => (int) $report->id,
            'audienceurl' => url::report_tab((int) $report->id, ['tab' => 'audience']),
            'manageaccessurl' => helper::is_billing_admin() ? url::preferences(['tab' => 'access']) : '',
            'search' => $search,
            'table' => $tablehtml === false ? '' : $tablehtml,
        ];
    }
}
