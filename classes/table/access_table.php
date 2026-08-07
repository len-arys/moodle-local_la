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

use local_la\local\audience;
use local_la\local\helper;
use local_la\local\url;

/**
 * Report access table.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_table extends general_table {
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
        parent::__construct('local-la-access-' . $reportid);
        $this->reportid = $reportid;
        $this->search = $search;
    }

    /**
     * Configure table.
     *
     * @return void
     */
    public function load(): void {
        [$from, $where, $params] = audience::get_access_users_sql($this->reportid, $this->search);

        $this->define_columns([
            'id',
            'firstname',
            'lastname',
            'email',
            'enabled',
            'role',
            'status',
            'favorite',
            'firstaccess',
            'lastaccess',
            'updatedat',
        ]);
        $this->define_headers([
            'ID',
            get_string('firstname', 'core'),
            get_string('lastname', 'core'),
            get_string('email', 'core'),
            get_string('enabled', 'local_la'),
            get_string('role'),
            get_string('status', 'core'),
            get_string('favorite', 'local_la'),
            get_string('firstaccess', 'local_la'),
            get_string('lastaccess', 'local_la'),
            get_string('updatedat', 'local_la'),
        ]);
        $this->collapsible(false);
        $this->sortable(true);
        $this->no_sorting('role');
        $this->is_downloadable(false);
        $this->define_baseurl(url::report_tab_url($this->reportid, [
            'tab' => 'access',
            'access_search' => $this->search,
        ]));
        $this->set_sql(
            "DISTINCT u.id, u.firstname, u.lastname, u.email,
                    ru.id AS relationid,
                    ru.status AS relationstatus,
                    CASE WHEN ru.id IS NULL THEN 0 ELSE 1 END AS enabled,
                    COALESCE(ru.status, -1) AS status,
                    COALESCE(ru.favorite, -1) AS favorite,
                    ru.timecreated AS firstaccess,
                    ru.timeaccess AS lastaccess,
                    ru.timemodified AS updatedat",
            $from,
            $where,
            $params
        );
        $this->set_count_sql("SELECT COUNT(DISTINCT u.id) FROM {$from} WHERE {$where}", $params);
    }

    /**
     * Default table sort.
     *
     * @return string
     */
    public function get_sql_sort() {
        return parent::get_sql_sort() ?: 'lastaccess DESC, firstname ASC, id ASC';
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
     * Enabled column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_enabled(\stdClass $row): string {
        return !empty($row->relationid) ? get_string('yes') : get_string('no');
    }

    /**
     * Role column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_role(\stdClass $row): string {
        $userid = (int) $row->id;

        if (helper::is_billing_admin($userid)) {
            return get_string('accessroleadmin', 'local_la');
        }

        if (has_capability('local/la:manage', \context_system::instance(), $userid)) {
            return get_string('accessrolemanager', 'local_la');
        }

        return get_string('accessroleviewer', 'local_la');
    }

    /**
     * Status column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_status(\stdClass $row): string {
        if (empty($row->relationid)) {
            return '';
        }

        return !empty($row->relationstatus) ? get_string('visible') : get_string('hidden', 'grades');
    }

    /**
     * Favorite column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_favorite(\stdClass $row): string {
        if (empty($row->relationid)) {
            return '';
        }

        return !empty($row->favorite) ? get_string('yes') : get_string('no');
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
     * Updated at column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_updatedat(\stdClass $row): string {
        return $this->format_time((int) ($row->updatedat ?? 0));
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
}
