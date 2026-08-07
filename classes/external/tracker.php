<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_la\local\tracker as tracker_helper;

/**
 * Learning time tracker API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tracker extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'token' => new external_value(PARAM_ALPHANUM, 'Server-issued page tracking token'),
            'trackedseconds' => new external_value(PARAM_INT, 'Tracked seconds to add', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $token
     * @param int $trackedseconds
     * @return array
     */
    public static function execute(
        string $token,
        int $trackedseconds = 0
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'token' => $token,
            'trackedseconds' => $trackedseconds,
        ]);

        self::validate_context(context_system::instance());
        require_login();

        if (isguestuser() || !tracker_helper::is_enabled()) {
            return ['status' => true];
        }

        tracker_helper::track((int) $USER->id, $params['token'], $params['trackedseconds']);

        return ['status' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Request result'),
        ]);
    }
}
