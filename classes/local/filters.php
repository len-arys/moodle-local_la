<?php
namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Filter helper methods for report requests and URLs.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filters {

    /**
     * Get request param names for one filter key.
     *
     * @param string $key
     * @return array
     */
    public static function get_param_names(string $key): array {
        return [
            'operator' => 'f_' . $key . '_operator',
            'value' => 'f_' . $key . '_value',
            'from' => 'f_' . $key . '_from',
            'to' => 'f_' . $key . '_to',
        ];
    }

    /**
     * Get the report search column, if configured.
     *
     * @param array $reportparams
     * @return array|null
     */
    public static function get_search_column(array $reportparams): ?array {
        foreach (($reportparams['columns'] ?? []) as $key => $column) {
            if (!report::is_column_enabled($column)) {
                continue;
            }

            $definition = $column['filter'] ?? [];

            if (
                empty($definition) ||
                !is_array($definition) ||
                ($definition['type'] ?? 'text') !== 'text' ||
                empty($definition['search'])
            ) {
                continue;
            }

            return [
                'key' => (string) $key,
                'name' => (string) ($definition['name'] ?? ($column['name'] ?? ucwords(str_replace('_', ' ', $key)))),
            ];
        }

        return null;
    }

    /**
     * Get current search term from active filters.
     *
     * @param string $searchcolumn
     * @param array $activefilters
     * @return string
     */
    public static function get_search_term(string $searchcolumn, array $activefilters): string {
        if ($searchcolumn === '') {
            return '';
        }

        $filter = $activefilters[$searchcolumn] ?? [];
        if (($filter['operator'] ?? 'any') === 'contains' && !empty($filter['value']) && !is_array($filter['value'])) {
            return (string) $filter['value'];
        }

        return '';
    }

    /**
     * Get current filter values from request.
     *
     * @param array $reportparams
     * @return array
     */
    public static function get_values(array $reportparams): array {
        $filters = [];

        foreach (($reportparams['columns'] ?? []) as $key => $column) {
            if (!report::is_column_enabled($column)) {
                continue;
            }

            $definition = $column['filter'] ?? [];

            if (empty($definition) || !is_array($definition)) {
                continue;
            }

            $names = self::get_param_names((string) $key);
            $type = (string) ($definition['type'] ?? 'text');
            $filter = [
                'operator' => optional_param($names['operator'], 'any', PARAM_ALPHA),
                'value' => in_array($type, ['users', 'courses'], true) ?
                    optional_param_array($names['value'], [], PARAM_INT) :
                    optional_param($names['value'], '', PARAM_RAW_TRIMMED),
            ];

            if ($type === 'date') {
                $filter['from'] = optional_param($names['from'], '', PARAM_RAW_TRIMMED);
                $filter['to'] = optional_param($names['to'], '', PARAM_RAW_TRIMMED);
            }

            $filters[$key] = $filter;
        }

        $searchcolumn = self::get_search_column($reportparams);
        if (!empty($searchcolumn['key']) &&
                (($filters[$searchcolumn['key']]['operator'] ?? 'any') === 'contains') &&
                empty($filters[$searchcolumn['key']]['value'])) {
            $filters[$searchcolumn['key']]['operator'] = 'any';
            $filters[$searchcolumn['key']]['value'] = '';
        }

        return $filters;
    }

    /**
     * Build filter params for forms or URLs.
     *
     * @param array $filters
     * @param array $baseparams
     * @param bool $flattenforurl
     * @return array
     */
    public static function get_params(array $filters, array $baseparams = [], bool $flattenforurl = false): array {
        $params = [];

        foreach ($filters as $key => $filter) {
            $operator = (string) ($filter['operator'] ?? 'any');

            if ($operator === 'any') {
                continue;
            }

            $names = self::get_param_names((string) $key);
            $params[$names['operator']] = $operator;

            if (!empty($filter['value'])) {
                $params[$names['value']] = is_array($filter['value']) ?
                    array_map('strval', $filter['value']) :
                    (string) $filter['value'];
            }

            if (!empty($filter['from'])) {
                $params[$names['from']] = (string) $filter['from'];
            }

            if (!empty($filter['to'])) {
                $params[$names['to']] = (string) $filter['to'];
            }
        }

        if ($flattenforurl) {
            $flattened = [];

            foreach ($params as $name => $value) {
                if (is_array($value)) {
                    foreach (array_values($value) as $index => $itemvalue) {
                        $flattened[(string) $name . '[' . $index . ']'] = (string) $itemvalue;
                    }

                    continue;
                }

                $flattened[(string) $name] = (string) $value;
            }

            $params = $flattened;
        }

        return $baseparams + $params;
    }

    /**
     * Build hidden input items.
     *
     * @param array $params
     * @return array
     */
    public static function get_hidden_param_items(array $params): array {
        $items = [];

        foreach ($params as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $itemvalue) {
                    $items[] = [
                        'name' => (string) $name . '[]',
                        'value' => (string) $itemvalue,
                    ];
                }

                continue;
            }

            $items[] = [
                'name' => (string) $name,
                'value' => (string) $value,
            ];
        }

        return $items;
    }

    /**
     * Build search hidden params preserving non-search filters only.
     *
     * @param int $reportid
     * @param array $reportparams
     * @param array $filters
     * @return array
     */
    public static function get_search_hidden_param_items(int $reportid, array $reportparams, array $filters): array {
        $params = [
            'id' => $reportid,
            'tab' => 'view',
        ];
        $searchcolumn = self::get_search_column($reportparams);

        foreach ($filters as $key => $filter) {
            if (!empty($searchcolumn['key']) && $key === $searchcolumn['key']) {
                continue;
            }

            $operator = (string) ($filter['operator'] ?? 'any');
            if ($operator === 'any') {
                continue;
            }

            $names = self::get_param_names((string) $key);
            $params[$names['operator']] = $operator;

            if (!empty($filter['value'])) {
                $params[$names['value']] = is_array($filter['value']) ?
                    array_map('strval', $filter['value']) :
                    (string) $filter['value'];
            }

            if (!empty($filter['from'])) {
                $params[$names['from']] = (string) $filter['from'];
            }

            if (!empty($filter['to'])) {
                $params[$names['to']] = (string) $filter['to'];
            }
        }

        return self::get_hidden_param_items($params);
    }
}
