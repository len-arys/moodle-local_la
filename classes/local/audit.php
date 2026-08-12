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

namespace local_la\local;

/**
 * Report audit helper.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit {
    /**
     * Build report audit logs SQL parts.
     *
     * @param int $reportid
     * @param string $search
     * @return array
     */
    public static function get_report_logs_sql(int $reportid, string $search = ''): array {
        global $DB;

        if ($reportid <= 0) {
            return ['', '1 = 0', []];
        }

        $params = [
            'name' => 'la_report',
            'reportid' => $reportid,
        ];
        $searchsql = '';
        $search = trim($search);

        if ($search !== '') {
            $searchsql = " AND (" .
                $DB->sql_like('LOWER(r.name)', ':search', false) .
                " OR " . $DB->sql_like('LOWER(u.firstname)', ':search', false) .
                " OR " . $DB->sql_like('LOWER(u.lastname)', ':search', false) .
                " OR " . $DB->sql_like('LOWER(u.email)', ':search', false) .
            ")";
            $params['search'] = '%' . $DB->sql_like_escape(\core_text::strtolower($search)) . '%';
        }

        return [
            "{local_la_time_page} tp
              JOIN {local_la_time_total} tt ON tt.pageid = tp.id
              JOIN {user} u ON u.id = tt.userid
              JOIN {local_la_report} r ON r.id = tp.instanceid
         LEFT JOIN {local_la_report_users} ru
                ON ru.reportid = r.id
               AND ru.userid = u.id",
            "tp.name = :name
                AND r.id = :reportid
                {$searchsql}",
            $params,
        ];
    }
}
