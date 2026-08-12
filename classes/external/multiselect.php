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
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_la\local\audience;
use local_la\local\repository;

/**
 * External multiselect option loader.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multiselect extends external_api {
    /**
     * Shared parameters for lazy filter loading.
     *
     * @return external_function_parameters
     */
    protected static function get_options_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
        ]);
    }

    /**
     * Get course filter options parameters.
     *
     * @return external_function_parameters
     */
    public static function get_courses_parameters(): external_function_parameters {
        return self::get_options_parameters();
    }

    /**
     * Get course options for one report.
     *
     * @param int $reportid
     * @return array
     */
    public static function get_courses(int $reportid): array {
        $params = self::validate_parameters(self::get_options_parameters(), [
            'reportid' => $reportid,
        ]);
        self::validate_context(context_system::instance());
        require_login();
        if (!audience::has_access((int) $params['reportid'])) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $groups = [];

        foreach (repository::get_filter_courses() as $item) {
            $label = trim((string) ($item['category'] ?? ''));

            if ($label === '') {
                $label = get_string('uncategorised', 'core');
            }

            if (!array_key_exists($label, $groups)) {
                $groups[$label] = [
                    'label' => $label,
                    'options' => [],
                ];
            }

            $groups[$label]['options'][] = [
                'value' => (string) ($item['value'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
            ];
        }

        return [
            'groups' => array_values($groups),
        ];
    }

    /**
     * Options return definition.
     *
     * @return external_single_structure
     */
    public static function get_courses_returns(): external_single_structure {
        return new external_single_structure([
            'groups' => new external_multiple_structure(
                new external_single_structure([
                    'label' => new external_value(PARAM_TEXT, 'Course category'),
                    'options' => new external_multiple_structure(
                        new external_single_structure([
                            'value' => new external_value(PARAM_TEXT, 'Course id'),
                            'name' => new external_value(PARAM_TEXT, 'Course name'),
                        ])
                    ),
                ])
            ),
        ]);
    }
}
