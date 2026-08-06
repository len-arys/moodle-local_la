<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_la\local\calendar as calendar_helper;
use local_la\local\helper;

/**
 * Calendar modal API.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar extends external_api {
    /**
     * Shared parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'metric' => new external_value(PARAM_ALPHAEXT, 'Calendar metric', VALUE_DEFAULT, 'timesec'),
            'scope' => new external_value(PARAM_ALPHANUMEXT, 'Calendar scope'),
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
            'activityid' => new external_value(PARAM_INT, 'Activity ID', VALUE_DEFAULT, 0),
            'view' => new external_value(PARAM_ALPHAEXT, 'Calendar view', VALUE_DEFAULT, 'month'),
            'year' => new external_value(PARAM_INT, 'Calendar year', VALUE_DEFAULT, 0),
            'month' => new external_value(PARAM_INT, 'Calendar month', VALUE_DEFAULT, 0),
            'day' => new external_value(PARAM_INT, 'Calendar day', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_ALPHANUMEXT, 'Tracked page name', VALUE_DEFAULT, ''),
            'instanceid' => new external_value(PARAM_INT, 'Tracked page instance ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Generic execute.
     *
     * @param string $metric
     * @param string $scope
     * @param int $userid
     * @param int $courseid
     * @param int $activityid
     * @param string $view
     * @param int $year
     * @param int $month
     * @param int $day
     * @param string $name
     * @param int $instanceid
     * @return array
     */
    public static function execute(
        string $metric = 'timesec',
        string $scope = '',
        int $userid = 0,
        int $courseid = 0,
        int $activityid = 0,
        string $view = 'month',
        int $year = 0,
        int $month = 0,
        int $day = 0,
        string $name = '',
        int $instanceid = 0
    ): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'metric' => $metric,
            'scope' => $scope,
            'userid' => $userid,
            'courseid' => $courseid,
            'activityid' => $activityid,
            'view' => $view,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'name' => $name,
            'instanceid' => $instanceid,
        ]);

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_login();
        if ($params['scope'] !== 'report_page') {
            require_capability('local/la:manage', $systemcontext);
        }

        if (!helper::has_feature('calendar')) {
            throw new \moodle_exception('featureunavailable_calendar', 'local_la');
        }

        $metric = $params['metric'] === 'visits' ? 'visits' : 'timesec';
        $context = calendar_helper::get_modal_context(
            $metric,
            $params['scope'],
            (int) $params['userid'],
            (int) $params['courseid'],
            (int) $params['activityid'],
            (string) $params['view'],
            (int) $params['year'],
            (int) $params['month'],
            (int) $params['day'],
            (string) $params['name'],
            (int) $params['instanceid']
        );

        $renderer = $PAGE->get_renderer('local_la');
        $titlekey = $metric === 'visits' ? 'visits' : ($params['scope'] === 'report_page' ? 'reporttime' : 'learningtime');

        return [
            'title' => get_string($titlekey, 'local_la'),
            'html' => $renderer->render_from_template('local_la/modal/calendar', $context),
        ];
    }

    /**
     * Timesec compatibility wrapper.
     *
     * @param string $scope
     * @param int $userid
     * @param int $courseid
     * @param int $activityid
     * @return array
     */
    public static function execute_timesec(
        string $scope,
        int $userid = 0,
        int $courseid = 0,
        int $activityid = 0
    ): array {
        return self::execute('timesec', $scope, $userid, $courseid, $activityid, 'month', 0, 0, 0);
    }

    /**
     * Timesec compatibility wrapper parameters.
     *
     * Moodle external functions require descriptors matching the registered method name.
     *
     * @return external_function_parameters
     */
    public static function execute_timesec_parameters(): external_function_parameters {
        return self::compatibility_parameters();
    }

    /**
     * Timesec compatibility wrapper return structure.
     *
     * @return external_single_structure
     */
    public static function execute_timesec_returns(): external_single_structure {
        return self::execute_returns();
    }

    /**
     * Visits compatibility wrapper.
     *
     * @param string $scope
     * @param int $userid
     * @param int $courseid
     * @param int $activityid
     * @return array
     */
    public static function execute_visits(
        string $scope,
        int $userid = 0,
        int $courseid = 0,
        int $activityid = 0
    ): array {
        return self::execute('visits', $scope, $userid, $courseid, $activityid, 'month', 0, 0, 0);
    }

    /**
     * Visits compatibility wrapper parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_visits_parameters(): external_function_parameters {
        return self::compatibility_parameters();
    }

    /**
     * Visits compatibility wrapper return structure.
     *
     * @return external_single_structure
     */
    public static function execute_visits_returns(): external_single_structure {
        return self::execute_returns();
    }

    /**
     * Shared parameters for compatibility wrapper methods.
     *
     * @return external_function_parameters
     */
    protected static function compatibility_parameters(): external_function_parameters {
        return new external_function_parameters([
            'scope' => new external_value(PARAM_ALPHANUMEXT, 'Calendar scope'),
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
            'activityid' => new external_value(PARAM_INT, 'Activity ID', VALUE_DEFAULT, 0),
        ]);
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
}
