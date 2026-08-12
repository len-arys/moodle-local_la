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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_la\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Learning Analytics.
 *
 * All plugin-owned personal data is stored in the system context.
 *
 * @package    local_la
 * @category   privacy
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Plugin-owned user preference. */
    protected const AGENT_PREFERENCE = 'local_la_agent_done';

    /**
     * Describe stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_la_report_users', [
            'reportid' => 'privacy:metadata:field:reportid',
            'status' => 'privacy:metadata:field:status',
            'userid' => 'privacy:metadata:field:userid',
            'favorite' => 'privacy:metadata:field:favorite',
            'user_params' => 'privacy:metadata:field:userparams',
            'timeaccess' => 'privacy:metadata:field:timeaccess',
            'timecreated' => 'privacy:metadata:field:timecreated',
            'timemodified' => 'privacy:metadata:field:timemodified',
        ], 'privacy:metadata:reportusers');

        $collection->add_database_table('local_la_report_audience', [
            'reportid' => 'privacy:metadata:field:reportid',
            'type' => 'privacy:metadata:field:audiencetype',
            'instanceid' => 'privacy:metadata:field:audienceinstanceid',
            'timecreated' => 'privacy:metadata:field:timecreated',
            'timemodified' => 'privacy:metadata:field:timemodified',
        ], 'privacy:metadata:reportaudience');

        $collection->add_database_table('local_la_report_schedule', [
            'reportid' => 'privacy:metadata:field:reportid',
            'name' => 'privacy:metadata:field:name',
            'format' => 'privacy:metadata:field:format',
            'timestart' => 'privacy:metadata:field:timestart',
            'recurrence' => 'privacy:metadata:field:recurrence',
            'subject' => 'privacy:metadata:field:subject',
            'body' => 'privacy:metadata:field:body',
            'audiences' => 'privacy:metadata:field:audiences',
            'emptyreport' => 'privacy:metadata:field:emptyreport',
            'status' => 'privacy:metadata:field:status',
            'timelastsent' => 'privacy:metadata:field:timelastsent',
            'failurecount' => 'privacy:metadata:field:failurecount',
            'timelastattempt' => 'privacy:metadata:field:timelastattempt',
            'timenextattempt' => 'privacy:metadata:field:timenextattempt',
            'lasterror' => 'privacy:metadata:field:lasterror',
            'usercreated' => 'privacy:metadata:field:usercreated',
            'usermodified' => 'privacy:metadata:field:usermodified',
            'timecreated' => 'privacy:metadata:field:timecreated',
            'timemodified' => 'privacy:metadata:field:timemodified',
        ], 'privacy:metadata:reportschedule');

        $collection->add_database_table('local_la_logs', [
            'userid' => 'privacy:metadata:field:userid',
            'action' => 'privacy:metadata:field:action',
            'objecttype' => 'privacy:metadata:field:objecttype',
            'objectid' => 'privacy:metadata:field:objectid',
            'details' => 'privacy:metadata:field:details',
            'ip' => 'privacy:metadata:field:ip',
            'timecreated' => 'privacy:metadata:field:timecreated',
        ], 'privacy:metadata:logs');

        $collection->add_database_table('local_la_ai', [
            'userid' => 'privacy:metadata:field:userid',
            'prompt' => 'privacy:metadata:field:prompt',
            'context' => 'privacy:metadata:field:aicontext',
            'response' => 'privacy:metadata:field:response',
            'definition' => 'privacy:metadata:field:definition',
            'status' => 'privacy:metadata:field:status',
            'timecreated' => 'privacy:metadata:field:timecreated',
        ], 'privacy:metadata:ai');

        $collection->add_database_table('local_la_time_total', [
            'userid' => 'privacy:metadata:field:userid',
            'pageid' => 'privacy:metadata:field:pageid',
            'visits' => 'privacy:metadata:field:visits',
            'timesec' => 'privacy:metadata:field:timesec',
            'params' => 'privacy:metadata:field:trackingparams',
            'firstaccess' => 'privacy:metadata:field:firstaccess',
            'lastaccess' => 'privacy:metadata:field:lastaccess',
        ], 'privacy:metadata:timetotal');

        $collection->add_database_table('local_la_time_page', [
            'name' => 'privacy:metadata:field:pagename',
            'instanceid' => 'privacy:metadata:field:pageinstanceid',
            'courseid' => 'privacy:metadata:field:courseid',
        ], 'privacy:metadata:timepage');

        $collection->add_database_table('local_la_time_day', [
            'totalid' => 'privacy:metadata:field:totalid',
            'daystamp' => 'privacy:metadata:field:daystamp',
            'visits' => 'privacy:metadata:field:visits',
            'timesec' => 'privacy:metadata:field:timesec',
        ], 'privacy:metadata:timeday');

        $collection->add_database_table('local_la_time_hour', [
            'dayid' => 'privacy:metadata:field:dayid',
            'hour' => 'privacy:metadata:field:hour',
            'visits' => 'privacy:metadata:field:visits',
            'timesec' => 'privacy:metadata:field:timesec',
        ], 'privacy:metadata:timehour');

        $collection->add_external_location_link('lenarys_api', [
            'license' => 'privacy:metadata:lenarysapi:license',
            'url' => 'privacy:metadata:lenarysapi:siteurl',
            'moodleversion' => 'privacy:metadata:lenarysapi:moodleversion',
            'pluginversion' => 'privacy:metadata:lenarysapi:pluginversion',
            'report' => 'privacy:metadata:lenarysapi:report',
            'app' => 'privacy:metadata:lenarysapi:app',
        ], 'privacy:metadata:lenarysapi');

        $collection->add_external_location_link('ai_provider', [
            'prompttext' => 'privacy:metadata:aiprovider:prompttext',
        ], 'privacy:metadata:aiprovider');

        $collection->add_user_preference(self::AGENT_PREFERENCE, 'privacy:metadata:preference:agentdone');

        return $collection;
    }

    /**
     * Get contexts containing data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if (
            self::user_has_data($userid) || $DB->record_exists('user_preferences', [
            'userid' => $userid,
            'name' => self::AGENT_PREFERENCE,
            ])
        ) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Add users with plugin data in the system context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_la_report_users}', []);
        $userlist->add_from_sql(
            'userid',
            "SELECT instanceid AS userid FROM {local_la_report_audience} WHERE type = :type",
            ['type' => 'user']
        );
        $userlist->add_from_sql('userid', 'SELECT usercreated AS userid FROM {local_la_report_schedule}', []);
        $userlist->add_from_sql('userid', 'SELECT usermodified AS userid FROM {local_la_report_schedule}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_la_logs}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_la_ai}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_la_time_total}', []);
        $userlist->add_from_sql(
            'userid',
            'SELECT instanceid AS userid FROM {local_la_time_page} WHERE name = :name',
            ['name' => 'profile']
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {user_preferences} WHERE name = :name',
            ['name' => self::AGENT_PREFERENCE]
        );
    }

    /**
     * Export user data from approved system context.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::has_system_context($contextlist)) {
            return;
        }

        $userid = (int) $contextlist->get_user()->id;
        $writer = writer::with_context(\context_system::instance());
        $path = [get_string('pluginname', 'local_la')];

        self::export_records($writer, $path, 'report_preferences', $DB->get_records(
            'local_la_report_users',
            ['userid' => $userid],
            'timecreated ASC, id ASC'
        ));
        self::export_records($writer, $path, 'audience_assignments', $DB->get_records(
            'local_la_report_audience',
            ['type' => 'user', 'instanceid' => $userid],
            'timecreated ASC, id ASC'
        ));
        self::export_records($writer, $path, 'schedules', $DB->get_records_select(
            'local_la_report_schedule',
            'usercreated = :created OR usermodified = :modified',
            ['created' => $userid, 'modified' => $userid],
            'timecreated ASC, id ASC'
        ));
        self::export_records($writer, $path, 'audit_logs', $DB->get_records(
            'local_la_logs',
            ['userid' => $userid],
            'timecreated ASC, id ASC'
        ));
        self::export_records($writer, $path, 'ai_history', $DB->get_records(
            'local_la_ai',
            ['userid' => $userid],
            'timecreated ASC, id ASC'
        ));

        $tracking = $DB->get_records_sql(
            'SELECT t.*, p.name AS pagename, p.instanceid, p.courseid
               FROM {local_la_time_total} t
               JOIN {local_la_time_page} p ON p.id = t.pageid
              WHERE t.userid = :userid
           ORDER BY t.firstaccess ASC, t.id ASC',
            ['userid' => $userid]
        );
        self::export_records($writer, $path, 'time_tracking', $tracking);
        self::export_records($writer, $path, 'tracked_profile_pages', $DB->get_records(
            'local_la_time_page',
            ['name' => 'profile', 'instanceid' => $userid],
            'id ASC'
        ));

        $days = $DB->get_records_sql(
            'SELECT d.*
               FROM {local_la_time_day} d
               JOIN {local_la_time_total} t ON t.id = d.totalid
              WHERE t.userid = :userid
           ORDER BY d.daystamp ASC, d.id ASC',
            ['userid' => $userid]
        );
        self::export_records($writer, $path, 'time_tracking_days', $days);

        $hours = $DB->get_records_sql(
            'SELECT h.*
               FROM {local_la_time_hour} h
               JOIN {local_la_time_day} d ON d.id = h.dayid
               JOIN {local_la_time_total} t ON t.id = d.totalid
              WHERE t.userid = :userid
           ORDER BY d.daystamp ASC, h.hour ASC, h.id ASC',
            ['userid' => $userid]
        );
        self::export_records($writer, $path, 'time_tracking_hours', $hours);

        $preference = get_user_preferences(self::AGENT_PREFERENCE, null, $userid);
        if ($preference !== null) {
            writer::export_user_preference(
                'local_la',
                self::AGENT_PREFERENCE,
                (string) $preference,
                get_string('privacy:metadata:preference:agentdone', 'local_la')
            );
        }
    }

    /**
     * Delete plugin personal data for every user in the system context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_la_time_hour');
        $DB->delete_records('local_la_time_day');
        $DB->delete_records('local_la_time_total');
        $DB->delete_records('local_la_time_page');
        $DB->delete_records('local_la_ai');
        $DB->delete_records('local_la_logs');
        $DB->delete_records('local_la_report_schedule');
        $DB->delete_records('local_la_report_audience', ['type' => 'user']);
        $DB->delete_records('local_la_report_users');
        $DB->delete_records('user_preferences', ['name' => self::AGENT_PREFERENCE]);
        $transaction->allow_commit();
    }

    /**
     * Delete plugin personal data for one approved user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!self::has_system_context($contextlist)) {
            return;
        }

        self::delete_user_data((int) $contextlist->get_user()->id);
    }

    /**
     * Delete plugin personal data for approved users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_data((int) $userid);
        }
    }

    /**
     * Check for any table data owned by a user.
     *
     * @param int $userid
     * @return bool
     */
    protected static function user_has_data(int $userid): bool {
        global $DB;

        return $DB->record_exists('local_la_report_users', ['userid' => $userid])
            || $DB->record_exists('local_la_report_audience', ['type' => 'user', 'instanceid' => $userid])
            || $DB->record_exists_select(
                'local_la_report_schedule',
                'usercreated = :created OR usermodified = :modified',
                ['created' => $userid, 'modified' => $userid]
            )
            || $DB->record_exists('local_la_logs', ['userid' => $userid])
            || $DB->record_exists('local_la_ai', ['userid' => $userid])
            || $DB->record_exists('local_la_time_total', ['userid' => $userid])
            || $DB->record_exists('local_la_time_page', ['name' => 'profile', 'instanceid' => $userid]);
    }

    /**
     * Check whether the approved list includes the system context.
     *
     * @param approved_contextlist $contextlist
     * @return bool
     */
    protected static function has_system_context(approved_contextlist $contextlist): bool {
        $systemid = (int) \context_system::instance()->id;
        return in_array($systemid, array_map('intval', $contextlist->get_contextids()), true);
    }

    /**
     * Export records under one related-data name.
     *
     * @param \core_privacy\local\request\content_writer $writer
     * @param array $path
     * @param string $name
     * @param array $records
     */
    protected static function export_records(
        \core_privacy\local\request\content_writer $writer,
        array $path,
        string $name,
        array $records
    ): void {
        if (!empty($records)) {
            $writer->export_related_data($path, $name, (object) ['records' => array_values($records)]);
        }
    }

    /**
     * Delete all data associated with one user.
     *
     * @param int $userid
     */
    protected static function delete_user_data(int $userid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $pageids = $DB->get_fieldset_select('local_la_time_total', 'pageid', 'userid = :userid', ['userid' => $userid]);
        $profilepageids = $DB->get_fieldset_select(
            'local_la_time_page',
            'id',
            'name = :name AND instanceid = :instanceid',
            ['name' => 'profile', 'instanceid' => $userid]
        );
        $pageids = array_values(array_unique(array_merge($pageids, $profilepageids)));
        $totalids = $DB->get_fieldset_select('local_la_time_total', 'id', 'userid = :userid', ['userid' => $userid]);

        if (!empty($profilepageids)) {
            [$pagesql, $pageparams] = $DB->get_in_or_equal($profilepageids, SQL_PARAMS_NAMED, 'profilepage');
            $profiletotalids = $DB->get_fieldset_select('local_la_time_total', 'id', 'pageid ' . $pagesql, $pageparams);
            $totalids = array_values(array_unique(array_merge($totalids, $profiletotalids)));
        }

        if (!empty($totalids)) {
            [$totalsql, $totalparams] = $DB->get_in_or_equal($totalids, SQL_PARAMS_NAMED, 'total');
            $dayids = $DB->get_fieldset_select('local_la_time_day', 'id', 'totalid ' . $totalsql, $totalparams);

            if (!empty($dayids)) {
                [$daysql, $dayparams] = $DB->get_in_or_equal($dayids, SQL_PARAMS_NAMED, 'day');
                $DB->delete_records_select('local_la_time_hour', 'dayid ' . $daysql, $dayparams);
            }

            $DB->delete_records_select('local_la_time_day', 'totalid ' . $totalsql, $totalparams);
        }

        if (!empty($totalids)) {
            [$totalsql, $totalparams] = $DB->get_in_or_equal($totalids, SQL_PARAMS_NAMED, 'deletetotal');
            $DB->delete_records_select('local_la_time_total', 'id ' . $totalsql, $totalparams);
        }

        foreach ($pageids as $pageid) {
            if (!$DB->record_exists('local_la_time_total', ['pageid' => (int) $pageid])) {
                $DB->delete_records('local_la_time_page', ['id' => (int) $pageid]);
            }
        }
        $DB->delete_records('local_la_ai', ['userid' => $userid]);
        $DB->delete_records('local_la_logs', ['userid' => $userid]);
        $DB->delete_records('local_la_report_schedule', ['usercreated' => $userid]);
        $DB->set_field('local_la_report_schedule', 'usermodified', 0, ['usermodified' => $userid]);
        $DB->delete_records('local_la_report_audience', ['type' => 'user', 'instanceid' => $userid]);
        $DB->delete_records('local_la_report_users', ['userid' => $userid]);
        unset_user_preference(self::AGENT_PREFERENCE, $userid);
        $transaction->allow_commit();
    }
}
