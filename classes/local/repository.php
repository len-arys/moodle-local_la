<?php
namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Data access layer for local_la records.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository {

    /**
     * Get enrolment method filter options from the site.
     *
     * @return array
     */
    public static function get_filter_enrolment_methods(): array {
        global $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        return array_map(function(string $value): array {
            return [
                'value' => $value,
                'name' => get_string('pluginname', 'enrol_' . $value),
            ];
        }, array_keys(enrol_get_plugins(true)));
    }

    /**
     * Get activity module filter options from Moodle.
     *
     * @return array
     */
    public static function get_filter_modules(): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $modules = get_module_types_names(false, true);
        asort($modules);

        return array_values(array_map(function(string $name, string $value): array {
            return [
                'value' => $value,
                'name' => $name,
            ];
        }, $modules, array_keys($modules)));
    }

    /**
     * Get filter options for one menu-style column definition.
     *
     * @param array $definition
     * @return array
     */
    public static function get_filter_menu_options(array $definition = []): array {
        global $DB;

        $field = trim((string) ($definition['source_params']['field'] ?? ''));

        if ($field !== '') {
            $profilefield = $DB->get_record('user_info_field', ['shortname' => $field], 'id,param1', IGNORE_MISSING);

            if ($profilefield) {
                $options = preg_split('/\r\n|\r|\n/', (string) ($profilefield->param1 ?? ''));
                $options = array_values(array_filter(array_map('trim', $options), function(string $item): bool {
                    return $item !== '';
                }));

                return array_values(array_map(function(string $value): array {
                    return [
                        'value' => $value,
                        'name' => $value,
                    ];
                }, $options));
            }
        }

        return [];
    }

    /**
     * Get specific users for selected filter values.
     *
     * @param array $ids
     * @return array
     */
    public static function get_filter_selected_users(array $ids): array {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT u.id AS value,
                    " . $DB->sql_concat('u.firstname', "' '", 'u.lastname') . " AS name
               FROM {user} u
              WHERE u.id {$insql}
           ORDER BY u.firstname ASC, u.lastname ASC, u.id ASC",
            $params
        );

        return array_values(array_map(function(\stdClass $record): array {
            return [
                'value' => (string) $record->value,
                'name' => trim((string) $record->name),
            ];
        }, $records));
    }

    /**
     * Get specific courses for selected filter values.
     *
     * @param array $ids
     * @return array
     */
    public static function get_filter_selected_courses(array $ids): array {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT c.id AS value,
                    c.fullname AS name,
                    COALESCE(cc.name, '') AS category
               FROM {course} c
          LEFT JOIN {course_categories} cc
                 ON cc.id = c.category
              WHERE c.id {$insql}
            ORDER BY cc.name ASC, c.fullname ASC, c.id ASC",
            $params
        );

        return array_values(array_map(function(\stdClass $record): array {
            return [
                'value' => (string) $record->value,
                'name' => trim((string) $record->name),
                'category' => trim((string) $record->category),
            ];
        }, $records));
    }

    /**
     * Get distinct course filter options.
     *
     * @return array
     */
    public static function get_filter_courses(): array {
        global $DB;

        $courseids = array_keys(\core_course_category::get(0)->get_courses(['recursive' => true]));

        if (empty($courseids)) {
            return [];
        }

        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
        $records = $DB->get_records_sql(
            "SELECT c.id AS value,
                    c.fullname AS name,
                    COALESCE(cc.name, '') AS category
               FROM {course} c
          LEFT JOIN {course_categories} cc
                 ON cc.id = c.category
              WHERE c.id {$coursesql}
            ORDER BY cc.name ASC, c.fullname ASC, c.id ASC",
            $params
        );

        return array_values(array_map(function(\stdClass $record): array {
            return [
                'value' => (string) $record->value,
                'name' => trim((string) $record->name),
                'category' => trim((string) $record->category),
            ];
        }, $records));
    }

    /**
     * Get one report by id or shortname.
     *
     * @param int|string $identifier
     * @param int|null $userid User whose report preferences should be loaded
     * @return \stdClass|false
     */
    public static function get_report($identifier, ?int $userid = null) {
        global $DB, $USER;

        $where = is_numeric($identifier) ? 'r.id = :reportid' : 'r.shortname = :shortname';
        $queryparams = [
            'userid' => $userid ?? (int) $USER->id,
        ];

        if (is_numeric($identifier)) {
            $queryparams['reportid'] = (int) $identifier;
        } else {
            $queryparams['shortname'] = (string) $identifier;
        }

        $sql = "SELECT r.*,
                       ru.userid,
                       ru.user_params,
                       ru.favorite,
                       ru.timeaccess,
                       s.id AS sqlid,
                       s.name AS sqlrecordname,
                       s.code AS sqlcode,
                       s.timeactivated AS sqltimeactivated
                  FROM {local_la_report} r
             LEFT JOIN {local_la_report_users} ru
                    ON ru.reportid = r.id
                   AND ru.userid = :userid
             LEFT JOIN {local_la_sql} s
                    ON s.name = r.sql_name
                   AND s.status = 1
                 WHERE {$where}";
        $report = $DB->get_record_sql($sql, $queryparams);

        if (!$report) {
            return false;
        }

        $report->params = report::build_params($report);
        $report->dependencies = self::get_dependencies($report->params);

        return $report;
    }

    /**
     * Get installed reports keyed by shortname.
     *
     * @return array
     */
    public static function get_all_reports(): array {
        global $DB;

        $records = $DB->get_records('local_la_report', null, 'name ASC, id ASC', 'id,shortname,name');
        $reports = [];

        foreach ($records as $record) {
            $shortname = trim((string) ($record->shortname ?? ''));

            if ($shortname === '') {
                continue;
            }

            $reports[$shortname] = [
                'id' => (int) $record->id,
                'name' => format_string((string) $record->name),
            ];
        }

        return $reports;
    }

    /**
     * Get SQL dependency code map for installed report params.
     *
     * @param array $params
     * @return array
     */
    protected static function get_dependencies(array $params): array {
        global $DB;

        $dependencies = [];
        $names = report::get_dependency_names($params);

        if (empty($names)) {
            return [];
        }

        [$insql, $queryparams] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT s.name, s.code
               FROM {local_la_sql} s
              WHERE s.status = 1
                AND s.name {$insql}",
            $queryparams
        );

        foreach ($records as $record) {
            $dependencies[(string) $record->name] = $record;
        }

        return $dependencies;
    }

    /**
     * Get library reports.
     *
     * @param int $limit
     * @return array
     */
    public static function get_reports(
        int $limit = 10,
        int $offset = 0,
        string $sort = 'name',
        string $dir = 'asc',
        string $search = ''
    ): array {
        global $DB, $USER;

        $direction = ($dir === 'desc') ? 'DESC' : 'ASC';
        $orderby = "COALESCE(urs.status, 1) DESC, {$sort} {$direction}, name ASC";
        $searchsql = '';
        $params = [
            'userid' => $USER->id,
        ];
        [$accesssql, $accessparams] = self::get_report_access_sql();
        $params += $accessparams;

        if ($search !== '') {
            $searchsql = " AND (" .
                $DB->sql_like('LOWER(r.name)', ':searchname', false) .
                " OR " . $DB->sql_like('LOWER(r.shortname)', ':searchshortname', false) .
                " OR " . $DB->sql_like('LOWER(r.info)', ':searchinfo', false) .
                ")";
            $searchvalue = '%' . \core_text::strtolower($search) . '%';
            $params['searchname'] = $searchvalue;
            $params['searchshortname'] = $searchvalue;
            $params['searchinfo'] = $searchvalue;
        }

        $sql = "SELECT r.*,
                       r.name AS name,
                       r.version AS version,
                       r.timesync AS timesync,
                       r.timecreated AS timecreated,
                       r.timemodified AS timemodified,
                       urs.id AS relationid,
                       urs.status AS relationstatus,
                       urs.favorite AS favorite,
                       s.id AS sqlid,
                       s.name AS sqlrecordname,
                       s.code AS sqlcode,
                       s.timeactivated AS sqltimeactivated,
                       CASE WHEN EXISTS (
                           SELECT 1
                             FROM {local_la_report_audience} a
                            WHERE a.reportid = r.id
                       ) THEN 1 ELSE 0 END AS hasaudience
                 FROM {local_la_report} r
            LEFT JOIN {local_la_report_users} urs
                   ON urs.reportid = r.id
                  AND urs.userid = :userid
            LEFT JOIN {local_la_sql} s
                   ON s.name = r.sql_name
                  AND s.status = 1
                WHERE 1 = 1
                  {$searchsql}
                  {$accesssql}
             ORDER BY {$orderby}";

        return $DB->get_records_sql($sql, $params, $offset, $limit);
    }

    /**
     * Count library reports.
     *
     * @return int
     */
    public static function count_reports(string $search = ''): int {
        global $DB;

        [$accesssql, $params] = self::get_report_access_sql();
        $searchsql = '';

        if ($search !== '') {
            $searchsql = " AND (" .
                $DB->sql_like('LOWER(r.name)', ':searchname', false) .
                " OR " . $DB->sql_like('LOWER(r.shortname)', ':searchshortname', false) .
                " OR " . $DB->sql_like('LOWER(r.info)', ':searchinfo', false) .
            ")";
            $searchvalue = '%' . \core_text::strtolower($search) . '%';
            $params += [
                'searchname' => $searchvalue,
                'searchshortname' => $searchvalue,
                'searchinfo' => $searchvalue,
            ];
        }

        return $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_la_report} r
              WHERE 1 = 1
                    {$searchsql}
                    {$accesssql}",
            $params
        );
    }

    /**
     * Build the current user's report audience condition.
     *
     * @return array
     */
    protected static function get_report_access_sql(): array {
        global $USER;

        if (isguestuser($USER)) {
            return [' AND 1 = 0', []];
        }

        if (helper::is_admin()) {
            return ['', []];
        }

        return [
            " AND EXISTS (
                SELECT 1
                  FROM {local_la_report_audience} a
                 WHERE a.reportid = r.id
                   AND (
                       (a.type = 'all' AND a.instanceid = 0)
                       OR (a.type = 'user' AND a.instanceid = :audienceuserid)
                       OR (
                           a.type = 'role'
                           AND EXISTS (
                               SELECT 1
                                 FROM {role_assignments} ra
                                WHERE ra.roleid = a.instanceid
                                  AND ra.userid = :audienceroleuserid
                                  AND ra.contextid = :audiencecontextid
                           )
                       )
                   )
            )",
            [
                'audienceuserid' => (int) $USER->id,
                'audienceroleuserid' => (int) $USER->id,
                'audiencecontextid' => (int) \context_system::instance()->id,
            ],
        ];
    }

    /**
     * Get apps.
     *
     * @param int $limit
     * @param int $offset
     * @param bool $activeonly
     * @return array
     */
    public static function get_apps(int $limit = 3, int $offset = 0, bool $activeonly = true): array {
        global $DB;

        $sql = "SELECT a.*
                  FROM {local_la_app} a";
        $params = [];

        if ($activeonly) {
            $sql .= " WHERE a.status = :status";
            $params['status'] = 1;
        }

        $sql .= "
              ORDER BY a.timemodified DESC, a.id DESC";

        if ($limit <= 0) {
            return $DB->get_records_sql($sql, $params);
        }

        return $DB->get_records_sql($sql, $params, $offset, $limit);
    }

    /**
     * Get one active app.
     *
     * @param int $id
     * @return \stdClass|false
     */
    public static function get_app(int $id) {
        global $DB;

        return $DB->get_record('local_la_app', ['id' => $id, 'status' => 1]);
    }

    /**
     * Set app status.
     *
     * @param int $appid
     * @param int $status
     * @return void
     */
    public static function set_app_status(int $appid, int $status): void {
        global $DB;

        if ($appid <= 0) {
            return;
        }
        if ($status) {
            $app = $DB->get_record('local_la_app', ['id' => $appid], 'id, plan', IGNORE_MISSING);
            if ($app && !helper::has_plan((string) $app->plan)) {
                throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label((string) $app->plan));
            }
        }

        $DB->set_field('local_la_app', 'status', $status, ['id' => $appid]);
    }

    /**
     * Get users related to one report.
     *
     * @param int $reportid
     * @param int $limit
     * @return array
     */
    public static function get_report_users(int $reportid, int $limit = 6): array {
        global $DB;

        if ($reportid <= 0 || $limit <= 0) {
            return [];
        }

        $fields = 'u.id, u.firstname, u.lastname, u.picture, u.imagealt, u.email, u.firstnamephonetic,
                   u.lastnamephonetic, u.middlename, u.alternatename';
        $sql = "SELECT {$fields}
                  FROM {local_la_report_users} ru
                  JOIN {user} u
                    ON u.id = ru.userid
                 WHERE ru.reportid = :reportid
              ORDER BY ru.favorite DESC, ru.timeaccess DESC, ru.timecreated ASC, ru.id ASC";

        return array_values($DB->get_records_sql($sql, ['reportid' => $reportid], 0, $limit));
    }

    /**
     * Get tracked time and visits for one report page.
     *
     * @param int $reportid
     * @param int $userid
     * @return array
     */
    public static function get_report_time_metrics(int $reportid, int $userid): array {
        global $DB;

        if ($reportid <= 0 || $userid <= 0) {
            return [
                'timesec' => 0,
                'visits' => 0,
            ];
        }

        $record = $DB->get_record_sql(
            "SELECT COALESCE(SUM(tt.timesec), 0) AS timesec,
                    COALESCE(SUM(tt.visits), 0) AS visits
               FROM {local_la_time_total} tt
               JOIN {local_la_time_page} tp
                 ON tp.id = tt.pageid
              WHERE tp.name = :name
                AND tp.instanceid = :reportid
                AND tt.userid = :userid",
            [
                'name' => 'la_report',
                'reportid' => $reportid,
                'userid' => $userid,
            ]
        );

        return [
            'timesec' => (int) ($record->timesec ?? 0),
            'visits' => (int) ($record->visits ?? 0),
        ];
    }

    /**
     * Add report for one user.
     *
     * @param int $reportid
     * @param int $userid
     * @return void
     */
    public static function add_report(int $reportid, int $userid): void {
        global $DB;

        $report = self::get_report($reportid);
        if (!$report) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }
        if (!helper::has_plan((string) $report->plan)) {
            throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label((string) $report->plan));
        }

        $relation = $DB->get_record('local_la_report_users', [
            'userid' => $userid,
            'reportid' => $reportid,
        ], '*', IGNORE_MISSING);

        if ($relation) {

            $relation->status = 1;
            $relation->timemodified = time();
            $DB->update_record('local_la_report_users', $relation);
            return;
        }

        if (empty($report->sql_name) || empty($report->sqlcode)) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $now = time();
        $DB->insert_record('local_la_report_users', (object) [
            'userid' => $userid,
            'reportid' => $reportid,
            'status' => 1,
            'favorite' => 0,
            'user_params' => $report->report_params,
            'timeaccess' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Toggle favorite report for one user.
     *
     * @param int $reportid
     * @param int $userid
     * @return void
     */
    public static function favorite_report(int $reportid, int $userid): void {
        global $DB;

        $relation = $DB->get_record('local_la_report_users', [
            'userid' => $userid,
            'reportid' => $reportid,
        ], '*', IGNORE_MISSING);

        if (!$relation) {
            return;
        }

        $relation->favorite = empty($relation->favorite) ? 1 : 0;
        $relation->timemodified = time();
        $DB->update_record('local_la_report_users', $relation);
    }

    /**
     * Update a user's last report access time.
     *
     * @param int $reportid Report id.
     * @param int $userid User id.
     */
    public static function update_report_access(int $reportid, int $userid): void {
        global $DB;

        $DB->set_field('local_la_report_users', 'timeaccess', time(), [
            'reportid' => $reportid,
            'userid' => $userid,
        ]);
    }

    /**
     * Reset one user's report params to the report defaults.
     *
     * @param int $reportid
     * @param int $userid
     * @return void
     */
    public static function reset_report(int $reportid, int $userid): void {
        global $DB;

        $report = $DB->get_record('local_la_report', ['id' => $reportid], 'id,report_params', IGNORE_MISSING);
        $relation = $DB->get_record('local_la_report_users', [
            'userid' => $userid,
            'reportid' => $reportid,
        ], '*', IGNORE_MISSING);

        if (!$report || !$relation) {
            return;
        }

        $relation->user_params = $report->report_params;
        $relation->timemodified = time();
        $DB->update_record('local_la_report_users', $relation);
    }

    /**
     * Duplicate one report for one user.
     *
     * @param int $reportid
     * @param int $userid
     * @return int
     */
    public static function duplicate_report(int $reportid, int $userid): int {
        global $DB;

        $report = self::get_report($reportid);

        if (!$report) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $base = clean_param((string) $report->shortname, PARAM_ALPHANUMEXT);
        $shortname = $base . '_copy';
        $index = 2;

        while ($DB->record_exists('local_la_report', ['shortname' => $shortname])) {
            $shortname = $base . '_copy_' . $index;
            $index++;
        }

        $now = time();
        $newreportid = (int) $DB->insert_record('local_la_report', (object) [
            'name' => trim((string) $report->name) . ' ' . get_string('copy', 'core'),
            'shortname' => $shortname,
            'info' => (string) ($report->info ?? ''),
            'tags' => (string) ($report->tags ?? ''),
            'version' => (string) ($report->version ?? '1.0'),
            'plan' => (string) $report->plan,
            'report_params' => json_encode($report->params),
            'sql_name' => (string) ($report->sql_name ?? ''),
            'timesync' => (int) ($report->timesync ?? 0),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        self::add_report($newreportid, $userid);

        return $newreportid;
    }

    /**
     * Update one report definition.
     *
     * @param \stdClass $data
     * @return void
     */
    public static function update_report(\stdClass $data): void {
        global $DB;

        $record = $DB->get_record('local_la_report', ['id' => (int) $data->id], '*', MUST_EXIST);
        $record->name = trim((string) $data->name);
        $record->info = trim((string) ($data->info ?? ''));
        $record->tags = trim((string) ($data->tags ?? ''));
        $record->timemodified = time();

        $DB->update_record('local_la_report', $record);
    }

    /**
     * Save report column preferences for one user.
     *
     * @param int $reportid
     * @param int $userid
     * @param array $columns
     * @return void
     */
    public static function save_report_columns(
        int $reportid,
        int $userid,
        array $columns
    ): void {
        $report = self::get_report($reportid);

        if (!$report) {
            return;
        }

        $params = $report->params;

        uasort($columns, function(array $a, array $b): int {
            return ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0));
        });

        foreach ($columns as $key => $updates) {
            if (!isset($params['columns'][$key])) {
                continue;
            }

            if (empty($params['columns'][$key]['name'])) {
                continue;
            }

            $params['columns'][$key]['order'] = (int) ($updates['order'] ?? 0);
            $params['columns'][$key] = self::apply_editable_column_updates(
                $params['columns'][$key],
                $updates
            );
        }

        self::save_report_params($reportid, $userid, $params);
    }

    /**
     * Save one column settings payload.
     *
     * @param int $reportid
     * @param int $userid
     * @param string $key
     * @param array $updates
     * @return void
     */
    public static function save_column_settings(int $reportid, int $userid, string $key, array $updates): void {
        $report = self::get_report($reportid);

        if (!$report || empty($report->params['columns'][$key])) {
            return;
        }

        $report->params['columns'][$key] = self::apply_editable_column_updates($report->params['columns'][$key], $updates);

        if (self::is_custom_column_key($key) && !empty($updates['filter']) && is_array($updates['filter'])) {
            $report->params['columns'][$key]['filter'] = $updates['filter'];
        }

        self::save_report_params($reportid, $userid, $report->params);
    }

    /**
     * Apply editable column updates without touching system config.
     *
     * @param array $column
     * @param array $updates
     * @return array
     */
    public static function apply_editable_column_updates(array $column, array $updates): array {
        if (array_key_exists('name', $updates)) {
            $name = trim((string) $updates['name']);

            if ($name !== '') {
                $column['name'] = $name;
            }
        }

        if (array_key_exists('visible', $updates)) {
            $column['visible'] = empty($updates['visible']) ? 0 : 1;
        }

        if (array_key_exists('sortable', $updates)) {
            $column['sortable'] = empty($updates['sortable']) ? 0 : 1;
        }

        if (array_key_exists('type', $updates) && trim((string) $updates['type']) !== '') {
            $column['type'] = (string) $updates['type'];
        }

        if (array_key_exists('formula', $updates)) {
            $formula = trim((string) $updates['formula']);

            if ($formula === '') {
                unset($column['formula']);
            } else {
                $column['formula'] = $formula;
            }
        }

        if (array_key_exists('condition', $updates)) {
            $condition = trim((string) $updates['condition']);

            if ($condition === '') {
                unset($column['condition']);
            } else {
                $column['condition'] = $condition;
            }
        }

        if (array_key_exists('enabled', $updates)) {
            $column['enabled'] = empty($updates['enabled']) ? 0 : 1;
        }

        return $column;
    }

    /**
     * Check whether the column key belongs to one custom user-managed column.
     *
     * @param string $key
     * @return bool
     */
    public static function is_custom_column_key(string $key): bool {
        return strpos($key, 'custom_') === 0;
    }

    /**
     * Save full report params for one user.
     *
     * @param int $reportid
     * @param int $userid
     * @param array $params
     * @return void
     */
    public static function save_report_params(int $reportid, int $userid, array $params): void {
        global $DB;

        $relation = $DB->get_record('local_la_report_users', [
            'userid' => $userid,
            'reportid' => $reportid,
        ], '*', IGNORE_MISSING);

        if (!$relation) {
            self::add_report($reportid, $userid);
            $relation = $DB->get_record('local_la_report_users', [
                'userid' => $userid,
                'reportid' => $reportid,
            ], '*', MUST_EXIST);
        }

        $relation->user_params = json_encode($params);
        $relation->timemodified = time();
        $DB->update_record('local_la_report_users', $relation);
    }

    /**
     * Set report status.
     *
     * @param int $reportid
     * @param int $status
     * @param int $userid
     * @return void
     */
    public static function set_report_status(int $reportid, int $status, int $userid): void {
        global $DB;

        $relation = $DB->get_record('local_la_report_users', [
            'userid' => $userid,
            'reportid' => $reportid,
        ], '*', IGNORE_MISSING);

        if (!$relation) {
            return;
        }
        if ($status && ($report = self::get_report($reportid)) && !helper::has_plan((string) $report->plan)) {
            throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label((string) $report->plan));
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->update_record('local_la_report_users', (object) [
            'id' => $relation->id,
            'status' => $status,
            'timemodified' => time(),
        ]);

        if (!$status) {
            $DB->set_field('local_la_report_schedule', 'status', 0, [
                'reportid' => $reportid,
                'usercreated' => $userid,
            ]);
        }
        $transaction->allow_commit();
    }

    /**
     * Delete one user report relation.
     *
     * @param int $reportid
     * @param int $userid
     * @return void
     */
    public static function delete_report(int $reportid, int $userid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_la_report_schedule', [
            'reportid' => $reportid,
            'usercreated' => $userid,
        ]);
        $DB->delete_records('local_la_report_users', [
            'userid' => $userid,
            'reportid' => $reportid,
        ]);
        $transaction->allow_commit();
    }

    /**
     * Get current user report relations.
     *
     * Recent items are ordered by user access time.
     *
     * @param int $limit
     * @param bool $favoriteonly
     * @return array
     */
    public static function get_user_reports(int $limit, bool $favoriteonly = false): array {
        global $DB, $USER;

        if (!isloggedin() || isguestuser()) {
            return [];
        }

        $params = [
            'userid' => $USER->id,
            'status' => 1,
        ];
        $favoritesql = '';

        if ($favoriteonly) {
            $params['favorite'] = 1;
            $favoritesql = ' AND urs.favorite = :favorite';
        }

        $sql = "SELECT r.*
                 FROM {local_la_report_users} urs
                  JOIN {local_la_report} r
                    ON r.id = urs.reportid
                 WHERE urs.userid = :userid
                   AND urs.status = :status
                       {$favoritesql}
              ORDER BY urs.timeaccess DESC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        $records = array_filter($records, function(\stdClass $record) use ($USER): bool {
            return audience::has_access((int) $record->id, (int) $USER->id);
        });

        return array_values($records);
    }
}
