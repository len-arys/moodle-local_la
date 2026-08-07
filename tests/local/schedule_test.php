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
 * Tests for schedule recurrence and lifecycle state.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_test extends advanced_testcase {
    /**
     * Prepare deterministic timezone state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('timezone', 'UTC');
    }

    /**
     * Monthly recurrence clamps short months and returns to the original anchor day.
     */
    public function test_monthly_recurrence_preserves_month_end_anchor(): void {
        $start = make_timestamp(2025, 1, 31, 9, 0, 0, 'UTC');
        $schedule = $this->due_record('monthly', $start, $start);

        $this->assertFalse(schedule::is_due(
            $schedule,
            make_timestamp(2025, 2, 28, 8, 59, 59, 'UTC')
        ));
        $this->assertTrue(schedule::is_due(
            $schedule,
            make_timestamp(2025, 2, 28, 9, 0, 0, 'UTC')
        ));

        $schedule->timelastsent = make_timestamp(2025, 2, 28, 9, 0, 0, 'UTC');
        $this->assertFalse(schedule::is_due(
            $schedule,
            make_timestamp(2025, 3, 30, 23, 59, 59, 'UTC')
        ));
        $this->assertTrue(schedule::is_due(
            $schedule,
            make_timestamp(2025, 3, 31, 9, 0, 0, 'UTC')
        ));
    }

    /**
     * Leap-year February is selected for a January 31 monthly schedule.
     */
    public function test_monthly_recurrence_uses_leap_day(): void {
        $start = make_timestamp(2024, 1, 31, 9, 0, 0, 'UTC');
        $schedule = $this->due_record('monthly', $start, $start);

        $this->assertFalse(schedule::is_due(
            $schedule,
            make_timestamp(2024, 2, 28, 23, 59, 59, 'UTC')
        ));
        $this->assertTrue(schedule::is_due(
            $schedule,
            make_timestamp(2024, 2, 29, 9, 0, 0, 'UTC')
        ));
    }

    /**
     * A January 30 schedule returns to day 30 after February.
     */
    public function test_monthly_recurrence_preserves_non_month_end_anchor(): void {
        $start = make_timestamp(2025, 1, 30, 9, 0, 0, 'UTC');
        $schedule = $this->due_record(
            'monthly',
            $start,
            make_timestamp(2025, 2, 28, 9, 0, 0, 'UTC')
        );

        $this->assertFalse(schedule::is_due(
            $schedule,
            make_timestamp(2025, 3, 29, 23, 59, 59, 'UTC')
        ));
        $this->assertTrue(schedule::is_due(
            $schedule,
            make_timestamp(2025, 3, 30, 9, 0, 0, 'UTC')
        ));
    }

    /**
     * Daily and weekly recurrence preserve their configured local time.
     */
    public function test_daily_and_weekly_recurrence(): void {
        $start = make_timestamp(2025, 4, 1, 13, 15, 0, 'UTC');

        $daily = $this->due_record('daily', $start, $start);
        $this->assertFalse(schedule::is_due($daily, $start + DAYSECS - 1));
        $this->assertTrue(schedule::is_due($daily, $start + DAYSECS));

        $weekly = $this->due_record('weekly', $start, $start);
        $this->assertFalse(schedule::is_due($weekly, $start + WEEKSECS - 1));
        $this->assertTrue(schedule::is_due($weekly, $start + WEEKSECS));
    }

    /**
     * Disabled, future, delivered one-time, and backoff schedules are not due.
     */
    public function test_due_guards(): void {
        $now = time();

        $disabled = $this->due_record('none', $now - 10, 0);
        $disabled->status = 0;
        $this->assertFalse(schedule::is_due($disabled, $now));

        $future = $this->due_record('none', $now + 10, 0);
        $this->assertFalse(schedule::is_due($future, $now));

        $delivered = $this->due_record('none', $now - 20, $now - 10);
        $this->assertFalse(schedule::is_due($delivered, $now));

        $backoff = $this->due_record('daily', $now - DAYSECS, $now - DAYSECS);
        $backoff->timenextattempt = $now + 10;
        $this->assertFalse(schedule::is_due($backoff, $now));
    }

    /**
     * Successful one-time schedules deactivate while recurring schedules remain active.
     */
    public function test_success_lifecycle_by_recurrence(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report();
        $now = time();

        $onetimeid = $this->create_schedule($reportid, (int) $user->id, 'none');
        $onetime = $DB->get_record('local_la_report_schedule', ['id' => $onetimeid], '*', MUST_EXIST);
        $this->invoke('record_success', [$onetime, $now]);
        $onetime = $DB->get_record('local_la_report_schedule', ['id' => $onetimeid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $onetime->status);
        $this->assertSame($now, (int) $onetime->timelastsent);
        $this->assertSame(0, (int) $onetime->failurecount);

        $dailyid = $this->create_schedule($reportid, (int) $user->id, 'daily');
        $daily = $DB->get_record('local_la_report_schedule', ['id' => $dailyid], '*', MUST_EXIST);
        $this->invoke('record_success', [$daily, $now]);
        $this->assertSame(1, (int) $DB->get_field('local_la_report_schedule', 'status', ['id' => $dailyid]));
    }

    /**
     * Transient failures back off and permanent failures disable immediately.
     */
    public function test_failure_backoff_and_permanent_disable(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report();
        $now = time();

        $transientid = $this->create_schedule($reportid, (int) $user->id, 'daily');
        $transient = $DB->get_record('local_la_report_schedule', ['id' => $transientid], '*', MUST_EXIST);
        $result = $this->invoke('record_failure', [$transient, new \RuntimeException('Temporary mail failure'), $now]);
        $transient = $DB->get_record('local_la_report_schedule', ['id' => $transientid], '*', MUST_EXIST);
        $this->assertFalse($result['disabled']);
        $this->assertSame(1, (int) $transient->status);
        $this->assertSame(1, (int) $transient->failurecount);
        $this->assertSame($now + 300, (int) $transient->timenextattempt);

        $permanentid = $this->create_schedule($reportid, (int) $user->id, 'daily');
        $permanent = $DB->get_record('local_la_report_schedule', ['id' => $permanentid], '*', MUST_EXIST);
        $result = $this->invoke('record_failure', [
            $permanent,
            new \moodle_exception('nopermissions', 'error'),
            $now,
        ]);
        $this->assertTrue($result['disabled']);
        $this->assertSame(0, (int) $DB->get_field(
            'local_la_report_schedule',
            'status',
            ['id' => $permanentid]
        ));
    }

    /**
     * A due schedule whose creator-report relation disappeared is disabled by cron.
     */
    public function test_orphaned_schedule_is_disabled_by_cron(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report();
        $scheduleid = $this->create_schedule($reportid, (int) $user->id, 'none', time() - 60);

        $this->expectOutputRegex('/local_la schedule .* failed \(disabled\):/');
        $result = schedule::process_due_schedules();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['disabled']);
        $record = $DB->get_record('local_la_report_schedule', ['id' => $scheduleid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $record->status);
        $this->assertSame(1, (int) $record->failurecount);
        $this->assertStringContainsString(
            get_string('errorinvalidreportconfig', 'local_la'),
            (string) $record->lasterror
        );
    }

    /**
     * Build the fields needed by schedule::is_due().
     *
     * @param string $recurrence
     * @param int $timestart
     * @param int $timelastsent
     * @return \stdClass
     */
    private function due_record(string $recurrence, int $timestart, int $timelastsent): \stdClass {
        return (object) [
            'status' => 1,
            'timestart' => $timestart,
            'timelastsent' => $timelastsent,
            'timenextattempt' => 0,
            'recurrence' => $recurrence,
        ];
    }

    /**
     * Create a minimal report row.
     *
     * @return int
     */
    private function create_report(): int {
        global $DB;

        $now = time();
        $shortname = 'test_' . random_string(12);

        return (int) $DB->insert_record('local_la_report', (object) [
            'name' => 'Test report',
            'shortname' => $shortname,
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
     * Create a schedule row.
     *
     * @param int $reportid
     * @param int $userid
     * @param string $recurrence
     * @param int|null $timestart
     * @return int
     */
    private function create_schedule(
        int $reportid,
        int $userid,
        string $recurrence,
        ?int $timestart = null
    ): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_la_report_schedule', (object) [
            'reportid' => $reportid,
            'name' => 'Test schedule',
            'format' => 'csv',
            'timestart' => $timestart ?? $now,
            'recurrence' => $recurrence,
            'subject' => 'Subject',
            'body' => 'Body',
            'audiences' => json_encode(['self']),
            'emptyreport' => 'send',
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

    /**
     * Invoke one protected static schedule helper.
     *
     * @param string $methodname
     * @param array $arguments
     * @return mixed
     */
    private function invoke(string $methodname, array $arguments) {
        $method = new \ReflectionMethod(schedule::class, $methodname);
        $method->setAccessible(true);

        return $method->invokeArgs(null, $arguments);
    }
}
