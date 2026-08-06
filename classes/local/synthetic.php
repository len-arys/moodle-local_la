<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Synthetic report column helper.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synthetic {
    /** @var string */
    public const PARAM_PRESET = 'synthetic_preset';

    /** @var string */
    public const PARAM_FROM = 'synthetic_from';

    /** @var string */
    public const PARAM_TO = 'synthetic_to';

    /** @var string */
    public const PARAM_METRIC = 'synthetic_metric';

    /** @var array */
    protected static $runtimeparams = [];

    /**
     * Apply synthetic columns to report params.
     *
     * @param array $params
     * @return array
     */
    public static function apply(array $params): array {
        if (($params['synthetic']['type'] ?? '') !== 'dates') {
            return $params;
        }

        $period = self::get_period();
        $metric = self::get_metric();
        $scope = self::get_scope($params);
        $order = (int) ($params['synthetic']['order'] ?? 100);
        $aggregate = !empty($params['synthetic']['aggregate']);
        $columns = self::get_columns($period, $metric, $scope);
        $dependency = self::get_aggregate_dependency($period, $metric, $scope, $columns);

        if (!empty($dependency)) {
            $params['_synthetic_dependencies'] = [
                $dependency['name'] => $dependency['code'],
            ];
        }

        foreach ($columns as $index => $column) {
            $sql = $column['sql'];
            $require = '';
            $source = '';

            if (!empty($dependency) && empty($column['correlated'])) {
                $sql = 'COALESCE(' . $dependency['alias'] . '.' . $column['key'] . ', 0)';
                $require = $dependency['name'];
                $source = 'join';
            }

            if ($aggregate) {
                $sql = 'MAX(' . $sql . ')';
            }

            $params['columns'][$column['key']] = [
                'enabled' => true,
                'name' => $column['label'],
                'order' => $order + $index,
                'visible' => true,
                'sortable' => false,
                'type' => 'text',
                'processor' => $column['key'] === 'synthetic_total' ?
                    'format_synthetic_total' :
                    ($metric === 'timesec' ? 'format_duration' : ''),
                'synthetic' => true,
                'metric' => $metric,
                'sql' => [
                    'column' => $sql,
                    'alias' => $column['key'],
                    'require' => $require,
                    'source' => $source,
                    'where' => '',
                ],
            ];
        }

        return $params;
    }

    /**
     * Get runtime SQL dependencies created by synthetic columns.
     *
     * @param array $params
     * @return array
     */
    public static function get_dependencies(array $params): array {
        $dependencies = [];

        foreach (($params['_synthetic_dependencies'] ?? []) as $name => $code) {
            $record = new \stdClass();
            $record->name = (string) $name;
            $record->code = (string) $code;
            $dependencies[(string) $name] = $record;
        }

        return $dependencies;
    }

    /**
     * Get active synthetic date params.
     *
     * @return array
     */
    public static function get_params(): array {
        $preset = optional_param(self::PARAM_PRESET, 'this_week', PARAM_ALPHAEXT);
        $from = optional_param(self::PARAM_FROM, '', PARAM_RAW_TRIMMED);
        $to = optional_param(self::PARAM_TO, '', PARAM_RAW_TRIMMED);
        $metric = optional_param(self::PARAM_METRIC, 'timesec', PARAM_ALPHAEXT);

        if (!empty(self::$runtimeparams['view'])) {
            $metric = (string) self::$runtimeparams['view'];
        } else if (!empty(self::$runtimeparams[self::PARAM_METRIC])) {
            $metric = (string) self::$runtimeparams[self::PARAM_METRIC];
        }

        if (!empty(self::$runtimeparams[self::PARAM_PRESET])) {
            $preset = (string) self::$runtimeparams[self::PARAM_PRESET];
        }

        if (array_key_exists(self::PARAM_FROM, self::$runtimeparams)) {
            $from = (string) self::$runtimeparams[self::PARAM_FROM];
        }

        if (array_key_exists(self::PARAM_TO, self::$runtimeparams)) {
            $to = (string) self::$runtimeparams[self::PARAM_TO];
        }

        return [
            self::PARAM_PRESET => $preset,
            self::PARAM_FROM => $from,
            self::PARAM_TO => $to,
            self::PARAM_METRIC => in_array($metric, ['timesec', 'visits'], true) ? $metric : 'timesec',
        ];
    }

    /**
     * Set runtime params for modal rendering.
     *
     * @param array $params
     * @return void
     */
    public static function set_runtime_params(array $params): void {
        self::$runtimeparams = $params;
    }

    /**
     * Clear runtime params.
     *
     * @return void
     */
    public static function clear_runtime_params(): void {
        self::$runtimeparams = [];
    }

    /**
     * Get active synthetic metric.
     *
     * @return string
     */
    public static function get_metric(): string {
        $params = self::get_params();

        return (string) $params[self::PARAM_METRIC];
    }

    /**
     * Get current period metadata.
     *
     * @return array
     */
    public static function get_period(): array {
        $params = self::get_params();
        $preset = (string) $params[self::PARAM_PRESET];
        $today = usergetmidnight(time());

        switch ($preset) {
            case 'today':
                return ['preset' => $preset, 'mode' => 'day', 'start' => $today, 'end' => $today];

            case 'yesterday':
                return ['preset' => $preset, 'mode' => 'day', 'start' => $today - DAYSECS, 'end' => $today - DAYSECS];

            case 'last_week':
                $start = self::week_start($today) - (7 * DAYSECS);
                return ['preset' => $preset, 'mode' => 'week', 'start' => $start, 'end' => $start + (6 * DAYSECS)];

            case 'this_month':
                $start = self::month_start($today);
                return ['preset' => $preset, 'mode' => 'month', 'start' => $start, 'end' => self::next_month_start($start) - DAYSECS];

            case 'last_month':
                $thismonth = self::month_start($today);
                $start = self::previous_month_start($thismonth);
                return ['preset' => $preset, 'mode' => 'month', 'start' => $start, 'end' => $thismonth - DAYSECS];

            case 'this_year':
                $start = self::year_start($today);
                return ['preset' => $preset, 'mode' => 'year', 'start' => $start, 'end' => self::next_year_start($start) - DAYSECS];

            case 'last_year':
                $thisyear = self::year_start($today);
                $start = self::previous_year_start($thisyear);
                return ['preset' => $preset, 'mode' => 'year', 'start' => $start, 'end' => $thisyear - DAYSECS];

            case 'custom':
                $start = self::parse_date((string) $params[self::PARAM_FROM], $today);
                $end = self::parse_date((string) $params[self::PARAM_TO], $start);
                if ($end < $start) {
                    $end = $start;
                }
                return ['preset' => $preset, 'mode' => self::custom_mode($start, $end), 'start' => $start, 'end' => $end];

            case 'this_week':
            default:
                $start = self::week_start($today);
                return ['preset' => 'this_week', 'mode' => 'week', 'start' => $start, 'end' => $start + (6 * DAYSECS)];
        }
    }

    /**
     * Get toolbar context.
     *
     * @param array $params
     * @return array
     */
    public static function get_toolbar_context(array $params): array {
        if (($params['synthetic']['type'] ?? '') !== 'dates') {
            return ['enabled' => false];
        }

        $active = self::get_params();
        $period = self::get_period();
        $metric = self::get_metric();
        $options = [
            'today' => get_string('today', 'local_la'),
            'yesterday' => get_string('yesterday', 'local_la'),
            'this_week' => get_string('thisweek', 'local_la'),
            'last_week' => get_string('lastweek', 'local_la'),
            'this_month' => get_string('thismonth', 'local_la'),
            'last_month' => get_string('lastmonth', 'local_la'),
            'this_year' => get_string('thisyear', 'local_la'),
            'last_year' => get_string('lastyear', 'local_la'),
            'custom' => get_string('custom', 'local_la'),
        ];
        $items = [];

        foreach ($options as $value => $label) {
            $items[] = [
                'value' => $value,
                'label' => $label,
                'selected' => $value === $period['preset'],
            ];
        }

        return [
            'enabled' => true,
            'label' => get_string('datecolumns', 'local_la'),
            'selected_label' => (string) ($options[$period['preset']] ?? get_string('thisweek', 'local_la')),
            'metric_name' => self::PARAM_METRIC,
            'timesec_selected' => $metric === 'timesec',
            'visits_selected' => $metric === 'visits',
            'preset_name' => self::PARAM_PRESET,
            'from_name' => self::PARAM_FROM,
            'to_name' => self::PARAM_TO,
            'from' => (string) $active[self::PARAM_FROM],
            'to' => (string) $active[self::PARAM_TO],
            'custom_selected' => $period['preset'] === 'custom',
            'options' => $items,
        ];
    }

    /**
     * Get synthetic params for URL preservation.
     *
     * @return array
     */
    public static function get_url_params(): array {
        $params = self::get_params();

        return array_filter($params, static function($value): bool {
            return $value !== '';
        });
    }

    /**
     * Build synthetic SQL columns.
     *
     * @param array $period
     * @param string $metric
     * @param array $scope
     * @return array
     */
    protected static function get_columns(array $period, string $metric, array $scope): array {
        if ($period['mode'] === 'day') {
            return array_merge(
                self::get_hour_columns((int) $period['start'], $metric, $scope),
                [self::get_total_column($period, $metric)]
            );
        }

        if ($period['mode'] === 'year') {
            return array_merge(
                self::get_month_columns((int) $period['start'], $metric, $scope),
                [self::get_total_column($period, $metric)]
            );
        }

        return array_merge(
            self::get_day_columns($period, $metric, $scope),
            [self::get_total_column($period, $metric)]
        );
    }

    /**
     * Build hourly columns for one day.
     *
     * @param int $daystamp
     * @param string $metric
     * @param array $scope
     * @return array
     */
    protected static function get_hour_columns(int $daystamp, string $metric, array $scope): array {
        $columns = [];
        $field = $metric === 'visits' ? 'visits' : 'timesec';
        [$join, $where] = self::get_scope_sql($scope);

        for ($hour = 0; $hour < 24; $hour++) {
            $columns[] = [
                'key' => 'synthetic_h_' . $hour,
                'label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00',
                'sql' => "(SELECT COALESCE(SUM(th.{$field}), 0)
                             FROM {local_la_time_total} tt2
                             JOIN {local_la_time_day} td ON td.totalid = tt2.id
                             JOIN {local_la_time_hour} th ON th.dayid = td.id
                             {$join}
                            WHERE tt2.userid = u.id
                              AND td.daystamp = {$daystamp}
                              AND th.hour = {$hour}
                              {$where})",
            ];
        }

        return $columns;
    }

    /**
     * Build day columns for week/month/custom ranges.
     *
     * @param array $period
     * @param string $metric
     * @param array $scope
     * @return array
     */
    protected static function get_day_columns(array $period, string $metric, array $scope): array {
        $columns = [];
        $start = (int) ($period['start'] ?? 0);
        $end = (int) ($period['end'] ?? 0);
        $field = $metric === 'visits' ? 'visits' : 'timesec';
        $labelformat = ($period['mode'] ?? '') === 'week' ? '%a' : '%b %e';
        [$join, $where] = self::get_scope_sql($scope);

        for ($stamp = $start; $stamp <= $end; $stamp += DAYSECS) {
            $columns[] = [
                'key' => 'synthetic_d_' . userdate($stamp, '%Y%m%d'),
                'label' => userdate($stamp, $labelformat),
                'stamp' => $stamp,
                'sql' => "(SELECT COALESCE(SUM(td.{$field}), 0)
                             FROM {local_la_time_total} tt2
                             JOIN {local_la_time_day} td ON td.totalid = tt2.id
                             {$join}
                            WHERE tt2.userid = u.id
                              AND td.daystamp = {$stamp}
                              {$where})",
            ];
        }

        return $columns;
    }

    /**
     * Build month columns for one year.
     *
     * @param int $yearstart
     * @param string $metric
     * @param array $scope
     * @return array
     */
    protected static function get_month_columns(int $yearstart, string $metric, array $scope): array {
        $columns = [];
        $field = $metric === 'visits' ? 'visits' : 'timesec';
        [$join, $where] = self::get_scope_sql($scope);

        for ($offset = 0; $offset < 12; $offset++) {
            $start = self::add_months($yearstart, $offset);
            $end = self::add_months($yearstart, $offset + 1) - DAYSECS;
            $columns[] = [
                'key' => 'synthetic_m_' . userdate($start, '%Y%m'),
                'label' => userdate($start, '%b'),
                'start' => $start,
                'end' => $end,
                'sql' => "(SELECT COALESCE(SUM(td.{$field}), 0)
                             FROM {local_la_time_total} tt2
                             JOIN {local_la_time_day} td ON td.totalid = tt2.id
                             {$join}
                            WHERE tt2.userid = u.id
                              AND td.daystamp >= {$start}
                              AND td.daystamp <= {$end}
                              {$where})",
            ];
        }

        return $columns;
    }

    /**
     * Build one aggregate join for synthetic day/month columns.
     *
     * @param array $period
     * @param string $metric
     * @param array $scope
     * @param array $columns
     * @return array
     */
    protected static function get_aggregate_dependency(array $period, string $metric, array $scope, array $columns): array {
        if (($period['mode'] ?? '') === 'day') {
            return [];
        }

        $field = $metric === 'visits' ? 'visits' : 'timesec';
        $alias = 'synthetic_time';
        $name = 'local_la_synthetic_time';
        $start = (int) ($period['start'] ?? 0);
        $end = (int) ($period['end'] ?? 0);
        $selects = ['tt2.userid'];
        $groups = ['tt2.userid'];
        $joins = [
            'JOIN {local_la_time_day} td ON td.totalid = tt2.id',
        ];
        $where = [
            "td.daystamp >= {$start}",
            "td.daystamp <= {$end}",
        ];
        $on = [
            "{$alias}.userid = u.id",
        ];

        if (!empty($scope['course']) || !empty($scope['activity'])) {
            $joins[] = 'JOIN {local_la_time_page} tp2 ON tp2.id = tt2.pageid';
        }

        if (!empty($scope['course'])) {
            $selects[] = 'tp2.courseid';
            $groups[] = 'tp2.courseid';
            $on[] = "{$alias}.courseid = tp.courseid";
        }

        if (!empty($scope['activity'])) {
            $selects[] = 'tp2.instanceid';
            $groups[] = 'tp2.instanceid';
            $where[] = "tp2.name = 'activity'";
            $on[] = "{$alias}.instanceid = tp.instanceid";
        }

        foreach ($columns as $column) {
            if (($column['key'] ?? '') === 'synthetic_total') {
                $selects[] = "SUM(td.{$field}) AS synthetic_total";
                continue;
            }

            if (!empty($column['start']) || !empty($column['end'])) {
                $columnstart = (int) ($column['start'] ?? 0);
                $columnend = (int) ($column['end'] ?? $columnstart);
                $selects[] = "SUM(CASE WHEN td.daystamp >= {$columnstart} AND td.daystamp <= {$columnend} " .
                    "THEN td.{$field} ELSE 0 END) AS " . $column['key'];
                continue;
            }

            if (!empty($column['stamp'])) {
                $stamp = (int) $column['stamp'];
                $selects[] = "SUM(CASE WHEN td.daystamp = {$stamp} THEN td.{$field} ELSE 0 END) AS " . $column['key'];
            }
        }

        $code = "LEFT JOIN (\n" .
            "    SELECT " . implode(",\n           ", $selects) . "\n" .
            "      FROM {local_la_time_total} tt2\n" .
            "      " . implode("\n      ", $joins) . "\n" .
            "     WHERE " . implode("\n       AND ", $where) . "\n" .
            "  GROUP BY " . implode(', ', $groups) . "\n" .
            ") {$alias} ON " . implode(' AND ', $on);

        return [
            'name' => $name,
            'alias' => $alias,
            'code' => $code,
        ];
    }

    /**
     * Get current synthetic row scope from active columns.
     *
     * @param array $params
     * @return array
     */
    protected static function get_scope(array $params): array {
        $hasactivity = self::has_visible_column($params, ['activity_id', 'activity_name', 'activity_type']);
        $hascourse = $hasactivity || self::has_visible_column($params, ['course_id', 'course_name']);

        return [
            'course' => $hascourse,
            'activity' => $hasactivity,
        ];
    }

    /**
     * Check whether any given column is visible.
     *
     * @param array $params
     * @param array $keys
     * @return bool
     */
    protected static function has_visible_column(array $params, array $keys): bool {
        foreach ($keys as $key) {
            if (!empty($params['columns'][$key]['visible'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build SQL fragments that scope synthetic values to the current row.
     *
     * @param array $scope
     * @return array
     */
    protected static function get_scope_sql(array $scope): array {
        if (empty($scope['course']) && empty($scope['activity'])) {
            return ['', ''];
        }

        $join = 'JOIN {local_la_time_page} tp2 ON tp2.id = tt2.pageid';
        $where = [];

        if (!empty($scope['course'])) {
            $where[] = 'tp2.courseid = tp.courseid';
        }

        if (!empty($scope['activity'])) {
            $where[] = "tp2.name = 'activity'";
            $where[] = 'tp2.instanceid = tp.instanceid';
        }

        return [$join, "\n                              AND " . implode("\n                              AND ", $where)];
    }

    /**
     * Build selected period total column.
     *
     * @param array $period
     * @param string $metric
     * @return array
     */
    protected static function get_total_column(array $period, string $metric): array {
        return [
            'key' => 'synthetic_total',
            'label' => get_string('totalsuffix', 'local_la', self::get_preset_label((string) $period['preset'])),
            'sql' => '0',
        ];
    }

    /**
     * Get visible preset label.
     *
     * @param string $preset
     * @return string
     */
    protected static function get_preset_label(string $preset): string {
        $labels = [
            'today' => get_string('today', 'local_la'),
            'yesterday' => get_string('yesterday', 'local_la'),
            'this_week' => get_string('thisweek', 'local_la'),
            'last_week' => get_string('lastweek', 'local_la'),
            'this_month' => get_string('thismonth', 'local_la'),
            'last_month' => get_string('lastmonth', 'local_la'),
            'this_year' => get_string('thisyear', 'local_la'),
            'last_year' => get_string('lastyear', 'local_la'),
            'custom' => get_string('custom', 'local_la'),
        ];

        return $labels[$preset] ?? get_string('selected', 'local_la');
    }

    /**
     * Get Monday start for a week.
     *
     * @param int $stamp
     * @return int
     */
    protected static function week_start(int $stamp): int {
        $weekday = (int) userdate($stamp, '%u') - 1;

        return usergetmidnight($stamp) - ($weekday * DAYSECS);
    }

    /**
     * Get month start.
     *
     * @param int $stamp
     * @return int
     */
    protected static function month_start(int $stamp): int {
        return usergetmidnight(make_timestamp((int) userdate($stamp, '%Y'), (int) userdate($stamp, '%m'), 1));
    }

    /**
     * Get next month start.
     *
     * @param int $monthstart
     * @return int
     */
    protected static function next_month_start(int $monthstart): int {
        return self::add_months($monthstart, 1);
    }

    /**
     * Get previous month start.
     *
     * @param int $monthstart
     * @return int
     */
    protected static function previous_month_start(int $monthstart): int {
        return self::add_months($monthstart, -1);
    }

    /**
     * Get year start.
     *
     * @param int $stamp
     * @return int
     */
    protected static function year_start(int $stamp): int {
        return usergetmidnight(make_timestamp((int) userdate($stamp, '%Y'), 1, 1));
    }

    /**
     * Get next year start.
     *
     * @param int $yearstart
     * @return int
     */
    protected static function next_year_start(int $yearstart): int {
        return usergetmidnight(make_timestamp((int) userdate($yearstart, '%Y') + 1, 1, 1));
    }

    /**
     * Get previous year start.
     *
     * @param int $yearstart
     * @return int
     */
    protected static function previous_year_start(int $yearstart): int {
        return usergetmidnight(make_timestamp((int) userdate($yearstart, '%Y') - 1, 1, 1));
    }

    /**
     * Add months to a month-start timestamp.
     *
     * @param int $stamp
     * @param int $months
     * @return int
     */
    protected static function add_months(int $stamp, int $months): int {
        $year = (int) userdate($stamp, '%Y');
        $month = (int) userdate($stamp, '%m') + $months;

        while ($month > 12) {
            $month -= 12;
            $year++;
        }

        while ($month < 1) {
            $month += 12;
            $year--;
        }

        return usergetmidnight(make_timestamp($year, $month, 1));
    }

    /**
     * Parse YYYY-MM-DD date.
     *
     * @param string $date
     * @param int $fallback
     * @return int
     */
    protected static function parse_date(string $date, int $fallback): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $fallback;
        }

        $stamp = strtotime($date . ' 00:00:00');

        return $stamp ? usergetmidnight($stamp) : $fallback;
    }

    /**
     * Resolve custom mode.
     *
     * @param int $start
     * @param int $end
     * @return string
     */
    protected static function custom_mode(int $start, int $end): string {
        $days = (int) floor(($end - $start) / DAYSECS) + 1;

        if ($days <= 1) {
            return 'day';
        }

        if ($days > 62) {
            return 'year';
        }

        return 'month';
    }
}
