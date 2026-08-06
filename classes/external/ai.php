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
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_la\local\helper;
use local_la\local\installer;
use local_la\local\logger;
use local_la\local\repository;
use local_la\local\url;

/**
 * External AI API.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function generate_report_parameters(): external_function_parameters {
        return new external_function_parameters([
            'prompt' => new external_value(PARAM_RAW_TRIMMED, 'Report request'),
            'reportid' => new external_value(PARAM_INT, 'Example report id', VALUE_DEFAULT, 0),
            'tables' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Moodle table name'),
                'Selected Moodle tables',
                VALUE_DEFAULT,
                [],
            ),
            'tablemode' => new external_value(PARAM_ALPHA, 'How selected tables should be used', VALUE_DEFAULT, 'context'),
        ]);
    }

    /**
     * Generate one report definition using Moodle AI providers.
     *
     * @param string $prompt
     * @return array
     */
    public static function generate_report(string $prompt, int $reportid = 0, array $tables = [], string $tablemode = 'context'): array {
        global $USER;

        $params = self::validate_parameters(self::generate_report_parameters(), [
            'prompt' => $prompt,
            'reportid' => $reportid,
            'tables' => $tables,
            'tablemode' => $tablemode,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('aipromptnoaccess', 'local_la');
        }

        $prompt = trim($params['prompt']);
        if ($prompt === '') {
            throw new \moodle_exception('invalidrequest', 'error');
        }

        if (!helper::supports_ai_providers()) {
            throw new \moodle_exception('erroraimoodleversion', 'local_la');
        }

        $context = self::build_context((int) $params['reportid'], $params['tables'], (string) $params['tablemode']);
        self::add_copy_identity($context);
        $requestid = logger::start_ai(self::get_log_start_details($prompt, $params, $context));

        if (!class_exists(\core_ai\aiactions\generate_text::class)) {
            self::fail($prompt, 'errorainotavailable', self::get_ai_setup_url(), $context, $requestid);
        }

        $manager = \core\di::get(\core_ai\manager::class);
        if (!$manager->is_action_available(\core_ai\aiactions\generate_text::class)) {
            self::fail($prompt, 'errorainotconfigured', self::get_ai_setup_url(), $context, $requestid);
        }

        try {
            $prompttext = self::build_prompt($prompt, $context);
        } catch (\moodle_exception $e) {
            logger::add_ai($requestid, 'prompt_failed', ['message' => $e->getMessage()]);
            throw $e;
        }
        logger::add_ai($requestid, 'request', ['promptchars' => strlen($prompttext)]);

        $action = new \core_ai\aiactions\generate_text(
            contextid: context_system::instance()->id,
            userid: (int) $USER->id,
            prompttext: $prompttext,
        );
        try {
            $response = $manager->process_action($action);
        } catch (\Throwable $e) {
            $message = get_string('erroraiproviderfailed', 'local_la', $e->getMessage());
            logger::add_ai($requestid, 'provider_exception', ['message' => $e->getMessage()]);
            self::save_history($prompt, 'error', $message, '', $context);
            throw new \moodle_exception('erroraiproviderfailed', 'local_la', '', $e->getMessage());
        }

        if (!$response->get_success()) {
            $message = get_string('erroraiproviderfailed', 'local_la', $response->get_errormessage());
            logger::add_ai($requestid, 'provider_failed', ['message' => $response->get_errormessage()]);
            self::save_history($prompt, 'error', $message, '', $context);
            throw new \moodle_exception('erroraiproviderfailed', 'local_la', '', $response->get_errormessage());
        }

        $data = $response->get_response_data();
        logger::add_ai($requestid, 'provider_response', self::get_provider_response_summary($data));
        $definitionjson = self::clean_json((string) ($data['generatedcontent'] ?? ''));
        $definition = json_decode($definitionjson, true);

        if (!is_array($definition)) {
            $message = $definitionjson !== '' ? $definitionjson : get_string('unabletogeneratereport', 'local_la');
            logger::add_ai($requestid, 'text_response', [
                'responsechars' => strlen($message),
                'response' => self::get_log_excerpt($message),
            ]);
            self::save_history($prompt, 'success', $message, '', $context);

            return [
                'title' => '',
                'message' => $message,
                'definition' => '',
                'installable' => false,
                'viewurl' => '',
            ];
        }
        logger::add_ai($requestid, 'definition', self::get_definition_summary($definition));
        $definition = self::prepare_generated_definition($definition, $context);
        logger::add_ai($requestid, 'prepared_definition', self::get_definition_summary($definition));

        try {
            installer::validate_definition($definition);
        } catch (\moodle_exception $e) {
            logger::add_ai($requestid, 'validation_failed', [
                'message' => $e->getMessage(),
                'definition' => self::get_definition_summary($definition),
            ]);
            self::save_history($prompt, 'error', $e->getMessage(), $definitionjson, $context);

            return [
                'title' => '',
                'message' => $e->getMessage(),
                'definition' => '',
                'installable' => false,
                'viewurl' => '',
            ];
        }
        $title = (string) ($definition['name'] ?? get_string('ai', 'local_la'));
        $viewurl = self::get_installed_report_url((string) ($definition['shortname'] ?? ''));
        self::save_history(
            $prompt,
            'success',
            get_string('reportreadyreview', 'local_la'),
            json_encode($definition, JSON_UNESCAPED_SLASHES),
            $context,
        );
        logger::add_ai($requestid, 'success', ['title' => $title]);

        return [
            'title' => $title,
            'message' => get_string('reportreadyreview', 'local_la'),
            'definition' => json_encode($definition, JSON_UNESCAPED_SLASHES),
            'installable' => true,
            'viewurl' => $viewurl,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function generate_report_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Report name'),
            'message' => new external_value(PARAM_RAW, 'Chat response'),
            'definition' => new external_value(PARAM_RAW, 'Generated report JSON'),
            'installable' => new external_value(PARAM_BOOL, 'Whether the response can be installed'),
            'viewurl' => new external_value(PARAM_RAW, 'Installed report URL', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get installed report URL by shortname.
     *
     * @param string $shortname
     * @return string
     */
    protected static function get_installed_report_url(string $shortname): string {
        $shortname = trim($shortname);
        if ($shortname === '') {
            return '';
        }

        $reports = repository::get_all_reports();
        if (empty($reports[$shortname]['id'])) {
            return '';
        }

        return url::report((int) $reports[$shortname]['id']);
    }

    /**
     * Clear history parameters.
     *
     * @return external_function_parameters
     */
    public static function clear_history_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Clear current user's AI chat history.
     *
     * @return array
     */
    public static function clear_history(): array {
        global $DB, $USER;

        self::validate_parameters(self::clear_history_parameters(), []);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('aipromptnoaccess', 'local_la');
        }

        $DB->delete_records('local_la_ai', ['userid' => (int) $USER->id]);

        return ['success' => true];
    }

    /**
     * Clear history returns.
     *
     * @return external_single_structure
     */
    public static function clear_history_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
        ]);
    }

    /**
     * Build the AI prompt.
     *
     * @param string $request
     * @return string
     */
    protected static function build_prompt(string $request, array $context = []): string {
        $prompt = trim((string) get_config('local_la', 'aiprompt'));
        if ($prompt === '') {
            throw new \moodle_exception('errorailicensepromptmissing', 'local_la');
        }

        $prompt .= "\n\n";
        if (!empty($context['report']['definition'])) {
            $copy = $context['copy'] ?? [];
            $prompt .= "Editing mode:\n" .
                "- The selected report below is the source of truth.\n" .
                "- Apply the user request as a modification to this report.\n" .
                "- Do not return the original report unchanged. If the requested edit cannot be made safely, return a short plain-text explanation.\n" .
                "- Return the complete updated report JSON, not a patch or explanation.\n" .
                "- This must be installed as a new AI-generated copy. Do not modify the original installed report.\n" .
                "- Use this exact new report name: " . (string) ($copy['name'] ?? '') . "\n" .
                "- Use this exact new report shortname: " . (string) ($copy['shortname'] ?? '') . "\n\n" .
                "Selected report JSON:\n" .
                json_encode($context['report']['definition'], JSON_UNESCAPED_SLASHES) . "\n\n";
        } else {
            $sample = self::get_sample_report();
            if ($sample !== '') {
                $prompt .= "Reference Lenarys report JSON. Learn this structure and follow the same approach:\n" .
                    $sample . "\n\n";
            }
        }

        if (!empty($context['tables'])) {
            $prompt .= ($context['tablemode'] ?? 'context') === 'only' ?
                "Selected Moodle table schemas. Use only these tables in SQL. Do not use any other Moodle tables:\n" :
                "Selected Moodle table schemas. Prefer these tables when they fit the request, but use other Moodle tables when needed:\n";
            $prompt .=
                json_encode($context['tables'], JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        return $prompt . "User request:\n" . $request;
    }

    /**
     * Reference report JSON used to teach the generator our report shape.
     *
     * @return string
     */
    protected static function get_sample_report(): string {
        $sample = trim((string) get_config('local_la', 'aireportsample'));
        if ($sample === '') {
            return '';
        }

        $report = json_decode($sample, true);
        if (!is_array($report)) {
            return '';
        }

        return json_encode($report, JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Make generated report identifiers Moodle-owned and stable.
     *
     * @param array $definition
     * @param array $context
     * @return array
     */
    protected static function prepare_generated_definition(array $definition, array $context): array {
        $copy = $context['copy'] ?? [];
        if (!empty($copy['name'])) {
            $definition['name'] = (string) $copy['name'];
        } else if (!empty($definition['name'])) {
            $definition['name'] = self::add_ai_name_suffix((string) $definition['name'], self::get_next_report_id());
        }

        $shortname = (string) ($copy['shortname'] ?? '');
        if ($shortname === '') {
            $shortname = self::get_next_ai_shortname();
        }
        $shortname = self::normalise_identifier($shortname, self::get_next_ai_shortname(), 100);

        $definition['shortname'] = $shortname;

        $mainname = self::normalise_identifier('report_' . $shortname, 'report_' . $shortname);
        $renamed = [];

        if (!empty($definition['sql']['name'])) {
            $renamed[(string) $definition['sql']['name']] = $mainname;
        }
        $definition['sql']['name'] = $mainname;

        $dependencies = [];
        $usednames = [$mainname => true];
        foreach (self::normalise_dependencies($definition['sql']['dependencies'] ?? []) as $dependency) {
            if (empty($dependency['name']) || empty($dependency['code'])) {
                continue;
            }

            $oldname = (string) $dependency['name'];
            $basename = self::normalise_identifier($oldname, 'dependency');
            $newname = self::normalise_identifier($mainname . '_' . $basename, $mainname . '_dependency');
            $index = 2;
            while (!empty($usednames[$newname])) {
                $newname = self::normalise_identifier($mainname . '_' . $basename . '_' . $index, $mainname . '_dependency_' . $index);
                $index++;
            }

            $usednames[$newname] = true;
            $renamed[$oldname] = $newname;
            $dependency['name'] = $newname;
            $dependencies[] = $dependency;
        }
        $definition['sql']['dependencies'] = $dependencies;

        if (!empty($renamed) && isset($definition['report_params'])) {
            self::replace_dependency_names($definition['report_params'], $renamed);
        }

        return $definition;
    }

    /**
     * Add stable copy identity for selected report edits.
     *
     * @param array $context
     * @return void
     */
    protected static function add_copy_identity(array &$context): void {
        if (empty($context['report']['definition'])) {
            return;
        }

        $nextid = self::get_next_report_id();
        $name = trim((string) ($context['report']['definition']['name'] ?? ''));
        $shortname = trim((string) ($context['report']['definition']['shortname'] ?? ''));

        $context['copy'] = [
            'name' => self::add_ai_name_suffix($name !== '' ? $name : get_string('report'), $nextid),
            'shortname' => self::normalise_identifier(
                ($shortname !== '' ? $shortname : 'report') . '_' . $nextid,
                'ai_' . $nextid,
                100,
            ),
        ];
    }

    /**
     * Add a visible AI suffix to generated report names.
     *
     * @param string $name
     * @param int $id
     * @return string
     */
    protected static function add_ai_name_suffix(string $name, int $id): string {
        return trim($name) . ' (AI' . $id . ')';
    }

    /**
     * Normalise AI dependency output to the installable array shape.
     *
     * @param mixed $dependencies
     * @return array
     */
    protected static function normalise_dependencies($dependencies): array {
        if (!is_array($dependencies)) {
            return [];
        }

        $normalised = [];
        foreach ($dependencies as $key => $dependency) {
            if (!is_array($dependency)) {
                continue;
            }

            $name = (string) ($dependency['name'] ?? (is_string($key) ? $key : ''));
            $code = (string) ($dependency['code'] ?? $dependency['sql'] ?? '');
            if ($name === '' || $code === '') {
                continue;
            }

            $normalised[] = [
                'name' => $name,
                'code' => $code,
            ];
        }

        return $normalised;
    }

    /**
     * Get the next generated report shortname.
     *
     * @return string
     */
    protected static function get_next_ai_shortname(): string {
        global $DB, $USER;

        $nextid = self::get_next_report_id();
        $index = max(1, $nextid);

        do {
            $shortname = 'ai_' . (int) $USER->id . '_' . $index;
            $index++;
        } while ($DB->record_exists('local_la_report', ['shortname' => $shortname]));

        return $shortname;
    }

    /**
     * Get the next report id.
     *
     * @return int
     */
    protected static function get_next_report_id(): int {
        global $DB;

        return (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) + 1 FROM {local_la_report}');
    }

    /**
     * Normalise one generated identifier.
     *
     * @param string $value
     * @param string $fallback
     * @param int $maxlength
     * @return string
     */
    protected static function normalise_identifier(string $value, string $fallback, int $maxlength = 255): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        if ($value === '') {
            $value = $fallback;
        }

        return substr($value, 0, $maxlength);
    }

    /**
     * Replace dependency names in report params.
     *
     * @param mixed $value
     * @param array $renamed
     * @return void
     */
    protected static function replace_dependency_names(&$value, array $renamed): void {
        if (is_string($value)) {
            if (isset($renamed[$value])) {
                $value = $renamed[$value];
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as &$child) {
            self::replace_dependency_names($child, $renamed);
        }
        unset($child);
    }

    /**
     * Build selected report/table context.
     *
     * @param int $reportid
     * @param array $tables
     * @return array
     */
    protected static function build_context(int $reportid, array $tables, string $tablemode = 'context'): array {
        $context = [];

        if ($report = self::get_report_context($reportid)) {
            $context['report'] = $report;
        }

        $schemas = self::get_table_context($tables);
        if ($schemas) {
            $context['tables'] = $schemas;
            $context['tablemode'] = $tablemode === 'only' ? 'only' : 'context';
        }

        return $context;
    }

    /**
     * Report context for AI examples.
     *
     * @param int $reportid
     * @return array|null
     */
    protected static function get_report_context(int $reportid): ?array {
        global $DB;

        if ($reportid <= 0) {
            return null;
        }

        $report = $DB->get_record('local_la_report', ['id' => $reportid]);
        if (!$report) {
            return null;
        }

        $definition = [
            'name' => (string) $report->name,
            'shortname' => (string) $report->shortname,
            'info' => (string) $report->info,
            'tags' => (string) $report->tags,
            'version' => (string) $report->version,
            'report_params' => json_decode((string) $report->report_params, true) ?: new \stdClass(),
        ];

        if (!empty($report->sql_name)) {
            $sql = $DB->get_record('local_la_sql', ['name' => $report->sql_name], 'name, code, version', IGNORE_MISSING);
            if ($sql) {
                $definition['sql'] = [
                    'name' => (string) $sql->name,
                    'code' => (string) $sql->code,
                    'version' => (string) $sql->version,
                ];
            }
        }

        $dependencynames = \local_la\local\report::get_dependency_names((array) $definition['report_params']);
        if ($dependencynames) {
            [$insql, $queryparams] = $DB->get_in_or_equal($dependencynames, SQL_PARAMS_NAMED);
            $dependencies = $DB->get_records_sql(
                "SELECT name, code, version
                   FROM {local_la_sql}
                  WHERE name {$insql}",
                $queryparams,
            );
            foreach ($dependencies as $dependency) {
                $definition['sql']['dependencies'][] = [
                    'name' => (string) $dependency->name,
                    'code' => (string) $dependency->code,
                    'version' => (string) $dependency->version,
                ];
            }
        }

        return [
            'id' => (int) $report->id,
            'name' => (string) $report->name,
            'definition' => $definition,
        ];
    }

    /**
     * Table schema context.
     *
     * @param array $tables
     * @return array
     */
    protected static function get_table_context(array $tables): array {
        global $DB;

        $schemas = [];
        foreach (array_unique($tables) as $table) {
            $table = trim((string) $table);
            if ($table === '' || !\local_la\local\validator::validate_table($table)) {
                continue;
            }

            try {
                $columns = $DB->get_columns($table);
            } catch (\Throwable $e) {
                continue;
            }

            $schemas[] = [
                'name' => $table,
                'columns' => array_values(array_map(static fn($column) => (string) $column->name, $columns)),
            ];
        }

        return $schemas;
    }

    /**
     * Compact details for the first AI log entry.
     *
     * @param string $prompt
     * @param array $params
     * @param array $context
     * @return array
     */
    protected static function get_log_start_details(string $prompt, array $params, array $context): array {
        return [
            'prompt' => $prompt,
            'reportid' => (int) ($params['reportid'] ?? 0),
            'report' => empty($context['report']) ? '' : [
                'id' => (int) ($context['report']['id'] ?? 0),
                'name' => (string) ($context['report']['name'] ?? ''),
                'shortname' => (string) ($context['report']['definition']['shortname'] ?? ''),
            ],
            'tablemode' => (string) ($context['tablemode'] ?? ''),
            'tables' => array_map(static fn($table): string => (string) ($table['name'] ?? ''), $context['tables'] ?? []),
        ];
    }

    /**
     * Compact provider response metadata.
     *
     * @param array $data
     * @return array
     */
    protected static function get_provider_response_summary(array $data): array {
        $content = (string) ($data['generatedcontent'] ?? '');
        unset($data['generatedcontent']);

        return [
            'generatedcontentchars' => strlen($content),
            'generatedcontentstart' => self::get_log_excerpt($content),
            'metadata' => $data,
        ];
    }

    /**
     * Compact generated report metadata.
     *
     * @param array $definition
     * @return array
     */
    protected static function get_definition_summary(array $definition): array {
        $dependencies = $definition['sql']['dependencies'] ?? [];
        $columns = $definition['report_params']['columns'] ?? [];

        return [
            'name' => (string) ($definition['name'] ?? ''),
            'shortname' => (string) ($definition['shortname'] ?? ''),
            'sqlname' => (string) ($definition['sql']['name'] ?? ''),
            'dependencies' => is_array($dependencies) ? count($dependencies) : 0,
            'columns' => is_array($columns) ? count($columns) : 0,
        ];
    }

    /**
     * Short safe log excerpt.
     *
     * @param string $text
     * @param int $limit
     * @return string
     */
    protected static function get_log_excerpt(string $text, int $limit = 500): string {
        $text = trim($text);
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . '...';
    }

    /**
     * Remove common wrapper text from provider output.
     *
     * @param string $json
     * @return string
     */
    protected static function clean_json(string $json): string {
        $json = trim($json);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $matches)) {
            $json = trim($matches[1]);
        }

        if ($json === '' || $json[0] === '{') {
            return $json;
        }

        return self::extract_json_object($json);
    }

    /**
     * Extract the first balanced JSON object from provider prose.
     *
     * @param string $text
     * @return string
     */
    protected static function extract_json_object(string $text): string {
        $start = strpos($text, '{');
        if ($start === false) {
            return trim($text);
        }

        $depth = 0;
        $instring = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($instring) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $instring = false;
                }
                continue;
            }

            if ($char === '"') {
                $instring = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return trim($text);
    }

    /**
     * Stop generation with one saved user-facing message.
     *
     * @param string $prompt
     * @param string $identifier
     * @param string $a
     * @return never
     */
    protected static function fail(string $prompt, string $identifier, string $a, array $context = [], string $requestid = ''): never {
        $message = get_string($identifier, 'local_la', $a);
        logger::add_ai($requestid, 'failed', [
            'identifier' => $identifier,
            'message' => $message,
        ]);
        self::save_history($prompt, 'error', $message, '', $context);
        throw new \moodle_exception($identifier, 'local_la', '', $a);
    }

    /**
     * AI provider setup URL.
     *
     * @return string
     */
    protected static function get_ai_setup_url(): string {
        return (new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(false);
    }

    /**
     * Save one AI chat history item.
     *
     * @param string $prompt
     * @param string $status
     * @param string $response
     * @param string $definition
     * @param array $context
     * @return void
     */
    protected static function save_history(
        string $prompt,
        string $status,
        string $response,
        string $definition = '',
        array $context = [],
    ): void {
        global $DB, $USER;

        $DB->insert_record('local_la_ai', (object) [
            'userid' => (int) $USER->id,
            'prompt' => $prompt,
            'response' => $response,
            'definition' => $definition,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '',
            'status' => $status,
            'timecreated' => time(),
        ]);
    }
}
