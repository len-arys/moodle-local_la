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

declare(strict_types=1);

namespace local_la\local;

use advanced_testcase;

/**
 * Tests for server-side learning time tracking.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\local\tracker
 */
final class tracker_test extends advanced_testcase {
    /**
     * Prepare isolated session and configuration state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('learningtimeinterval', 30, 'local_la');
        $this->reset_tracking_session();
    }

    /**
     * An initial heartbeat records a visit and later accepted time updates the same visit.
     */
    public function test_immediate_visit_and_short_duration(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $page = $this->page('course', 101, 101);
        $token = $this->create_token((int) $user->id, $page);

        tracker::track((int) $user->id, $token, 0);
        $total = $this->get_total((int) $user->id, $page);
        $this->assertSame(1, (int) $total->visits);
        $this->assertSame(0, (int) $total->timesec);

        $cache = \cache::make('local_la', 'tracking');
        $tokens = $cache->get('tokens');
        $tokens[$token]['lastheartbeat'] = time() - 3;
        $cache->set('tokens', $tokens);
        $cache->set('lastaccounted', time() - 3);
        tracker::track((int) $user->id, $token, 120);

        $total = $DB->get_record('local_la_time_total', ['id' => $total->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $total->visits);
        $this->assertSame(3, (int) $total->timesec);
        $this->assertSame(1, $DB->count_records('local_la_time_day', ['totalid' => $total->id]));
        $this->assertSame(1, $DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {local_la_time_hour} h
               JOIN {local_la_time_day} d ON d.id = h.dayid
              WHERE d.totalid = :totalid',
            ['totalid' => $total->id]
        ));
    }

    /**
     * Replaying one token does not duplicate its visit or add unelapsed time.
     */
    public function test_replay_does_not_duplicate_visit_or_time(): void {
        $user = $this->getDataGenerator()->create_user();
        $page = $this->page('activity', 202, 101);
        $token = $this->create_token((int) $user->id, $page);

        tracker::track((int) $user->id, $token, 0);
        tracker::track((int) $user->id, $token, 120);
        tracker::track((int) $user->id, $token, 120);

        $total = $this->get_total((int) $user->id, $page);
        $this->assertSame(1, (int) $total->visits);
        $this->assertSame(0, (int) $total->timesec);
    }

    /**
     * Multiple page tokens share one session-wide wall-clock allowance.
     */
    public function test_parallel_tokens_share_session_time_budget(): void {
        $user = $this->getDataGenerator()->create_user();
        $page = $this->page('course', 303, 303);
        $first = $this->create_token((int) $user->id, $page);
        $second = $this->create_token((int) $user->id, $page);

        tracker::track((int) $user->id, $first, 0);
        tracker::track((int) $user->id, $second, 0);
        $cache = \cache::make('local_la', 'tracking');
        $tokens = $cache->get('tokens');
        foreach ([$first, $second] as $token) {
            $tokens[$token]['lastheartbeat'] = time() - 30;
        }
        $cache->set('tokens', $tokens);
        $cache->set('lastaccounted', time() - 30);

        tracker::track((int) $user->id, $first, 30);
        tracker::track((int) $user->id, $second, 30);

        $total = $this->get_total((int) $user->id, $page);
        $this->assertSame(2, (int) $total->visits);
        $this->assertSame(30, (int) $total->timesec);
    }

    /**
     * A token cannot be used by a different authenticated user.
     */
    public function test_token_is_bound_to_user(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $token = $this->create_token((int) $owner->id, $this->page('site', 0, 0));

        $this->expectException(\invalid_parameter_exception::class);
        tracker::track((int) $other->id, $token, 0);
    }

    /**
     * Expired and unknown tokens are rejected.
     */
    public function test_expired_token_is_rejected(): void {
        $user = $this->getDataGenerator()->create_user();
        $token = $this->create_token((int) $user->id, $this->page('site', 0, 0));
        $cache = \cache::make('local_la', 'tracking');
        $tokens = $cache->get('tokens');
        $tokens[$token]['created'] = time() - DAYSECS - 1;
        $cache->set('tokens', $tokens);

        $this->expectException(\invalid_parameter_exception::class);
        tracker::track((int) $user->id, $token, 0);
    }

    /**
     * Canonical page metadata comes from the server-side token state.
     */
    public function test_token_controls_page_identity(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $page = $this->page('la_report', 404, 0);
        $token = $this->create_token((int) $user->id, $page);

        tracker::track((int) $user->id, $token, 0);

        $this->assertTrue($DB->record_exists('local_la_time_page', $page));
        $this->assertSame(1, $DB->count_records('local_la_time_page'));
    }

    /**
     * Create a server-side tracking token through the protected production helper.
     *
     * @param int $userid
     * @param array $page
     * @return string
     */
    private function create_token(int $userid, array $page): string {
        $method = new \ReflectionMethod(tracker::class, 'create_session_token');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $userid, $page);
    }

    /**
     * Reset per-request Moodle session tracking state.
     */
    private function reset_tracking_session(): void {
        \cache::make('local_la', 'tracking')->purge();
    }

    /**
     * Build canonical page metadata.
     *
     * @param string $name
     * @param int $instanceid
     * @param int $courseid
     * @return array
     */
    private function page(string $name, int $instanceid, int $courseid): array {
        return [
            'name' => $name,
            'instanceid' => $instanceid,
            'courseid' => $courseid,
        ];
    }

    /**
     * Get one aggregate for its canonical page.
     *
     * @param int $userid
     * @param array $page
     * @return \stdClass
     */
    private function get_total(int $userid, array $page): \stdClass {
        global $DB;

        $pageid = $DB->get_field('local_la_time_page', 'id', $page, MUST_EXIST);

        return $DB->get_record(
            'local_la_time_total',
            ['userid' => $userid, 'pageid' => $pageid],
            '*',
            MUST_EXIST
        );
    }
}
