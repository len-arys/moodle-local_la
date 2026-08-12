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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_la\local\api;
use local_la\local\helper;
use local_la\local\installer;
use local_la\local\logger;
use local_la\local\repository;

/**
 * Guided agent external API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent extends external_api {
    /**
     * Require the current user can run guided setup.
     *
     * @return void
     */
    protected static function require_setup_access(): void {
        global $USER;

        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_billing_admin((int) $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
    }

    /**
     * Empty parameters.
     *
     * @return external_function_parameters
     */
    public static function empty_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Completion parameters.
     *
     * @return external_function_parameters
     */
    public static function complete_parameters(): external_function_parameters {
        return self::empty_parameters();
    }

    /**
     * Setup check parameters.
     *
     * @return external_function_parameters
     */
    public static function check_setup_parameters(): external_function_parameters {
        return self::empty_parameters();
    }

    /**
     * Report list parameters.
     *
     * @return external_function_parameters
     */
    public static function get_reports_parameters(): external_function_parameters {
        return self::empty_parameters();
    }

    /**
     * Mark the first-run agent as completed for the current user.
     *
     * @return array
     */
    public static function complete(): array {
        self::require_setup_access();

        set_user_preference('local_la_agent_done', 1);

        return ['success' => true];
    }

    /**
     * Check API or local license setup.
     *
     * @return array
     */
    public static function check_setup(): array {
        self::require_setup_access();

        $license = helper::is_api_mode() ? api::check_license() : api::get_local_license();
        $error = (string) ($license['error'] ?? '');

        return [
            'success' => $error === '',
            'source' => helper::is_api_mode() ?
                get_string('pluginapi_auto', 'local_la') :
                get_string('pluginapi_manual', 'local_la'),
            'status' => (string) ($license['status'] ?? ''),
            'plan' => (string) ($license['planlabel'] ?? ''),
            'message' => $error === '' ? '' : get_string('agentlicensefailed', 'local_la'),
            'details' => helper::is_debug_enabled() ? $error : '',
        ];
    }

    /**
     * Get available marketplace reports for guided setup.
     *
     * @return array
     */
    public static function get_reports(): array {
        self::require_setup_access();

        $installed = repository::get_all_reports();
        $reports = [];

        foreach (api::get_marketplace_reports() as $report) {
            $shortname = (string) ($report['shortname'] ?? '');
            if ($shortname === '') {
                continue;
            }

            $reports[] = [
                'shortname' => $shortname,
                'name' => (string) ($report['name'] ?? $shortname),
                'info' => (string) ($report['info'] ?? ''),
                'installed' => isset($installed[$shortname]),
            ];
        }

        return ['reports' => $reports];
    }

    /**
     * Install one guided setup report parameters.
     *
     * @return external_function_parameters
     */
    public static function install_report_parameters(): external_function_parameters {
        return new external_function_parameters([
            'shortname' => new external_value(PARAM_ALPHANUMEXT, 'Report shortname'),
        ]);
    }

    /**
     * Install one marketplace report.
     *
     * @param string $shortname
     * @return array
     */
    public static function install_report(string $shortname): array {
        global $USER;

        $params = self::validate_parameters(self::install_report_parameters(), [
            'shortname' => $shortname,
        ]);
        self::require_setup_access();

        $reportid = installer::install_report((string) $params['shortname']);
        repository::add_report($reportid, (int) $USER->id);
        logger::add('agent_install_report', 'report', $reportid, [
            'reportkey' => (string) $params['shortname'],
        ]);

        return [
            'success' => true,
            'reportid' => $reportid,
        ];
    }

    /**
     * Completion returns.
     *
     * @return external_single_structure
     */
    public static function complete_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Completion result'),
        ]);
    }

    /**
     * Setup check returns.
     *
     * @return external_single_structure
     */
    public static function check_setup_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Setup result'),
            'source' => new external_value(PARAM_TEXT, 'License source'),
            'status' => new external_value(PARAM_TEXT, 'License status', VALUE_DEFAULT, ''),
            'plan' => new external_value(PARAM_TEXT, 'Current plan', VALUE_DEFAULT, ''),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_DEFAULT, ''),
            'details' => new external_value(PARAM_TEXT, 'Technical error details', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Report list returns.
     *
     * @return external_single_structure
     */
    public static function get_reports_returns(): external_single_structure {
        return new external_single_structure([
            'reports' => new external_multiple_structure(new external_single_structure([
                'shortname' => new external_value(PARAM_ALPHANUMEXT, 'Report shortname'),
                'name' => new external_value(PARAM_TEXT, 'Report name'),
                'info' => new external_value(PARAM_TEXT, 'Report description', VALUE_DEFAULT, ''),
                'installed' => new external_value(PARAM_BOOL, 'Already installed'),
            ])),
        ]);
    }

    /**
     * Install report returns.
     *
     * @return external_single_structure
     */
    public static function install_report_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Install result'),
            'reportid' => new external_value(PARAM_INT, 'Installed report id'),
        ]);
    }
}
