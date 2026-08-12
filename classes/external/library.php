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

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_la\local\audience;
use local_la\local\helper;
use local_la\local\report as report_helper;
use local_la\local\installer;
use local_la\local\logger;
use local_la\local\repository;
use local_la\local\validator;

/**
 * External library actions API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHA, 'Library action'),
            'reportid' => new external_value(PARAM_INT, 'Report id', VALUE_DEFAULT, 0),
            'reportkey' => new external_value(PARAM_ALPHANUMEXT, 'Report shortname', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $action
     * @param int $reportid
     * @param string $reportkey
     * @return array
     */
    public static function execute(string $action, int $reportid = 0, string $reportkey = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action,
            'reportid' => $reportid,
            'reportkey' => $reportkey,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        $duplicatereportid = 0;
        $audienceactions = ['addreport', 'favorite', 'hide', 'show', 'reset', 'delete'];
        if (
            in_array($params['action'], $audienceactions, true) &&
                !audience::has_access((int) $params['reportid'], (int) $USER->id)
        ) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        switch ($params['action']) {
            case 'installapp':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('apps', 'local_la'));
                }
                if ($params['reportkey'] === '') {
                    throw new \invalid_parameter_exception('Missing app key');
                }
                $appid = installer::install_app($params['reportkey']);
                logger::add('install_app', 'app', $appid, [
                    'appkey' => $params['reportkey'],
                ]);
                break;

            case 'updateapp':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('apps', 'local_la'));
                }
                if ($params['reportkey'] === '') {
                    throw new \invalid_parameter_exception('Missing app key');
                }
                $appid = installer::update_app($params['reportkey']);
                logger::add('update_app', 'app', $appid, [
                    'appkey' => $params['reportkey'],
                ]);
                break;

            case 'uninstallapp':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('apps', 'local_la'));
                }
                $app = $DB->get_record('local_la_app', ['id' => $params['reportid']], 'id, name, shortname', IGNORE_MISSING);
                installer::uninstall_app($params['reportid']);
                if ($app) {
                    logger::add('uninstall_app', 'app', (int) $params['reportid'], [
                        'name' => (string) $app->name,
                        'shortname' => (string) $app->shortname,
                    ]);
                }
                break;

            case 'hideapp':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('apps', 'local_la'));
                }
                repository::set_app_status($params['reportid'], 0);
                logger::add('hide_app', 'app', (int) $params['reportid']);
                break;

            case 'showapp':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('apps', 'local_la'));
                }
                repository::set_app_status($params['reportid'], 1);
                logger::add('show_app', 'app', (int) $params['reportid']);
                break;

            case 'installreport':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
                }
                if ($params['reportkey'] === '') {
                    throw new \invalid_parameter_exception('Missing report key');
                }
                $installedreportid = installer::install_report($params['reportkey']);
                repository::add_report($installedreportid, (int) $USER->id);
                logger::add('install_report', 'report', $installedreportid, [
                    'reportkey' => $params['reportkey'],
                ]);
                $duplicatereportid = $installedreportid;
                break;

            case 'updatereport':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
                }
                if ($params['reportkey'] === '') {
                    throw new \invalid_parameter_exception('Missing report key');
                }
                $updatedreportid = installer::update_report($params['reportkey']);
                logger::add('update_report', 'report', $updatedreportid, [
                    'reportkey' => $params['reportkey'],
                ]);
                break;

            case 'installgenerated':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
                }
                $token = $params['reportkey'];
                $cache = \cache::make('local_la', 'install_definitions');
                $definition = $cache->get($token);
                if (!is_array($definition)) {
                    throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
                }

                $installedreportid = installer::install_definition($definition);
                $cache->delete($token);
                repository::add_report($installedreportid, (int) $USER->id);
                logger::add('install_generated_report', 'report', $installedreportid, [
                    'shortname' => (string) ($definition['shortname'] ?? ''),
                ]);
                $duplicatereportid = $installedreportid;
                break;

            case 'addreport':
                repository::add_report($params['reportid'], (int) $USER->id);
                logger::add('enable_report', 'report', (int) $params['reportid']);
                break;

            case 'uninstallreport':
                if (!helper::is_billing_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error', '', get_string('marketplace', 'local_la'));
                }
                $report = $DB->get_record('local_la_report', ['id' => $params['reportid']], 'id, name, shortname', IGNORE_MISSING);
                installer::uninstall_report($params['reportid']);
                if ($report) {
                    logger::add('uninstall_report', 'report', (int) $params['reportid'], [
                        'name' => (string) $report->name,
                        'shortname' => (string) $report->shortname,
                    ]);
                }
                break;

            case 'favorite':
                repository::favorite_report($params['reportid'], (int) $USER->id);
                logger::add('toggle_favorite_report', 'report', (int) $params['reportid']);
                break;

            case 'hide':
                repository::set_report_status($params['reportid'], 0, (int) $USER->id);
                logger::add('hide_report', 'report', (int) $params['reportid']);
                break;

            case 'show':
                repository::set_report_status($params['reportid'], 1, (int) $USER->id);
                logger::add('show_report', 'report', (int) $params['reportid']);
                break;

            case 'reset':
                repository::reset_report($params['reportid'], (int) $USER->id);
                logger::add('reset_report', 'report', (int) $params['reportid']);
                break;

            case 'delete':
                repository::delete_report($params['reportid'], (int) $USER->id);
                logger::add('disable_report', 'report', (int) $params['reportid']);
                break;

            case 'duplicate':
                if (!helper::is_admin((int) $USER->id)) {
                    throw new \moodle_exception('nopermissions', 'error');
                }
                $duplicatereportid = repository::duplicate_report($params['reportid'], (int) $USER->id);
                logger::add('duplicate_report', 'report', $duplicatereportid, [
                    'source_reportid' => (int) $params['reportid'],
                ]);
                break;

            default:
                throw new \invalid_parameter_exception('Invalid library action');
        }

        return [
            'success' => true,
            'reportid' => $duplicatereportid,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Action result'),
            'reportid' => new external_value(PARAM_INT, 'New report id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * SQL preview parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_sql_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
        ]);
    }

    /**
     * Get library report SQL preview.
     *
     * @param int $reportid
     * @return array
     */
    public static function execute_sql(int $reportid): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_sql_parameters(), [
            'reportid' => $reportid,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $report = repository::get_report($params['reportid']);

        if (!$report) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $context = report_helper::get_context($report);

        $columns = self::get_preview_columns($report->params);
        $sqlsnippets = self::get_sql_snippets($report);

        $renderer = $PAGE->get_renderer('local_la');
        $html = $renderer->render_from_template('local_la/modal/library_sql', [
            'report_name' => (string) $report->name,
            'report_fields' => self::get_report_fields($report),
            'sql_snippets' => $sqlsnippets,
            'has_sql_snippets' => !empty($sqlsnippets),
            'sql_name' => $report->sql_name,
            'sql' => $context ? $context->sql : '',
            'has_sql' => !empty($context),
            'columns' => $columns,
            'has_columns' => !empty($columns),
        ]);

        return [
            'title' => (string) ($report->name ?? 'SQL'),
            'html' => $html,
        ];
    }

    /**
     * SQL preview returns.
     *
     * @return external_single_structure
     */
    public static function execute_sql_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Rendered SQL preview html'),
        ]);
    }

    /**
     * App params preview parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_app_params_parameters(): external_function_parameters {
        return new external_function_parameters([
            'appid' => new external_value(PARAM_INT, 'App id'),
        ]);
    }

    /**
     * Get library app params preview.
     *
     * @param int $appid
     * @return array
     */
    public static function execute_app_params(int $appid): array {
        global $DB, $PAGE, $USER;

        $params = self::validate_parameters(self::execute_app_params_parameters(), [
            'appid' => $appid,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_billing_admin((int) $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $app = $DB->get_record('local_la_app', ['id' => $params['appid']], '*', MUST_EXIST);
        $definition = json_decode((string) ($app->definition ?? ''), true);
        $definition = is_array($definition) ? $definition : [];
        $definitionjson = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $renderer = $PAGE->get_renderer('local_la');
        $html = $renderer->render_from_template('local_la/modal/library_app_params', [
            'app_fields' => self::get_app_fields($app),
            'widgets' => self::get_app_widgets($definition),
            'has_widgets' => !empty($definition['widgets']),
            'definition_json' => $definitionjson ?: '{}',
        ]);

        return [
            'title' => (string) ($app->name ?? get_string('apps', 'local_la')),
            'html' => $html,
        ];
    }

    /**
     * App params preview returns.
     *
     * @return external_single_structure
     */
    public static function execute_app_params_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Rendered app params preview html'),
        ]);
    }

    /**
     * SQL status parameters.
     *
     * @return external_function_parameters
     */
    public static function update_sql_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_TEXT, 'SQL name'),
            'status' => new external_value(PARAM_BOOL, 'SQL status'),
        ]);
    }

    /**
     * Update SQL snippet status.
     *
     * @param string $name
     * @param bool $status
     * @return array
     */
    public static function update_sql_status(string $name, bool $status): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::update_sql_status_parameters(), [
            'name' => $name,
            'status' => $status,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin((int) $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $sql = $DB->get_record('local_la_sql', ['name' => $params['name']], 'id, name', IGNORE_MISSING);
        $timeactivated = $params['status'] ? time() : 0;
        $DB->set_field('local_la_sql', 'status', $params['status'] ? 1 : 0, ['name' => $params['name']]);
        $DB->set_field('local_la_sql', 'timeactivated', $timeactivated, ['name' => $params['name']]);
        if ($sql) {
            logger::add('toggle_sql_status', 'sql', (int) $sql->id, [
                'name' => (string) $sql->name,
                'status' => $params['status'] ? 1 : 0,
            ]);
        }

        return [
            'success' => true,
            'timeactivated' => self::format_sql_timeactivated($timeactivated),
        ];
    }

    /**
     * SQL status returns.
     *
     * @return external_single_structure
     */
    public static function update_sql_status_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Action result'),
            'timeactivated' => new external_value(PARAM_TEXT, 'Activation time'),
        ]);
    }

    /**
     * Get report table fields for preview.
     *
     * @param \stdClass $report
     * @return array
     */
    protected static function get_report_fields(\stdClass $report): array {
        $fields = [];
        $excluded = [
            'sql_name', 'sqlcode', 'sqlid', 'sqlrecordname', 'sqltimeactivated', 'report_params', 'user_params',
            'userid', 'favorite', 'timeaccess', 'params', 'dependencies',
        ];

        foreach ((array) $report as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            $fields[] = [
                'key' => (string) $key,
                'value' => self::format_report_field_value((string) $key, $value),
                'istags' => $key === 'tags',
                'tags' => $key === 'tags' ? self::get_tag_badges((string) $value) : [],
            ];
        }

        return $fields;
    }

    /**
     * Format report preview field values.
     *
     * @param string $key
     * @param mixed $value
     * @return string
     */
    protected static function format_report_field_value(string $key, $value): string {
        if (in_array($key, ['timecreated', 'timemodified', 'timesync', 'timeaccess', 'timeactivated'], true) && (int) $value > 0) {
            return userdate((int) $value);
        }

        return (string) $value;
    }

    /**
     * Get app table fields for preview.
     *
     * @param \stdClass $app
     * @return array
     */
    protected static function get_app_fields(\stdClass $app): array {
        $fields = [];

        foreach ((array) $app as $key => $value) {
            if ($key === 'definition' || is_array($value) || is_object($value)) {
                continue;
            }

            $fields[] = [
                'key' => (string) $key,
                'value' => self::format_report_field_value((string) $key, $value),
            ];
        }

        return $fields;
    }

    /**
     * Get app widgets for preview.
     *
     * @param array $definition
     * @return array
     */
    protected static function get_app_widgets(array $definition): array {
        $items = [];

        foreach (($definition['widgets'] ?? []) as $widget) {
            if (!is_array($widget)) {
                continue;
            }

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
                'snippets' => $snippets,
            ];
        }

        return $items;
    }

    /**
     * Get tag badge context.
     *
     * @param string $tags
     * @return array
     */
    protected static function get_tag_badges(string $tags): array {
        $items = array_filter(array_map('trim', explode(',', $tags)));

        return array_map(static function (string $tag): array {
            return ['name' => $tag];
        }, $items);
    }

    /**
     * Get SQL snippets used by the report.
     *
     * @param \stdClass $report
     * @return array
     */
    protected static function get_sql_snippets(\stdClass $report): array {
        global $DB;

        $names = array_merge([(string) $report->sql_name], report_helper::get_dependency_names($report->params));
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));

        if (empty($names)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql("
            SELECT name, code, version, timecreated, timemodified, status, timeactivated
              FROM {local_la_sql}
             WHERE name {$insql}
        ", $params);

        $snippets = [];
        foreach ($names as $name) {
            if (empty($records[$name])) {
                continue;
            }

            $record = $records[$name];
            $snippets[] = [
                'name' => (string) $record->name,
                'code' => (string) $record->code,
                'version' => (string) $record->version,
                'timecreated' => self::format_report_field_value('timecreated', $record->timecreated),
                'timemodified' => self::format_report_field_value('timemodified', $record->timemodified),
                'status_checked' => !empty($record->status),
                'timeactivated' => self::format_sql_timeactivated((int) $record->timeactivated),
                'validation' => validator::get_rules((string) $record->code),
            ];
        }

        return $snippets;
    }

    /**
     * Format SQL activation time.
     *
     * @param int $time
     * @return string
     */
    protected static function format_sql_timeactivated(int $time): string {
        return $time > 0 ? userdate($time) : get_string('notactivated', 'local_la');
    }

    /**
     * Get configured report columns for preview output.
     *
     * @param array $params
     * @return array
     */
    protected static function get_preview_columns(array $params): array {
        $columns = [];
        $reports = repository::get_all_reports();

        foreach (($params['columns'] ?? []) as $key => $config) {
            if (!report_helper::is_column_enabled($config)) {
                continue;
            }

            $drilldown = self::get_column_drilldown($config, $reports);
            $columns[] = [
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

        return $columns;
    }

    /**
     * Get report column type for preview output.
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
            return [
                'value' => self::get_drilldown_method_name((string) $link['method']),
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
}
