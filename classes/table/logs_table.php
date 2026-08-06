<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\table;

defined('MOODLE_INTERNAL') || die();

use local_la\local\url;

/**
 * User action logs table.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logs_table extends general_table {
    /** @var string */
    protected $search;

    /** @var string */
    protected $action;

    /**
     * Constructor.
     *
     * @param string $search
     * @param string $action
     */
    public function __construct(string $search = '', string $action = '') {
        parent::__construct('local-la-logs');
        $this->search = trim($search);
        $this->action = trim($action);
    }

    /**
     * Configure table.
     *
     * @return void
     */
    public function load(): void {
        global $DB;

        $from = "{local_la_logs} l
            LEFT JOIN {user} u ON u.id = l.userid";
        $where = '1 = 1';
        $params = [];

        if ($this->search !== '') {
            $search = '%' . $DB->sql_like_escape(\core_text::strtolower($this->search)) . '%';
            $where .= " AND (" .
                $DB->sql_like('LOWER(l.action)', ':searchaction', false) .
                " OR " . $DB->sql_like('LOWER(u.firstname)', ':searchfirstname', false) .
                " OR " . $DB->sql_like('LOWER(u.lastname)', ':searchlastname', false) .
                " OR " . $DB->sql_like('LOWER(u.email)', ':searchemail', false) .
                ")";
            $params += [
                'searchaction' => $search,
                'searchfirstname' => $search,
                'searchlastname' => $search,
                'searchemail' => $search,
            ];
        }

        if ($this->action !== '') {
            $where .= " AND l.action = :logaction";
            $params['logaction'] = $this->action;
        }

        $this->define_columns(['timecreated', 'user', 'email', 'action', 'objectid', 'ip']);
        $this->define_headers([
            get_string('time', 'local_la'),
            get_string('user'),
            get_string('email'),
            get_string('action'),
            get_string('id', 'local_la'),
            get_string('ipaddress', 'local_la'),
        ]);
        $this->collapsible(false);
        $this->sortable(true);
        $this->is_downloadable(false);
        $this->define_baseurl(url::preferences_url([
            'tab' => 'audit',
            'log_search' => $this->search,
            'log_action' => $this->action,
        ]));
        $this->set_sql(
            "l.id,
                    l.userid,
                    u.firstname,
                    u.lastname,
                    u.firstnamephonetic,
                    u.lastnamephonetic,
                    u.middlename,
                    u.alternatename,
                    u.email,
                    l.action,
                    l.objectid,
                    l.ip,
                    l.timecreated",
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
        return parent::get_sql_sort() ?: 'timecreated DESC, id DESC';
    }

    /**
     * User column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_user(\stdClass $row): string {
        return trim(fullname($row));
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
     * Time created column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timecreated(\stdClass $row): string {
        $time = (int) ($row->timecreated ?? 0);

        return $time > 0 ? userdate($time, get_string('strftimedatetime', 'langconfig')) : '';
    }

    /**
     * Action column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_action(\stdClass $row): string {
        return \html_writer::link('#', s($row->action), [
            'data-action' => 'show-log-details',
            'data-log-id' => (int) $row->id,
        ]);
    }
}
