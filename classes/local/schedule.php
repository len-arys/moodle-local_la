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
 * Report schedule helper.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule {
    /** @var string[] Supported recurrence values. */
    protected const RECURRENCES = ['none', 'daily', 'weekly', 'monthly'];

    /** @var int Maximum consecutive transient failures before a schedule is disabled. */
    protected const MAX_FAILURES = 8;

    /** @var int Initial retry delay after a transient failure. */
    protected const RETRY_BASE_SECONDS = 300;

    /** @var int Maximum retry delay after a transient failure. */
    protected const RETRY_MAX_SECONDS = 86400;

    /**
     * Get the maximum number of recipients allowed for one scheduled delivery.
     *
     * @return int
     */
    public static function get_max_recipients(): int {
        static $limit = null;

        if ($limit === null) {
            $defaults = require(__DIR__ . '/../../config.php');
            $limit = max(1, (int) ($defaults['schedulemaxrecipients'] ?? 100));
        }

        return $limit;
    }

    /**
     * Check whether the site's email-enabled user count exceeds the schedule limit.
     *
     * @return bool
     */
    protected static function is_all_users_over_limit(): bool {
        global $CFG, $DB;

        return $DB->count_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND confirmed = 1 AND emailstop = 0
                AND email <> :emptyemail AND id <> :guestid',
            ['emptyemail' => '', 'guestid' => (int) ($CFG->siteguest ?? 0)]
        ) > self::get_max_recipients();
    }

    public static function get_records_sql(int $reportid, string $search = ''): array {
        global $DB, $USER;

        $from = "{local_la_report_schedule} s
            LEFT JOIN {user} u ON u.id = s.usermodified";
        $where = "s.reportid = :reportid";
        $params = ['reportid' => $reportid];

        if (!helper::is_admin()) {
            $where .= " AND s.usercreated = :usercreated";
            $params['usercreated'] = (int) $USER->id;
        }

        $search = trim($search);
        if ($search !== '') {
            $where .= " AND " . $DB->sql_like('LOWER(s.name)', ':search', false);
            $params['search'] = '%' . $DB->sql_like_escape(\core_text::strtolower($search)) . '%';
        }

        return [$from, $where, $params];
    }

    public static function require_manage(int $scheduleid): \stdClass {
        global $DB, $USER;

        $schedule = $DB->get_record('local_la_report_schedule', ['id' => $scheduleid], '*');
        if (!$schedule) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $report = $DB->get_record('local_la_report', ['id' => (int) $schedule->reportid], 'id, plan', IGNORE_MISSING);
        if (!$report) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        if (!helper::has_plan((string) $report->plan)) {
            throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label((string) $report->plan));
        }

        if (!audience::has_access((int) $schedule->reportid) ||
                (!helper::is_admin() && (int) $schedule->usercreated !== (int) $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        return $schedule;
    }

    public static function get_modal_context(int $reportid, int $scheduleid = 0): array {
        global $DB, $USER;

        $record = null;
        if ($scheduleid) {
            $record = self::require_manage($scheduleid);
            if ((int) $record->reportid !== $reportid) {
                throw new \moodle_exception('invalidrecord', 'error');
            }
        }
        $selectedaudiences = $record ? json_decode((string) $record->audiences, true) : [];
        $selectedaudiences = is_array($selectedaudiences) ? $selectedaudiences : [];
        $isselfschedule = in_array('self', $selectedaudiences, true);
        $creator = null;

        if ($isselfschedule) {
            $creator = $DB->get_record(
                'user',
                ['id' => (int) $record->usercreated],
                'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename',
                IGNORE_MISSING
            );
        }

        $audiences = !helper::is_admin() || $isselfschedule ? [[
            'type' => 'self',
            'name' => get_string('schedulecreator', 'local_la'),
            'value' => empty($creator) ? fullname($USER) : fullname($creator),
            'locked' => true,
        ]] : audience::get_report_audiences($reportid);
        $time = $record ? (int) $record->timestart : time();

        $hasallusers = in_array('all', array_column($audiences, 'type'), true);
        $allusersoverlimit = $hasallusers && self::is_all_users_over_limit();
        $maxrecipients = self::get_max_recipients();

        foreach ($audiences as &$audience) {
            $oversized = $audience['type'] === 'all' && $allusersoverlimit;
            $audience['selected'] = !$oversized &&
                ($audience['type'] === 'self' || in_array($audience['type'], $selectedaudiences, true));
            $audience['disabled'] = !empty($audience['locked']) || $oversized;
            $audience['limitmessage'] = $oversized ?
                get_string('scheduleaudiencetoolarge', 'local_la', $maxrecipients) : '';
        }

        return [
            'reportid' => $reportid,
            'scheduleid' => $scheduleid,
            'name' => $record ? format_string((string) $record->name) : '',
            'subject' => $record ? format_string((string) $record->subject) : '',
            'body' => $record ? (string) $record->body : '',
            'time' => [
                'days' => self::get_number_options(1, 31, (int) userdate($time, '%d')),
                'months' => self::get_month_options((int) userdate($time, '%m')),
                'years' => self::get_number_options((int) userdate($time, '%Y'), (int) userdate($time, '%Y') + 10, (int) userdate($time, '%Y')),
                'hours' => self::get_number_options(0, 23, (int) userdate($time, '%H'), true),
                'minutes' => self::get_number_options(0, 59, (int) userdate($time, '%M'), true),
            ],
            'formats' => self::get_format_options($record ? (string) $record->format : 'csv'),
            'recurrences' => self::get_options(['none', 'daily', 'weekly', 'monthly'], 'recurrence', $record ? (string) $record->recurrence : 'none'),
            'emptyoptions' => [
                ['value' => 'send', 'name' => get_string('sendemptyreport', 'local_la'), 'selected' => !$record || $record->emptyreport === 'send'],
                ['value' => 'skip', 'name' => get_string('donotsendemptyreport', 'local_la'), 'selected' => $record && $record->emptyreport === 'skip'],
            ],
            'audiences' => $audiences,
            'hasaudiences' => !empty($audiences),
        ];
    }

    public static function save(array $data): int {
        global $DB, $USER;

        $now = time();
        $audiences = helper::is_admin() ? array_values($data['audiences']) : ['self'];

        self::require_active_report_relation((int) $data['reportid'], (int) $USER->id);

        if (!in_array((string) $data['recurrence'], self::RECURRENCES, true)) {
            throw new \invalid_parameter_exception('Invalid schedule recurrence');
        }
        if (!in_array((string) $data['emptyreport'], ['send', 'skip'], true)) {
            throw new \invalid_parameter_exception('Invalid empty report option');
        }
        if (!self::is_enabled_format((string) $data['format'])) {
            throw new \invalid_parameter_exception('Invalid or disabled export format');
        }

        $existing = null;
        if (!empty($data['scheduleid'])) {
            $existing = self::require_manage((int) $data['scheduleid']);
            $existingaudiences = json_decode((string) $existing->audiences, true);
            if (is_array($existingaudiences) && in_array('self', $existingaudiences, true)) {
                $audiences = ['self'];
            }
        }

        if (in_array('all', $audiences, true) && self::is_all_users_over_limit()) {
            throw new \moodle_exception(
                'schedulerecipientlimit',
                'local_la',
                '',
                self::get_max_recipients()
            );
        }

        $record = (object) [
            'reportid' => (int) $data['reportid'],
            'name' => (string) $data['name'],
            'format' => (string) $data['format'],
            'timestart' => (int) $data['timestart'],
            'recurrence' => (string) $data['recurrence'],
            'subject' => (string) $data['subject'],
            'body' => (string) $data['body'],
            'audiences' => json_encode($audiences),
            'emptyreport' => (string) $data['emptyreport'],
            'failurecount' => 0,
            'timelastattempt' => 0,
            'timenextattempt' => 0,
            'lasterror' => null,
            'usermodified' => (int) $USER->id,
            'timemodified' => $now,
        ];

        if (!empty($data['scheduleid'])) {
            $record->id = (int) $data['scheduleid'];
            if ((int) $existing->reportid !== (int) $record->reportid) {
                throw new \moodle_exception('invalidrecord', 'error');
            }
            $DB->update_record('local_la_report_schedule', $record);
            return (int) $record->id;
        }

        $record->usercreated = (int) $USER->id;
        $record->timecreated = $now;
        $record->status = 1;
        return (int) $DB->insert_record('local_la_report_schedule', $record);
    }

    public static function toggle(int $scheduleid, int $status): \stdClass {
        global $DB, $USER;

        $schedule = self::require_manage($scheduleid);

        if ($status) {
            $audiences = json_decode((string) $schedule->audiences, true);
            if (is_array($audiences) && in_array('all', $audiences, true) && self::is_all_users_over_limit()) {
                throw new \moodle_exception(
                    'schedulerecipientlimit',
                    'local_la',
                    '',
                    self::get_max_recipients()
                );
            }
        }

        $DB->update_record('local_la_report_schedule', (object) [
            'id' => $scheduleid,
            'status' => $status ? 1 : 0,
            'failurecount' => $status ? 0 : (int) $schedule->failurecount,
            'timelastattempt' => $status ? 0 : (int) $schedule->timelastattempt,
            'timenextattempt' => 0,
            'lasterror' => $status ? null : $schedule->lasterror,
            'usermodified' => (int) $USER->id,
            'timemodified' => time(),
        ]);

        return $schedule;
    }

    public static function send(int $scheduleid): \stdClass {
        global $DB, $USER;

        $schedule = self::require_manage($scheduleid);
        $now = time();

        try {
            $result = self::deliver($schedule);
        } catch (\Throwable $exception) {
            self::record_failure($schedule, $exception, $now);
            throw $exception;
        }

        $DB->update_record('local_la_report_schedule', (object) [
            'id' => $scheduleid,
            'status' => (string) $schedule->recurrence === 'none' ? 0 : (int) $schedule->status,
            'timelastsent' => $now,
            'failurecount' => 0,
            'timelastattempt' => $now,
            'timenextattempt' => 0,
            'lasterror' => null,
            'usermodified' => (int) $USER->id,
            'timemodified' => $now,
        ]);

        $schedule->deliverysent = $result['sent'];
        $schedule->deliveryrows = $result['rows'];
        $schedule->deliveryskipped = $result['skipped'];

        return $schedule;
    }

    /**
     * Process all schedules that are due.
     *
     * Errors are isolated per schedule so one invalid report does not block all deliveries.
     *
     * @return array Delivery counters
     */
    public static function process_due_schedules(): array {
        global $DB, $USER;

        $now = time();
        $records = $DB->get_records_select(
            'local_la_report_schedule',
            'status = :status AND timestart <= :now AND (timenextattempt = 0 OR timenextattempt <= :retrytime)',
            ['status' => 1, 'now' => $now, 'retrytime' => $now],
            'timestart ASC, id ASC'
        );
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0, 'skipped' => 0];

        foreach ($records as $schedule) {
            if (!self::is_due($schedule, $now)) {
                continue;
            }

            $originaluser = $USER;
            $userchanged = false;
            try {
                $creator = $DB->get_record('user', [
                    'id' => (int) $schedule->usercreated,
                    'deleted' => 0,
                    'suspended' => 0,
                ], '*', MUST_EXIST);
                \core\cron::setup_user($creator);
                $userchanged = true;
                $delivery = self::deliver($schedule);
                self::record_success($schedule, $now);
                $result['processed']++;
                $result['sent'] += (int) $delivery['sent'];
                $result['skipped'] += !empty($delivery['skipped']) ? 1 : 0;
                mtrace('local_la schedule ' . (int) $schedule->id . ': sent to ' . (int) $delivery['sent'] . ' recipient(s)');
            } catch (\Throwable $exception) {
                $failure = self::record_failure($schedule, $exception, $now);
                $result['failed']++;
                $result['disabled'] += !empty($failure['disabled']) ? 1 : 0;
                $state = !empty($failure['disabled']) ? 'disabled' : 'retry after ' . userdate((int) $failure['nextattempt']);
                mtrace(
                    'local_la schedule ' . (int) $schedule->id . ' failed (' . $state . '): ' . $exception->getMessage()
                );
            } finally {
                if ($userchanged) {
                    \core\cron::setup_user($originaluser);
                }
            }
        }

        return $result;
    }

    /**
     * Determine whether a schedule should be sent now.
     *
     * @param \stdClass $schedule
     * @param int $now
     * @return bool
     */
    public static function is_due(\stdClass $schedule, int $now): bool {
        if (empty($schedule->status) || (int) $schedule->timestart > $now ||
                (!empty($schedule->timenextattempt) && (int) $schedule->timenextattempt > $now)) {
            return false;
        }

        if ((string) $schedule->recurrence === 'none') {
            return (int) $schedule->timelastsent < (int) $schedule->timestart;
        }

        if (!in_array((string) $schedule->recurrence, self::RECURRENCES, true)) {
            return false;
        }

        return self::next_occurrence($schedule) <= $now;
    }

    /**
     * Deliver one schedule without changing its database timestamps.
     *
     * @param \stdClass $schedule
     * @return array
     */
    public static function deliver(\stdClass $schedule): array {
        global $DB;

        $creator = $DB->get_record('user', [
            'id' => (int) $schedule->usercreated,
            'deleted' => 0,
            'suspended' => 0,
        ], '*', MUST_EXIST);
        self::require_active_report_relation((int) $schedule->reportid, (int) $creator->id);
        $report = repository::get_report((int) $schedule->reportid, (int) $creator->id);

        if (!$report || empty($report->userid)) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }
        if (!helper::has_plan((string) $report->plan) ||
                !audience::has_access((int) $report->id, (int) $creator->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        if (!self::is_enabled_format((string) $schedule->format)) {
            throw new \moodle_exception('invalidscheduleformat', 'local_la');
        }

        $recipients = self::get_recipients($schedule, $creator);
        if (empty($recipients)) {
            return ['sent' => 0, 'rows' => 0, 'skipped' => true];
        }

        $attachment = self::create_report_attachment($schedule, $report);
        if ($attachment['rows'] === 0 && (string) $schedule->emptyreport === 'skip') {
            return ['sent' => 0, 'rows' => 0, 'skipped' => true];
        }

        $messagehtml = format_text((string) $schedule->body, FORMAT_HTML, [
            'context' => \context_system::instance(),
            'trusted' => false,
            'noclean' => false,
            'para' => true,
        ]);
        $messagetext = html_to_text($messagehtml);
        $sender = \core_user::get_noreply_user();
        $sent = 0;

        foreach ($recipients as $recipient) {
            if (email_to_user(
                $recipient,
                $sender,
                (string) $schedule->subject,
                $messagetext,
                $messagehtml,
                $attachment['path'],
                $attachment['filename']
            )) {
                $sent++;
            }
        }

        if ($sent === 0) {
            throw new \moodle_exception('scheduledeliveryfailed', 'local_la');
        }

        return ['sent' => $sent, 'rows' => $attachment['rows'], 'skipped' => false];
    }

    /**
     * Resolve current recipients for selected report audience types.
     *
     * @param \stdClass $schedule
     * @param \stdClass $creator
     * @return \stdClass[]
     */
    protected static function get_recipients(\stdClass $schedule, \stdClass $creator): array {
        global $CFG, $DB;

        $types = json_decode((string) $schedule->audiences, true);
        $types = is_array($types) ? array_values(array_unique(array_map('strval', $types))) : [];
        $users = [];

        if (in_array('all', $types, true) && self::is_all_users_over_limit()) {
            throw new \moodle_exception(
                'schedulerecipientlimit',
                'local_la',
                '',
                self::get_max_recipients()
            );
        }

        if (in_array('self', $types, true) && self::can_receive_email($creator)) {
            $users[(int) $creator->id] = $creator;
        }

        $conditions = [
            'u.deleted = 0',
            'u.suspended = 0',
            'u.confirmed = 1',
            'u.emailstop = 0',
            'u.email <> :emptyemail',
        ];
        $params = ['emptyemail' => '', 'guestid' => (int) ($CFG->siteguest ?? 0)];
        $conditions[] = 'u.id <> :guestid';

        if (in_array('all', $types, true)) {
            $records = $DB->get_records_sql(
                'SELECT u.* FROM {user} u WHERE ' . implode(' AND ', $conditions),
                $params
            );
            $users += $records;
        } else {
            if (in_array('admin', $types, true)) {
                foreach (get_admins() as $admin) {
                    if (self::can_receive_email($admin)) {
                        $users[(int) $admin->id] = $admin;
                    }
                }
            }

            if (in_array('user', $types, true)) {
                $sql = 'SELECT u.*
                          FROM {user} u
                          JOIN {local_la_report_audience} a ON a.instanceid = u.id
                         WHERE a.reportid = :reportid
                           AND a.type = :type
                           AND ' . implode(' AND ', $conditions);
                $users += $DB->get_records_sql($sql, $params + [
                    'reportid' => (int) $schedule->reportid,
                    'type' => 'user',
                ]);
            }

            if (in_array('role', $types, true)) {
                $sql = 'SELECT DISTINCT u.*
                          FROM {user} u
                          JOIN {role_assignments} ra ON ra.userid = u.id
                          JOIN {local_la_report_audience} a ON a.instanceid = ra.roleid
                         WHERE a.reportid = :reportid
                           AND a.type = :type
                           AND ra.contextid = :contextid
                           AND ' . implode(' AND ', $conditions);
                $users += $DB->get_records_sql($sql, $params + [
                    'reportid' => (int) $schedule->reportid,
                    'type' => 'role',
                    'contextid' => (int) \context_system::instance()->id,
                ]);
            }
        }

        foreach ($users as $userid => $user) {
            if (!self::can_receive_email($user)) {
                unset($users[$userid]);
            }
        }

        return array_values($users);
    }

    /**
     * Require an active user-report relation before creating or delivering a schedule.
     *
     * @param int $reportid
     * @param int $userid
     */
    protected static function require_active_report_relation(int $reportid, int $userid): void {
        global $DB;

        if (!$DB->record_exists('local_la_report', ['id' => $reportid]) ||
                !$DB->record_exists('local_la_report_users', [
                    'reportid' => $reportid,
                    'userid' => $userid,
                    'status' => 1,
                ])) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }
    }

    /**
     * Clear failure state after a successful scheduled delivery.
     *
     * @param \stdClass $schedule
     * @param int $now
     */
    protected static function record_success(\stdClass $schedule, int $now): void {
        global $DB;

        $DB->update_record('local_la_report_schedule', (object) [
            'id' => (int) $schedule->id,
            'status' => (string) $schedule->recurrence === 'none' ? 0 : (int) $schedule->status,
            'timelastsent' => $now,
            'failurecount' => 0,
            'timelastattempt' => $now,
            'timenextattempt' => 0,
            'lasterror' => null,
            'timemodified' => $now,
        ]);
    }

    /**
     * Persist delivery failure state and calculate a bounded retry delay.
     *
     * Permanent configuration failures are disabled immediately. Transient
     * failures use exponential backoff and are disabled after MAX_FAILURES.
     *
     * @param \stdClass $schedule
     * @param \Throwable $exception
     * @param int $now
     * @return array{disabled: bool, nextattempt: int}
     */
    protected static function record_failure(\stdClass $schedule, \Throwable $exception, int $now): array {
        global $DB;

        $current = $DB->get_record(
            'local_la_report_schedule',
            ['id' => (int) $schedule->id],
            'id, failurecount',
            IGNORE_MISSING
        );
        if (!$current) {
            return ['disabled' => true, 'nextattempt' => 0];
        }

        $failurecount = (int) $current->failurecount + 1;
        $disabled = self::is_permanent_failure($exception) || $failurecount >= self::MAX_FAILURES;
        $delay = min(
            self::RETRY_MAX_SECONDS,
            self::RETRY_BASE_SECONDS * (2 ** min($failurecount - 1, self::MAX_FAILURES - 1))
        );
        $nextattempt = $disabled ? 0 : $now + $delay;

        $DB->update_record('local_la_report_schedule', (object) [
            'id' => (int) $schedule->id,
            'status' => $disabled ? 0 : 1,
            'failurecount' => $failurecount,
            'timelastattempt' => $now,
            'timenextattempt' => $nextattempt,
            'lasterror' => \core_text::substr(trim($exception->getMessage()), 0, 2000),
        ]);

        return ['disabled' => $disabled, 'nextattempt' => $nextattempt];
    }

    /**
     * Determine whether retrying cannot succeed without configuration changes.
     *
     * @param \Throwable $exception
     * @return bool
     */
    protected static function is_permanent_failure(\Throwable $exception): bool {
        if ($exception instanceof \dml_missing_record_exception) {
            return true;
        }

        return $exception instanceof \moodle_exception && in_array($exception->errorcode, [
            'errorinvalidreportconfig',
            'invalidscheduleformat',
            'nopermissions',
            'schedulerecipientlimit',
        ], true);
    }

    /**
     * Check whether a Moodle user can receive scheduled email.
     *
     * @param \stdClass $user
     * @return bool
     */
    protected static function can_receive_email(\stdClass $user): bool {
        return empty($user->deleted)
            && empty($user->suspended)
            && !empty($user->confirmed)
            && empty($user->emailstop)
            && !empty($user->email);
    }

    /**
     * Generate a report attachment using Moodle's enabled data format plugin.
     *
     * @param \stdClass $schedule
     * @param \stdClass $report
     * @return array
     */
    protected static function create_report_attachment(\stdClass $schedule, \stdClass $report): array {
        global $CFG;

        require_once($CFG->libdir . '/tablelib.php');

        $filename = clean_filename((string) $report->name . '-' . userdate(time(), '%Y%m%d-%H%M'));
        $table = new \local_la\table\report_table((int) $report->id, []);
        $table->download = (string) $schedule->format;
        $table->load($report);
        $table->baseurl = new \moodle_url('/local/la/report.php', ['id' => (int) $report->id]);
        $table->setup();
        $table->query_db(0, false);
        $rows = is_countable($table->rawdata) ? count($table->rawdata) : 0;

        $exportclass = new \table_dataformat_export_format($table, (string) $schedule->format);
        try {
            $path = \core\dataformat::write_data(
                $filename,
                (string) $schedule->format,
                $exportclass->format_data($table->headers),
                $table->rawdata,
                static function(\stdClass $record, bool $supportshtml) use ($table, $exportclass): array {
                    $record = $table->format_row($record);
                    return $supportshtml ? $record : $exportclass->format_data($record);
                }
            );
        } finally {
            $table->close_recordset();
        }

        return [
            'path' => $path,
            'filename' => basename($path),
            'rows' => $rows,
        ];
    }

    /**
     * Get the first recurrence after the last successful send.
     *
     * @param \stdClass $schedule
     * @return int
     */
    protected static function next_occurrence(\stdClass $schedule): int {
        global $CFG;

        $next = (int) $schedule->timestart;
        $lastsent = (int) $schedule->timelastsent;
        $recurrence = (string) $schedule->recurrence;
        $monthlyday = $recurrence === 'monthly' ?
            (int) usergetdate($next, $CFG->timezone)['mday'] : 0;

        if ($lastsent < $next) {
            return $next;
        }

        do {
            $next = self::advance_occurrence($next, $recurrence, $monthlyday);
        } while ($next <= $lastsent);

        return $next;
    }

    /**
     * Advance one timestamp by a supported recurrence in the site timezone.
     *
     * @param int $timestamp
     * @param string $recurrence
     * @param int $monthlyday Original day of month used to prevent month-end drift
     * @return int
     */
    protected static function advance_occurrence(int $timestamp, string $recurrence, int $monthlyday = 0): int {
        global $CFG;

        $date = usergetdate($timestamp, $CFG->timezone);
        if ($recurrence === 'daily') {
            $date['mday']++;
        } else if ($recurrence === 'weekly') {
            $date['mday'] += 7;
        } else if ($recurrence === 'monthly') {
            $monthlyday = $monthlyday > 0 ? $monthlyday : (int) $date['mday'];
            $date['mon']++;
            if ($date['mon'] > 12) {
                $date['mon'] = 1;
                $date['year']++;
            }
            $date['mday'] = min($monthlyday, days_in_month((int) $date['mon'], (int) $date['year']));
        } else {
            return PHP_INT_MAX;
        }

        return make_timestamp(
            (int) $date['year'],
            (int) $date['mon'],
            (int) $date['mday'],
            (int) $date['hours'],
            (int) $date['minutes'],
            0,
            $CFG->timezone
        );
    }

    /**
     * Check whether a data format exists and is enabled.
     *
     * @param string $format
     * @return bool
     */
    protected static function is_enabled_format(string $format): bool {
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('dataformat');
        return isset($plugins[$format]) && $plugins[$format]->is_enabled();
    }

    public static function delete(int $scheduleid): \stdClass {
        global $DB;

        $schedule = self::require_manage($scheduleid);

        $DB->delete_records('local_la_report_schedule', ['id' => $scheduleid]);

        return $schedule;
    }

    protected static function get_options(array $keys, string $prefix, string $selected = ''): array {
        return array_map(function(string $key) use ($prefix, $selected): array {
            return [
                'value' => $key,
                'name' => get_string($prefix . $key, 'local_la'),
                'selected' => $key === $selected,
            ];
        }, $keys);
    }

    protected static function get_format_options(string $selected): array {
        $options = [];

        foreach (\core_plugin_manager::instance()->get_plugins_of_type('dataformat') as $format) {
            if ($format->is_enabled()) {
                $options[] = [
                    'value' => $format->name,
                    'name' => get_string('dataformat', $format->component),
                    'selected' => $format->name === $selected,
                ];
            }
        }

        return $options;
    }

    protected static function get_number_options(int $from, int $to, int $selected, bool $pad = false): array {
        $options = [];

        for ($value = $from; $value <= $to; $value++) {
            $options[] = [
                'value' => $value,
                'name' => $pad ? sprintf('%02d', $value) : (string) $value,
                'selected' => $value === $selected,
            ];
        }

        return $options;
    }

    protected static function get_month_options(int $selected): array {
        $options = [];

        for ($month = 1; $month <= 12; $month++) {
            $options[] = [
                'value' => $month,
                'name' => userdate(make_timestamp(2000, $month, 1), '%B'),
                'selected' => $month === $selected,
            ];
        }

        return $options;
    }

}
