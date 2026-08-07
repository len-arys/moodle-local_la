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
use local_la\local\audience;
use local_la\local\analysis as analyzer;
use local_la\local\helper;

/**
 * Analyze selected report rows.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analysis extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'action' => new external_value(PARAM_ALPHA, 'Analysis action'),
            'rowsjson' => new external_value(PARAM_RAW, 'Selected rows JSON'),
        ]);
    }

    /**
     * Analyze rows and render HTML.
     *
     * @param int $reportid
     * @param string $action
     * @param string $rowsjson
     * @return array
     */
    public static function execute(int $reportid, string $action, string $rowsjson): array {
        global $OUTPUT;

        $params = self::validate_parameters(self::execute_parameters(), [
            'reportid' => $reportid,
            'action' => $action,
            'rowsjson' => $rowsjson,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!audience::has_access((int) $params['reportid'])) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if ($params['action'] === 'summary' && !helper::has_feature('insights')) {
            throw new \moodle_exception('featureunavailable_insights', 'local_la');
        }

        if ($params['action'] === 'patterns' && !helper::has_feature('patterns')) {
            throw new \moodle_exception('featureunavailable_patterns', 'local_la');
        }

        $rows = json_decode((string) $params['rowsjson'], true);
        if (!is_array($rows) || empty($rows)) {
            throw new \invalid_parameter_exception('No selected rows');
        }

        $rows = array_slice(array_filter($rows, 'is_array'), 0, 100);
        if (empty($rows)) {
            throw new \invalid_parameter_exception('No selected rows');
        }

        if ($params['action'] === 'summary') {
            return [
                'title' => get_string('summarizeinsights', 'local_la'),
                'html' => $OUTPUT->render_from_template('local_la/modal/row_summary', analyzer::summary_context($rows)),
            ];
        }

        if ($params['action'] === 'patterns') {
            return [
                'title' => get_string('findpatterns', 'local_la'),
                'html' => $OUTPUT->render_from_template('local_la/modal/row_patterns', analyzer::patterns_context($rows)),
            ];
        }

        throw new \invalid_parameter_exception('Unknown analysis action');
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Rendered analysis HTML'),
        ]);
    }
}
