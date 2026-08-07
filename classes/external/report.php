<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use editor_tiny\editor;
use editor_tiny\manager;
use local_la\local\audience;
use local_la\local\filters;
use local_la\local\helper;
use local_la\local\logger;
use local_la\local\report as report_helper;
use local_la\local\repository;
use local_la\local\synthetic;
use local_la\local\url;
use local_la\table\report_table;

/**
 * Generic report modal API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'report' => new external_value(PARAM_ALPHANUMEXT, 'Target report shortname'),
            'filters' => new external_value(PARAM_RAW, 'Preset filters as JSON', VALUE_DEFAULT, '{}'),
            'columns' => new external_value(PARAM_RAW, 'Preview columns as JSON', VALUE_DEFAULT, '[]'),
            'metrics' => new external_value(PARAM_RAW, 'Modal metrics as JSON', VALUE_DEFAULT, '[]'),
            'params' => new external_value(PARAM_RAW, 'Runtime params as JSON', VALUE_DEFAULT, '{}'),
            'title' => new external_value(PARAM_TEXT, 'Modal title', VALUE_DEFAULT, ''),
            'summary' => new external_value(PARAM_RAW, 'Modal summary as JSON', VALUE_DEFAULT, '{}'),
            'showsubheader' => new external_value(PARAM_BOOL, 'Whether to show modal subheader', VALUE_DEFAULT, 1),
            'showfullreporturl' => new external_value(PARAM_BOOL, 'Whether to show full report link', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $reportshortname
     * @param string $filtersjson
     * @param string $columnsjson
     * @param string $metricsjson
     * @param string $paramsjson
     * @param string $title
     * @param string $summary
     * @param bool $showsubheader
     * @param bool $showfullreporturl
     * @return array
     */
    public static function execute(
        string $reportshortname,
        string $filtersjson = '{}',
        string $columnsjson = '[]',
        string $metricsjson = '[]',
        string $paramsjson = '{}',
        string $title = '',
        string $summary = '',
        bool $showsubheader = true,
        bool $showfullreporturl = true
    ): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'report' => $reportshortname,
            'filters' => $filtersjson,
            'columns' => $columnsjson,
            'metrics' => $metricsjson,
            'params' => $paramsjson,
            'title' => $title,
            'summary' => $summary,
            'showsubheader' => $showsubheader,
            'showfullreporturl' => $showfullreporturl,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::can_use_drilldown()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if (!helper::has_feature('drilldown')) {
            throw new \moodle_exception('featureunavailable_drilldown', 'local_la');
        }

        $report = repository::get_report($params['report']);
        if (!$report) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        if (!audience::has_access((int) $report->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $presetfilters = self::normalise_filters(
            self::decode_array_json($params['filters'], []),
            $report->params
        );
        $previewcolumns = self::decode_array_json($params['columns'], []);
        $metricdefinitions = self::decode_array_json($params['metrics'], []);
        $runtimeparams = self::decode_array_json($params['params'], []);
        $report = self::apply_preview_columns($report, $previewcolumns);
        $syntheticurlparams = [];

        synthetic::set_runtime_params($runtimeparams);
        try {
            $context = report_helper::get_context($report, $presetfilters);
            if (!$context) {
                throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
            }

            $syntheticurlparams = synthetic::get_url_params();

            $table = new report_table((int) $report->id, $presetfilters, true);
            $table->load($report);
            $table->pagesize = 100;
            $table->baseurl = url::report_tab_url(
                (int) $report->id,
                filters::get_params($presetfilters, ['tab' => 'view'] + $syntheticurlparams, true)
            );

            ob_start();
            $table->out($table->pagesize, false);
            $tablehtml = ob_get_clean();
        } finally {
            synthetic::clear_runtime_params();
        }

        $fullreporturl = '';

        if (!empty($params['showfullreporturl']) && !empty($report->id)) {
            $fullreporturl = url::report_tab(
                (int) $report->id,
                filters::get_params($presetfilters, ['tab' => 'view'] + $syntheticurlparams, true)
            );
        }

        $renderer = $PAGE->get_renderer('local_la');
        $title = $params['title'] !== '' ? $params['title'] : format_string((string) $report->name);

        return [
            'title' => $title,
            'html' => $renderer->render_from_template('local_la/modal/report_preview', [
                'tablehtml' => $tablehtml === false ? '' : $tablehtml,
                'hastablehtml' => $tablehtml !== false && $tablehtml !== '',
                'empty' => get_string('nothingtodisplay'),
                'summary' => self::normalise_summary(self::decode_array_json($params['summary'], [])),
                'metrics' => self::resolve_metrics($context, $metricdefinitions, $params['title']),
                'showsubheader' => !empty($params['showsubheader']),
                'fullreporturl' => $fullreporturl,
                'fullreportname' => format_string((string) $report->name),
            ]),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Rendered modal html'),
        ]);
    }

    /**
     * Report details modal parameters.
     *
     * @return external_function_parameters
     */
    public static function modal_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
        ]);
    }

    /**
     * Render report details edit modal.
     *
     * @param int $reportid
     * @return array
     */
    public static function modal(int $reportid): array {
        global $PAGE;

        $params = self::validate_parameters(self::modal_parameters(), ['reportid' => $reportid]);
        $context = context_system::instance();
        self::validate_context($context);
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $report = repository::get_report((int) $params['reportid']);
        if (!$report) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $siteconfig = get_config('editor_tiny');
        $renderer = $PAGE->get_renderer('local_la');

        return [
            'title' => get_string('editreportdetails', 'local_la'),
            'html' => $renderer->render_from_template('local_la/modal/report_details', [
                'reportid' => (int) $report->id,
                'name' => (string) $report->name,
                'description' => (string) ($report->info ?? ''),
                'tags' => (string) ($report->tags ?? ''),
            ]),
            'editoroptions' => json_encode([
                'css' => $PAGE->theme->editor_css_url()->out(false),
                'context' => $context->id,
                'filepicker' => (object) [],
                'draftitemid' => 0,
                'currentLanguage' => current_language(),
                'branding' => property_exists($siteconfig, 'branding') ? !empty($siteconfig->branding) : true,
                'extended_valid_elements' => $siteconfig->extended_valid_elements ?? 'script[*],p[*],i[*]',
                'language' => [
                    'currentlang' => current_language(),
                    'installed' => get_string_manager()->get_list_of_translations(true),
                    'available' => get_string_manager()->get_list_of_languages(),
                ],
                'placeholderSelectors' => [],
                'plugins' => (new manager())->get_plugin_configuration($context, ['autosave' => false], [], new editor()),
            ]),
        ];
    }

    /**
     * Save report details parameters.
     *
     * @return external_function_parameters
     */
    public static function save_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_DEFAULT, ''),
            'tags' => new external_value(PARAM_TEXT, 'Tags', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save report details.
     *
     * @param int $reportid
     * @param string $name
     * @param string $description
     * @param string $tags
     * @return array
     */
    public static function save(int $reportid, string $name, string $description = '', string $tags = ''): array {
        $params = self::validate_parameters(self::save_parameters(), [
            'reportid' => $reportid,
            'name' => $name,
            'description' => $description,
            'tags' => $tags,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if (trim($params['name']) === '') {
            throw new \invalid_parameter_exception('Missing report name');
        }

        repository::update_report((object) [
            'id' => (int) $params['reportid'],
            'name' => $params['name'],
            'info' => $params['description'],
            'tags' => $params['tags'],
        ]);
        logger::add('update_report_details', 'report', (int) $params['reportid'], [
            'name' => $params['name'],
            'tags' => $params['tags'],
        ]);

        return ['success' => true];
    }

    /**
     * Report details modal returns.
     *
     * @return external_single_structure
     */
    public static function modal_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Modal HTML'),
            'editoroptions' => new external_value(PARAM_RAW, 'Editor options'),
        ]);
    }

    /**
     * Save report details returns.
     *
     * @return external_single_structure
     */
    public static function save_returns(): external_single_structure {
        return new external_single_structure(['success' => new external_value(PARAM_BOOL, 'Success')]);
    }

    /**
     * Decode one JSON array.
     *
     * @param string $json
     * @param array $default
     * @return array
     */
    protected static function decode_array_json(string $json, array $default): array {
        $data = json_decode($json, true);

        return is_array($data) ? $data : $default;
    }

    /**
     * Normalise modal summary context.
     *
     * @param array $summary
     * @return array
     */
    protected static function normalise_summary(array $summary): array {
        $primary = trim((string) ($summary['primary'] ?? ''));
        $secondary = trim((string) ($summary['secondary'] ?? ''));

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'hasprimary' => $primary !== '',
            'hassecondary' => $secondary !== '',
        ];
    }

    /**
     * Resolve modal metrics from one report context.
     *
     * @param \stdClass $context
     * @param array $definitions
     * @param string $defaultlabel
     * @return array
     */
    protected static function resolve_metrics(\stdClass $context, array $definitions, string $defaultlabel): array {
        global $DB;

        if (empty($definitions)) {
            $definitions = [[
                'type' => 'count',
                'label' => trim($defaultlabel) !== '' ? trim($defaultlabel) : 'Count',
            ]];
        }

        $metrics = [];

        foreach ($definitions as $definition) {
            $type = (string) ($definition['type'] ?? 'count');
            $label = trim((string) ($definition['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            if ($type === 'avg') {
                $column = self::resolve_preview_column_key((string) ($definition['column'] ?? ''), $context->params);
                $value = '-';

                if ($column !== '') {
                    $sql = 'SELECT AVG(reportdata.' . $column . ') AS metricvalue
                              FROM (' . $context->sql . ') reportdata';
                    $record = $DB->get_record_sql($sql, $context->queryparams);

                    if (!empty($record) && $record->metricvalue !== null) {
                        $value = number_format((float) $record->metricvalue, 2, '.', '');
                    }
                }

                $metrics[] = [
                    'label' => $label,
                    'value' => $value,
                ];
                continue;
            }

            $sql = 'SELECT COUNT(1) AS metricvalue
                      FROM (' . $context->sql . ') reportdata';
            $record = $DB->get_record_sql($sql, $context->queryparams);

            $metrics[] = [
                'label' => $label,
                'value' => (string) (int) ($record->metricvalue ?? 0),
            ];
        }

        return $metrics;
    }

    /**
     * Build runtime filters from link config.
     *
     * @param array $filterspec
     * @param array $reportparams
     * @return array
     */
    protected static function normalise_filters(array $filterspec, array $reportparams): array {
        $filters = [];

        foreach ($filterspec as $key => $spec) {
            $definition = ($reportparams['columns'][$key]['filter'] ?? []);
            $type = (string) ($definition['type'] ?? 'text');

            if (is_array($spec) && array_key_exists('operator', $spec)) {
                $filter = [
                    'operator' => (string) ($spec['operator'] ?? 'equal'),
                    'value' => $spec['value'] ?? '',
                ];

                if (array_key_exists('from', $spec)) {
                    $filter['from'] = (string) ($spec['from'] ?? '');
                }

                if (array_key_exists('to', $spec)) {
                    $filter['to'] = (string) ($spec['to'] ?? '');
                }
            } else {
                $value = $spec;

                if (in_array($type, ['users', 'courses'], true) && !is_array($value) && $value !== '') {
                    $value = [(string) $value];
                }

                $filter = [
                    'operator' => 'equal',
                    'value' => $value,
                ];
            }

            $filters[(string) $key] = $filter;
        }

        return $filters;
    }

    /**
     * Apply preview column visibility/order to one report object.
     *
     * @param \stdClass $report
     * @param array $requested
     * @return \stdClass
     */
    protected static function apply_preview_columns(\stdClass $report, array $requested): \stdClass {
        if (empty($requested) || empty($report->params['columns']) || !is_array($report->params['columns'])) {
            return $report;
        }

        $requestedkeys = [];

        foreach ($requested as $requestedkey) {
            $key = self::resolve_preview_column_key((string) $requestedkey, $report->params);

            if ($key !== '') {
                $requestedkeys[] = $key;
            }
        }

        $requestedkeys = array_values(array_unique($requestedkeys));

        if (empty($requestedkeys)) {
            return $report;
        }

        foreach ($report->params['columns'] as $key => $columnconfig) {
            if (empty($columnconfig['name'])) {
                continue;
            }

            $report->params['columns'][$key]['enabled'] = false;
            $report->params['columns'][$key]['visible'] = false;
        }

        $order = 10;

        foreach ($requestedkeys as $key) {
            if (empty($report->params['columns'][$key]['name'])) {
                continue;
            }

            $report->params['columns'][$key]['enabled'] = true;
            $report->params['columns'][$key]['visible'] = true;
            $report->params['columns'][$key]['order'] = $order;
            $order += 10;
        }

        return $report;
    }

    /**
     * Resolve one preview column key from config key or SQL alias.
     *
     * @param string $requested
     * @param array $reportparams
     * @return string
     */
    protected static function resolve_preview_column_key(string $requested, array $reportparams): string {
        if (isset($reportparams['columns'][$requested])) {
            return $requested;
        }

        foreach (($reportparams['columns'] ?? []) as $key => $columnconfig) {
            $alias = trim((string) (($columnconfig['sql'] ?? [])['alias'] ?? ''));
            $outputname = $alias !== '' ? $alias : (string) $key;

            if ($outputname === $requested) {
                return (string) $key;
            }
        }

        return '';
    }
}
