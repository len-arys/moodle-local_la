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

namespace local_la\table;

defined('MOODLE_INTERNAL') || die();

use local_la\local\audit;
use local_la\local\calendar;
use local_la\local\url;

/**
 * Report audit logs table.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_table extends general_table {
    /** @var int */
    protected $reportid;

    /** @var string */
    protected $search;

    /**
     * Constructor.
     *
     * @param int $reportid
     * @param string $search
     */
    public function __construct(int $reportid, string $search = '') {
        parent::__construct('local-la-audit-' . $reportid);
        $this->reportid = $reportid;
        $this->search = $search;
    }

    /**
     * Configure table.
     *
     * @return void
     */
    public function load(): void {
        [$from, $where, $params] = audit::get_report_logs_sql($this->reportid, $this->search);

        $this->define_columns([
            'id',
            'reportname',
            'firstname',
            'lastname',
            'email',
            'visits',
            'timespent',
            'firstaccess',
            'lastaccess',
            'ip',
            'os',
            'browser',
        ]);
        $this->define_headers([
            'ID',
            get_string('name', 'core'),
            get_string('firstname', 'core'),
            get_string('lastname', 'core'),
            get_string('email', 'core'),
            get_string('visits', 'local_la'),
            get_string('timespent', 'local_la'),
            get_string('firstaccess', 'local_la'),
            get_string('lastaccess', 'local_la'),
            get_string('ip', 'local_la'),
            get_string('os', 'local_la'),
            get_string('browser', 'local_la'),
        ]);
        $this->collapsible(false);
        $this->sortable(true);
        $this->no_sorting('ip');
        $this->no_sorting('os');
        $this->no_sorting('browser');
        $this->is_downloadable(false);
        $this->define_baseurl(url::report_tab_url($this->reportid, [
            'tab' => 'auditlogs',
            'audit_search' => $this->search,
        ]));
        $this->set_sql(
            "tt.id,
                    r.name AS reportname,
                    u.id AS userid,
                    u.firstname,
                    u.lastname,
                    u.email,
                    tt.visits,
                    tt.timesec AS timespent,
                    tt.firstaccess,
                    tt.lastaccess,
                    tt.params",
            $from,
            $where,
            $params
        );
        $this->set_count_sql("SELECT COUNT(1) FROM {$from} WHERE {$where}", $params);
    }

    /**
     * Default table sort.
     *
     * @return string
     */
    public function get_sql_sort() {
        return parent::get_sql_sort() ?: 'lastaccess DESC, firstname ASC, id DESC';
    }

    /**
     * Report name column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_reportname(\stdClass $row): string {
        return format_string($row->reportname);
    }

    /**
     * First name column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_firstname(\stdClass $row): string {
        return s($row->firstname);
    }

    /**
     * Last name column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_lastname(\stdClass $row): string {
        return s($row->lastname);
    }

    /**
     * Email column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_email(\stdClass $row): string {
        return s($row->email);
    }

    /**
     * Time spent column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timespent(\stdClass $row): string {
        return $this->calendar_link($row, 'timesec', calendar::format_duration_value((int) $row->timespent));
    }

    /**
     * Visits column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_visits(\stdClass $row): string {
        return $this->calendar_link($row, 'visits', (string) (int) $row->visits);
    }

    /**
     * First access column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_firstaccess(\stdClass $row): string {
        return $this->format_time((int) ($row->firstaccess ?? 0));
    }

    /**
     * Last access column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_lastaccess(\stdClass $row): string {
        return $this->format_time((int) ($row->lastaccess ?? 0));
    }

    /**
     * IP column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_ip(\stdClass $row): string {
        return s($this->get_param($row, 'ip'));
    }

    /**
     * OS column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_os(\stdClass $row): string {
        return s($this->get_param($row, 'os'));
    }

    /**
     * Browser column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_browser(\stdClass $row): string {
        return s($this->get_param($row, 'browser'));
    }

    /**
     * Format timestamp.
     *
     * @param int $time
     * @return string
     */
    protected function format_time(int $time): string {
        return $time > 0 ? userdate($time, get_string('strftimedatetime', 'langconfig')) : '';
    }

    /**
     * Get one JSON param.
     *
     * @param \stdClass $row
     * @param string $name
     * @return string
     */
    protected function get_param(\stdClass $row, string $name): string {
        $params = json_decode((string) ($row->params ?? ''), true);

        return is_array($params) ? (string) ($params[$name] ?? '') : '';
    }

    /**
     * Calendar modal link.
     *
     * @param \stdClass $row
     * @param string $metric
     * @param string $label
     * @return string
     */
    protected function calendar_link(\stdClass $row, string $metric, string $label): string {
        if ($label === '' || $label === '0' || $label === '0m') {
            return $label;
        }

        $params = json_encode([
            'metric' => $metric,
            'scope' => 'report_page',
            'userid' => (int) $row->userid,
            'courseid' => SITEID,
            'name' => 'la_report',
            'instanceid' => $this->reportid,
        ]);

        return \html_writer::link('#', $label, [
            'class' => 'la-modal-link',
            'data-action' => 'open-report-modal-link',
            'data-method' => 'local_la_get_calendar',
            'data-params' => $params === false ? '{}' : $params,
            'data-title' => get_string($metric === 'visits' ? 'visits' : 'reporttime', 'local_la'),
        ]);
    }
}
