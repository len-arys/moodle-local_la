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

defined('MOODLE_INTERNAL') || die();

/**
 * Builds lightweight analyst summaries for selected report rows.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analysis {
    /** @var string[] Semantic columns we can reason about safely. */
    protected const SEMANTICS = [
        'user' => ['user', 'userid', 'username', 'firstname', 'lastname', 'fullname', 'email'],
        'course' => ['course', 'course_name', 'coursename', 'coursefullname', 'shortname'],
        'grade' => ['grade', 'score', 'finalgrade', 'rawgrade', 'avggrade', 'averagegrade', 'course_avg_grade'],
        'progress' => ['progress', 'completion', 'completionrate', 'completionpercent'],
        'status' => ['status', 'state', 'visible', 'enabled'],
        'lastaccess' => ['lastaccess', 'last_access', 'timeaccess', 'lastlogin', 'last_login'],
        'enrolment' => ['enrol', 'enrollment', 'enrolment', 'enrolled'],
        'time' => ['timespent', 'time_spent', 'learningtime', 'learning_time', 'timesec', 'duration'],
        'visits' => ['visits', 'views', 'pageviews', 'hits'],
        'attempts' => ['attempt', 'attempts'],
        'activity' => ['activity', 'activities', 'module', 'activitycount', 'activity_count'],
    ];

    /** @var string[] Semantics that should be treated as numeric measures. */
    protected const MEASURES = ['grade', 'progress', 'time', 'visits', 'attempts', 'activity'];

    /**
     * Build selected-row summary context.
     *
     * @param array $rows
     * @return array
     */
    public static function summary_context(array $rows): array {
        $rows = self::normalise_rows($rows);
        $profile = self::profile($rows);
        $semantics = self::detect_semantics($profile['columns']);
        $stats = self::measure_stats($rows, $semantics);
        $dominant = self::dominant_signal($rows, $semantics, $profile['columns']);

        $metrics = [
            ['label' => get_string('rowsselected', 'local_la'), 'value' => $profile['rowcount']],
            ['label' => get_string('columnsanalyzed', 'local_la'), 'value' => $profile['columncount']],
            ['label' => get_string('missingvalues', 'local_la'), 'value' => $profile['missingvalues']],
        ];

        if ($profile['duplicaterows'] > 0) {
            $metrics[] = ['label' => get_string('duplicaterows', 'local_la'), 'value' => $profile['duplicaterows']];
        }

        if ($dominant !== null) {
            $metrics[] = [
                'label' => get_string('dominantvalue', 'local_la'),
                'value' => $dominant['value'],
                'detail' => get_string('xrows', 'local_la', $dominant['count']),
            ];
        }

        foreach (['progress', 'grade', 'time', 'visits', 'attempts'] as $semantic) {
            if (!isset($stats[$semantic])) {
                continue;
            }

            $metrics[] = [
                'label' => get_string('avg' . $semantic, 'local_la'),
                'value' => self::format_measure($stats[$semantic]['average'], $semantic),
                'detail' => get_string('rangevalue', 'local_la', (object) [
                    'min' => self::format_measure($stats[$semantic]['min'], $semantic),
                    'max' => self::format_measure($stats[$semantic]['max'], $semantic),
                ]),
            ];
        }

        return [
            'count' => $profile['rowcount'],
            'countlabel' => get_string('selectedrowscount', 'local_la', $profile['rowcount']),
            'summary' => self::summary_text($profile, $dominant, $stats),
            'metrics' => array_slice($metrics, 0, 8),
            'focusitems' => self::focus_items($rows, $profile, $semantics, $stats),
        ];
    }

    /**
     * Build pattern context.
     *
     * @param array $rows
     * @return array
     */
    public static function patterns_context(array $rows): array {
        $rows = self::normalise_rows($rows);
        $profile = self::profile($rows);
        $semantics = self::detect_semantics($profile['columns']);
        $stats = self::measure_stats($rows, $semantics);
        $signals = self::signals($rows, $profile, $semantics, $stats);

        return [
            'signals' => $signals,
            'actions' => self::actions($signals),
        ];
    }

    /**
     * Keep only array rows and normalize scalar values.
     *
     * @param array $rows
     * @return array
     */
    protected static function normalise_rows(array $rows): array {
        $normalised = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [];
            foreach ($row as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $item[(string) $key] = trim((string) $value);
                }
            }

            if ($item) {
                $normalised[] = $item;
            }
        }

        return $normalised;
    }

    /**
     * Profile selected rows.
     *
     * @param array $rows
     * @return array
     */
    protected static function profile(array $rows): array {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }

        $columns = array_keys($columns);
        $missing = 0;
        $duplicates = [];
        $duplicaterows = 0;

        foreach ($rows as $row) {
            $fingerprint = [];
            foreach ($columns as $column) {
                $value = trim((string) ($row[$column] ?? ''));
                $missing += $value === '' ? 1 : 0;
                $fingerprint[$column] = $value;
            }

            $hash = json_encode($fingerprint);
            $duplicates[$hash] = ($duplicates[$hash] ?? 0) + 1;
            if ($duplicates[$hash] > 1) {
                $duplicaterows++;
            }
        }

        return [
            'rowcount' => count($rows),
            'columns' => $columns,
            'columncount' => count($columns),
            'missingvalues' => $missing,
            'duplicaterows' => $duplicaterows,
        ];
    }

    /**
     * Detect likely LMS semantics from column names.
     *
     * @param array $columns
     * @return array
     */
    protected static function detect_semantics(array $columns): array {
        $detected = [];

        foreach ($columns as $column) {
            $normalised = preg_replace('/[^a-z0-9]+/', '', strtolower($column));

            foreach (self::SEMANTICS as $semantic => $needles) {
                if (isset($detected[$semantic])) {
                    continue;
                }

                foreach ($needles as $needle) {
                    if ($normalised === $needle || strpos($normalised, $needle) !== false) {
                        $detected[$semantic] = $column;
                        break;
                    }
                }
            }
        }

        return $detected;
    }

    /**
     * Return first non-empty row value.
     *
     * @param array $row
     * @param string|null $key
     * @return string
     */
    protected static function value(array $row, ?string $key): string {
        return $key !== null && isset($row[$key]) ? trim((string) $row[$key]) : '';
    }

    /**
     * Parse a measure into a comparable number.
     *
     * @param string $value
     * @param string $semantic
     * @return float|null
     */
    protected static function measure(string $value, string $semantic): ?float {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($semantic === 'time') {
            $seconds = self::duration_seconds($value);
            if ($seconds !== null) {
                return $seconds;
            }
        }

        if (!preg_match('/-?\d+(?:\.\d+)?/', str_replace(',', '', $value), $match)) {
            return null;
        }

        return (float) $match[0];
    }

    /**
     * Parse common duration strings into seconds.
     *
     * @param string $value
     * @return float|null
     */
    protected static function duration_seconds(string $value): ?float {
        $text = strtolower(str_replace(',', ' ', $value));
        $seconds = 0;
        $matched = false;

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(h|hr|hrs|hour|hours)\b/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $seconds += (float) $match[1] * HOURSECS;
                $matched = true;
            }
        }
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(m|min|mins|minute|minutes)\b/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $seconds += (float) $match[1] * MINSECS;
                $matched = true;
            }
        }
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(s|sec|secs|second|seconds)\b/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $seconds += (float) $match[1];
                $matched = true;
            }
        }

        return $matched ? $seconds : null;
    }

    /**
     * Calculate measure stats.
     *
     * @param array $rows
     * @param array $semantics
     * @return array
     */
    protected static function measure_stats(array $rows, array $semantics): array {
        $stats = [];

        foreach (self::MEASURES as $semantic) {
            if (empty($semantics[$semantic])) {
                continue;
            }

            $values = [];
            foreach ($rows as $index => $row) {
                $value = self::measure(self::value($row, $semantics[$semantic]), $semantic);
                if ($value !== null) {
                    $values[$index] = $value;
                }
            }

            if (!$values) {
                continue;
            }

            $sorted = array_values($values);
            sort($sorted, SORT_NUMERIC);
            $average = array_sum($values) / count($values);
            $stats[$semantic] = [
                'key' => $semantics[$semantic],
                'values' => $values,
                'count' => count($values),
                'min' => reset($sorted),
                'max' => end($sorted),
                'average' => $average,
                'median' => self::median($sorted),
                'stdev' => self::stdev($values, $average),
                'outliers' => self::iqr_outliers($values, $sorted),
            ];
        }

        return $stats;
    }

    /**
     * Median.
     *
     * @param array $sorted
     * @return float
     */
    protected static function median(array $sorted): float {
        $count = count($sorted);
        $middle = (int) floor($count / 2);

        return $count % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * Standard deviation.
     *
     * @param array $values
     * @param float $average
     * @return float
     */
    protected static function stdev(array $values, float $average): float {
        $variance = 0;
        foreach ($values as $value) {
            $variance += ($value - $average) ** 2;
        }

        return sqrt($variance / count($values));
    }

    /**
     * IQR outlier rows.
     *
     * @param array $values
     * @param array $sorted
     * @return array
     */
    protected static function iqr_outliers(array $values, array $sorted): array {
        if (count($sorted) < 4) {
            return [];
        }

        $q1 = self::median(array_slice($sorted, 0, (int) floor(count($sorted) / 2)));
        $q3 = self::median(array_slice($sorted, (int) ceil(count($sorted) / 2)));
        $iqr = $q3 - $q1;
        if ($iqr <= 0) {
            return [];
        }

        $low = $q1 - (1.5 * $iqr);
        $high = $q3 + (1.5 * $iqr);

        return array_filter($values, static function(float $value) use ($low, $high): bool {
            return $value < $low || $value > $high;
        });
    }

    /**
     * Dominant categorical signal.
     *
     * @param array $rows
     * @param array $semantics
     * @param array $columns
     * @return array|null
     */
    protected static function dominant_signal(array $rows, array $semantics, array $columns): ?array {
        $candidatekeys = array_values(array_filter([
            $semantics['course'] ?? null,
            $semantics['status'] ?? null,
            $semantics['activity'] ?? null,
        ]));

        if (!$candidatekeys) {
            $candidatekeys = array_slice($columns, 0, 6);
        }

        $best = null;
        foreach ($candidatekeys as $key) {
            $counts = self::counts($rows, $key);
            if (!$counts || count($counts) === 1) {
                continue;
            }

            arsort($counts);
            $value = array_key_first($counts);
            $candidate = [
                'column' => $key,
                'value' => $value,
                'count' => $counts[$value],
                'ratio' => $counts[$value] / max(1, count($rows)),
            ];

            if ($best === null || $candidate['ratio'] > $best['ratio']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Count non-empty values.
     *
     * @param array $rows
     * @param string $key
     * @return array
     */
    protected static function counts(array $rows, string $key): array {
        $counts = [];

        foreach ($rows as $row) {
            $value = self::value($row, $key);
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Summary narrative.
     *
     * @param array $profile
     * @param array|null $dominant
     * @param array $stats
     * @return string
     */
    protected static function summary_text(array $profile, ?array $dominant, array $stats): string {
        $parts = [get_string('summaryprofile', 'local_la',
            (object) ['rows' => $profile['rowcount'], 'columns' => $profile['columncount']])];

        if ($dominant !== null && $dominant['count'] > 1) {
            $parts[] = get_string('summarydominantvalue', 'local_la',
                (object) ['value' => $dominant['value'], 'count' => $dominant['count']]);
        }

        foreach (['progress', 'grade', 'time', 'visits'] as $semantic) {
            if (isset($stats[$semantic])) {
                $parts[] = get_string('summaryaveragevalue', 'local_la',
                    (object) [
                        'label' => get_string('avg' . $semantic, 'local_la'),
                        'value' => self::format_measure($stats[$semantic]['average'], $semantic),
                    ]);
            }
        }

        return implode(' ', array_slice($parts, 0, 4));
    }

    /**
     * Focus items.
     *
     * @param array $rows
     * @param array $profile
     * @param array $semantics
     * @param array $stats
     * @return array
     */
    protected static function focus_items(array $rows, array $profile, array $semantics, array $stats): array {
        $items = [];
        $lowprogress = self::count_below($stats['progress']['values'] ?? [], 40);
        $lowgrade = self::count_below($stats['grade']['values'] ?? [], 60);
        $inactive = self::inactive_count($rows, $semantics['status'] ?? null);
        $close = self::count_between($stats['progress']['values'] ?? [], 80, 99.99);
        $outliers = self::outlier_count($stats);

        if ($profile['missingvalues'] > 0 || $profile['duplicaterows'] > 0) {
            $items[] = get_string('focusdataquality', 'local_la',
                (object) ['missing' => $profile['missingvalues'], 'duplicates' => $profile['duplicaterows']]);
        }
        if ($lowprogress > 0) {
            $items[] = get_string('focuslowprogress', 'local_la', $lowprogress);
        }
        if ($lowgrade > 0) {
            $items[] = get_string('focuslowgrade', 'local_la', $lowgrade);
        }
        if ($inactive > 0) {
            $items[] = get_string('focusinactive', 'local_la', $inactive);
        }
        if ($close > 0) {
            $items[] = get_string('focusclosecompletion', 'local_la', $close);
        }
        if ($outliers > 0) {
            $items[] = get_string('focusoutliers', 'local_la', $outliers);
        }

        return $items ?: [get_string('focusstable', 'local_la')];
    }

    /**
     * Pattern signals.
     *
     * @param array $rows
     * @param array $profile
     * @param array $semantics
     * @param array $stats
     * @return array
     */
    protected static function signals(array $rows, array $profile, array $semantics, array $stats): array {
        $signals = [];
        $dominant = self::dominant_signal($rows, $semantics, $profile['columns']);
        $lowprogress = self::count_below($stats['progress']['values'] ?? [], 40);
        $lowgrade = self::count_below($stats['grade']['values'] ?? [], 60);
        $inactive = self::inactive_count($rows, $semantics['status'] ?? null);
        $close = self::count_between($stats['progress']['values'] ?? [], 80, 99.99);
        $effortwithoutoutcome = self::effort_without_outcome($stats);
        $outliers = self::strongest_outlier_signal($stats);
        $relationship = self::relationship_signal($stats);

        if ($dominant !== null && $dominant['count'] > 1) {
            $signals[] = self::signal('concentration', 'info', get_string('signalconcentration', 'local_la',
                (object) ['count' => $dominant['count'], 'label' => $dominant['value']]));
        }
        if ($inactive > 0) {
            $signals[] = self::signal('inactivityrisk', 'warning', get_string('signalinactivityrisk', 'local_la', $inactive));
        }
        if ($lowprogress > 0) {
            $signals[] = self::signal('progressrisk', 'warning', get_string('signalprogressrisk', 'local_la', $lowprogress));
        }
        if ($lowgrade > 0) {
            $signals[] = self::signal('performancegap', 'danger', get_string('signalperformancegap', 'local_la', $lowgrade));
        }
        if ($effortwithoutoutcome > 0) {
            $signals[] = self::signal('effortwithoutoutcome', 'danger',
                get_string('signaleffortwithoutoutcome', 'local_la', $effortwithoutoutcome));
        }
        if ($close > 0) {
            $signals[] = self::signal('completionopportunity', 'success',
                get_string('signalcompletionopportunity', 'local_la', $close));
        }
        if ($outliers !== null) {
            $signals[] = self::signal('outlier', 'neutral', get_string('signaloutlier', 'local_la', $outliers));
        }
        if ($relationship !== null) {
            $signals[] = self::signal('relationship', 'info', get_string('signalrelationship', 'local_la', $relationship));
        }
        if ($profile['missingvalues'] > 0 || $profile['duplicaterows'] > 0) {
            $signals[] = self::signal('dataquality', 'neutral', get_string('signaldataquality', 'local_la',
                (object) ['missing' => $profile['missingvalues'], 'duplicates' => $profile['duplicaterows']]));
        }

        return array_slice($signals ?: [self::signal('limitedsignal', 'neutral', get_string('signallimited', 'local_la'))], 0, 6);
    }

    /**
     * Build one signal.
     *
     * @param string $titlekey
     * @param string $tone
     * @param string $body
     * @return array
     */
    protected static function signal(string $titlekey, string $tone, string $body): array {
        return [
            'key' => $titlekey,
            'title' => get_string('signaltitle' . $titlekey, 'local_la'),
            'tone' => $tone,
            'body' => $body,
        ];
    }

    /**
     * Pattern actions.
     *
     * @param array $signals
     * @return array
     */
    protected static function actions(array $signals): array {
        $tones = array_column($signals, 'tone');
        $keys = array_column($signals, 'key');
        $actions = [];

        if (in_array('danger', $tones, true) || in_array('warning', $tones, true)) {
            $actions[] = get_string('actionprioritizerisk', 'local_la');
            $actions[] = get_string('actionreviewtogether', 'local_la');
        }
        if (in_array('dataquality', $keys, true)) {
            $actions[] = get_string('actionvalidatequality', 'local_la');
        }
        if (in_array('outlier', $keys, true)) {
            $actions[] = get_string('actionreviewoutliers', 'local_la');
        }
        if (in_array('completionopportunity', $keys, true)) {
            $actions[] = get_string('actionrecognizecompletion', 'local_la');
        }
        if (in_array('concentration', $keys, true)) {
            $actions[] = get_string('actioncomparesegment', 'local_la');
        }

        $actions[] = get_string('actionrecheck', 'local_la');

        return array_values(array_slice(array_unique($actions), 0, 4));
    }

    /**
     * Count values below a threshold.
     *
     * @param array $values
     * @param float $threshold
     * @return int
     */
    protected static function count_below(array $values, float $threshold): int {
        return count(array_filter($values, static function(float $value) use ($threshold): bool {
            return $value < $threshold;
        }));
    }

    /**
     * Count values within a band.
     *
     * @param array $values
     * @param float $min
     * @param float $max
     * @return int
     */
    protected static function count_between(array $values, float $min, float $max): int {
        return count(array_filter($values, static function(float $value) use ($min, $max): bool {
            return $value >= $min && $value <= $max;
        }));
    }

    /**
     * Inactive or failing rows.
     *
     * @param array $rows
     * @param string|null $statuskey
     * @return int
     */
    protected static function inactive_count(array $rows, ?string $statuskey): int {
        if ($statuskey === null) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $status = strtolower(self::value($row, $statuskey));
            $count += (strpos($status, 'inactive') !== false || strpos($status, 'suspend') !== false ||
                strpos($status, 'hidden') !== false || strpos($status, 'fail') !== false ||
                strpos($status, 'not complete') !== false) ? 1 : 0;
        }

        return $count;
    }

    /**
     * Count high effort with low grade outcomes.
     *
     * @param array $stats
     * @return int
     */
    protected static function effort_without_outcome(array $stats): int {
        if (empty($stats['grade']['values'])) {
            return 0;
        }

        $engagement = $stats['visits']['values'] ?? $stats['attempts']['values'] ?? $stats['time']['values'] ?? [];
        if (!$engagement) {
            return 0;
        }

        $engagementvalues = array_values($engagement);
        sort($engagementvalues, SORT_NUMERIC);
        $threshold = self::median($engagementvalues);
        $count = 0;
        foreach ($stats['grade']['values'] as $index => $grade) {
            if ($grade < 60 && isset($engagement[$index]) && $engagement[$index] > $threshold) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count all IQR outliers.
     *
     * @param array $stats
     * @return int
     */
    protected static function outlier_count(array $stats): int {
        $count = 0;
        foreach ($stats as $stat) {
            $count += count($stat['outliers']);
        }

        return $count;
    }

    /**
     * Most useful outlier signal.
     *
     * @param array $stats
     * @return object|null
     */
    protected static function strongest_outlier_signal(array $stats): ?object {
        foreach (['grade', 'progress', 'time', 'visits', 'attempts'] as $semantic) {
            if (!empty($stats[$semantic]['outliers'])) {
                return (object) [
                    'count' => count($stats[$semantic]['outliers']),
                    'label' => get_string('avg' . $semantic, 'local_la'),
                ];
            }
        }

        return null;
    }

    /**
     * Simple numeric relationship signal.
     *
     * @param array $stats
     * @return object|null
     */
    protected static function relationship_signal(array $stats): ?object {
        foreach ([['grade', 'visits'], ['grade', 'time'], ['grade', 'attempts'], ['progress', 'visits']] as $pair) {
            [$left, $right] = $pair;
            if (empty($stats[$left]['values']) || empty($stats[$right]['values'])) {
                continue;
            }

            $correlation = self::correlation($stats[$left]['values'], $stats[$right]['values']);
            if ($correlation !== null && abs($correlation) >= 0.5) {
                return (object) [
                    'left' => get_string('avg' . $left, 'local_la'),
                    'right' => get_string('avg' . $right, 'local_la'),
                    'direction' => $correlation > 0 ? get_string('positive', 'local_la') : get_string('negative', 'local_la'),
                ];
            }
        }

        return null;
    }

    /**
     * Pearson correlation on rows where both measures exist.
     *
     * @param array $left
     * @param array $right
     * @return float|null
     */
    protected static function correlation(array $left, array $right): ?float {
        $x = [];
        $y = [];
        foreach ($left as $index => $value) {
            if (isset($right[$index])) {
                $x[] = $value;
                $y[] = $right[$index];
            }
        }

        $count = count($x);
        if ($count < 3) {
            return null;
        }

        $avgx = array_sum($x) / $count;
        $avgy = array_sum($y) / $count;
        $num = 0;
        $denx = 0;
        $deny = 0;

        for ($i = 0; $i < $count; $i++) {
            $dx = $x[$i] - $avgx;
            $dy = $y[$i] - $avgy;
            $num += $dx * $dy;
            $denx += $dx ** 2;
            $deny += $dy ** 2;
        }

        return $denx > 0 && $deny > 0 ? $num / sqrt($denx * $deny) : null;
    }

    /**
     * Format a measure for display.
     *
     * @param float $value
     * @param string $semantic
     * @return string
     */
    protected static function format_measure(float $value, string $semantic): string {
        if ($semantic === 'time') {
            return format_time((int) round($value));
        }

        if ($semantic === 'progress') {
            return self::format_number($value) . '%';
        }

        return self::format_number($value);
    }

    /**
     * Human number format.
     *
     * @param float $value
     * @return string
     */
    protected static function format_number(float $value): string {
        return abs($value) >= 100 ? number_format(round($value)) : (string) (round($value * 10) / 10);
    }
}
