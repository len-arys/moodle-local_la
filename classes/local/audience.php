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
 * Report audience helper.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience {
    /**
     * Check whether one user has audience access to one report.
     *
     * @param int $reportid
     * @param int|null $userid
     * @return bool
     */
    public static function has_access(int $reportid, ?int $userid = null): bool {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;

        if ($reportid <= 0 || $userid <= 0 || isguestuser($userid)) {
            return false;
        }

        if (helper::is_admin($userid)) {
            return true;
        }

        if (!$DB->record_exists('local_la_report_audience', ['reportid' => $reportid])) {
            return false;
        }

        if ($DB->record_exists('local_la_report_audience', ['reportid' => $reportid, 'type' => 'all', 'instanceid' => 0])) {
            return true;
        }

        if (is_siteadmin($userid) && $DB->record_exists('local_la_report_audience', [
            'reportid' => $reportid,
            'type' => 'admin',
            'instanceid' => 0,
        ])) {
            return true;
        }

        if ($DB->record_exists('local_la_report_audience', [
            'reportid' => $reportid,
            'type' => 'user',
            'instanceid' => $userid,
        ])) {
            return true;
        }

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_la_report_audience} a
               JOIN {role_assignments} ra
                 ON ra.roleid = a.instanceid
              WHERE a.reportid = :reportid
                AND a.type = :type
                AND ra.userid = :userid
                AND ra.contextid = :contextid",
            [
                'reportid' => $reportid,
                'type' => 'role',
                'userid' => $userid,
                'contextid' => (int) \context_system::instance()->id,
            ]
        );
    }

    /**
     * Get audience cards for one report.
     *
     * @param int $reportid
     * @return array
     */
    public static function get_report_audiences(int $reportid): array {
        global $DB;

        $records = $DB->get_records('local_la_report_audience', ['reportid' => $reportid], 'type ASC, timecreated ASC, id ASC');
        $grouped = [];

        foreach ($records as $record) {
            $type = (string) $record->type;

            if (!array_key_exists($type, $grouped)) {
                $grouped[$type] = [];
            }

            $grouped[$type][] = (int) $record->instanceid;
        }

        $items = [];
        foreach ($grouped as $type => $instanceids) {
            $items[] = [
                'type' => $type,
                'name' => self::get_type_name($type),
                'value' => self::get_description($type, $instanceids),
            ];
        }

        return $items;
    }

    /**
     * Build access users SQL parts.
     *
     * @param int $reportid
     * @param string $search
     * @return array
     */
    public static function get_access_users_sql(int $reportid, string $search = ''): array {
        global $CFG, $DB;

        if ($reportid <= 0) {
            return ['', '1 = 0', []];
        }

        $params = [
            'audiencereportid' => $reportid,
            'relationreportid' => $reportid,
            'contextid' => (int) \context_system::instance()->id,
            'guestid' => (int) ($CFG->siteguest ?? 0),
        ];
        $adminids = array_filter(array_map('intval', explode(',', (string) ($CFG->siteadmins ?? ''))));
        $admincondition = '1 = 0';

        if (!empty($adminids)) {
            [$adminsql, $adminparams] = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED, 'adminid');
            $admincondition = 'u.id ' . $adminsql;
            $params += $adminparams;
        }

        $managerids = array_column(helper::get_admins(), 'id');
        $managercondition = '1 = 0';

        if (!empty($managerids)) {
            [$managersql, $managerparams] = $DB->get_in_or_equal($managerids, SQL_PARAMS_NAMED, 'managerid');
            $managercondition = 'u.id ' . $managersql;
            $params += $managerparams;
        }

        $searchsql = '';
        $search = trim($search);

        if ($search !== '') {
            $searchsql = " AND (" .
                $DB->sql_like('LOWER(u.firstname)', ':search', false) .
                " OR " . $DB->sql_like('LOWER(u.lastname)', ':search', false) .
                " OR " . $DB->sql_like('LOWER(u.email)', ':search', false) .
            ")";
            $params['search'] = '%' . $DB->sql_like_escape(\core_text::strtolower($search)) . '%';
        }

        return [
            "{user} u
          LEFT JOIN {local_la_report_users} ru
                 ON ru.reportid = :relationreportid
                AND ru.userid = u.id",
            "u.deleted = 0
                AND u.id <> :guestid
                AND (
                    {$managercondition}
                    OR EXISTS (
                        SELECT 1
                          FROM {local_la_report_audience} a
                         WHERE a.reportid = :audiencereportid
                           AND (
                               (a.type = 'all' AND a.instanceid = 0)
                               OR (a.type = 'user' AND a.instanceid = u.id)
                               OR (a.type = 'admin' AND a.instanceid = 0 AND {$admincondition})
                               OR (
                                   a.type = 'role'
                                   AND EXISTS (
                                       SELECT 1
                                         FROM {role_assignments} ra
                                        WHERE ra.roleid = a.instanceid
                                          AND ra.userid = u.id
                                          AND ra.contextid = :contextid
                                   )
                               )
                           )
                    )
                )
                {$searchsql}",
            $params
        ];
    }

    /**
     * Get audience forms for one report.
     *
     * @param int $reportid
     * @return array
     */
    public static function get_forms(int $reportid): array {
        $records = self::get_records_by_type($reportid);
        $forms = [];
        $types = ['all', 'role', 'user', 'admin'];

        foreach ($types as $type) {
            $instanceids = $records[$type] ?? [];
            $forms[] = [
                'type' => $type,
                'title' => self::get_type_name($type),
                'description' => self::get_form_description($type),
                'is_simple' => in_array($type, ['all', 'admin'], true),
                'is_role' => $type === 'role',
                'is_user' => $type === 'user',
                'selectid' => 'la-audience-form-' . $type,
                'selected_roles' => $type === 'role' ? self::get_role_options($instanceids) : [],
                'selected_users' => $type === 'user' ? repository::get_filter_selected_users($instanceids) : [],
            ];
        }

        return $forms;
    }

    /**
     * Get role options.
     *
     * @return array
     */
    public static function get_options(): array {
        return [
            'roles' => self::get_role_options(),
        ];
    }

    /**
     * Save one audience type.
     *
     * @param int $reportid
     * @param string $type
     * @param array $instanceids
     * @return void
     */
    public static function save(int $reportid, string $type, array $instanceids = []): void {
        global $DB;

        if (!in_array($type, ['all', 'admin', 'role', 'user'], true)) {
            return;
        }

        $instanceids = array_values(array_unique(array_map('intval', $instanceids)));

        if (in_array($type, ['all', 'admin'], true)) {
            $instanceids = [0];
        } else {
            $instanceids = array_values(array_filter($instanceids, function(int $instanceid): bool {
                return $instanceid > 0;
            }));
        }

        $DB->delete_records('local_la_report_audience', [
            'reportid' => $reportid,
            'type' => $type,
        ]);

        if (empty($instanceids)) {
            return;
        }

        $now = time();

        foreach ($instanceids as $instanceid) {
            $DB->insert_record('local_la_report_audience', (object) [
                'reportid' => $reportid,
                'type' => $type,
                'instanceid' => $instanceid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Delete one audience type.
     *
     * @param int $reportid
     * @param string $type
     * @return void
     */
    public static function delete(int $reportid, string $type): void {
        global $DB;

        if (!in_array($type, ['all', 'admin', 'role', 'user'], true)) {
            return;
        }

        $DB->delete_records('local_la_report_audience', [
            'reportid' => $reportid,
            'type' => $type,
        ]);
    }

    /**
     * Get audience type label.
     *
     * @param string $type
     * @return string
     */
    protected static function get_type_name(string $type): string {
        if ($type === 'all') {
            return get_string('audienceallusers', 'local_la');
        }

        if ($type === 'admin') {
            return get_string('audiencesiteadministrators', 'local_la');
        }

        if ($type === 'role') {
            return get_string('audienceassignedsystemrole', 'local_la');
        }

        return get_string('audiencemanuallyaddedusers', 'local_la');
    }

    /**
     * Get grouped audience ids by type.
     *
     * @param int $reportid
     * @return array
     */
    protected static function get_records_by_type(int $reportid): array {
        global $DB;

        $grouped = [];

        foreach ($DB->get_records('local_la_report_audience', ['reportid' => $reportid], 'type ASC, timecreated ASC, id ASC') as $record) {
            $type = (string) $record->type;

            if (!array_key_exists($type, $grouped)) {
                $grouped[$type] = [];
            }

            $grouped[$type][] = (int) $record->instanceid;
        }

        return $grouped;
    }

    /**
     * Get role options, optionally selected.
     *
     * @param array $selectedids
     * @return array
     */
    protected static function get_role_options(array $selectedids = []): array {
        global $DB;

        $roles = $DB->get_records('role', null, 'sortorder ASC', 'id,name,shortname');

        return array_values(array_map(function(\stdClass $role) use ($selectedids): array {
            return [
                'id' => (int) $role->id,
                'name' => trim((string) $role->name) !== '' ? format_string($role->name) : (string) $role->shortname,
                'selected' => in_array((int) $role->id, $selectedids, true),
            ];
        }, $roles));
    }

    /**
     * Get audience description.
     *
     * @param string $type
     * @param array $instanceids
     * @return string
     */
    protected static function get_description(string $type, array $instanceids): string {
        global $DB;

        if ($type === 'all') {
            return get_string('audienceallsiteusers', 'local_la');
        }

        if ($type === 'admin') {
            $admins = array_map(function(\stdClass $user): string {
                return fullname($user);
            }, get_admins());

            return empty($admins) ? get_string('audiencesiteadministrators', 'local_la') : implode(', ', $admins);
        }

        if ($type === 'role') {
            $roles = [];

            foreach (self::get_role_options($instanceids) as $role) {
                if (!empty($role['selected'])) {
                    $roles[] = (string) $role['name'];
                }
            }

            return implode(', ', $roles);
        }

        $users = repository::get_filter_selected_users($instanceids);
        return implode(', ', array_map(function(array $user): string {
            return (string) $user['name'];
        }, $users));
    }

    /**
     * Get audience form description.
     *
     * @param string $type
     * @return string
     */
    protected static function get_form_description(string $type): string {
        if ($type === 'all') {
            return get_string('audienceallsiteusers', 'local_la');
        }

        if ($type === 'admin') {
            return get_string('audiencesiteadministrators', 'local_la');
        }

        if ($type === 'role') {
            return get_string('selectrole', 'role');
        }

        return get_string('addusers', 'core_reportbuilder');
    }
}
