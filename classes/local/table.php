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
 * Table helper.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class table {
    /**
     * Build Moodle singleview grade table for one user in one course.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param \context_course $context
     * @return array
     */
    public static function grade_singleview(\stdClass $course, \stdClass $user, \context_course $context): array {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/grade/report/lib.php');
        require_once($CFG->dirroot . '/grade/report/singleview/lib.php');

        grade_regrade_final_grades_if_required($course);

        $gpr = new \grade_plugin_return([
            'type' => 'report',
            'plugin' => 'singleview',
            'courseid' => $course->id,
        ]);

        $report = new \gradereport_singleview\report\singleview($course->id, $gpr, $context, 'user', $user->id);

        return [
            'table' => $report->output(),
            'url' => (new \moodle_url('/grade/report/singleview/index.php', [
                'id' => $course->id,
                'item' => 'user',
                'itemid' => $user->id,
            ]))->out(false),
            'name' => get_string('singleview', 'local_la'),
        ];
    }

    /**
     * Build Moodle profile grade view for one user in one course.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return array
     */
    public static function grade_user(\stdClass $course, \stdClass $user): array {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/grade/report/' . $CFG->grade_profilereport . '/lib.php');

        $functionname = 'grade_report_' . $CFG->grade_profilereport . '_profilereport';
        if (!function_exists($functionname)) {
            return [
                'table' => '',
                'url' => (new \moodle_url('/grade/report/user/index.php', [
                    'id' => $course->id,
                    'userid' => $user->id,
                ]))->out(false),
                'name' => get_string('userreport', 'local_la'),
            ];
        }

        ob_start();
        $functionname($course, $user, false);
        return [
            'table' => ob_get_clean(),
            'url' => (new \moodle_url('/grade/report/user/index.php', [
                'id' => $course->id,
                'userid' => $user->id,
            ]))->out(false),
            'name' => get_string('userreport', 'local_la'),
        ];
    }

    /**
     * Replace {{field}} placeholders from a row.
     *
     * @param string $template
     * @param \stdClass $row
     * @return string
     */
    public static function interpolate_value_template(string $template, \stdClass $row): string {
        foreach ((array) $row as $field => $value) {
            $template = str_replace('{{' . $field . '}}', (string) $value, $template);
        }

        return $template;
    }

    /**
     * Interpolate strings recursively inside one scalar/array value.
     *
     * @param mixed $value
     * @param \stdClass $row
     * @return mixed
     */
    public static function interpolate_template_value($value, \stdClass $row) {
        if (is_string($value)) {
            return self::interpolate_value_template($value, $row);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::interpolate_template_value($item, $row);
        }

        return $value;
    }

    /**
     * Build concise summary text for one report link.
     *
     * @param array $filters
     * @param \stdClass $row
     * @return array
     */
    public static function build_report_link_summary(array $filters, \stdClass $row): array {
        if (array_key_exists('activity_id', $filters)) {
            return self::resolve_report_link_summary('activity_id', $row);
        }

        if (array_key_exists('user_id', $filters) && array_key_exists('course_id', $filters)) {
            return [
                'primary' => self::get_summary($row, ['user_name', 'name']),
                'secondary' => self::get_summary($row, ['course_name', 'category_name']),
            ];
        }

        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $summary = self::resolve_report_link_summary((string) $key, $row);
            if ($summary['primary'] === '') {
                continue;
            }

            return $summary;
        }

        return [
            'primary' => '',
            'secondary' => '',
        ];
    }

    /**
     * Resolve primary/secondary summary values for one report filter key.
     *
     * @param string $key
     * @param \stdClass $row
     * @return array
     */
    public static function resolve_report_link_summary(string $key, \stdClass $row): array {
        switch ($key) {
            case 'user_id':
                return [
                    'primary' => self::get_summary($row, ['user_name', 'name']),
                    'secondary' => self::get_summary($row, 'email'),
                ];

            case 'course_id':
                return [
                    'primary' => self::get_summary($row, ['course_name', 'name']),
                    'secondary' => self::get_summary($row, 'category_name'),
                ];

            case 'activity_id':
                return [
                    'primary' => self::get_summary($row, 'activity_name'),
                    'secondary' => self::get_summary($row, ['course_name', 'name']),
                ];

            default:
                return [
                    'primary' => self::get_summary($row, $key),
                    'secondary' => '',
                ];
        }
    }

    /**
     * Get one safe summary value from one field or a list of fallback fields.
     *
     * @param \stdClass $row
     * @param string|array $fields
     * @return string
     */
    public static function get_summary(\stdClass $row, $fields): string {
        $fields = is_array($fields) ? $fields : [$fields];

        foreach ($fields as $field) {
            $value = trim(strip_tags((string) ($row->{(string) $field} ?? '')));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Get items for one named menu preset.
     *
     * @param string $preset
     * @param \stdClass $row
     * @return array
     */
    public static function get_menu_preset_items(string $preset, \stdClass $row): array {
        switch ($preset) {
            case 'course':
                return self::get_course_menu($row);

            case 'user':
                return self::get_user_menu($row);

            case 'user_course':
                return self::get_user_course_menu($row);

            case 'course_activity':
                return self::get_course_activity_menu($row);

            default:
                return [];
        }
    }

    /**
     * Build native Moodle course menu group.
     *
     * @param \stdClass $row
     * @return array
     */
    public static function get_course_menu(\stdClass $row): array {
        if ($row->id < 1) {
            return [];
        }

        return [
            [
                'label' => get_string('participants'),
                'url' => (new \moodle_url('/user/index.php', ['id' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('graderreport', 'local_la'),
                'url' => (new \moodle_url('/grade/report/grader/index.php', ['id' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('gradehistory', 'local_la'),
                'url' => (new \moodle_url('/grade/report/history/index.php', ['id' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('singleview', 'local_la'),
                'url' => (new \moodle_url('/grade/report/singleview/index.php', ['id' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('activitycompletion', 'local_la'),
                'url' => (new \moodle_url('/report/progress/index.php', ['course' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('coursecompletion', 'local_la'),
                'url' => (new \moodle_url('/report/completion/index.php', ['course' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('logs'),
                'url' => (new \moodle_url('/report/log/index.php', ['id' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('livelogs', 'local_la'),
                'url' => (new \moodle_url('/report/loglive/index.php', ['id' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
        ];
    }

    /**
     * Build native Moodle user-course menu.
     *
     * @param \stdClass $row
     * @return array
     */
    public static function get_user_course_menu(\stdClass $row): array {
        if ($row->course_id < 1 || $row->user_id < 1) {
            return [];
        }

        return [
            [
                'label' => get_string('singleview', 'local_la'),
                'url' => (new \moodle_url('/grade/report/singleview/index.php', [
                    'id' => $row->course_id,
                    'userid' => $row->user_id,
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('userreport', 'local_la'),
                'url' => (new \moodle_url('/grade/report/user/index.php', [
                    'id' => $row->course_id,
                    'userid' => $row->user_id,
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('graderreport', 'local_la'),
                'url' => (new \moodle_url('/grade/report/grader/index.php', [
                    'id' => $row->course_id,
                    'gpr_search' => $row->user_name ?? '',
                    'gpr_userid' => $row->user_id,
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('outlinereport', 'local_la'),
                'url' => (new \moodle_url('/report/outline/user.php', [
                    'id' => $row->user_id,
                    'course' => $row->course_id,
                    'mode' => 'outline',
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('completereport', 'local_la'),
                'url' => (new \moodle_url('/report/outline/user.php', [
                    'id' => $row->user_id,
                    'course' => $row->course_id,
                    'mode' => 'complete',
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('todaylogs', 'local_la'),
                'url' => (new \moodle_url('/report/log/user.php', [
                    'id' => $row->user_id,
                    'course' => $row->course_id,
                    'mode' => 'today',
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('alllogs', 'local_la'),
                'url' => (new \moodle_url('/report/log/user.php', [
                    'id' => $row->user_id,
                    'course' => $row->course_id,
                    'mode' => 'all',
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
        ];
    }

    /**
     * Build native Moodle course-activity menu.
     *
     * @param \stdClass $row
     * @return array
     */
    public static function get_course_activity_menu(\stdClass $row): array {
        if ($row->course_id < 1 || $row->activity_id < 1 || $row->activity_type === '') {
            return [];
        }

        $items = [
            [
                'label' => get_string('activitypage', 'local_la'),
                'url' => (new \moodle_url('/mod/' . $row->activity_type . '/view.php', ['id' => $row->activity_id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
        ];

        if (!empty($row->grade_item_id)) {
            $items[] = [
                'label' => get_string('singleview', 'local_la'),
                'url' => (new \moodle_url('/grade/report/singleview/index.php', [
                    'id' => $row->course_id,
                    'item' => 'grade',
                    'gradesearchvalue' => '',
                    'itemid' => $row->grade_item_id,
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ];
        }

        $items[] = [
            'label' => get_string('logs'),
            'url' => (new \moodle_url('/report/log/index.php', [
                'chooselog' => 1,
                'id' => $row->course_id,
                'modid' => $row->activity_id,
                'isactivitypage' => 1,
            ]))->out(false),
            'target' => '_blank',
            'moodle' => true,
        ];

        return $items;
    }

    /**
     * Build native Moodle user menu group.
     *
     * @param \stdClass $row
     * @return array
     */
    public static function get_user_menu(\stdClass $row): array {
        if ($row->id < 1) {
            return [];
        }

        return [
            [
                'label' => get_string('todaylogs', 'local_la'),
                'url' => (new \moodle_url('/report/log/user.php', [
                    'id' => $row->id,
                    'course' => SITEID,
                    'mode' => 'today',
                ]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
            [
                'label' => get_string('logs'),
                'url' => (new \moodle_url('/report/log/user.php', ['id' => SITEID, 'user' => $row->id]))->out(false),
                'target' => '_blank',
                'moodle' => true,
            ],
        ];
    }
}
