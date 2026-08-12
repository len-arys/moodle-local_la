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

/**
 * Report and app installer.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class installer {
    /** @var string[] Supported app widget types. */
    protected const APP_WIDGET_TYPES = ['metric', 'duration', 'donut', 'totals'];

    /**
     * Install one report from the active report source.
     *
     * @param string $shortname
     * @return int
     */
    public static function install_report(string $shortname): int {
        $definition = api::get_report_definition($shortname);

        if (empty($definition)) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $reportid = self::install_definition($definition);
        api::report_installed((string) ($definition['shortname'] ?? $shortname));

        return $reportid;
    }

    /**
     * Update one installed report from the active report source.
     *
     * @param string $shortname
     * @return int
     */
    public static function update_report(string $shortname): int {
        $definition = api::get_report_definition($shortname);

        if (empty($definition)) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        return self::update_definition($definition);
    }

    /**
     * Install one app from the active app source.
     *
     * @param string $shortname
     * @return int
     */
    public static function install_app(string $shortname): int {
        $definition = api::get_app_definition($shortname);

        if (empty($definition)) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        $appid = self::install_app_definition($definition);
        api::app_installed((string) ($definition['shortname'] ?? $shortname));

        return $appid;
    }

    /**
     * Update one installed app from the active app source.
     *
     * @param string $shortname
     * @return int
     */
    public static function update_app(string $shortname): int {
        $definition = api::get_app_definition($shortname);

        if (empty($definition)) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        return self::update_app_definition($definition);
    }

    /**
     * Require access to a definition plan.
     *
     * @param array $definition
     * @return void
     */
    protected static function require_definition_plan(array $definition): void {
        $plan = (string) $definition['plan'];

        if (!helper::has_plan($plan)) {
            throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label($plan));
        }
    }

    /**
     * Uninstall one report package and remove all related records.
     *
     * @param int $reportid
     * @return void
     */
    public static function uninstall_report(int $reportid): void {
        global $DB;

        $report = $DB->get_record('local_la_report', ['id' => $reportid], '*', IGNORE_MISSING);

        if (!$report) {
            return;
        }

        $dependencynames = self::get_report_dependency_names((string) ($report->report_params ?? ''));
        $mainsqlname = trim((string) ($report->sql_name ?? ''));

        $transaction = $DB->start_delegated_transaction();

        $DB->delete_records('local_la_report_schedule', ['reportid' => $reportid]);
        $DB->delete_records('local_la_report_audience', ['reportid' => $reportid]);
        $DB->delete_records('local_la_report_users', ['reportid' => $reportid]);
        $DB->delete_records('local_la_report', ['id' => $reportid]);

        if ($mainsqlname !== '' && !self::is_sql_name_in_use($mainsqlname)) {
            $DB->delete_records('local_la_sql', ['name' => $mainsqlname]);
        }

        foreach ($dependencynames as $dependencyname) {
            if (!self::is_sql_name_in_use($dependencyname)) {
                $DB->delete_records('local_la_sql', ['name' => $dependencyname]);
            }
        }

        $transaction->allow_commit();
    }

    /**
     * Uninstall one app.
     *
     * @param int $appid
     * @return void
     */
    public static function uninstall_app(int $appid): void {
        global $DB;

        if ($appid <= 0) {
            return;
        }

        $DB->delete_records('local_la_app', ['id' => $appid]);
    }

    /**
     * Install or update one report definition in the database.
     *
     * @param array $definition
     * @return int
     */
    public static function install_definition(array $definition): int {
        global $DB;

        self::validate_definition($definition);
        self::require_definition_plan($definition);

        $now = time();
        $version = (string) ($definition['version'] ?? '1.0.0');
        $transaction = $DB->start_delegated_transaction();

        self::upsert_definition_sql($definition, $now, $version);
        $reportid = self::upsert_report_record($definition, $now, $version);
        $transaction->allow_commit();

        return $reportid;
    }

    /**
     * Update an installed report definition without changing local ownership data.
     *
     * @param array $definition
     * @return int
     */
    public static function update_definition(array $definition): int {
        global $DB;

        self::validate_definition($definition);
        self::require_definition_plan($definition);

        $existing = $DB->get_record('local_la_report', [
            'shortname' => (string) $definition['shortname'],
        ], '*', MUST_EXIST);

        $now = time();
        $version = (string) ($definition['version'] ?? '1.0.0');
        $transaction = $DB->start_delegated_transaction();

        self::upsert_definition_sql($definition, $now, $version);
        self::update_report_record($existing, $definition, $now, $version);

        $transaction->allow_commit();

        return (int) $existing->id;
    }

    /**
     * Install or update one app definition in the database.
     *
     * @param array $definition
     * @return int
     */
    public static function install_app_definition(array $definition): int {
        global $DB;

        self::validate_app_definition($definition);
        self::require_definition_plan($definition);

        $now = time();
        $version = (string) ($definition['version'] ?? '1.0.0');
        $shortname = (string) $definition['shortname'];
        $definitionjson = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($definitionjson === false) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        $existing = $DB->get_record('local_la_app', ['shortname' => $shortname], '*', IGNORE_MISSING);
        $record = (object) [
            'name' => (string) $definition['name'],
            'shortname' => $shortname,
            'info' => (string) ($definition['info'] ?? ''),
            'definition' => $definitionjson,
            'version' => $version,
            'plan' => (string) $definition['plan'],
            'status' => 1,
            'timemodified' => $now,
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_la_app', $record);
            return (int) $existing->id;
        }

        $record->timecreated = $now;
        return (int) $DB->insert_record('local_la_app', $record);
    }

    /**
     * Update an installed app definition without changing local status or widget state.
     *
     * @param array $definition
     * @return int
     */
    public static function update_app_definition(array $definition): int {
        global $DB;

        self::validate_app_definition($definition);
        self::require_definition_plan($definition);

        $existing = $DB->get_record('local_la_app', [
            'shortname' => (string) $definition['shortname'],
        ], '*', MUST_EXIST);

        $definition = self::merge_app_widget_state($definition, (string) ($existing->definition ?? ''));
        $definitionjson = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($definitionjson === false) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        $DB->update_record('local_la_app', (object) [
            'id' => $existing->id,
            'info' => (string) ($definition['info'] ?? ''),
            'definition' => $definitionjson,
            'version' => (string) ($definition['version'] ?? '1.0.0'),
            'plan' => (string) $definition['plan'],
            'timemodified' => time(),
        ]);

        return (int) $existing->id;
    }

    /**
     * Validate one report definition.
     *
     * @param array $definition
     * @return void
     */
    public static function validate_definition(array $definition): void {
        if (
            empty($definition['name']) || empty($definition['shortname']) || empty($definition['plan']) ||
                empty($definition['sql']['name']) || empty($definition['sql']['code'])
        ) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        self::validate_main_sql_template((string) $definition['sql']['code']);
        self::validate_definition_sql($definition);
        self::validate_column_sql($definition);
    }

    /**
     * Validate one app definition.
     *
     * @param array $definition
     * @return void
     */
    public static function validate_app_definition(array $definition): void {
        if (
            empty($definition['name']) || empty($definition['shortname']) || empty($definition['plan']) ||
                empty($definition['widgets']) ||
                !is_array($definition['widgets'])
        ) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        foreach ($definition['widgets'] as $widget) {
            if (
                !is_array($widget) || empty($widget['key']) || empty($widget['type']) || empty($widget['title']) ||
                    empty($widget['sql'])
            ) {
                throw new \moodle_exception('errorinvalidappconfig', 'local_la');
            }

            if (!in_array((string) $widget['type'], self::APP_WIDGET_TYPES, true)) {
                throw new \moodle_exception('errorinvalidappconfig', 'local_la');
            }

            foreach (['sql', 'segments_sql'] as $field) {
                $sql = trim((string) ($widget[$field] ?? ''));
                if ($sql !== '' && !validator::passes($sql)) {
                    throw new \moodle_exception('errorsqlvalidationfailed', 'local_la', '', (string) $widget['key']);
                }
            }
        }
    }

    /**
     * Validate the main report SQL has the placeholders required by the report engine.
     *
     * @param string $sql
     * @return void
     */
    protected static function validate_main_sql_template(string $sql): void {
        foreach (['SQL_COLUMNS', 'SQL_JOIN', 'SQL_WHERE'] as $placeholder) {
            if (strpos($sql, $placeholder) === false) {
                throw new \moodle_exception('errorinvalidreportsqltemplate', 'local_la', '', $placeholder);
            }
        }

        $joinposition = strpos($sql, 'SQL_JOIN');
        $whereposition = stripos($sql, 'WHERE');
        $whereplaceholderposition = strpos($sql, 'SQL_WHERE');

        if ($whereposition === false || $joinposition > $whereposition || $whereplaceholderposition < $whereposition) {
            throw new \moodle_exception('errorinvalidreportsqltemplateorder', 'local_la');
        }
    }

    /**
     * Validate all SQL snippets before install.
     *
     * @param array $definition
     * @return void
     */
    protected static function validate_definition_sql(array $definition): void {
        $dependencynames = array_filter(array_map(static fn($dependency): string =>
            (string) ($dependency['name'] ?? ''), $definition['sql']['dependencies'] ?? []));
        $missingdependencies = array_diff(report::get_dependency_names($definition['report_params'] ?? []), $dependencynames);

        if (!empty($missingdependencies)) {
            throw new \moodle_exception(
                'errorinvalidreportdependency',
                'local_la',
                '',
                reset($missingdependencies),
            );
        }

        $mainsql = (string) ($definition['sql']['code'] ?? '');
        if ($mainsql !== '' && !validator::passes($mainsql)) {
            throw new \moodle_exception(
                'errorsqlvalidationfailed',
                'local_la',
                '',
                (string) ($definition['sql']['name'] ?? '')
            );
        }

        foreach (($definition['sql']['dependencies'] ?? []) as $dependency) {
            $code = (string) ($dependency['code'] ?? '');
            if ($code !== '' && !validator::passes_fragment($code)) {
                throw new \moodle_exception(
                    'errorsqlvalidationfailed',
                    'local_la',
                    '',
                    (string) ($dependency['name'] ?? '')
                );
            }
        }
    }

    /**
     * Validate report column SQL expressions.
     *
     * @param array $definition
     * @return void
     */
    protected static function validate_column_sql(array $definition): void {
        foreach (($definition['report_params']['columns'] ?? []) as $key => $column) {
            $sql = $column['sql'] ?? [];
            $expression = (string) ($sql['column'] ?? '');
            $source = (string) ($sql['source'] ?? '');

            if ($source === 'join' && preg_match('/\b(?:AVG|SUM|COUNT|MIN|MAX)\s*\(/i', $expression)) {
                throw new \moodle_exception('errorinvalidreportaggregatecolumn', 'local_la', '', $key);
            }
        }
    }

    /**
     * Upsert one SQL template/dependency record.
     *
     * @param array $record
     * @return void
     */
    protected static function upsert_sql_record(array $record): void {
        global $DB;

        $existing = $DB->get_record('local_la_sql', ['name' => $record['name']], '*', IGNORE_MISSING);

        if ($existing) {
            $DB->update_record('local_la_sql', (object) [
                'id' => $existing->id,
                'code' => $record['code'],
                'status' => 1,
                'version' => $record['version'],
                'timeactivated' => $record['timeactivated'],
                'timemodified' => $record['timemodified'],
            ]);
            return;
        }

        $DB->insert_record('local_la_sql', (object) [
            'name' => $record['name'],
            'code' => $record['code'],
            'status' => 1,
            'version' => $record['version'],
            'timeactivated' => $record['timeactivated'],
            'timecreated' => $record['timemodified'],
            'timemodified' => $record['timemodified'],
        ]);
    }

    /**
     * Upsert all SQL snippets from one report definition.
     *
     * @param array $definition
     * @param int $now
     * @param string $version
     * @return void
     */
    protected static function upsert_definition_sql(array $definition, int $now, string $version): void {
        foreach (($definition['sql']['dependencies'] ?? []) as $dependency) {
            if (empty($dependency['name']) || empty($dependency['code'])) {
                continue;
            }

            self::upsert_sql_record([
                'name' => (string) $dependency['name'],
                'code' => (string) $dependency['code'],
                'version' => $version,
                'timeactivated' => $now,
                'timemodified' => $now,
            ]);
        }

        self::upsert_sql_record([
            'name' => (string) $definition['sql']['name'],
            'code' => (string) $definition['sql']['code'],
            'version' => $version,
            'timeactivated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Upsert one report record.
     *
     * @param array $definition
     * @param int $now
     * @param string $version
     * @return int
     */
    protected static function upsert_report_record(array $definition, int $now, string $version): int {
        global $DB;

        $existing = $DB->get_record('local_la_report', [
            'shortname' => (string) $definition['shortname'],
        ], '*', IGNORE_MISSING);

        $data = [
            'name' => (string) $definition['name'],
            'shortname' => (string) $definition['shortname'],
            'info' => (string) ($definition['info'] ?? ''),
            'tags' => (string) ($definition['tags'] ?? ''),
            'report_params' => json_encode($definition['report_params'] ?? []),
            'version' => $version,
            'plan' => (string) $definition['plan'],
            'sql_name' => (string) $definition['sql']['name'],
            'timesync' => $now,
            'timemodified' => $now,
        ];

        if ($existing) {
            $DB->update_record('local_la_report', (object) (['id' => $existing->id] + $data));
            return (int) $existing->id;
        }

        return (int) $DB->insert_record('local_la_report', (object) ($data + [
            'timecreated' => $now,
        ]));
    }

    /**
     * Update marketplace-controlled report fields only.
     *
     * @param \stdClass $existing
     * @param array $definition
     * @param int $now
     * @param string $version
     * @return void
     */
    protected static function update_report_record(\stdClass $existing, array $definition, int $now, string $version): void {
        global $DB;

        $reportparams = json_encode($definition['report_params'] ?? []);
        if ($reportparams === false) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $DB->update_record('local_la_report', (object) [
            'id' => $existing->id,
            'info' => (string) ($definition['info'] ?? ''),
            'report_params' => $reportparams,
            'version' => $version,
            'plan' => (string) $definition['plan'],
            'sql_name' => (string) $definition['sql']['name'],
            'timesync' => $now,
            'timemodified' => $now,
        ]);

        $DB->set_field('local_la_report_users', 'user_params', $reportparams, [
            'reportid' => (int) $existing->id,
        ]);
        $DB->set_field('local_la_report_users', 'timemodified', $now, [
            'reportid' => (int) $existing->id,
        ]);
    }

    /**
     * Preserve local widget UI state while replacing marketplace app content.
     *
     * @param array $definition
     * @param string $existingjson
     * @return array
     */
    protected static function merge_app_widget_state(array $definition, string $existingjson): array {
        $existingdefinition = json_decode($existingjson, true);
        if (
            !is_array($existingdefinition) || empty($existingdefinition['widgets']) ||
                !is_array($existingdefinition['widgets'])
        ) {
            return $definition;
        }

        $statebykey = [];
        foreach ($existingdefinition['widgets'] as $widget) {
            if (!is_array($widget) || empty($widget['key'])) {
                continue;
            }

            $statebykey[(string) $widget['key']] = [
                'active' => !empty($widget['active']),
                'fullwidth' => !empty($widget['fullwidth']),
                'autorefresh' => !empty($widget['autorefresh']),
            ];
        }

        foreach ($definition['widgets'] as &$widget) {
            if (!is_array($widget) || empty($widget['key']) || empty($statebykey[(string) $widget['key']])) {
                continue;
            }

            foreach ($statebykey[(string) $widget['key']] as $field => $value) {
                $widget[$field] = $value;
            }
        }
        unset($widget);

        return $definition;
    }

    /**
     * Check whether one SQL record is still referenced by installed reports.
     *
     * @param string $sqlname
     * @return bool
     */
    protected static function is_sql_name_in_use(string $sqlname): bool {
        global $DB;

        if ($sqlname === '') {
            return false;
        }

        if ($DB->record_exists('local_la_report', ['sql_name' => $sqlname])) {
            return true;
        }

        $reports = $DB->get_records_select('local_la_report', 'report_params IS NOT NULL', [], '', 'id,report_params');

        foreach ($reports as $report) {
            if (in_array($sqlname, self::get_report_dependency_names((string) $report->report_params), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get report SQL dependency names from report params JSON.
     *
     * @param string $reportparamsjson
     * @return array
     */
    protected static function get_report_dependency_names(string $reportparamsjson): array {
        $params = report::decode_params($reportparamsjson);

        return report::get_dependency_names($params);
    }
}
