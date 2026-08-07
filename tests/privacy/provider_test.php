<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

declare(strict_types=1);

namespace local_la\privacy;

use advanced_testcase;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Tests for the local_la privacy provider.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends advanced_testcase {
    /**
     * Reset database and privacy writer state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        writer::reset();
    }

    /**
     * External data transfers are declared in the privacy metadata.
     */
    public function test_external_locations_are_declared(): void {
        $items = provider::get_metadata(new collection('local_la'))->get_collection();
        $locations = [];

        foreach ($items as $item) {
            if ($item instanceof external_location) {
                $locations[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }

        $this->assertSame(
            ['license', 'url', 'moodleversion', 'pluginversion', 'report', 'app'],
            $locations['lenarys_api']
        );
        $this->assertSame(['prompttext'], $locations['ai_provider']);
    }

    /**
     * User deletion removes dependent aggregates but preserves a page used by another user.
     */
    public function test_delete_user_tracking_preserves_shared_page(): void {
        global $DB;

        $deleteduser = $this->getDataGenerator()->create_user();
        $retaineduser = $this->getDataGenerator()->create_user();
        $pageid = $this->create_page('course', 501, 501);
        $deletedrecords = $this->create_tracking((int) $deleteduser->id, $pageid, 2, 30);
        $retainedrecords = $this->create_tracking((int) $retaineduser->id, $pageid, 1, 15);

        $contextlist = new approved_contextlist(
            $deleteduser,
            'local_la',
            [\context_system::instance()->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('local_la_time_total', ['id' => $deletedrecords['totalid']]));
        $this->assertFalse($DB->record_exists('local_la_time_day', ['id' => $deletedrecords['dayid']]));
        $this->assertFalse($DB->record_exists('local_la_time_hour', ['id' => $deletedrecords['hourid']]));
        $this->assertTrue($DB->record_exists('local_la_time_total', ['id' => $retainedrecords['totalid']]));
        $this->assertTrue($DB->record_exists('local_la_time_page', ['id' => $pageid]));
    }

    /**
     * A page row is removed after deletion of its final user aggregate.
     */
    public function test_delete_user_tracking_removes_orphan_page(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $pageid = $this->create_page('activity', 502, 501);
        $this->create_tracking((int) $user->id, $pageid, 1, 8);

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'local_la',
            [\context_system::instance()->id]
        ));

        $this->assertFalse($DB->record_exists('local_la_time_page', ['id' => $pageid]));
    }

    /**
     * System-context deletion removes all tracking aggregates in dependency order.
     */
    public function test_delete_all_users_in_system_context(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $pageid = $this->create_page('course', 503, 503);
        $this->create_tracking((int) $user->id, $pageid, 1, 20);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        foreach (['local_la_time_hour', 'local_la_time_day', 'local_la_time_total', 'local_la_time_page'] as $table) {
            $this->assertSame(0, $DB->count_records($table));
        }
    }

    /**
     * Tracking totals, days, and hours are included in a user privacy export.
     */
    public function test_export_user_tracking_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $pageid = $this->create_page('course', 504, 504);
        $this->create_tracking((int) $user->id, $pageid, 3, 45);
        $context = \context_system::instance();

        provider::export_user_data(new approved_contextlist($user, 'local_la', [$context->id]));

        $writer = writer::with_context($context);
        $path = [get_string('pluginname', 'local_la')];
        $tracking = $writer->get_related_data($path, 'time_tracking');
        $days = $writer->get_related_data($path, 'time_tracking_days');
        $hours = $writer->get_related_data($path, 'time_tracking_hours');

        $this->assertCount(1, $tracking->records);
        $this->assertSame(3, (int) $tracking->records[0]->visits);
        $this->assertSame(45, (int) $tracking->records[0]->timesec);
        $this->assertCount(1, $days->records);
        $this->assertCount(1, $hours->records);
    }

    /**
     * Create a canonical tracked page.
     *
     * @param string $name
     * @param int $instanceid
     * @param int $courseid
     * @return int
     */
    private function create_page(string $name, int $instanceid, int $courseid): int {
        global $DB;

        return (int) $DB->insert_record('local_la_time_page', (object) [
            'name' => $name,
            'instanceid' => $instanceid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * Create total, day, and hour tracking rows.
     *
     * @param int $userid
     * @param int $pageid
     * @param int $visits
     * @param int $seconds
     * @return array
     */
    private function create_tracking(int $userid, int $pageid, int $visits, int $seconds): array {
        global $DB;

        $now = time();
        $totalid = (int) $DB->insert_record('local_la_time_total', (object) [
            'userid' => $userid,
            'pageid' => $pageid,
            'visits' => $visits,
            'timesec' => $seconds,
            'params' => '{}',
            'firstaccess' => $now,
            'lastaccess' => $now,
        ]);
        $dayid = (int) $DB->insert_record('local_la_time_day', (object) [
            'totalid' => $totalid,
            'daystamp' => usergetmidnight($now),
            'visits' => $visits,
            'timesec' => $seconds,
        ]);
        $hourid = (int) $DB->insert_record('local_la_time_hour', (object) [
            'dayid' => $dayid,
            'hour' => (int) userdate($now, '%H'),
            'visits' => $visits,
            'timesec' => $seconds,
        ]);

        return [
            'totalid' => $totalid,
            'dayid' => $dayid,
            'hourid' => $hourid,
        ];
    }
}
