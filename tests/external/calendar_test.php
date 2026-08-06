<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

declare(strict_types=1);

namespace local_la\external;

use advanced_testcase;

/**
 * Tests for calendar API access control.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_test extends advanced_testcase {
    /**
     * Prepare an active calendar feature.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('licenseplantime', time() + DAYSECS, 'local_la');
        set_config('licensefeatures', json_encode(['calendar' => true]), 'local_la');
    }

    /**
     * View-only users cannot access broad tracking scopes.
     */
    public function test_user_without_manage_access_cannot_access_broad_scopes(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $denied = 0;

        foreach (['all', 'user', 'course', 'user_course', 'activity', 'user_activity'] as $scope) {
            try {
                calendar::execute('timesec', $scope, (int) $user->id);
            } catch (\required_capability_exception) {
                $denied++;
            }
        }

        $this->assertSame(6, $denied);
    }

    /**
     * Manage capability permits broad tracking scopes.
     */
    public function test_manage_capability_can_access_broad_scope(): void {
        $user = $this->create_manager_user();
        $this->setUser($user);

        $result = calendar::execute('timesec', 'all');

        $this->assertArrayHasKey('html', $result);
    }

    /**
     * Report audience members cannot access report-page calendars by default.
     */
    public function test_report_audience_cannot_access_report_page_scope_by_default(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report();
        $now = time();
        $DB->insert_record('local_la_report_audience', (object) [
            'reportid' => $reportid,
            'type' => 'user',
            'instanceid' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        calendar::execute(
            'timesec',
            'report_page',
            (int) $user->id,
            0,
            0,
            'month',
            0,
            0,
            0,
            'la_report',
            $reportid
        );
    }

    /**
     * The security setting permits audience report-page calendars.
     */
    public function test_setting_allows_report_audience_report_page_scope(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report();
        $now = time();
        $DB->insert_record('local_la_report_audience', (object) [
            'reportid' => $reportid,
            'type' => 'user',
            'instanceid' => $user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        set_config('allowaudiencedrilldown', 1, 'local_la');
        $this->setUser($user);

        $result = calendar::execute(
            'timesec',
            'report_page',
            (int) $user->id,
            0,
            0,
            'month',
            0,
            0,
            0,
            'la_report',
            $reportid
        );

        $this->assertArrayHasKey('html', $result);
    }

    /**
     * Report-page scope still enforces report audience access.
     */
    public function test_non_audience_user_cannot_access_report_page_scope(): void {
        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report();
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        calendar::execute(
            'timesec',
            'report_page',
            (int) $user->id,
            0,
            0,
            'month',
            0,
            0,
            0,
            'la_report',
            $reportid
        );
    }

    /**
     * Create a user with management access.
     *
     * @return \stdClass
     */
    private function create_manager_user(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Calendar test role', 'calendar_' . random_string(8), '');
        $systemcontext = \context_system::instance();
        assign_capability('local/la:manage', CAP_ALLOW, $roleid, $systemcontext->id, true);
        role_assign($roleid, $user->id, $systemcontext->id);

        return $user;
    }

    /**
     * Create a minimal report.
     *
     * @return int
     */
    private function create_report(): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_la_report', (object) [
            'name' => 'Calendar access report',
            'shortname' => 'calendar_' . random_string(12),
            'version' => '1.0',
            'plan' => 'core',
            'timesync' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
