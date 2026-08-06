<?php
namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Report audit helper.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
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
