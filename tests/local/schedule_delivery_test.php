<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

declare(strict_types=1);

namespace local_la\local;

use advanced_testcase;

/**
 * Integration tests for scheduled report delivery.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_delivery_test extends advanced_testcase {
    /**
     * Prepare an active core license and administrator identity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('licenseplan', 'core', 'local_la');
        set_config('licensestatus', 'active', 'local_la');
        set_config('licenseplantime', time() + DAYSECS, 'local_la');
    }

    /**
     * A successful one-time delivery sends one email and deactivates the schedule.
     */
    public function test_one_time_delivery_sends_email_and_deactivates(): void {
        global $DB, $USER;

        $reportid = $this->create_report('u.id > 0');
        $this->add_report_user($reportid, (int) $USER->id);
        $scheduleid = $this->create_schedule($reportid, (int) $USER->id, 'send');
        $sink = $this->redirectEmails();

        $result = schedule::send($scheduleid);

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Scheduled test report', $messages[0]->subject);
        $this->assertSame(1, (int) $result->deliverysent);
        $this->assertGreaterThan(0, (int) $result->deliveryrows);
        $record = $DB->get_record('local_la_report_schedule', ['id' => $scheduleid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $record->status);
        $this->assertGreaterThan(0, (int) $record->timelastsent);
        $sink->close();
    }

    /**
     * An empty report configured to skip sends no email but completes its one-time lifecycle.
     */
    public function test_empty_report_skip_deactivates_without_email(): void {
        global $DB, $USER;

        $reportid = $this->create_report('u.id < 0');
        $this->add_report_user($reportid, (int) $USER->id);
        $scheduleid = $this->create_schedule($reportid, (int) $USER->id, 'skip');
        $sink = $this->redirectEmails();

        $result = schedule::send($scheduleid);

        $this->assertCount(0, $sink->get_messages());
        $this->assertTrue((bool) $result->deliveryskipped);
        $this->assertSame(0, (int) $result->deliverysent);
        $record = $DB->get_record('local_la_report_schedule', ['id' => $scheduleid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $record->status);
        $this->assertGreaterThan(0, (int) $record->timelastsent);
        $sink->close();
    }

    /**
     * One invalid recipient does not prevent delivery to other eligible recipients.
     */
    public function test_partial_email_failure_keeps_successful_delivery(): void {
        global $DB, $USER;

        $generator = $this->getDataGenerator();
        $validuser = $generator->create_user();
        $invaliduser = $generator->create_user();
        $invaliduser->email = 'not-an-email';
        $DB->set_field('user', 'email', $invaliduser->email, ['id' => $invaliduser->id]);

        $reportid = $this->create_report('u.id > 0');
        $this->add_report_user($reportid, (int) $USER->id);
        $this->add_audience($reportid, 'user', (int) $validuser->id);
        $this->add_audience($reportid, 'user', (int) $invaliduser->id);
        $scheduleid = $this->create_schedule($reportid, (int) $USER->id, 'send', ['user']);
        $sink = $this->redirectEmails();

        $result = schedule::send($scheduleid);

        $this->assertDebuggingCalled();
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(1, (int) $result->deliverysent);
        $record = $DB->get_record('local_la_report_schedule', ['id' => $scheduleid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $record->status);
        $this->assertGreaterThan(0, (int) $record->timelastsent);
        $sink->close();
    }

    /**
     * Create a minimal report and SQL definition suitable for table export.
     *
     * @param string $condition
     * @return int
     */
    private function create_report(string $condition): int {
        global $DB;

        $now = time();
        $sqlname = 'delivery_sql_' . random_string(10);
        $DB->insert_record('local_la_sql', (object) [
            'name' => $sqlname,
            'code' => 'SELECT SQL_COLUMNS FROM {user} u SQL_JOIN WHERE ' . $condition . ' SQL_WHERE',
            'status' => 1,
            'version' => '1.0',
            'timeactivated' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $params = [
            'has_checkbox' => false,
            'columns' => [
                'id' => [
                    'enabled' => true,
                    'name' => 'ID',
                    'order' => 10,
                    'visible' => true,
                    'sortable' => true,
                    'type' => 'numeric',
                    'sql' => [
                        'table' => 'user',
                        'column' => 'id',
                        'require' => '',
                        'source' => '',
                        'where' => '',
                    ],
                ],
            ],
        ];

        return (int) $DB->insert_record('local_la_report', (object) [
            'name' => 'Delivery report',
            'shortname' => 'delivery_' . random_string(10),
            'info' => '',
            'tags' => '',
            'version' => '1.0',
            'plan' => 'core',
            'report_params' => json_encode($params),
            'sql_name' => $sqlname,
            'timesync' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Add an active user-report relation.
     *
     * @param int $reportid
     * @param int $userid
     */
    private function add_report_user(int $reportid, int $userid): void {
        global $DB;

        $now = time();
        $DB->insert_record('local_la_report_users', (object) [
            'reportid' => $reportid,
            'status' => 1,
            'userid' => $userid,
            'favorite' => 0,
            'user_params' => null,
            'timeaccess' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Add one report audience rule.
     *
     * @param int $reportid
     * @param string $type
     * @param int $instanceid
     */
    private function add_audience(int $reportid, string $type, int $instanceid): void {
        global $DB;

        $now = time();
        $DB->insert_record('local_la_report_audience', (object) [
            'reportid' => $reportid,
            'type' => $type,
            'instanceid' => $instanceid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Create a one-time self-delivery schedule.
     *
     * @param int $reportid
     * @param int $userid
     * @param string $emptyreport
     * @param array $audiences
     * @return int
     */
    private function create_schedule(
        int $reportid,
        int $userid,
        string $emptyreport,
        array $audiences = ['self']
    ): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_la_report_schedule', (object) [
            'reportid' => $reportid,
            'name' => 'Delivery schedule',
            'format' => 'csv',
            'timestart' => $now,
            'recurrence' => 'none',
            'subject' => 'Scheduled test report',
            'body' => '<p>Attached report.</p>',
            'audiences' => json_encode($audiences),
            'emptyreport' => $emptyreport,
            'status' => 1,
            'timelastsent' => 0,
            'failurecount' => 0,
            'timelastattempt' => 0,
            'timenextattempt' => 0,
            'lasterror' => null,
            'usercreated' => $userid,
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
