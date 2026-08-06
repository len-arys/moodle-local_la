<?php
namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Report helper.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** @var array */
    protected static $tablealiases = [];

    /**
     * Check whether one column is enabled.
     *
     * @param array $config
     * @return bool
     */
    public static function is_column_enabled(array $config): bool {
        return !empty($config['enabled']);
    }

    /**
     * Build final report params from report and user config.
     *
     * @param \stdClass $report
     * @return array
     */
    public static function build_params(\stdClass $report): array {
        $defaultparams = [
            'has_checkbox' => true,
            'columns' => [],
            'menu' => [
                'enable' => false,
                'items' => [],
            ],
        ];
        $reportparams = self::decode_params((string) ($report->report_params ?? ''));
        $userparams = self::decode_params((string) ($report->user_params ?? ''));

        return array_replace_recursive($defaultparams, $reportparams, $userparams);
    }

    /**
     * Decode report params JSON safely.
     *
     * @param string $json
     * @return array
     */
    public static function decode_params(string $json): array {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Get full report context for building a report page/table.
     *
     * @param \stdClass $report
     * @param array $filters
     * @return \stdClass|false
     */
    public static function get_context(\stdClass $report, array $filters = []) {
        $params = synthetic::apply($report->params);
        $report->params = $params;

        if (empty($report->sqlcode)) {
            return false;
        }

        $dependencies = array_merge($report->dependencies ?? [], synthetic::get_dependencies($params));
        self::$tablealiases = self::get_sql_table_aliases((string) $report->sqlcode, $dependencies);
        $filters = self::merge_condition_filters($params, $filters);
        $filtercontext = self::get_filter_sql($params, $filters);
        $sql = self::build_sql((string) $report->sqlcode, $params, $dependencies, $filters, $filtercontext['sql']);

        $columns = self::get_columns($params, $filters);

        if (empty($columns)) {
            return false;
        }

        return (object) [
            'report' => $report,
            'params' => $params,
            'dependencies' => $dependencies,
            'sql' => $sql,
            'queryparams' => $filtercontext['params'],
            'columns' => $columns,
            'selectcolumns' => self::get_select_columns($params, $filters),
        ];
    }

    /**
     * Merge hidden condition filters from enabled columns.
     *
     * @param array $params
     * @param array $filters
     * @return array
     */
    protected static function merge_condition_filters(array $params, array $filters): array {
        foreach (($params['columns'] ?? []) as $key => $columnconfig) {
            if (!self::is_column_enabled($columnconfig)) {
                continue;
            }

            $condition = trim((string) ($columnconfig['condition'] ?? ''));

            if ($condition === '' || empty($params['columns'][$key])) {
                continue;
            }

            $filters[$key] = [
                'operator' => 'equal',
                'value' => $condition,
            ];
        }

        return $filters;
    }

    /**
     * Get report SQL table aliases.
     *
     * @param string $sqlcode
     * @param array $dependencies
     * @return array
     */
    public static function get_sql_table_aliases(string $sqlcode, array $dependencies = []): array {
        $aliases = [];
        $parts = [$sqlcode];

        foreach ($dependencies as $dependency) {
            if (!empty($dependency->code)) {
                $parts[] = (string) $dependency->code;
            }
        }

        foreach ($parts as $sql) {
            preg_match_all('/(?:FROM|JOIN)\s+\{([a-z0-9_]+)\}\s+([a-z][a-z0-9_]*)/i', $sql, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (!isset($aliases[$match[1]])) {
                    $aliases[$match[1]] = $match[2];
                }
            }
        }

        return $aliases;
    }

    /**
     * Collect required SQL dependency names from report params.
     *
     * @param array $params
     * @return array
     */
    public static function get_dependency_names(array $params): array {
        $names = [];

        foreach (($params['columns'] ?? []) as $columnconfig) {
            if (!self::is_column_enabled($columnconfig)) {
                continue;
            }

            foreach (self::get_required_names($columnconfig['sql'] ?? []) as $name) {
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_unique($names);
    }

    /**
     * Get SQL output column names from configured report params.
     *
     * Used as a fallback when a valid report returns zero rows.
     *
     * @param array $params
     * @return array
     */
    public static function get_columns(array $params, array $filters = []): array {
        $columns = [];

        foreach (($params['columns'] ?? []) as $key => $config) {
            if (!self::should_include_column((string) $key, $config, $filters)) {
                continue;
            }

            $sql = $config['sql'] ?? [];
            $column = self::get_column_expression((string) $key, $config);
            $alias = trim((string) ($sql['alias'] ?? ''));
            $source = trim((string) ($sql['source'] ?? ''));

            if ($column === '' && $source === '') {
                continue;
            }

            $columns[] = $alias !== '' ? $alias : (string) $key;
        }

        return $columns;
    }

    /**
     * Get SQL output columns needed to hydrate table rows.
     *
     * @param array $params
     * @return array
     */
    public static function get_select_columns(array $params, array $filters = []): array {
        $columns = self::get_columns($params, $filters);

        foreach (($params['columns'] ?? []) as $key => $config) {
            if (!self::should_include_column((string) $key, $config, $filters)) {
                continue;
            }

            foreach (self::get_include_selects($config) as $include) {
                $columns[] = $include['alias'];
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Build final SQL from a template and configured report columns.
     *
     * @param string $template
     * @param array $params
     * @param array $dependencies
     * @return string
     */
    public static function build_sql(
        string $template,
        array $params,
        array $dependencies = [],
        array $filters = [],
        string $filtersql = ''
    ): string {
        $columns = $params['columns'] ?? [];

        if (empty($columns) || !is_array($columns)) {
            return $template;
        }

        uasort($columns, function(array $left, array $right): int {
            return ((int) ($left['order'] ?? 9999)) <=> ((int) ($right['order'] ?? 9999));
        });

        $selects = [];
        $from = [];
        $joins = [];
        $where = [];
        $groups = [];

        foreach ($columns as $key => $config) {
            if (!self::should_include_column((string) $key, $config, $filters)) {
                continue;
            }

            $sql = $config['sql'] ?? [];
            $column = self::get_column_expression((string) $key, $config);
            $alias = trim((string) ($sql['alias'] ?? ''));
            $source = trim((string) ($sql['source'] ?? ''));
            $condition = trim((string) ($sql['where'] ?? ''));
            $requirednames = self::get_required_names($sql);
            $outputname = $alias !== '' ? $alias : $key;

            foreach ($requirednames as $require) {
                $dependency = !empty($dependencies[$require]) ? trim((string) $dependencies[$require]->code) : '';

                if ($source === 'from' && $dependency !== '') {
                    $from[$require] = $dependency;
                } else if ($source === 'join' && $dependency !== '') {
                    $joins[$require] = $dependency;
                } else if ($source === 'select' && $dependency !== '') {
                    $selects[] = '(' . $dependency . ') AS ' . $outputname;
                }
            }

            if ($source !== 'select' && $column !== '') {
                $selects[] = $column . ' AS ' . $outputname;
            }

            foreach (self::get_include_selects($config) as $include) {
                $selectkey = $include['alias'];

                if (!isset($selects[$selectkey])) {
                    $selects[$selectkey] = $include['expression'] . ' AS ' . $include['alias'];
                }
            }

            if ($condition !== '') {
                $where[] = $condition;
            }

            foreach (self::get_group_expressions($sql) as $group) {
                $groups[] = $group;
            }
        }

        if ($filtersql !== '') {
            $where[] = $filtersql;
        }

        $sql = strtr($template, [
            'SQL_COLUMNS' => implode(",\n                   ", array_values($selects)),
            'SQL_FROM' => empty($from) ? '' : implode(",\n                   ", $from) . ",\n                   ",
            'SQL_JOIN' => empty($joins) ? '' : "\n" . implode("\n", $joins),
            'SQL_WHERE' => empty($where) ? '' : "\n               AND " . implode("\n               AND ", array_unique($where)),
            'SQL_GROUP' => empty($groups) ? '' : ', ' . implode(', ', array_unique($groups)),
        ]);

        return str_replace('SQL_NOW', (string) time(), $sql);
    }

    /**
     * Check whether one configured column should participate in SQL generation.
     *
     * Dynamic columns are optional dimensions. They should not change the query
     * shape until the user enables the column or applies a filter on it.
     *
     * @param string $key
     * @param array $config
     * @param array $filters
     * @return bool
     */
    protected static function should_include_column(string $key, array $config, array $filters = []): bool {
        if (!self::is_column_enabled($config)) {
            return false;
        }

        if (empty($config['dynamic'])) {
            return true;
        }

        if (!array_key_exists('visible', $config) || !empty($config['visible'])) {
            return true;
        }

        $filter = $filters[$key] ?? [];
        $operator = trim((string) ($filter['operator'] ?? ''));
        $definition = $config['filter'] ?? [];

        return $operator !== '' && $operator !== 'any' &&
            is_array($definition) && self::is_filter_operator_allowed($definition, $operator);
    }

    /**
     * Get required dependency names from a column SQL config.
     *
     * @param array $sql
     * @return string[]
     */
    protected static function get_required_names(array $sql): array {
        $require = $sql['require'] ?? '';

        if (is_array($require)) {
            $names = $require;
        } else {
            $names = explode(',', (string) $require);
        }

        return array_values(array_filter(array_map(static function($name): string {
            return trim((string) $name);
        }, $names), static function(string $name): bool {
            return $name !== '';
        }));
    }

    /**
     * Get group expressions from a column SQL config.
     *
     * @param array $sql
     * @return string[]
     */
    protected static function get_group_expressions(array $sql): array {
        $group = $sql['group'] ?? '';

        if (is_array($group)) {
            $groups = $group;
        } else {
            $groups = explode(',', (string) $group);
        }

        return array_values(array_filter(array_map(static function($item): string {
            return trim((string) $item);
        }, $groups), static function(string $item): bool {
            return $item !== '';
        }));
    }

    /**
     * Get extra SQL selects required by a visible column.
     *
     * @param array $config
     * @return array
     */
    protected static function get_include_selects(array $config): array {
        $result = [];

        foreach (($config['include'] ?? []) as $item) {
            if (!is_string($item)) {
                continue;
            }

            $expression = trim($item);
            if ($expression === '') {
                continue;
            }

            $alias = self::resolve_include_alias($expression);
            if ($alias === '') {
                continue;
            }

            $result[] = [
                'expression' => $expression,
                'alias' => $alias,
            ];
        }

        return $result;
    }

    /**
     * Resolve alias name for one include expression.
     *
     * @param string $expression
     * @return string
     */
    protected static function resolve_include_alias(string $expression): string {
        if (preg_match('/\s+AS\s+([a-zA-Z0-9_]+)$/i', $expression, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/([a-zA-Z0-9_]+)$/', $expression, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * Build SQL filter conditions from configured report filters.
     *
     * @param array $params
     * @param array $filters
     * @return array
     */
    public static function get_filter_sql(array $params, array $filters): array {
        global $DB;

        $conditions = [];
        $queryparams = [];
        $columns = $params['columns'] ?? [];

        foreach ($columns as $key => $columnconfig) {
            $definition = $columnconfig['filter'] ?? [];

            if (empty($definition) || !is_array($definition)) {
                continue;
            }

            $filter = $filters[$key] ?? [];
            $operator = trim((string) ($filter['operator'] ?? ''));

            if ($operator === '' || $operator === 'any') {
                continue;
            }

            if (!self::is_filter_operator_allowed($definition, $operator)) {
                continue;
            }

            $field = self::get_column_expression((string) $key, $columnconfig);

            if ($field === '') {
                continue;
            }

            $paramname = 'filter_' . $key;

            if (in_array($operator, ['empty', 'notempty'], true)) {
                $conditions[] = $operator === 'empty' ?
                    "({$field} IS NULL OR {$field} = '')" :
                    "({$field} IS NOT NULL AND {$field} <> '')";
                continue;
            }

            if (($definition['type'] ?? 'text') === 'date') {
                $from = trim((string) ($filter['from'] ?? ''));
                $to = trim((string) ($filter['to'] ?? ''));
                $value = trim((string) ($filter['value'] ?? ''));

                if ($operator === 'range' && $from !== '') {
                    $queryparams[$paramname . 'from'] = strtotime($from . ' 00:00:00');
                    $conditions[] = "{$field} >= :" . $paramname . 'from';

                    if ($to !== '') {
                        $queryparams[$paramname . 'to'] = strtotime($to . ' 00:00:00') + DAYSECS;
                        $conditions[] = "{$field} < :" . $paramname . 'to';
                    }

                    continue;
                }

                if ($value === '') {
                    continue;
                }

                if ($operator === 'before') {
                    $queryparams[$paramname] = strtotime($value . ' 00:00:00') + DAYSECS;
                    $conditions[] = "{$field} < :" . $paramname;
                } else if ($operator === 'after') {
                    $queryparams[$paramname] = strtotime($value . ' 00:00:00');
                    $conditions[] = "{$field} >= :" . $paramname;
                }

                continue;
            }

            $value = $filter['value'] ?? '';

            if (is_array($value)) {
                $value = array_values(array_filter(array_map('trim', array_map('strval', $value)), function(string $item): bool {
                    return $item !== '';
                }));
            } else {
                $value = trim((string) $value);
            }

            if ($value === '' || $value === []) {
                continue;
            }

            if ($operator === 'contains') {
                $conditions[] = $DB->sql_like("LOWER({$field})", ':' . $paramname, false);
                $queryparams[$paramname] = '%' . \core_text::strtolower($value) . '%';
            } else if ($operator === 'notcontains') {
                $conditions[] = 'NOT (' . $DB->sql_like("LOWER({$field})", ':' . $paramname, false) . ')';
                $queryparams[$paramname] = '%' . \core_text::strtolower($value) . '%';
            } else if ($operator === 'equal') {
                if (is_array($value)) {
                    [$insql, $inparams] = $DB->get_in_or_equal($value, SQL_PARAMS_NAMED, $paramname . '_');
                    $conditions[] = "{$field} {$insql}";
                    $queryparams += $inparams;
                } else {
                    $conditions[] = "{$field} = :" . $paramname;
                    $queryparams[$paramname] = $value;
                }
            } else if ($operator === 'notequal') {
                if (is_array($value)) {
                    [$insql, $inparams] = $DB->get_in_or_equal($value, SQL_PARAMS_NAMED, $paramname . '_', false);
                    $conditions[] = "{$field} {$insql}";
                    $queryparams += $inparams;
                } else {
                    $conditions[] = "{$field} <> :" . $paramname;
                    $queryparams[$paramname] = $value;
                }
            } else if ($operator === 'startswith') {
                $conditions[] = $DB->sql_like("LOWER({$field})", ':' . $paramname, false);
                $queryparams[$paramname] = \core_text::strtolower($value) . '%';
            } else if ($operator === 'endswith') {
                $conditions[] = $DB->sql_like("LOWER({$field})", ':' . $paramname, false);
                $queryparams[$paramname] = '%' . \core_text::strtolower($value);
            }
        }

        return [
            'sql' => implode(' AND ', $conditions),
            'params' => $queryparams,
        ];
    }

    /**
     * Check whether a filter operator is valid for its filter type.
     *
     * @param array $definition
     * @param string $operator
     * @return bool
     */
    protected static function is_filter_operator_allowed(array $definition, string $operator): bool {
        $type = (string) ($definition['type'] ?? 'text');

        if (in_array($type, ['select', 'users', 'courses'], true)) {
            return in_array($operator, ['equal', 'notequal'], true);
        }

        if ($type === 'date') {
            return in_array($operator, ['range', 'before', 'after'], true);
        }

        return in_array($operator, [
            'contains',
            'notcontains',
            'equal',
            'notequal',
            'startswith',
            'endswith',
            'empty',
            'notempty',
        ], true);
    }

    /**
     * Resolve one SQL column expression, including generated columns.
     *
     * @param string $key
     * @param array $config
     * @return string
     */
    protected static function get_column_expression(string $key, array $config): string {
        $sql = $config['sql'] ?? [];
        $table = trim((string) ($sql['table'] ?? ''));
        $column = trim((string) ($sql['column'] ?? ''));
        $outputname = trim((string) ($sql['alias'] ?? '')) ?: $key;

        if ($outputname === 'activity_name') {
            return self::get_activity_name_expression();
        }

        if ($table !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            $alias = self::$tablealiases[$table] ?? '';

            if ($alias !== '') {
                return $alias . '.' . $column;
            }
        }

        return $column;
    }

    /**
     * Build SQL expression for Moodle activity instance names.
     *
     * @return string
     */
    protected static function get_activity_name_expression(): string {
        global $DB;

        static $expression = null;

        if ($expression !== null) {
            return $expression;
        }

        $modules = $DB->get_records_select('modules', 'visible = :visible', ['visible' => 1], '', 'id,name');
        $cases = [];

        foreach ($modules as $module) {
            $cases[] = "WHEN m.name = '{$module->name}' THEN (SELECT name FROM {" . $module->name . "} WHERE id = cm.instance)";
        }

        $expression = empty($cases) ? "''" : '(CASE ' . implode(' ', $cases) . " ELSE '' END)";

        return $expression;
    }
}
