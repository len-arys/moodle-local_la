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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_la\external;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_la\local\audience;
use local_la\local\helper;
use local_la\local\report as report_helper;
use local_la\local\repository;
use local_la\local\validator;

/**
 * Columns modal API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class columns extends external_api {
    /** @var array */
    protected static $tablefieldcache = [];
    /** @var array */
    protected static $profilefieldcache = [];
    /** @var string|null */
    protected static $cohortnameexpression = null;
    /** @var \xmldb_structure|null */
    protected static $installxmlschema = null;

    /**
     * Get one valid report or fail.
     *
     * @param int $id
     * @return \stdClass
     */
    protected static function get_report_or_fail(int $id): \stdClass {
        $report = repository::get_report($id);

        if (!$report) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        if (!audience::has_access((int) $report->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        return $report;
    }

    /**
     * Get one manager-accessible report or fail.
     *
     * @param int $id
     * @return \stdClass
     */
    protected static function get_managed_report_or_fail(int $id): \stdClass {
        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        return self::get_report_or_fail($id);
    }

    /**
     * Get field metadata from Moodle schema tools.
     *
     * @param string $table
     * @return array
     */
    protected static function get_table_fields(string $table): array {
        global $DB;

        if (array_key_exists($table, self::$tablefieldcache)) {
            return self::$tablefieldcache[$table];
        }

        $dbman = $DB->get_manager();

        if (!$dbman->table_exists($table)) {
            return [];
        }

        if (self::$installxmlschema === null) {
            self::$installxmlschema = $dbman->get_install_xml_schema();
        }

        $schematable = self::$installxmlschema->getTable($table);
        $livecolumns = $DB->get_columns($table, false);
        $fields = [];

        foreach ($livecolumns as $name => $adocolumn) {
            $field = new \xmldb_field((string) $name);
            $field->setFromADOField($adocolumn);
            $schemafield = $schematable ? $schematable->getField((string) $name) : null;

            if ($schemafield && $schemafield->getComment() !== '') {
                $field->setComment($schemafield->getComment());
            }

            $fields[(string) $name] = $field;
        }

        self::$tablefieldcache[$table] = $fields;

        return $fields;
    }

    /**
     * Detect a simple report column type.
     *
     * @param string $field
     * @param \xmldb_field|null $metadata
     * @return string
     */
    protected static function infer_type(string $field, ?\xmldb_field $metadata = null): string {
        $boolfields = [
            'visible',
            'visibleold',
            'deleted',
            'suspended',
            'confirmed',
            'maildisplay',
            'legacyfiles',
            'completionnotify',
            'enablecompletion',
        ];

        if (in_array($field, $boolfields, true)) {
            return 'bool';
        }

        if ($metadata) {
            if ($metadata->getType() === XMLDB_TYPE_TIMESTAMP || $metadata->getType() === XMLDB_TYPE_DATETIME) {
                return 'time';
            }

            if ($metadata->getType() === XMLDB_TYPE_INTEGER && (int) $metadata->getLength() === 1) {
                return 'bool';
            }
        }

        if (preg_match('/(^time)|(_date$)|(^startdate$)|(^enddate$)|(^added$)/', $field)) {
            return 'time';
        }

        return 'text';
    }

    /**
     * Detect a simple report column type from one profile field datatype.
     *
     * @param string $datatype
     * @return string
     */
    protected static function infer_profile_type(string $datatype): string {
        $mapping = [
            'menu' => 'menu',
            'checkbox' => 'bool',
            'datetime' => 'time',
        ];

        return $mapping[$datatype] ?? 'text';
    }

    /**
     * Build one filter config from one simple type.
     *
     * @param string $type
     * @param array $sourceparams
     * @return array
     */
    protected static function build_filter_config(string $type, array $sourceparams = []): array {
        if ($type === 'time') {
            return ['type' => 'date'];
        }

        if ($type === 'bool') {
            return [
                'type' => 'select',
                'options' => [
                    '1' => get_string('yes'),
                    '0' => get_string('no'),
                ],
            ];
        }

        if ($type === 'menu') {
            return [
                'type' => 'select',
                'source' => 'get_filter_menu_options',
                'source_params' => $sourceparams,
            ];
        }

        return ['type' => 'text'];
    }

    /**
     * Get available column type options.
     *
     * @param string $current
     * @return array
     */
    protected static function get_column_type_options(string $current = 'text'): array {
        $options = [];

        $types = ['text', 'time', 'bool'];

        if ($current === 'menu') {
            $types[] = 'menu';
        }

        foreach ($types as $type) {
            $options[] = [
                'value' => $type,
                'label' => ucfirst($type),
                'selected' => ($current === $type),
            ];
        }

        return $options;
    }

    /**
     * Get editable column updates from one payload.
     *
     * @param array $item
     * @param array $defaults
     * @return array
     */
    protected static function get_editable_column_updates(array $item, array $defaults = []): array {
        return [
            'name' => trim((string) ($item['name'] ?? ($defaults['name'] ?? ''))),
            'type' => (string) ($item['type'] ?? ($defaults['type'] ?? 'text')),
            'formula' => trim((string) ($item['formula'] ?? ($defaults['formula'] ?? ''))),
            'condition' => trim((string) ($item['condition'] ?? ($defaults['condition'] ?? ''))),
            'visible' => empty($item['visible']) ? 0 : 1,
            'sortable' => empty($item['sortable']) ? 0 : 1,
        ];
    }

    /**
     * Sort section fields with active items first.
     *
     * @param array $fields
     * @return void
     */
    protected static function sort_section_fields(array &$fields): void {
        usort($fields, function (array $a, array $b): int {
            if (!empty($a['active']) && empty($b['active'])) {
                return -1;
            }

            if (empty($a['active']) && !empty($b['active'])) {
                return 1;
            }

            return strnatcasecmp((string) $a['label'], (string) $b['label']);
        });
    }

    /**
     * Check whether one column flag is enabled.
     *
     * @param array $column
     * @param string $name
     * @return bool
     */
    protected static function is_column_flag_enabled(array $column, string $name): bool {
        return !empty($column[$name]);
    }

    /**
     * Build builder form context for one field row.
     *
     * @param string $idprefix
     * @param string $columnkey
     * @param string $type
     * @param string $namevalue
     * @param string $formulavalue
     * @param string $conditionvalue
     * @param bool $enabled
     * @param bool $visible
     * @param bool $sortable
     * @return array
     */
    protected static function get_builder_form_context(
        string $idprefix,
        string $columnkey,
        string $type,
        string $namevalue,
        string $formulavalue,
        string $conditionvalue,
        bool $enabled,
        bool $visible,
        bool $sortable
    ): array {
        return [
            'columnkey' => $columnkey,
            'types' => self::get_column_type_options($type),
            'idprefix' => $idprefix,
            'enabledname' => 'columns[' . $columnkey . '][enabled]',
            'enabledregion' => 'builder-enabled',
            'enabledchecked' => $enabled,
            'namename' => 'columns[' . $columnkey . '][name]',
            'nameregion' => 'builder-name',
            'namevalue' => $namevalue,
            'typename' => 'columns[' . $columnkey . '][type]',
            'typeregion' => 'builder-type',
            'formulaname' => 'columns[' . $columnkey . '][formula]',
            'formularegion' => 'builder-formula',
            'formulavalue' => $formulavalue,
            'conditionname' => 'columns[' . $columnkey . '][condition]',
            'conditionregion' => 'builder-condition',
            'conditionvalue' => $conditionvalue,
            'visiblename' => 'columns[' . $columnkey . '][visible]',
            'visiblechecked' => $visible,
            'visibleregion' => 'builder-visible',
            'sortablename' => 'columns[' . $columnkey . '][sortable]',
            'sortablechecked' => $sortable,
            'sortableregion' => 'builder-sortable',
        ];
    }

    /**
     * Build settings form context for one column.
     *
     * @param string $idprefix
     * @param array $column
     * @return array
     */
    protected static function get_settings_form_context(string $idprefix, array $column): array {
        $columnkey = (string) ($column['key'] ?? '');

        return [
            'idprefix' => $idprefix,
            'columnkey' => $columnkey,
            'enabledchecked' => self::is_column_flag_enabled($column, 'enabled'),
            'enabledname' => 'columns[' . $columnkey . '][enabled]',
            'enabledregion' => 'column-enabled',
            'namevalue' => (string) ($column['name'] ?? ''),
            'namename' => 'columns[' . $columnkey . '][name]',
            'nameregion' => 'column-name',
            'typename' => 'columns[' . $columnkey . '][type]',
            'typeregion' => 'column-type',
            'formulaname' => 'columns[' . $columnkey . '][formula]',
            'formularegion' => 'column-formula',
            'formulavalue' => (string) ($column['formula'] ?? ''),
            'conditionname' => 'columns[' . $columnkey . '][condition]',
            'conditionregion' => 'column-condition',
            'conditionvalue' => (string) ($column['condition'] ?? ''),
            'visiblechecked' => !empty($column['visible']),
            'visiblename' => 'columns[' . $columnkey . '][visible]',
            'visibleregion' => 'column-visible',
            'sortablechecked' => !empty($column['sortable']),
            'sortablename' => 'columns[' . $columnkey . '][sortable]',
            'sortableregion' => 'column-sortable',
            'types' => self::get_column_type_options((string) ($column['type'] ?? 'text')),
        ];
    }

    /**
     * Build a visible name from one field.
     *
     * @param string $field
     * @param string $entitykey
     * @param \xmldb_field|null $metadata
     * @return string
     */
    protected static function format_field_name(string $field, string $entitykey = '', ?\xmldb_field $metadata = null): string {
        $stringmanager = get_string_manager();
        $mappings = [
            'user' => [
                'picture' => ['userpicture', 'reportbuilder'],
            ],
            'course' => [
                'fullname' => ['coursefullname', 'moodle'],
                'shortname' => ['shortnamecourse', 'moodle'],
            ],
        ];

        if ($entitykey !== '' && !empty($mappings[$entitykey][$field])) {
            [$stringkey, $component] = $mappings[$entitykey][$field];

            if ($stringmanager->string_exists($stringkey, $component)) {
                return get_string($stringkey, $component);
            }
        }

        foreach (['moodle', 'reportbuilder'] as $component) {
            if ($stringmanager->string_exists($field, $component)) {
                return get_string($field, $component);
            }
        }

        return (string) $field;
    }

    /**
     * Get a SQL table alias from report SQL.
     *
     * @param \stdClass $report
     * @param string $table
     * @return string
     */
    protected static function get_entity_alias(\stdClass $report, string $table): string {
        if ($table === '') {
            return '';
        }

        return report_helper::get_sql_table_aliases(
            (string) ($report->sqlcode ?? ''),
            $report->dependencies ?? []
        )[$table] ?? '';
    }

    /**
     * Get next column order.
     *
     * @param array $reportcolumns
     * @return int
     */
    protected static function get_next_order(array $reportcolumns): int {
        $max = 0;

        foreach ($reportcolumns as $column) {
            $max = max($max, (int) ($column['order'] ?? 0));
        }

        return $max + 10;
    }

    /**
     * Find an existing column by source metadata or SQL expression.
     *
     * @param array $reportcolumns
     * @param string $table
     * @param string $column
     * @param string $expression
     * @return string
     */
    protected static function find_existing_column(
        array $reportcolumns,
        string $table = '',
        string $column = '',
        string $expression = ''
    ): string {
        if ($table !== '' && $column !== '') {
            foreach ($reportcolumns as $key => $config) {
                if (($config['sql']['table'] ?? '') === $table && ($config['sql']['column'] ?? '') === $column) {
                    return (string) $key;
                }
            }
        }

        if ($expression !== '') {
            foreach ($reportcolumns as $key => $column) {
                if ((string) (($column['sql']['column'] ?? '')) === $expression) {
                    return (string) $key;
                }
            }
        }

        return '';
    }

    /**
     * Build a unique custom column key.
     *
     * @param array $reportcolumns
     * @param string $entitykey
     * @param string $field
     * @return string
     */
    protected static function get_custom_key(array $reportcolumns, string $entitykey, string $field): string {
        $base = 'custom_' . preg_replace('/_id$/', '', $entitykey) . '_' . clean_param($field, PARAM_ALPHANUMEXT);
        $key = $base;
        $index = 2;

        while (array_key_exists($key, $reportcolumns)) {
            $key = $base . '_' . $index;
            $index++;
        }

        return $key;
    }

    /**
     * Get custom profile fields.
     *
     * @return array
     */
    protected static function get_profile_fields(): array {
        global $DB;

        if (self::$profilefieldcache !== []) {
            return self::$profilefieldcache;
        }

        self::$profilefieldcache = $DB->get_records('user_info_field', null, 'sortorder ASC, name ASC');

        return self::$profilefieldcache;
    }

    /**
     * Build one profile field SQL expression.
     *
     * @param string $alias
     * @param int $fieldid
     * @return string
     */
    protected static function get_profile_field_expression(string $alias, int $fieldid): string {
        return "(SELECT uid.data
                  FROM {user_info_data} uid
                 WHERE uid.userid = {$alias}.id
                   AND uid.fieldid = {$fieldid})";
    }

    /**
     * Get filter source params for one profile field.
     *
     * @param \stdClass $profilefield
     * @return array
     */
    protected static function get_profile_field_source_params(\stdClass $profilefield): array {
        return [
            'field' => (string) $profilefield->shortname,
        ];
    }

    /**
     * Build one custom profile field column config.
     *
     * @param \stdClass $report
     * @param string $field
     * @return array
     */
    protected static function build_profile_field_column(\stdClass $report, string $field): array {
        $reportcolumns = $report->params['columns'] ?? [];
        $alias = self::get_entity_alias($report, 'user');

        if ($alias === '') {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        if (!preg_match('/^field_(\d+)$/', $field, $matches)) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $fieldid = (int) $matches[1];
        $profilefield = self::get_profile_fields()[$fieldid] ?? null;

        if (!$profilefield) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $shortname = clean_param((string) $profilefield->shortname, PARAM_ALPHANUMEXT);
        $expression = self::get_profile_field_expression($alias, $fieldid);
        $type = self::infer_profile_type((string) $profilefield->datatype);
        $key = self::get_custom_key($reportcolumns, 'profile_field', $shortname);
        $config = [
            'name' => trim((string) $profilefield->name) !== '' ? (string) $profilefield->name : (string) $profilefield->shortname,
            'order' => self::get_next_order($reportcolumns),
            'visible' => 1,
            'sortable' => 1,
            'type' => $type,
            'sql' => [
                'column' => $expression,
                'alias' => $key,
                'require' => '',
                'source' => '',
                'where' => '',
            ],
        ];

        $sourceparams = $type === 'menu' ? self::get_profile_field_source_params($profilefield) : [];
        $config['filter'] = self::build_filter_config($type, $sourceparams);

        return [$key, $config, $expression];
    }

    /**
     * Build cohort names column config.
     *
     * @param \stdClass $report
     * @return array
     */
    protected static function build_cohort_names_column(\stdClass $report): array {
        global $DB;

        $reportcolumns = $report->params['columns'] ?? [];
        $alias = self::get_entity_alias($report, 'user');

        if ($alias === '') {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        if (self::$cohortnameexpression === null) {
            self::$cohortnameexpression = $DB->sql_group_concat('c.name', ', ', 'c.name ASC');
        }

        $expression = "(SELECT " . self::$cohortnameexpression . "
                          FROM {cohort_members} cm
                          JOIN {cohort} c ON c.id = cm.cohortid
                         WHERE cm.userid = {$alias}.id)";
        $key = self::get_custom_key($reportcolumns, 'cohort_field', 'names');
        $config = [
            'name' => get_string('cohortnames', 'local_la'),
            'order' => self::get_next_order($reportcolumns),
            'visible' => 1,
            'sortable' => 1,
            'type' => 'text',
            'sql' => [
                'column' => $expression,
                'alias' => $key,
                'require' => '',
                'source' => '',
                'where' => '',
            ],
            'filter' => [
                'type' => 'text',
            ],
        ];

        return [$key, $config, $expression];
    }

    /**
     * Build one custom column config.
     *
     * @param \stdClass $report
     * @param string $entitykey
     * @param string $field
     * @return array
     */
    protected static function build_custom_column(\stdClass $report, string $entitykey, string $field): array {
        if ($entitykey === 'profile_field') {
            return self::build_profile_field_column($report, $field);
        }

        if ($entitykey === 'cohort_field') {
            return self::build_cohort_names_column($report);
        }

        if ($entitykey === 'calculated_field') {
            $reportcolumns = $report->params['columns'] ?? [];
            $config = $reportcolumns[$field] ?? null;

            if (!$config || !empty($config['sql']['table'])) {
                throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
            }

            return [$field, $config, (string) ($config['sql']['column'] ?? '')];
        }

        $reportcolumns = $report->params['columns'] ?? [];
        $entitymap = self::get_entity_map();
        $table = $entitykey;
        $alias = self::get_entity_alias($report, $table);
        $labelkey = $entitymap[$table]['label'] ?? '';

        if ($alias === '' || $table === '') {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $fields = self::get_table_fields($table);
        $metadata = $fields[$field] ?? null;
        $expression = $alias . '.' . $field;
        $type = self::infer_type($field, $metadata);
        $key = self::get_custom_key($reportcolumns, $entitykey, $field);
        $config = [
            'name' => self::format_field_name($field, (string) $labelkey, $metadata),
            'order' => self::get_next_order($reportcolumns),
            'visible' => 1,
            'sortable' => 1,
            'type' => $type,
            'sql' => [
                'table' => $table,
                'column' => $field,
                'require' => '',
                'source' => '',
                'where' => '',
            ],
        ];

        $config['filter'] = self::build_filter_config($type);

        return [$key, $config, $expression];
    }

    /**
     * Supported table mapping.
     *
     * @return array
     */
    protected static function get_entity_map(): array {
        return [
            'user' => [
                'label' => get_string('user'),
                'skipfields' => ['id'],
            ],
            'course' => [
                'label' => get_string('course'),
                'skipfields' => ['id'],
            ],
            'course_modules' => [
                'label' => get_string('activity'),
                'skipfields' => ['id'],
            ],
            'grade_items' => [
                'label' => get_string('gradeitem', 'grades'),
                'skipfields' => ['id'],
            ],
            'enrol' => [
                'label' => 'Enrol',
                'skipfields' => ['id'],
            ],
            'user_enrolments' => [
                'label' => 'User enrolments',
                'skipfields' => ['id'],
            ],
            'user_lastaccess' => [
                'label' => 'Last access',
                'skipfields' => ['id'],
            ],
            'course_completions' => [
                'label' => 'Course completions',
                'skipfields' => ['id'],
            ],
            'grade_grades' => [
                'label' => 'Grades',
                'skipfields' => ['id'],
            ],
            'course_categories' => [
                'label' => 'Categories',
                'skipfields' => ['id'],
            ],
            'modules' => [
                'label' => 'Modules',
                'skipfields' => ['id'],
            ],
            'profile_field' => [
                'label' => get_string('profilefields', 'admin'),
                'skipfields' => [],
            ],
            'cohort_field' => [
                'label' => get_string('cohorts', 'cohort'),
                'skipfields' => [],
            ],
            'calculated_field' => [
                'label' => get_string('calculated', 'local_la'),
                'skipfields' => [],
            ],
        ];
    }

    /**
     * Build sections for one report.
     *
     * @param \stdClass $report
     * @return array
     */
    protected static function get_sections(\stdClass $report): array {
        $sections = [];
        $reportcolumns = $report->params['columns'] ?? [];
        $entitymap = self::get_entity_map();
        $tables = [];

        foreach ($reportcolumns as $column) {
            if (empty($column['enabled'])) {
                continue;
            }

            $table = trim((string) ($column['sql']['table'] ?? ''));

            if ($table !== '' && validator::validate_table($table)) {
                $tables[$table] = $table;
            }
        }

        if (isset($tables['user'])) {
            $fields = [];
            $alias = self::get_entity_alias($report, 'user');

            foreach (self::get_profile_fields() as $profilefield) {
                $fieldkey = 'field_' . (int) $profilefield->id;
                $expression = self::get_profile_field_expression($alias, (int) $profilefield->id);
                $existingkey = self::find_existing_column($reportcolumns, '', '', $expression);
                $existing = $existingkey !== '' ? ($reportcolumns[$existingkey] ?? []) : [];
                $type = $existing['type'] ?? self::infer_profile_type((string) $profilefield->datatype);
                $label = trim((string) $profilefield->name) !== '' ?
                    (string) $profilefield->name :
                    (string) $profilefield->shortname;

                $fields[] = [
                    'name' => (string) $profilefield->shortname,
                    'label' => $label,
                    'comment' => trim((string) ($profilefield->description ?? '')),
                    'entitykey' => 'profile_field',
                    'active' => self::is_column_flag_enabled($existing, 'enabled'),
                    'key' => $existingkey,
                    'fieldkey' => $fieldkey,
                ] + self::get_builder_form_context(
                    'la-builder-' . clean_param('profile_field-' . (string) $profilefield->shortname, PARAM_ALPHANUMEXT),
                    $fieldkey,
                    $type,
                    (string) ($existing['name'] ?? $label),
                    (string) ($existing['formula'] ?? ''),
                    (string) ($existing['condition'] ?? ''),
                    $existingkey !== '',
                    self::is_column_flag_enabled($existing, 'visible'),
                    self::is_column_flag_enabled($existing, 'sortable')
                );
            }

            self::sort_section_fields($fields);

            $sections[] = [
                'id' => 'la-columns-profile_field',
                'name' => (string) $entitymap['profile_field']['label'],
                'count' => count($fields),
                'fields' => $fields,
            ];

            [, , $expression] = self::build_cohort_names_column($report);
            $existingkey = self::find_existing_column($reportcolumns, '', '', $expression);
            $existing = $existingkey !== '' ? ($reportcolumns[$existingkey] ?? []) : [];
            $label = get_string('cohortnames', 'local_la');

            $sections[] = [
                'id' => 'la-columns-cohort_field',
                'name' => (string) $entitymap['cohort_field']['label'],
                'count' => 1,
                'fields' => [[
                    'name' => 'names',
                    'fieldkey' => 'cohort_names',
                    'label' => $label,
                    'comment' => '',
                    'entitykey' => 'cohort_field',
                    'active' => self::is_column_flag_enabled($existing, 'enabled'),
                    'key' => $existingkey,
                ] + self::get_builder_form_context(
                    'la-builder-cohort_field-names',
                    'cohort_names',
                    'text',
                    (string) ($existing['name'] ?? $label),
                    (string) ($existing['formula'] ?? ''),
                    (string) ($existing['condition'] ?? ''),
                    $existingkey !== '',
                    self::is_column_flag_enabled($existing, 'visible'),
                    self::is_column_flag_enabled($existing, 'sortable')
                )],
            ];
        }

        $fields = [];

        foreach ($reportcolumns as $key => $column) {
            if (($column['sql']['table'] ?? '') !== '') {
                continue;
            }

            if (in_array((string) $key, ['user_name', 'course_name', 'activity_name'], true)) {
                continue;
            }

            $label = (string) ($column['name'] ?? $key);

            $fields[] = [
                'name' => (string) $key,
                'fieldkey' => (string) $key,
                'label' => $label,
                'comment' => '',
                'entitykey' => 'calculated_field',
                'active' => self::is_column_flag_enabled($column, 'enabled'),
                'key' => (string) $key,
            ] + self::get_builder_form_context(
                'la-builder-calculated-' . clean_param((string) $key, PARAM_ALPHANUMEXT),
                (string) $key,
                (string) ($column['type'] ?? 'text'),
                $label,
                (string) ($column['formula'] ?? ''),
                (string) ($column['condition'] ?? ''),
                true,
                self::is_column_flag_enabled($column, 'visible'),
                self::is_column_flag_enabled($column, 'sortable')
            );
        }

        if ($fields !== []) {
            self::sort_section_fields($fields);

            $sections[] = [
                'id' => 'la-columns-calculated',
                'name' => (string) $entitymap['calculated_field']['label'],
                'count' => count($fields),
                'fields' => $fields,
            ];
        }

        foreach ($tables as $table) {
            $tablecolumns = self::get_table_fields($table);
            $alias = self::get_entity_alias($report, $table);
            $skipfields = $entitymap[$table]['skipfields'] ?? ['id'];

            if (empty($tablecolumns) || $alias === '') {
                continue;
            }

            $fields = [];

            foreach ($tablecolumns as $name => $column) {
                if (in_array((string) $name, $skipfields, true)) {
                    continue;
                }

                $expression = $alias . '.' . (string) $name;
                $existingkey = self::find_existing_column($reportcolumns, $table, (string) $name, $expression);
                $existing = $existingkey !== '' ? ($reportcolumns[$existingkey] ?? []) : [];
                $type = $existing['type'] ?? self::infer_type((string) $name, $column);
                $label = self::format_field_name((string) $name, $table, $column);

                $fields[] = [
                    'name' => (string) $name,
                    'fieldkey' => (string) $name,
                    'label' => $label,
                    'comment' => trim((string) $column->getComment()),
                    'entitykey' => $table,
                    'active' => self::is_column_flag_enabled($existing, 'enabled'),
                    'key' => $existingkey,
                ] + self::get_builder_form_context(
                    'la-builder-' . clean_param($table . '-' . (string) $name, PARAM_ALPHANUMEXT),
                    clean_param($table . '_' . (string) $name, PARAM_ALPHANUMEXT),
                    $type,
                    (string) ($existing['name'] ?? $label),
                    (string) ($existing['formula'] ?? ''),
                    (string) ($existing['condition'] ?? ''),
                    $existingkey !== '',
                    self::is_column_flag_enabled($existing, 'visible'),
                    self::is_column_flag_enabled($existing, 'sortable')
                );
            }

            self::sort_section_fields($fields);

            $sections[] = [
                'id' => 'la-columns-' . clean_param($table, PARAM_ALPHANUMEXT),
                'name' => (string) ($entitymap[$table]['label'] ?? ucfirst(str_replace('_', ' ', $table))),
                'count' => count($fields),
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $id
     * @return array
     */
    public static function execute(int $id): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        $report = self::get_managed_report_or_fail($params['id']);

        $renderer = $PAGE->get_renderer('local_la');
        $sections = self::get_sections($report);

        return [
            'title' => get_string('columnbank', 'local_la'),
            'size' => 'lg',
            'html' => $renderer->render_from_template('local_la/modal/columns', [
                'reportid' => (int) $report->id,
                'reportname' => format_string((string) $report->name),
                'hassections' => !empty($sections),
                'sections' => $sections,
                'placeholder' => get_string('nocolumnsections', 'local_la'),
            ]),
        ];
    }

    /**
     * Save builder selections from the add-column modal.
     *
     * @param int $id
     * @param array $columns
     * @return array
     */
    public static function save_builder(int $id, array $columns): array {
        global $USER;

        $params = self::validate_parameters(new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'columns' => new \external_multiple_structure(
                new external_single_structure([
                    'entitykey' => new external_value(PARAM_ALPHANUMEXT, 'Entity key'),
                    'field' => new external_value(PARAM_ALPHANUMEXT, 'Field key'),
                    'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                    'name' => new external_value(PARAM_TEXT, 'Column name'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Column type'),
                    'formula' => new external_value(PARAM_RAW_TRIMMED, 'Formula'),
                    'condition' => new external_value(PARAM_RAW_TRIMMED, 'Condition'),
                    'visible' => new external_value(PARAM_BOOL, 'Visible'),
                    'sortable' => new external_value(PARAM_BOOL, 'Sortable'),
                ]),
                'Builder columns',
                VALUE_DEFAULT,
                []
            ),
        ]), [
            'id' => $id,
            'columns' => $columns,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        $report = self::get_managed_report_or_fail($params['id']);

        foreach ($params['columns'] as $item) {
            if (empty($item['entitykey']) || empty($item['field'])) {
                continue;
            }

            if (
                !in_array((string) $item['entitykey'], ['calculated_field', 'profile_field', 'cohort_field'], true) &&
                    !validator::validate_table((string) $item['entitykey'])
            ) {
                continue;
            }

            [$key, $config, $expression] = self::build_custom_column(
                $report,
                (string) $item['entitykey'],
                (string) $item['field']
            );
            $existing = $item['entitykey'] === 'calculated_field' ? $key : self::find_existing_column(
                $report->params['columns'] ?? [],
                (string) ($config['sql']['table'] ?? ''),
                (string) ($config['sql']['column'] ?? ''),
                $expression
            );
            $updates = self::get_editable_column_updates($item, [
                'name' => $config['name'],
                'type' => $config['type'],
            ]);
            $sourceparams = $config['filter']['source_params'] ?? [];
            $filter = self::build_filter_config($updates['type'], $sourceparams);

            if ($existing !== '') {
                $sourceparams = $report->params['columns'][$existing]['filter']['source_params'] ?? $sourceparams;
                $filter = self::build_filter_config($updates['type'], $sourceparams);
            }

            if ($existing !== '') {
                $updates['enabled'] = empty($item['enabled']) ? 0 : 1;
                $report->params['columns'][$existing] = repository::apply_editable_column_updates(
                    $report->params['columns'][$existing],
                    $updates
                );

                if (repository::is_custom_column_key($existing)) {
                    $report->params['columns'][$existing]['filter'] = $filter;
                }
            } else if (!empty($item['enabled'])) {
                $config = repository::apply_editable_column_updates($config, $updates);
                $config['enabled'] = 1;
                $config['filter'] = $filter;
                $report->params['columns'][$key] = $config;
            }
        }

        repository::save_report_params((int) $report->id, (int) $USER->id, $report->params);

        return ['success' => 1];
    }

    /**
     * Get one column settings modal.
     *
     * @param int $id
     * @param string $key
     * @return array
     */
    public static function get_settings(int $id, string $key): array {
        global $PAGE;

        $params = self::validate_parameters(new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Column key'),
        ]), [
            'id' => $id,
            'key' => $key,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        $report = self::get_managed_report_or_fail($params['id']);

        if (empty($report->params['columns'][$params['key']])) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $column = $report->params['columns'][$params['key']];
        $renderer = $PAGE->get_renderer('local_la');
        return [
            'title' => (string) ($column['name'] ?? get_string('edit', 'core')),
            'size' => 'lg',
            'html' => $renderer->render_from_template('local_la/modal/column_settings', [
                'reportid' => (int) $report->id,
                'key' => $params['key'],
            ] + self::get_settings_form_context(
                'la-column-' . clean_param($params['key'], PARAM_ALPHANUMEXT),
                ['key' => $params['key']] + $column
            )),
        ];
    }

    /**
     * Save one column settings form.
     *
     * @param int $id
     * @param string $key
     * @param array $column
     * @return array
     */
    public static function save_settings(
        int $id,
        string $key,
        array $column
    ): array {
        global $USER;

        $params = self::validate_parameters(new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Column key'),
            'column' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                'name' => new external_value(PARAM_TEXT, 'Column name'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Column type'),
                'formula' => new external_value(PARAM_RAW_TRIMMED, 'Formula'),
                'condition' => new external_value(PARAM_RAW_TRIMMED, 'Condition'),
                'visible' => new external_value(PARAM_BOOL, 'Visible'),
                'sortable' => new external_value(PARAM_BOOL, 'Sortable'),
            ]),
        ]), [
            'id' => $id,
            'key' => $key,
            'column' => $column,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        $report = self::get_managed_report_or_fail($params['id']);

        if (empty($report->params['columns'][$params['key']])) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $updates = self::get_editable_column_updates($params['column']) + [
            'enabled' => (int) $params['column']['enabled'],
        ];
        $sourceparams = $report->params['columns'][$params['key']]['filter']['source_params'] ?? [];
        $updates['filter'] = self::build_filter_config($updates['type'], $sourceparams);
        repository::save_column_settings((int) $params['id'], (int) $USER->id, $params['key'], $updates);

        return ['success' => 1];
    }

    /**
     * Save standard columns dropdown preferences.
     *
     * @param int $id
     * @param array $columns
     * @return array
     */
    public static function save_preferences(
        int $id,
        array $columns
    ): array {
        global $USER;

        $params = self::validate_parameters(new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'columns' => new \external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'Column key'),
                    'order' => new external_value(PARAM_INT, 'Column order'),
                    'name' => new external_value(PARAM_TEXT, 'Column name'),
                    'visible' => new external_value(PARAM_BOOL, 'Visible'),
                    'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                ]),
                'Columns',
                VALUE_DEFAULT,
                []
            ),
        ]), [
            'id' => $id,
            'columns' => $columns,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        self::get_report_or_fail($params['id']);

        $columnsnormalized = [];
        $canmanage = helper::is_admin();

        foreach ($params['columns'] as $item) {
            $column = [
                'order' => (int) $item['order'],
                'visible' => (int) $item['visible'],
            ];

            if ($canmanage) {
                $column['name'] = (string) $item['name'];
                $column['enabled'] = (int) $item['enabled'];
            }

            $columnsnormalized[(string) $item['key']] = $column;
        }

        repository::save_report_columns((int) $params['id'], (int) $USER->id, $columnsnormalized);

        return ['success' => 1];
    }

    /**
     * Save default report search column for current user.
     *
     * @param int $id
     * @param string $key
     * @return array
     */
    public static function save_search_default(int $id, string $key): array {
        global $USER;

        $params = self::validate_parameters(self::save_search_default_parameters(), [
            'id' => $id,
            'key' => $key,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        $report = self::get_managed_report_or_fail($params['id']);
        $columns = $report->params['columns'] ?? [];

        if (
            empty($columns[$params['key']]['filter']) ||
                ($columns[$params['key']]['filter']['type'] ?? '') !== 'text'
        ) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        foreach ($columns as $columnkey => $column) {
            if (!empty($column['filter']) && is_array($column['filter'])) {
                $report->params['columns'][$columnkey]['filter']['search'] = false;
            }
        }

        $report->params['columns'][$params['key']]['filter']['search'] = true;
        repository::save_report_params((int) $report->id, (int) $USER->id, $report->params);

        return ['success' => 1];
    }

    /**
     * Parameters for get_settings.
     *
     * @return external_function_parameters
     */
    public static function get_settings_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Column key'),
        ]);
    }

    /**
     * Returns for get_settings.
     *
     * @return external_single_structure
     */
    public static function get_settings_returns(): external_single_structure {
        return self::execute_returns();
    }

    /**
     * Parameters for save_settings.
     *
     * @return external_function_parameters
     */
    public static function save_settings_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Column key'),
            'column' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                'name' => new external_value(PARAM_TEXT, 'Column name'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Column type'),
                'formula' => new external_value(PARAM_RAW_TRIMMED, 'Formula'),
                'condition' => new external_value(PARAM_RAW_TRIMMED, 'Condition'),
                'visible' => new external_value(PARAM_BOOL, 'Visible'),
                'sortable' => new external_value(PARAM_BOOL, 'Sortable'),
            ]),
        ]);
    }

    /**
     * Returns for save_settings.
     *
     * @return external_single_structure
     */
    public static function save_settings_returns(): external_single_structure {
        return self::simple_returns();
    }

    /**
     * Parameters for save_builder.
     *
     * @return external_function_parameters
     */
    public static function save_builder_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'columns' => new \external_multiple_structure(
                new external_single_structure([
                    'entitykey' => new external_value(PARAM_ALPHANUMEXT, 'Entity key'),
                    'field' => new external_value(PARAM_ALPHANUMEXT, 'Field key'),
                    'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                    'name' => new external_value(PARAM_TEXT, 'Column name'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Column type'),
                    'formula' => new external_value(PARAM_RAW_TRIMMED, 'Formula'),
                    'condition' => new external_value(PARAM_RAW_TRIMMED, 'Condition'),
                    'visible' => new external_value(PARAM_BOOL, 'Visible'),
                    'sortable' => new external_value(PARAM_BOOL, 'Sortable'),
                ]),
                'Builder columns',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Parameters for save_preferences.
     *
     * @return external_function_parameters
     */
    public static function save_preferences_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'columns' => new \external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'Column key'),
                    'order' => new external_value(PARAM_INT, 'Column order'),
                    'name' => new external_value(PARAM_TEXT, 'Column name'),
                    'visible' => new external_value(PARAM_BOOL, 'Visible'),
                    'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
                ]),
                'Columns',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Parameters for save_search_default.
     *
     * @return external_function_parameters
     */
    public static function save_search_default_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Report ID'),
            'key' => new external_value(PARAM_ALPHANUMEXT, 'Column key'),
        ]);
    }

    /**
     * Returns for save_preferences.
     *
     * @return external_single_structure
     */
    public static function save_preferences_returns(): external_single_structure {
        return self::simple_returns();
    }

    /**
     * Returns for save_search_default.
     *
     * @return external_single_structure
     */
    public static function save_search_default_returns(): external_single_structure {
        return self::simple_returns();
    }

    /**
     * Returns for save_builder.
     *
     * @return external_single_structure
     */
    public static function save_builder_returns(): external_single_structure {
        return self::simple_returns();
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'size' => new external_value(PARAM_ALPHANUMEXT, 'Modal size'),
            'html' => new external_value(PARAM_RAW, 'Rendered modal html'),
        ]);
    }

    /**
     * Returns for simple write operations.
     *
     * @return external_single_structure
     */
    public static function simple_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
        ]);
    }
}
