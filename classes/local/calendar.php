<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

use local_la\local\filters as filters_helper;
use local_la\local\url as url_helper;

/**
 * Calendar helper.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar {
    public static function get_modal_context(
        string $metric,
        string $scope,
        int $userid = 0,
        int $courseid = 0,
        int $activityid = 0,
        string $view = 'month',
        int $year = 0,
        int $month = 0,
        int $day = 0,
        string $name = '',
        int $instanceid = 0
    ): array {
        global $DB;

        $metric = self::normalise_metric($metric);
        $view = self::normalise_view($view);
        [$summary, $where, $params] = self::resolve_scope_context($scope, $userid, $courseid, $activityid, $name, $instanceid);

        $select = $metric === 'visits' ? 'SUM(d.visits) AS value' : 'SUM(d.timesec) AS value';
        $sql = "SELECT d.daystamp,
                       {$select}
                  FROM {local_la_time_day} d
                  JOIN {local_la_time_total} t ON t.id = d.totalid
                  JOIN {local_la_time_page} p ON p.id = t.pageid
                 WHERE {$where}
              GROUP BY d.daystamp
              ORDER BY d.daystamp ASC";
        $records = $DB->get_records_sql($sql, $params);
        $days = array_values(array_map(static function($record): array {
            return [
                'daystamp' => (int) $record->daystamp,
                'value' => (int) $record->value,
            ];
        }, $records));

        $anchor = self::resolve_anchor($days, $year, $month, $day);

        if ($view === 'week') {
            $viewcontext = self::build_week_view($metric, $scope, $userid, $courseid, $activityid, $anchor['year'], $anchor['month'], $anchor['day'], $name, $instanceid);
        } else if ($view === 'year') {
            $viewcontext = self::build_year_view($days, $metric, $anchor['year'], $anchor['month']);
        } else if ($view === 'years') {
            $viewcontext = self::build_years_view($days, $metric, $anchor['year']);
        } else {
            $viewcontext = self::build_month_view($days, $metric, $anchor['year'], $anchor['month']);
        }

        return array_merge([
            'summary' => $summary,
            'filtertags' => self::build_filter_tags($scope, $summary, $userid, $courseid, $activityid),
            'trackingreport' => $scope === 'report_page' ? [] : self::build_tracking_report_link($scope, $metric, $userid, $courseid, $activityid),
            'metricisvisits' => $metric === 'visits',
            'metrics' => self::build_metrics($metric, (int) ($viewcontext['total'] ?? 0), $scope),
            'legendlabel' => get_string($metric === 'visits' ? 'visits' : 'timespent', 'local_la'),
            'legend' => self::build_legend($metric),
            'calendar_metric' => $metric,
            'calendar_scope' => $scope,
            'calendar_userid' => $userid,
            'calendar_courseid' => $courseid,
            'calendar_activityid' => $activityid,
            'calendar_view' => $viewcontext['view'],
            'calendar_year' => $viewcontext['year'],
            'calendar_month' => $viewcontext['month'],
            'calendar_day' => $viewcontext['day'],
            'calendar_name' => $name,
            'calendar_instanceid' => $instanceid,
            'debugsql' => helper::is_debug_enabled() ? ($viewcontext['debugsql'] ?? $sql) : '',
        ], $viewcontext);
    }

    protected static function build_week_view(
        string $metric,
        string $scope,
        int $userid,
        int $courseid,
        int $activityid,
        int $year,
        int $month,
        int $day,
        string $name = '',
        int $instanceid = 0
    ): array {
        global $DB;

        [, $where, $params] = self::resolve_scope_context($scope, $userid, $courseid, $activityid, $name, $instanceid);
        $daystamp = usergetmidnight(make_timestamp($year, $month, $day, 0, 0, 0));
        $weekstart = $daystamp - ((((int) userdate($daystamp, '%w') + 6) % 7) * DAYSECS);
        $weekend = $weekstart + (6 * DAYSECS);

        $select = $metric === 'visits' ? 'SUM(h.visits) AS value' : 'SUM(h.timesec) AS value';
        $sql = "SELECT d.daystamp,
                       h.hour,
                       {$select}
                  FROM {local_la_time_hour} h
                  JOIN {local_la_time_day} d ON d.id = h.dayid
                  JOIN {local_la_time_total} t ON t.id = d.totalid
                  JOIN {local_la_time_page} p ON p.id = t.pageid
                 WHERE {$where}
                   AND d.daystamp >= :weekstart
                   AND d.daystamp <= :weekend
              GROUP BY d.daystamp, h.hour
              ORDER BY h.hour ASC, d.daystamp ASC";
        $hourrecords = $DB->get_records_sql($sql, $params + [
            'weekstart' => $weekstart,
            'weekend' => $weekend,
        ]);

        $values = [];
        $maxvalue = 0;
        $total = 0;
        foreach ($hourrecords as $record) {
            $stamp = (int) $record->daystamp;
            $hour = (int) $record->hour;
            $value = (int) $record->value;
            if (!isset($values[$hour])) {
                $values[$hour] = [];
            }
            $values[$hour][$stamp] = $value;
            $maxvalue = max($maxvalue, $value);
            $total += $value;
        }

        $weekdays = [];
        for ($index = 0; $index < 7; $index++) {
            $stamp = $weekstart + ($index * DAYSECS);
            $weekdays[] = [
                'label' => userdate($stamp, '%a'),
                'day' => userdate($stamp, '%e'),
            ];
        }

        $hours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $row = [
                'label' => ltrim(userdate(make_timestamp($year, $month, max(1, $day), $hour, 0, 0), '%I %p'), '0'),
                'cells' => [],
            ];
            for ($index = 0; $index < 7; $index++) {
                $stamp = $weekstart + ($index * DAYSECS);
                $value = (int) ($values[$hour][$stamp] ?? 0);
                $row['cells'][] = [
                    'value' => self::format_cell_value($metric, $value),
                    'title' => userdate($stamp, '%A, %b %e') . ' · ' . $row['label'] . ' · ' . self::format_metric_value($metric, $value),
                    'level' => self::get_heatmap_level($value, $maxvalue),
                    'hasactivity' => $value > 0,
                ];
            }
            $hours[] = $row;
        }

        [$prevyear, $prevmonth, $prevday] = self::shift_week($weekstart, -1);
        [$nextyear, $nextmonth, $nextday] = self::shift_week($weekstart, 1);

        return [
            'view' => 'week',
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'headerlabel' => userdate($weekstart, '%b %e') . ' - ' . userdate($weekend, '%b %e, %Y'),
            'headerclickable' => true,
            'headertargetview' => 'month',
            'headertargetyear' => $year,
            'headertargetmonth' => $month,
            'headertargetday' => $day,
            'prevview' => 'week',
            'prevyear' => $prevyear,
            'prevmonth' => $prevmonth,
            'prevday' => $prevday,
            'nextview' => 'week',
            'nextyear' => $nextyear,
            'nextmonth' => $nextmonth,
            'nextday' => $nextday,
            'weekdays' => $weekdays,
            'hours' => $hours,
            'total' => $total,
            'debugsql' => $sql,
            'hasweekdays' => false,
            'ismonthview' => false,
            'isweekview' => true,
            'isyearview' => false,
            'isyearsview' => false,
        ];
    }

    protected static function build_month_view(array $days, string $metric, int $year, int $month): array {
        $byday = [];
        $maxvalue = 0;

        foreach ($days as $day) {
            $stamp = (int) $day['daystamp'];
            $byday[$stamp] = (int) $day['value'];
        }

        $monthstart = usergetmidnight(make_timestamp($year, $month, 1, 0, 0, 0));
        $nextmonthyear = $month === 12 ? $year + 1 : $year;
        $nextmonth = $month === 12 ? 1 : $month + 1;
        $nextmonthstart = usergetmidnight(make_timestamp($nextmonthyear, $nextmonth, 1, 0, 0, 0));
        $monthdays = (int) (($nextmonthstart - $monthstart) / DAYSECS);

        for ($stamp = $monthstart; $stamp < $nextmonthstart; $stamp += DAYSECS) {
            $maxvalue = max($maxvalue, (int) ($byday[$stamp] ?? 0));
        }

        $startweekday = ((int) userdate($monthstart, '%w') + 6) % 7;
        $gridstart = $monthstart - ($startweekday * DAYSECS);
        $monthend = $monthstart + (($monthdays - 1) * DAYSECS);
        $endweekday = ((int) userdate($monthend, '%w') + 6) % 7;
        $gridend = $monthend + ((6 - $endweekday) * DAYSECS);

        $weekdays = [
            ['label' => 'Mo'], ['label' => 'Tu'], ['label' => 'We'], ['label' => 'Th'],
            ['label' => 'Fr'], ['label' => 'Sa'], ['label' => 'Su'],
        ];

        $weeks = [];
        $total = 0;
        for ($weekstart = $gridstart; $weekstart <= $gridend; $weekstart += (7 * DAYSECS)) {
            $week = ['days' => []];
            for ($index = 0; $index < 7; $index++) {
                $daystamp = $weekstart + ($index * DAYSECS);
                $value = (int) ($byday[$daystamp] ?? 0);
                $isinmonth = $daystamp >= $monthstart && $daystamp <= $monthend;
                $total += $value;

                $week['days'][] = [
                    'day' => userdate($daystamp, '%e'),
                    'value' => self::format_cell_value($metric, $value),
                    'title' => userdate($daystamp, '%A, %b %e, %Y') . ' · ' . self::format_metric_value($metric, $value),
                    'level' => self::get_heatmap_level($value, $maxvalue),
                    'isoutside' => !$isinmonth,
                    'hasactivity' => $value > 0,
                    'targetview' => 'week',
                    'targetyear' => (int) userdate($daystamp, '%Y'),
                    'targetmonth' => (int) userdate($daystamp, '%m'),
                    'targetday' => (int) userdate($daystamp, '%d'),
                ];
            }
            $weeks[] = $week;
        }

        [$prevyear, $prevmonth] = self::shift_month($year, $month, -1);
        [$nextyear, $nextmonth] = self::shift_month($year, $month, 1);

        return [
            'view' => 'month',
            'year' => $year,
            'month' => $month,
            'day' => 1,
            'headerlabel' => userdate($monthstart, '%B %Y'),
            'headerclickable' => true,
            'headertargetview' => 'year',
            'headertargetyear' => $year,
            'headertargetmonth' => $month,
            'headertargetday' => 1,
            'prevview' => 'month',
            'prevyear' => $prevyear,
            'prevmonth' => $prevmonth,
            'prevday' => 1,
            'nextview' => 'month',
            'nextyear' => $nextyear,
            'nextmonth' => $nextmonth,
            'nextday' => 1,
            'weekdays' => $weekdays,
            'weeks' => $weeks,
            'total' => $total,
            'hasweekdays' => true,
            'ismonthview' => true,
            'isweekview' => false,
            'isyearview' => false,
            'isyearsview' => false,
        ];
    }

    protected static function build_year_view(array $days, string $metric, int $year, int $selectedmonth): array {
        $values = array_fill(1, 12, 0);

        foreach ($days as $day) {
            $stamp = (int) $day['daystamp'];
            if ((int) userdate($stamp, '%Y') !== $year) {
                continue;
            }
            $month = (int) userdate($stamp, '%m');
            $values[$month] += (int) $day['value'];
        }

        $maxvalue = max($values);
        $months = [];
        $total = 0;

        for ($month = 1; $month <= 12; $month++) {
            $stamp = usergetmidnight(make_timestamp($year, $month, 1, 0, 0, 0));
            $value = (int) $values[$month];
            $total += $value;
            $months[] = [
                'label' => userdate($stamp, '%b'),
                'value' => self::format_cell_value($metric, $value),
                'title' => userdate($stamp, '%B %Y') . ' · ' . self::format_metric_value($metric, $value),
                'level' => self::get_heatmap_level($value, $maxvalue),
                'hasactivity' => $value > 0,
                'isselected' => $month === $selectedmonth,
                'targetview' => 'month',
                'targetyear' => $year,
                'targetmonth' => $month,
            ];
        }

        return [
            'view' => 'year',
            'year' => $year,
            'month' => $selectedmonth,
            'day' => 1,
            'headerlabel' => (string) $year,
            'headerclickable' => true,
            'headertargetview' => 'years',
            'headertargetyear' => $year,
            'headertargetmonth' => $selectedmonth,
            'headertargetday' => 1,
            'prevview' => 'year',
            'prevyear' => $year - 1,
            'prevmonth' => $selectedmonth,
            'prevday' => 1,
            'nextview' => 'year',
            'nextyear' => $year + 1,
            'nextmonth' => $selectedmonth,
            'nextday' => 1,
            'months' => $months,
            'total' => $total,
            'hasweekdays' => false,
            'ismonthview' => false,
            'isweekview' => false,
            'isyearview' => true,
            'isyearsview' => false,
        ];
    }

    protected static function build_years_view(array $days, string $metric, int $year): array {
        $decadestart = (int) floor($year / 10) * 10;
        $gridstart = $decadestart - 1;
        $gridend = $decadestart + 10;
        $values = [];

        foreach ($days as $day) {
            $dayyear = (int) userdate((int) $day['daystamp'], '%Y');
            if (!isset($values[$dayyear])) {
                $values[$dayyear] = 0;
            }
            $values[$dayyear] += (int) $day['value'];
        }

        $maxvalue = 0;
        for ($itemyear = $gridstart; $itemyear <= $gridend; $itemyear++) {
            $maxvalue = max($maxvalue, (int) ($values[$itemyear] ?? 0));
        }

        $years = [];
        $total = 0;
        for ($itemyear = $gridstart; $itemyear <= $gridend; $itemyear++) {
            $value = (int) ($values[$itemyear] ?? 0);
            $total += $value;
            $years[] = [
                'label' => (string) $itemyear,
                'value' => self::format_cell_value($metric, $value),
                'title' => $itemyear . ' · ' . self::format_metric_value($metric, $value),
                'level' => self::get_heatmap_level($value, $maxvalue),
                'hasactivity' => $value > 0,
                'isselected' => $itemyear === $year,
                'isoutside' => $itemyear < $decadestart || $itemyear > ($decadestart + 9),
                'targetview' => 'year',
                'targetyear' => $itemyear,
                'targetmonth' => 1,
            ];
        }

        return [
            'view' => 'years',
            'year' => $year,
            'month' => 1,
            'day' => 1,
            'headerlabel' => $decadestart . ' - ' . ($decadestart + 9),
            'headerclickable' => false,
            'prevview' => 'years',
            'prevyear' => $year - 10,
            'prevmonth' => 1,
            'prevday' => 1,
            'nextview' => 'years',
            'nextyear' => $year + 10,
            'nextmonth' => 1,
            'nextday' => 1,
            'years' => $years,
            'total' => $total,
            'hasweekdays' => false,
            'ismonthview' => false,
            'isweekview' => false,
            'isyearview' => false,
            'isyearsview' => true,
        ];
    }

    public static function normalise_metric(string $metric): string {
        return $metric === 'visits' ? 'visits' : 'timesec';
    }

    public static function normalise_view(string $view): string {
        return in_array($view, ['month', 'week', 'year', 'years'], true) ? $view : 'month';
    }

    public static function resolve_anchor(array $days, int $year, int $month, int $day): array {
        $lateststamp = 0;
        foreach ($days as $dayrecord) {
            $lateststamp = max($lateststamp, (int) $dayrecord['daystamp']);
        }
        $basestamp = $lateststamp > 0 ? $lateststamp : usergetmidnight(time());

        return [
            'year' => $year > 0 ? $year : (int) userdate($basestamp, '%Y'),
            'month' => $month >= 1 && $month <= 12 ? $month : (int) userdate($basestamp, '%m'),
            'day' => $day >= 1 && $day <= 31 ? $day : (int) userdate($basestamp, '%d'),
        ];
    }

    public static function shift_month(int $year, int $month, int $delta): array {
        $index = (($year * 12) + ($month - 1)) + $delta;
        $shiftedyear = (int) floor($index / 12);
        $shiftedmonth = ($index % 12) + 1;
        if ($shiftedmonth <= 0) {
            $shiftedmonth += 12;
            $shiftedyear--;
        }
        return [$shiftedyear, $shiftedmonth];
    }

    public static function shift_week(int $weekstart, int $delta): array {
        $targetstamp = $weekstart + ($delta * 7 * DAYSECS);
        return [
            (int) userdate($targetstamp, '%Y'),
            (int) userdate($targetstamp, '%m'),
            (int) userdate($targetstamp, '%d'),
        ];
    }

    public static function build_metrics(string $metric, int $total, string $scope = ''): array {
        if ($metric === 'visits') {
            return [
                ['value' => (string) $total, 'label' => get_string('visits', 'local_la')],
            ];
        }

        return [
            [
                'value' => self::format_duration_value($total),
                'label' => get_string($scope === 'report_page' ? 'reporttime' : 'learningtime', 'local_la'),
            ],
        ];
    }

    public static function build_legend(string $metric): array {
        if ($metric === 'visits') {
            return [
                ['label' => get_string('calendarvisitslegend1', 'local_la'), 'level' => 1],
                ['label' => get_string('calendarvisitslegend2', 'local_la'), 'level' => 2],
                ['label' => get_string('calendarvisitslegend3', 'local_la'), 'level' => 3],
                ['label' => get_string('calendarvisitslegend4', 'local_la'), 'level' => 4],
            ];
        }

        return [
            ['label' => get_string('calendartimelegend1', 'local_la'), 'level' => 1],
            ['label' => get_string('calendartimelegend2', 'local_la'), 'level' => 2],
            ['label' => get_string('calendartimelegend3', 'local_la'), 'level' => 3],
            ['label' => get_string('calendartimelegend4', 'local_la'), 'level' => 4],
        ];
    }

    public static function get_heatmap_level(int $value, int $maxvalue): int {
        if ($value <= 0 || $maxvalue <= 0) {
            return 0;
        }
        $ratio = $value / $maxvalue;
        if ($ratio <= 0.25) {
            return 1;
        }
        if ($ratio <= 0.5) {
            return 2;
        }
        if ($ratio <= 0.75) {
            return 3;
        }
        return 4;
    }

    public static function format_metric_value(string $metric, int $value): string {
        if ($metric === 'visits') {
            return $value . ' visits';
        }
        return self::format_duration_value($value);
    }

    public static function format_cell_value(string $metric, int $value): string {
        if ($metric === 'visits') {
            return (string) $value;
        }
        return self::format_duration_value($value);
    }

    public static function format_duration_value(int $seconds): string {
        if ($seconds <= 0) {
            return '0m';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < (5 * 60)) {
            $minutes = (int) floor($seconds / 60);
            $remainingseconds = $seconds % 60;
            if ($remainingseconds <= 0) {
                return $minutes . 'm';
            }
            return $minutes . 'm ' . $remainingseconds . 's';
        }
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }
        return max(1, $minutes) . 'm';
    }

    protected static function resolve_scope_context(
        string $scope,
        int $userid,
        int $courseid,
        int $activityid,
        string $name = '',
        int $instanceid = 0
    ): array {
        global $DB;

        switch ($scope) {
            case 'all':
                return [[
                    'primary' => get_string('alltrackedpages', 'local_la'),
                    'secondary' => get_string('alllearners', 'local_la'),
                ], '1 = 1', []];

            case 'user':
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
                return [
                    self::get_user_summary($user, get_string('alltrackedpages', 'local_la')),
                    't.userid = :userid',
                    ['userid' => $user->id],
                ];

            case 'course':
                $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
                return [[
                    'primary' => format_string((string) $course->fullname),
                    'secondary' => get_string('alllearners', 'local_la'),
                ], 'p.courseid = :courseid', ['courseid' => $course->id]];

            case 'user_course':
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
                $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
                return [
                    self::get_user_summary($user, format_string((string) $course->fullname)),
                    't.userid = :userid AND p.courseid = :courseid',
                    [
                        'userid' => $user->id,
                        'courseid' => $course->id,
                    ],
                ];

            case 'activity':
                [$activityname, $coursename] = self::get_activity_summary($activityid);
                $where = "p.name = 'activity' AND p.instanceid = :activityid";
                $params = ['activityid' => $activityid];
                if ($courseid > 0) {
                    $where .= ' AND p.courseid = :courseid';
                    $params['courseid'] = $courseid;
                }
                return [[
                    'primary' => $activityname,
                    'secondary' => $coursename,
                ], $where, $params];

            case 'user_activity':
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
                [$activityname, $coursename] = self::get_activity_summary($activityid);
                $where = "t.userid = :userid AND p.name = 'activity' AND p.instanceid = :activityid";
                $params = [
                    'userid' => $user->id,
                    'activityid' => $activityid,
                ];
                if ($courseid > 0) {
                    $where .= ' AND p.courseid = :courseid';
                    $params['courseid'] = $courseid;
                }
                return [
                    self::get_user_summary($user, $activityname . ' · ' . $coursename),
                    $where,
                    $params,
                ];

            case 'report_page':
                if ($name !== 'la_report' || $userid <= 0 || $instanceid <= 0 ||
                        !helper::can_use_drilldown() || !audience::has_access($instanceid)) {
                    throw new \moodle_exception('nopermissions', 'error');
                }

                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
                $report = $DB->get_record('local_la_report', ['id' => $instanceid], 'id, name', MUST_EXIST);
                $where = 't.userid = :userid AND p.name = :pagename AND p.instanceid = :pageinstanceid';
                $params = [
                    'userid' => $user->id,
                    'pagename' => $name,
                    'pageinstanceid' => $report->id,
                ];
                if ($courseid > 0) {
                    $where .= ' AND p.courseid = :courseid';
                    $params['courseid'] = $courseid;
                }

                return [
                    self::get_user_summary($user, format_string((string) $report->name)),
                    $where,
                    $params,
                ];
        }

        throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
    }

    protected static function get_user_summary(\stdClass $user, string $secondary): array {
        global $PAGE;

        $picture = new \user_picture($user);
        $picture->size = 64;
        $fullname = fullname($user);

        return [
            'primary' => $fullname,
            'secondary' => $secondary,
            'hasavatar' => true,
            'avatarurl' => $picture->get_url($PAGE)->out(false),
            'avataralt' => $fullname,
        ];
    }

    protected static function build_filter_tags(string $scope, array $summary, int $userid, int $courseid, int $activityid): array {
        $tags = [];

        if ($scope === 'user' && !empty($summary['primary'])) {
            $tags[] = self::make_filter_tag(get_string('user'), (string) $summary['primary'], 'all', 0, 0, 0);
        } else if ($scope === 'course' && !empty($summary['primary'])) {
            $tags[] = self::make_filter_tag(get_string('course'), (string) $summary['primary'], 'all', 0, 0, 0);
        } else if ($scope === 'user_course') {
            if (!empty($summary['primary'])) {
                $tags[] = self::make_filter_tag(get_string('user'), (string) $summary['primary'], 'course', 0, $courseid, 0);
            }
            if (!empty($summary['secondary'])) {
                $tags[] = self::make_filter_tag(get_string('course'), (string) $summary['secondary'], 'user', $userid, 0, 0);
            }
        } else if ($scope === 'activity') {
            if (!empty($summary['secondary'])) {
                $tags[] = self::make_filter_tag(get_string('course'), (string) $summary['secondary'], 'activity', 0, 0, $activityid);
            }
            if (!empty($summary['primary'])) {
                $tags[] = self::make_filter_tag(get_string('activity'), (string) $summary['primary'], $courseid > 0 ? 'course' : 'all', 0, $courseid, 0);
            }
        } else if ($scope === 'user_activity') {
            if (!empty($summary['primary'])) {
                $tags[] = self::make_filter_tag(get_string('user'), (string) $summary['primary'], 'activity', 0, $courseid, $activityid);
            }

            $parts = array_map('trim', explode('·', (string) ($summary['secondary'] ?? '')));
            if (!empty($parts[1])) {
                $tags[] = self::make_filter_tag(get_string('course'), $parts[1], 'user_activity', $userid, 0, $activityid);
            }
            if (!empty($parts[0])) {
                $tags[] = self::make_filter_tag(get_string('activity'), $parts[0], $courseid > 0 ? 'user_course' : 'user', $userid, $courseid, 0);
            }
        } else if ($scope === 'report_page') {
            if (!empty($summary['primary'])) {
                $tags[] = [
                    'label' => get_string('user'),
                    'value' => (string) $summary['primary'],
                ];
            }
            if (!empty($summary['secondary'])) {
                $tags[] = [
                    'label' => get_string('report'),
                    'value' => (string) $summary['secondary'],
                ];
            }
        }

        return $tags;
    }

    protected static function make_filter_tag(string $label, string $value, string $targetscope, int $targetuserid, int $targetcourseid, int $targetactivityid): array {
        return [
            'label' => $label,
            'value' => $value,
            'removable' => true,
            'targetscope' => $targetscope,
            'targetuserid' => $targetuserid,
            'targetcourseid' => $targetcourseid,
            'targetactivityid' => $targetactivityid,
        ];
    }

    protected static function build_tracking_report_link(string $scope, string $metric, int $userid, int $courseid, int $activityid): array {
        global $DB;

        $report = $DB->get_record('local_la_report', ['shortname' => 'users_tracking'], 'id, name');
        $reportid = (int) ($report->id ?? 0);
        if ($reportid <= 0) {
            return [];
        }

        $filters = [];

        if (in_array($scope, ['user', 'user_course', 'user_activity'], true) && $userid > 0) {
            $filters['user_id'] = [
                'operator' => 'equal',
                'value' => (string) $userid,
            ];
        }

        if (in_array($scope, ['course', 'user_course', 'activity', 'user_activity'], true) && $courseid > 0) {
            $filters['course_id'] = [
                'operator' => 'equal',
                'value' => (string) $courseid,
            ];
        }

        if (in_array($scope, ['activity', 'user_activity'], true) && $activityid > 0) {
            $filters['activity_id'] = [
                'operator' => 'equal',
                'value' => (string) $activityid,
            ];
        }

        $params = ['tab' => 'view'];
        $view = $metric === 'visits' ? 'visits' : 'timesec';
        $params['view'] = $view;

        return [
            'url' => url_helper::report_tab(
                $reportid,
                filters_helper::get_params($filters, $params, true)
            ),
            'label' => trim((string) ($report->name ?? '')) ?: get_string('userstracking', 'local_la'),
        ];
    }

    protected static function get_activity_summary(int $activityid): array {
        global $DB;

        $record = $DB->get_record_sql(
            "SELECT cm.id,
                    c.id AS courseid,
                    c.fullname AS coursename
               FROM {course_modules} cm
               JOIN {course} c ON c.id = cm.course
              WHERE cm.id = :id",
            ['id' => $activityid],
            MUST_EXIST
        );

        $modinfo = get_fast_modinfo((int) $record->courseid);
        $cm = $modinfo->get_cm((int) $record->id);

        return [
            format_string((string) $cm->name),
            format_string((string) $record->coursename),
        ];
    }
}
