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

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_la\local\api;
use local_la\local\helper;
use local_la\local\installer;
use local_la\local\repository;
use local_la\local\validator;

/**
 * External marketplace API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marketplace extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'plan' => new external_value(PARAM_ALPHA, 'Marketplace plan', VALUE_DEFAULT, 'all'),
            'type' => new external_value(PARAM_ALPHA, 'Marketplace type', VALUE_DEFAULT, 'reports'),
            'search' => new external_value(PARAM_RAW_TRIMMED, 'Marketplace search', VALUE_DEFAULT, ''),
            'sort' => new external_value(PARAM_ALPHA, 'Marketplace sort', VALUE_DEFAULT, 'name'),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $plan
     * @param string $type
     * @param string $search
     * @param string $sort
     * @return array
     */
    public static function execute(string $plan = 'all', string $type = 'reports', string $search = '', string $sort = 'name'): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'plan' => $plan,
            'type' => $type,
            'search' => $search,
            'sort' => $sort,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_billing_admin()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
        }

        $items = self::get_items($params['plan'], $params['type'], $params['search'], $params['sort']);

        $renderer = $PAGE->get_renderer('local_la');
        $html = $renderer->render_from_template('local_la/pages/library/marketplace_ajax', [
            'items' => $items,
        ]);

        return [
            'html' => $html,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered marketplace html'),
        ]);
    }

    /**
     * Install review modal parameters.
     *
     * @return external_function_parameters
     */
    public static function install_modal_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportkey' => new external_value(PARAM_ALPHANUMEXT, 'Report shortname'),
        ]);
    }

    /**
     * Get install review modal.
     *
     * @param string $reportkey
     * @return array
     */
    public static function install_modal(string $reportkey): array {
        $params = self::validate_parameters(self::install_modal_parameters(), [
            'reportkey' => $reportkey,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_billing_admin()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
        }

        $definition = api::get_report_definition($params['reportkey']);

        if (empty($definition)) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        return self::build_install_modal($definition);
    }

    /**
     * App install review modal parameters.
     *
     * @return external_function_parameters
     */
    public static function app_install_modal_parameters(): external_function_parameters {
        return new external_function_parameters([
            'appkey' => new external_value(PARAM_ALPHANUMEXT, 'App shortname'),
        ]);
    }

    /**
     * Get app install review modal.
     *
     * @param string $appkey
     * @return array
     */
    public static function app_install_modal(string $appkey): array {
        $params = self::validate_parameters(self::app_install_modal_parameters(), [
            'appkey' => $appkey,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_billing_admin()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
        }

        $definition = api::get_app_definition($params['appkey']);

        if (empty($definition)) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        installer::validate_app_definition($definition);

        return self::build_app_install_modal($definition);
    }

    /**
     * App install review modal returns.
     *
     * @return external_single_structure
     */
    public static function app_install_modal_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Rendered app install review html'),
        ]);
    }

    /**
     * Generated install review modal parameters.
     *
     * @return external_function_parameters
     */
    public static function generated_install_modal_parameters(): external_function_parameters {
        return new external_function_parameters([
            'definition' => new external_value(PARAM_RAW, 'Generated report JSON'),
        ]);
    }

    /**
     * Get install review modal for a generated report definition.
     *
     * @param string $definition
     * @return array
     */
    public static function generated_install_modal(string $definition): array {
        global $SESSION;

        $params = self::validate_parameters(self::generated_install_modal_parameters(), [
            'definition' => $definition,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_billing_admin()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
        }

        $definition = json_decode($params['definition'], true);
        if (!is_array($definition)) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        installer::validate_definition($definition);

        $token = random_string(32);
        $SESSION->local_la_install_definitions[$token] = [
            'definition' => $definition,
            'timecreated' => time(),
        ];

        return self::build_install_modal($definition) + ['token' => $token];
    }

    /**
     * Generated install review modal returns.
     *
     * @return external_single_structure
     */
    public static function generated_install_modal_returns(): external_single_structure {
        return new external_single_structure([
            'token' => new external_value(PARAM_ALPHANUMEXT, 'Temporary install token'),
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Rendered install review html'),
        ]);
    }

    /**
     * Build install review modal from a full report definition.
     *
     * @param array $definition
     * @return array
     */
    protected static function build_install_modal(array $definition): array {
        global $PAGE;

        $renderer = $PAGE->get_renderer('local_la');
        $sqlsnippets = self::get_sql_snippets($definition);
        $columns = self::get_preview_columns($definition['report_params']['columns'] ?? []);
        $sample = self::get_sample_preview($definition['report_params']['columns'] ?? []);
        $reportparamsjson = json_encode(['report_params' => $definition['report_params'] ?? []],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $html = $renderer->render_from_template('local_la/modal/marketplace_install', [
            'fields' => self::get_definition_fields($definition),
            'sql_snippets' => $sqlsnippets,
            'has_sql_snippets' => !empty($sqlsnippets),
            'columns' => $columns,
            'has_columns' => !empty($columns),
            'sample_columns' => $sample['columns'],
            'sample_rows' => $sample['rows'],
            'has_sample' => !empty($sample['columns']) && !empty($sample['rows']),
            'report_params_json' => $reportparamsjson,
        ]);

        return [
            'title' => (string) ($definition['name'] ?? get_string('install', 'local_la')),
            'html' => $html,
        ];
    }

    /**
     * Build install review modal from a full app definition.
     *
     * @param array $definition
     * @return array
     */
    protected static function build_app_install_modal(array $definition): array {
        global $PAGE;

        $renderer = $PAGE->get_renderer('local_la');
        $html = $renderer->render_from_template('local_la/modal/marketplace_app_install', [
            'fields' => self::get_definition_fields($definition),
            'widgets' => self::get_app_widgets($definition),
        ]);

        return [
            'title' => (string) ($definition['name'] ?? get_string('install', 'local_la')),
            'html' => $html,
        ];
    }

    /**
     * Install review modal returns.
     *
     * @return external_single_structure
     */
    public static function install_modal_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Rendered install review html'),
        ]);
    }

    /**
     * Build marketplace template items.
     *
     * @param array $records
     * @return array
     */
    protected static function build_items(array $records, string $type): array {
        global $DB;

        $items = [];
        $shortnames = [];

        foreach ($records as $record) {
            if (!empty($record->shortname)) {
                $shortnames[] = (string) $record->shortname;
            }
        }

        $installeditems = [];
        if (!empty($shortnames)) {
            [$insql, $params] = $DB->get_in_or_equal(array_values(array_unique($shortnames)), SQL_PARAMS_NAMED);

            $table = $type === 'app' ? '{local_la_app}' : '{local_la_report}';
            $sql = "SELECT i.id, i.shortname, i.version
                      FROM {$table} i
                     WHERE i.shortname {$insql}";

            foreach ($DB->get_records_sql($sql, $params) as $item) {
                $installeditems[(string) $item->shortname] = $item;
            }
        }

        foreach ($records as $record) {
            $plan = self::get_plan_item((string) $record->plan);
            $installed = $installeditems[(string) ($record->shortname ?? '')] ?? null;
            $isadded = !empty($installed);
            $itemid = !empty($installed->id) ? (int) $installed->id : 0;
            $installslabel = self::format_installs((int) ($record->installs ?? 0));
            $isupdatable = $installed &&
                version_compare((string) ($record->version ?? ''), (string) ($installed->version ?? ''), '>');

            $items[] = [
                'id' => $record->id,
                'itemid' => $itemid,
                'type' => $type,
                'isapp' => $type === 'app',
                'isreport' => $type !== 'app',
                'shortname' => $record->shortname ?? '',
                'name' => $record->name,
                'info' => $record->info ?? '',
                'version' => $record->version ?? '',
                'plan_name' => $plan['name'],
                'plan_class' => $plan['class'],
                'install_count' => $installslabel,
                'install_label' => get_string($installslabel === '1' ? 'installation' : 'installations', 'local_la'),
                'has_installs' => $installslabel !== '',
                'icon' => $type === 'app' ? 'chart-column' : 'bars',
                'icon_class' => 'text-bg-primary',
                'isadded' => $isadded,
                'isupdatable' => $isupdatable,
                'uninstallaction' => $type === 'app' ? 'uninstallapp' : 'uninstallreport',
                'uninstallmessage' => get_string($type === 'app' ? 'uninstallappconfirm' : 'uninstallreportconfirm', 'local_la'),
            ];
        }

        return $items;
    }

    /**
     * Format marketplace install count.
     *
     * @param int $installs
     * @return string
     */
    protected static function format_installs(int $installs): string {
        if ($installs <= 0) {
            return '';
        }

        if ($installs < 101) {
            return (string) $installs;
        }

        return '+' . (int) (floor($installs / 100) * 100);
    }

    /**
     * Get report definition fields for install review.
     *
     * @param array $definition
     * @return array
     */
    protected static function get_definition_fields(array $definition): array {
        $fields = [];

        foreach (['name', 'shortname', 'version', 'info', 'plan', 'tags'] as $key) {
            $rawvalue = $definition[$key] ?? '';
            $tags = $key === 'tags' ? self::get_tags($rawvalue) : [];
            $value = is_array($rawvalue) ? '' : (string) $rawvalue;

            $fields[] = [
                'key' => $key,
                'value' => self::format_definition_field($key, $value),
                'istags' => $key === 'tags',
                'tags' => $tags,
            ];
        }

        return $fields;
    }

    /**
     * Format report definition field.
     *
     * @param string $key
     * @param string $value
     * @return string
     */
    protected static function format_definition_field(string $key, string $value): string {
        return trim($value) === '' ? get_string('notset', 'local_la') : $value;
    }

    /**
     * Get SQL snippets from report definition.
     *
     * @param array $definition
     * @return array
     */
    protected static function get_sql_snippets(array $definition): array {
        $version = (string) ($definition['version'] ?? '');
        $snippets = [];
        $mainsql = (string) ($definition['sql']['code'] ?? '');
        $snippets[] = self::get_sql_snippet_context(
            (string) ($definition['sql']['name'] ?? ''),
            $mainsql,
            $version
        );

        foreach (($definition['sql']['dependencies'] ?? []) as $dependency) {
            $code = (string) ($dependency['code'] ?? '');
            $snippets[] = self::get_sql_snippet_context(
                (string) ($dependency['name'] ?? ''),
                $code,
                $version
            );
        }

        return array_values(array_filter($snippets, static function(array $snippet): bool {
            return $snippet['name'] !== '' && $snippet['code'] !== '';
        }));
    }

    /**
     * Build install-review context for one SQL snippet.
     *
     * @param string $name
     * @param string $code
     * @param string $version
     * @return array
     */
    protected static function get_sql_snippet_context(string $name, string $code, string $version): array {
        $validation = validator::get_rules($code);
        $restrictedtables = validator::get_restricted_tables_used($code);

        return [
            'name' => $name,
            'code' => $code,
            'version' => $version,
            'validation' => $validation,
            'has_validation_errors' => !empty(array_filter($validation, static function(array $rule): bool {
                return !empty($rule['failed']);
            })),
            'has_restricted_table_error' => !empty($restrictedtables),
            'restricted_tables_help' => !empty($restrictedtables) ? get_string(
                'sqlvalidationrestrictedtableshelp',
                'local_la',
                implode(', ', array_map(static fn(string $table): string => '{' . $table . '}', $restrictedtables))
            ) : '',
            'restricted_tables_settings_url' => (new \moodle_url(
                '/admin/settings.php',
                ['section' => 'local_la'],
                'admin-restrictedtables'
            ))->out(false),
        ];
    }

    /**
     * Get app widgets for install review.
     *
     * @param array $definition
     * @return array
     */
    protected static function get_app_widgets(array $definition): array {
        $items = [];

        foreach (($definition['widgets'] ?? []) as $widget) {
            $snippets = [];

            foreach (['sql', 'segments_sql'] as $field) {
                $code = trim((string) ($widget[$field] ?? ''));
                if ($code === '') {
                    continue;
                }

                $snippets[] = [
                    'name' => $field,
                    'code' => $code,
                    'validation' => validator::get_rules($code),
                ];
            }

            $items[] = [
                'key' => (string) ($widget['key'] ?? ''),
                'title' => (string) ($widget['title'] ?? ''),
                'type' => (string) ($widget['type'] ?? ''),
                'has_snippets' => !empty($snippets),
                'snippets' => $snippets,
            ];
        }

        return $items;
    }

    /**
     * Get configured report columns for install review.
     *
     * @param array $columns
     * @return array
     */
    protected static function get_preview_columns(array $columns): array {
        $items = [];
        $reports = repository::get_all_reports();

        foreach ($columns as $key => $config) {
            $drilldown = self::get_column_drilldown($config, $reports);
            $items[] = [
                'key' => (string) $key,
                'name' => (string) ($config['name'] ?? $key),
                'type' => self::get_column_type($config),
                'drilldown' => $drilldown['value'],
                'drilldown_is_badge' => $drilldown['isbadge'],
                'drilldown_class' => $drilldown['class'],
                'drilldown_has_tooltip' => $drilldown['hastooltip'],
                'drilldown_tooltip' => $drilldown['tooltip'],
                'visible' => empty($config['visible']) ? get_string('no') : get_string('yes'),
            ];
        }

        return $items;
    }

    /**
     * Get report column type for install review.
     *
     * @param array $config
     * @return string
     */
    protected static function get_column_type(array $config): string {
        $linktype = (string) ($config['link']['type'] ?? '');

        if ($linktype === 'report' || $linktype === 'modal') {
            return get_string('modal', 'local_la');
        }

        if ($linktype === 'url') {
            return get_string('link', 'local_la');
        }

        $type = (string) ($config['type'] ?? 'text');

        if ($type === 'text') {
            return get_string('text', 'local_la');
        }

        if ($type === 'bool') {
            return get_string('boolean', 'local_la');
        }

        return ucfirst($type);
    }

    /**
     * Get report column drilldown target.
     *
     * @param array $config
     * @param array $reports
     * @return array
     */
    protected static function get_column_drilldown(array $config, array $reports): array {
        $link = $config['link'] ?? [];

        if (!empty($link['method'])) {
            $method = (string) $link['method'];
            return [
                'value' => self::get_drilldown_method_name($method),
                'isbadge' => true,
                'class' => 'text-bg-success',
                'hastooltip' => false,
                'tooltip' => '',
            ];
        }

        if ((string) ($link['type'] ?? '') === 'report' && !empty($link['report'])) {
            $report = (string) $link['report'];
            $installed = !empty($reports[$report]['name']);
            return [
                'value' => $report,
                'isbadge' => true,
                'class' => $installed ? 'text-bg-success' : 'text-bg-danger',
                'hastooltip' => !$installed,
                'tooltip' => $installed ? '' : get_string('installlinkedreporttoactivate', 'local_la', $report),
            ];
        }

        return [
            'value' => get_string('notset', 'local_la'),
            'isbadge' => false,
            'class' => '',
            'hastooltip' => false,
            'tooltip' => '',
        ];
    }

    /**
     * Get user-facing drilldown method name.
     *
     * @param string $method
     * @return string
     */
    protected static function get_drilldown_method_name(string $method): string {
        if ($method === 'local_la_get_grade') {
            return get_string('grade', 'local_la');
        }

        if ($method === 'local_la_get_calendar') {
            return get_string('calendar', 'local_la');
        }

        return $method;
    }

    /**
     * Get fake sample report data for install review.
     *
     * @param array $columns
     * @return array
     */
    protected static function get_sample_preview(array $columns): array {
        $samplecolumns = [];

        foreach ($columns as $key => $config) {
            if (empty($config['enabled']) || empty($config['visible'])) {
                continue;
            }

            $samplecolumns[] = [
                'key' => (string) $key,
                'name' => (string) ($config['name'] ?? $key),
                'type' => (string) ($config['type'] ?? 'text'),
                'is_link' => !empty($config['link']),
            ];
        }

        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $cells = [];

            foreach ($samplecolumns as $column) {
                $status = self::get_sample_status($column['key'], $column['name'], $i);
                $cells[] = [
                    'value' => $status ? get_string($status, 'local_la') :
                        self::get_sample_value($column['key'], $column['name'], $column['type'], $i),
                    'is_link' => $column['is_link'],
                    'is_status' => !empty($status),
                    'status_class' => $status ? 'la-status-pill la-status-pill-' . $status : '',
                ];
            }

            $rows[] = ['cells' => $cells];
        }

        return [
            'columns' => $samplecolumns,
            'rows' => $rows,
        ];
    }

    /**
     * Get one fake sample value.
     *
     * @param string $key
     * @param string $name
     * @param string $type
     * @param int $row
     * @return string
     */
    protected static function get_sample_value(string $key, string $name, string $type, int $row): string {
        $field = \core_text::strtolower($key . ' ' . $name);
        $users = ['Alex Chen', 'Maria Garcia', 'Sam Taylor', 'Priya Shah', 'Jordan Lee'];
        $courses = ['Data Analytics', 'Project Management', 'Course Design', 'Business English', 'Compliance Basics'];
        $activities = ['Quiz 1', 'Final assessment', 'Welcome forum', 'Module overview', 'Assignment 2'];

        if (strpos($field, 'email') !== false) {
            return 'learner' . $row . '@example.com';
        }

        if (preg_match('/\b(enrolled|completed)\s+(courses|users)\b|\bactivities\b|\bcount\b|\bvisits\b/', $field)) {
            return (string) ($row * 3);
        }

        if (strpos($field, 'course') !== false) {
            return $courses[($row - 1) % count($courses)];
        }

        if (strpos($field, 'activity') !== false || strpos($field, 'module') !== false) {
            return $activities[($row - 1) % count($activities)];
        }

        if (strpos($field, 'user') !== false || strpos($field, 'name') !== false) {
            return $users[($row - 1) % count($users)];
        }

        if (strpos($field, 'grade') !== false || strpos($field, 'progress') !== false) {
            return (string) (62 + ($row * 3) % 38) . '%';
        }

        if (strpos($field, 'time') !== false || strpos($field, 'duration') !== false) {
            return (string) (1 + ($row % 5)) . 'h ' . (string) (($row * 7) % 60) . 'm';
        }

        if ($type === 'time' || strpos($field, 'date') !== false || strpos($field, 'created') !== false || strpos($field, 'modified') !== false) {
            return userdate(time() - ($row * DAYSECS), get_string('strftimedate', 'langconfig'));
        }

        if ($type === 'bool') {
            return $row % 2 === 0 ? get_string('yes') : get_string('no');
        }

        if (strpos($field, 'id') !== false) {
            return (string) ($row * 3);
        }

        return get_string('samplevalue', 'local_la', $row);
    }

    /**
     * Get fake status value.
     *
     * @param string $key
     * @param string $name
     * @param int $row
     * @return string
     */
    protected static function get_sample_status(string $key, string $name, int $row): string {
        $field = \core_text::strtolower($key . ' ' . $name);

        if (strpos($field, 'status') === false) {
            return '';
        }

        return $row % 3 === 0 ? 'completed' : 'inprogress';
    }
    /**
     * Get marketplace items.
     *
     * @param string $plan
     * @param string $type
     * @param string $search
     * @param string $sort
     * @return array
     */
    protected static function get_items(string $plan, string $type, string $search, string $sort): array {
        $type = self::normalize_item_type($type);
        $records = self::get_source_items($type);

        $search = \core_text::strtolower(trim($search));

        $records = array_values(array_filter($records, function($record) use ($plan, $search) {
            if ($plan !== 'all' && $record->plan !== $plan) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = \core_text::strtolower(trim(($record->name ?? '') . ' ' . ($record->info ?? '')));

            return strpos($haystack, $search) !== false;
        }));

        $planorder = array_flip(array_keys(helper::get_plans()));

        usort($records, function($left, $right) use ($sort, $planorder) {
            if ($sort === 'plan') {
                $plandiff = ($planorder[(string) $left->plan] ?? PHP_INT_MAX) <=>
                    ($planorder[(string) $right->plan] ?? PHP_INT_MAX);

                if ($plandiff !== 0) {
                    return $plandiff;
                }
            }

            return \core_text::strtolower((string) ($left->name ?? '')) <=>
                \core_text::strtolower((string) ($right->name ?? ''));
        });

        return self::build_items($records, $type);
    }

    /**
     * Get marketplace source items from the active sources.
     *
     * @return array
     */
    protected static function get_source_items(string $type): array {
        $items = [];
        $definitions = $type === 'app' ? api::get_marketplace_apps() : api::get_marketplace_reports();

        if ($type !== 'app') {
            $definitions = array_values(array_filter($definitions, [self::class, 'is_report_compatible']));
        }

        self::append_source_items($items, $definitions, $type);

        return $items;
    }

    /**
     * Check whether every Moodle table used by a report exists on this site.
     *
     * @param array $definition
     * @return bool
     */
    protected static function is_report_compatible(array $definition): bool {
        $snippets = [(string) ($definition['sql']['code'] ?? '')];

        foreach (($definition['sql']['dependencies'] ?? []) as $dependency) {
            $snippets[] = (string) ($dependency['code'] ?? '');
        }

        foreach ($snippets as $sql) {
            if ($sql !== '' && !empty(validator::get_missing_tables_used($sql))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize marketplace item type.
     *
     * @param string $type
     * @return string
     */
    protected static function normalize_item_type(string $type): string {
        return $type === 'apps' || $type === 'app' ? 'app' : 'report';
    }

    /**
     * Append marketplace definitions.
     *
     * @param array $items
     * @param array $definitions
     * @param string $type
     * @return void
     */
    protected static function append_source_items(array &$items, array $definitions, string $type): void {
        foreach ($definitions as $record) {
            $items[] = (object) [
                'id' => count($items) + 1,
                'type' => $type,
                'shortname' => (string) ($record['shortname'] ?? ''),
                'name' => (string) ($record['name'] ?? ''),
                'info' => (string) ($record['info'] ?? ''),
                'version' => (string) ($record['version'] ?? ''),
                'sql_name' => (string) ($record['sql']['name'] ?? ''),
                'plan' => (string) $record['plan'],
                'installs' => (int) ($record['installs'] ?? 0),
                'tags' => self::get_tags($record['tags'] ?? ''),
            ];
        }
    }

    /**
     * Get marketplace tag records.
     *
     * @param mixed $tags
     * @return array
     */
    protected static function get_tags($tags): array {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        if (!is_array($tags)) {
            return [];
        }

        $items = [];
        foreach ($tags as $tag) {
            $name = is_array($tag) ? (string) ($tag['name'] ?? '') : (string) $tag;
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $items[] = (object) [
                'name' => ucwords(str_replace('_', ' ', $name)),
                'class' => is_array($tag) ? (string) ($tag['class'] ?? 'text-bg-secondary') : 'text-bg-secondary',
            ];
        }

        return $items;
    }

    /**
     * Get one prepared plan item.
     *
     * @param string $plan
     * @return array
     */
    protected static function get_plan_item(string $plan): array {
        $item = helper::get_plans()[$plan] ?? [
            'label' => helper::get_plan_label($plan),
            'class' => 'text-bg-secondary',
        ];

        return [
            'key' => $plan,
            'value' => $plan,
            'name' => $item['label'],
            'class' => $item['class'],
        ];
    }

}
