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
 * Tests for schedule audience recipient filtering.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_recipient_test extends advanced_testcase {
    /**
     * Reset database and access state for every test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Explicit recipients require valid email state.
     */
    public function test_explicit_users_are_filtered_by_status(): void {
        global $DB;

        $reportid = $this->create_report();
        $valid = $this->getDataGenerator()->create_user();
        $secondvalid = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $emailstopped = $this->getDataGenerator()->create_user(['emailstop' => 1]);
        $unconfirmed = $this->getDataGenerator()->create_user(['confirmed' => 0]);
        $emptyemail = $this->getDataGenerator()->create_user();
        $deleted = $this->getDataGenerator()->create_user();

        $DB->set_field('user', 'email', '', ['id' => $emptyemail->id]);
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);

        foreach ([$valid, $secondvalid, $suspended, $emailstopped, $unconfirmed, $emptyemail, $deleted] as $user) {
            $this->add_audience($reportid, 'user', (int) $user->id);
        }

        $recipients = $this->get_recipients($reportid, ['user'], $valid);
        $ids = array_map('intval', array_column($recipients, 'id'));
        sort($ids);
        $expected = [(int) $valid->id, (int) $secondvalid->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * Self delivery uses the creator's email eligibility.
     */
    public function test_self_recipient_does_not_require_a_plugin_capability(): void {
        $reportid = $this->create_report();
        $user = $this->getDataGenerator()->create_user();

        $recipients = $this->get_recipients($reportid, ['self'], $user);
        $this->assertSame([(int) $user->id], array_map('intval', array_column($recipients, 'id')));
    }

    /**
     * A system-role audience resolves only eligible role holders.
     */
    public function test_role_audience_resolves_system_role_holders(): void {
        $reportid = $this->create_report();
        $roleid = create_role('Audience role', 'audience_' . random_string(8), '');
        $included = $this->getDataGenerator()->create_user();
        $notassigned = $this->getDataGenerator()->create_user();
        role_assign($roleid, (int) $included->id, \context_system::instance()->id);
        $this->add_audience($reportid, 'role', $roleid);

        $recipients = $this->get_recipients($reportid, ['role'], $included);
        $ids = array_map('intval', array_column($recipients, 'id'));

        $this->assertContains((int) $included->id, $ids);
        $this->assertNotContains((int) $notassigned->id, $ids);
    }

    /**
     * Administrators are eligible through the dedicated admin audience.
     */
    public function test_admin_audience_includes_site_administrator(): void {
        $reportid = $this->create_report();
        $admins = get_admins();
        $admin = reset($admins);

        $recipients = $this->get_recipients($reportid, ['admin'], $admin);

        $this->assertContains((int) $admin->id, array_map('intval', array_column($recipients, 'id')));
    }

    /**
     * All-users delivery is rejected before loading more than the configured ceiling.
     */
    public function test_all_users_audience_is_rejected_above_limit(): void {
        $reportid = $this->create_report();
        $creator = $this->getDataGenerator()->create_user();

        for ($index = 0; $index < schedule::get_max_recipients(); $index++) {
            $this->getDataGenerator()->create_user();
        }

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string(
            'schedulerecipientlimit',
            'local_la',
            schedule::get_max_recipients()
        ));
        $this->get_recipients($reportid, ['all'], $creator);
    }

    /**
     * Create a minimal report row.
     *
     * @return int
     */
    private function create_report(): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_la_report', (object) [
            'name' => 'Recipient report',
            'shortname' => 'recipient_' . random_string(12),
            'info' => '',
            'tags' => '',
            'version' => '1.0',
            'plan' => 'core',
            'report_params' => null,
            'sql_name' => null,
            'timesync' => 0,
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
     * Resolve recipients through the protected production helper.
     *
     * @param int $reportid
     * @param array $audiences
     * @param \stdClass $creator
     * @return array
     */
    private function get_recipients(int $reportid, array $audiences, \stdClass $creator): array {
        $method = new \ReflectionMethod(schedule::class, 'get_recipients');
        $method->setAccessible(true);
        $schedule = (object) [
            'reportid' => $reportid,
            'audiences' => json_encode($audiences),
        ];

        return $method->invoke(null, $schedule, $creator);
    }
}
