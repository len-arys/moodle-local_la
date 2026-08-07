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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_la\local\audience as audience_helper;
use local_la\local\helper;
use local_la\local\logger;

/**
 * Report audience external API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience extends external_api {
    public static function save_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report ID'),
            'type' => new external_value(PARAM_ALPHA, 'Audience type'),
            'instanceids' => new \core_external\external_multiple_structure(
                new external_value(PARAM_INT, 'Audience instance ID'),
                'Audience instance IDs',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function save(int $reportid, string $type, array $instanceids = []): array {
        $params = self::validate_parameters(self::save_parameters(), [
            'reportid' => $reportid,
            'type' => $type,
            'instanceids' => $instanceids,
        ]);

        self::validate_context(context_system::instance());
        require_login();
        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        audience_helper::save((int) $params['reportid'], (string) $params['type'], $params['instanceids']);
        logger::add('save_report_audience', 'report', (int) $params['reportid'], [
            'type' => (string) $params['type'],
            'instanceids' => array_values(array_map('intval', $params['instanceids'])),
        ]);

        return ['success' => true];
    }

    public static function delete_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report ID'),
            'type' => new external_value(PARAM_ALPHA, 'Audience type'),
        ]);
    }

    public static function delete(int $reportid, string $type): array {
        $params = self::validate_parameters(self::delete_parameters(), [
            'reportid' => $reportid,
            'type' => $type,
        ]);

        self::validate_context(context_system::instance());
        require_login();
        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        audience_helper::delete((int) $params['reportid'], (string) $params['type']);
        logger::add('delete_report_audience', 'report', (int) $params['reportid'], [
            'type' => (string) $params['type'],
        ]);

        return ['success' => true];
    }

    public static function delete_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
        ]);
    }

    public static function save_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
        ]);
    }
}
