<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_la\local\audience;
use local_la\local\filters;
use local_la\local\helper;
use local_la\local\repository;
use local_la\local\table as table_helper;
use local_la\local\url;

/**
 * Grade modal API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'userid' => new external_value(PARAM_INT, 'User ID'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function execute(int $courseid, int $userid): array {
        global $DB, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $params['userid'], 'deleted' => 0], '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        self::validate_context($context);

        require_login($course);
        require_capability('gradereport/singleview:view', $context);
        require_capability('moodle/grade:viewall', $context);
        require_capability('moodle/grade:edit', $context);

        if (!helper::can_use_drilldown()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if (!helper::has_feature('drilldown')) {
            throw new \moodle_exception('featureunavailable_drilldown', 'local_la');
        }

        $grade = table_helper::grade_singleview($course, $user, $context);

        $renderer = $PAGE->get_renderer('local_la');

        return [
            'title' => get_string('grades'),
            'html' => $renderer->render_from_template('local_la/modal/grade', [
                'summary' => [
                    'primary' => fullname($user),
                    'secondary' => format_string((string) $course->fullname),
                ],
                'grade' => $grade,
                'buttons' => array_values(array_filter([
                    self::get_report_button('grade_details', 'Grade details', $course->id, $user->id),
                    self::get_report_button('grade_history', 'Grade history', $course->id, $user->id),
                ])),
            ]),
        ];
    }

    /**
     * Build one internal report button for the grade modal.
     *
     * @param string $shortname
     * @param string $label
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    protected static function get_report_button(string $shortname, string $label, int $courseid, int $userid): array {
        $report = repository::get_report($shortname);

        if (!$report || empty($report->id) || !audience::has_access((int) $report->id)) {
            return [];
        }

        $params = filters::get_params([
            'user_id' => [
                'operator' => 'equal',
                'value' => [$userid],
            ],
            'course_id' => [
                'operator' => 'equal',
                'value' => [$courseid],
            ],
        ], ['tab' => 'view'], true);

        return [
            'label' => $label,
            'url' => url::report_tab((int) $report->id, $params),
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
}
