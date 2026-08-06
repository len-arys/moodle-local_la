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

defined('MOODLE_INTERNAL') || die();

/**
 * Local modal data access.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external {
    /**
     * Get enrolled courses for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_enrolled_courses(int $userid): array {
        global $DB;

        $sql = "SELECT c.id, c.fullname, c.shortname, MIN(ue.timecreated) AS timeenrolled
                  FROM {user_enrolments} ue
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                  JOIN {course} c
                    ON c.id = e.courseid
                 WHERE ue.userid = :userid
                   AND c.visible = 1
              GROUP BY c.id, c.fullname, c.shortname
              ORDER BY c.fullname ASC";

        return array_values($DB->get_records_sql($sql, ['userid' => $userid]));
    }

    /**
     * Get completed courses for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_completed_courses(int $userid): array {
        global $DB;

        $sql = "SELECT c.id, c.fullname, c.shortname, cc.timecompleted
                  FROM {course_completions} cc
                  JOIN {course} c
                    ON c.id = cc.course
                 WHERE cc.userid = :userid
                   AND cc.timecompleted IS NOT NULL
                   AND c.visible = 1
              ORDER BY cc.timecompleted DESC, c.fullname ASC";

        return array_values($DB->get_records_sql($sql, ['userid' => $userid]));
    }

    /**
     * Get course grades for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_course_grades(int $userid): array {
        global $DB;

        $sql = "SELECT c.id, c.fullname, c.shortname, gg.finalgrade, gg.timemodified
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi
                    ON gi.id = gg.itemid
                  JOIN {course} c
                    ON c.id = gi.courseid
                 WHERE gg.userid = :userid
                   AND gi.itemtype = 'course'
                   AND gg.finalgrade IS NOT NULL
                   AND c.visible = 1
              ORDER BY c.fullname ASC";

        return array_values($DB->get_records_sql($sql, ['userid' => $userid]));
    }

    /**
     * Get course activities.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_activities(int $courseid): array {
        global $DB;

        $sql = "SELECT cm.id, m.name AS modname, cm.instance, cm.added, cm.visible
                  FROM {course_modules} cm
                  JOIN {modules} m
                    ON m.id = cm.module
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0
              ORDER BY cm.id ASC";

        return array_values($DB->get_records_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * Get enrolled users for one course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_enrolled_users(int $courseid): array {
        global $DB;

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, MIN(ue.timecreated) AS timeenrolled
                  FROM {user_enrolments} ue
                  JOIN {enrol} e
                    ON e.id = ue.enrolid
                  JOIN {user} u
                    ON u.id = ue.userid
                 WHERE e.courseid = :courseid
                   AND u.deleted = 0
              GROUP BY u.id, u.firstname, u.lastname, u.email
              ORDER BY u.firstname ASC, u.lastname ASC";

        return array_values($DB->get_records_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * Get completed users for one course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_completed_users(int $courseid): array {
        global $DB;

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, cc.timecompleted
                  FROM {course_completions} cc
                  JOIN {user} u
                    ON u.id = cc.userid
                 WHERE cc.course = :courseid
                   AND cc.timecompleted IS NOT NULL
                   AND u.deleted = 0
              ORDER BY cc.timecompleted DESC, u.firstname ASC, u.lastname ASC";

        return array_values($DB->get_records_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * Get course grades for one course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_grades(int $courseid): array {
        global $DB;

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, gg.finalgrade, gg.timemodified
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi
                    ON gi.id = gg.itemid
                  JOIN {user} u
                    ON u.id = gg.userid
                 WHERE gi.courseid = :courseid
                   AND gi.itemtype = 'course'
                   AND gg.finalgrade IS NOT NULL
                   AND u.deleted = 0
              ORDER BY u.firstname ASC, u.lastname ASC";

        return array_values($DB->get_records_sql($sql, ['courseid' => $courseid]));
    }
}
