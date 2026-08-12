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

namespace local_la\external;

use advanced_testcase;

/**
 * Tests for report sharing access control.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\external\share
 */
final class share_test extends advanced_testcase {
    /**
     * Prepare isolated sharing state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Audience sharing is disabled by default.
     */
    public function test_audience_sharing_is_disabled_by_default(): void {
        $sender = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $reportid = $this->create_audience_report((int) $sender->id);
        $this->setUser($sender);

        $this->expectException(\moodle_exception::class);
        $this->share($reportid, (string) $recipient->email);
    }

    /**
     * Enabled audience sharing accepts active Moodle users.
     */
    public function test_enabled_audience_sharing_accepts_active_moodle_user(): void {
        $sender = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $reportid = $this->create_audience_report((int) $sender->id);
        set_config('allowaudiencesharing', 1, 'local_la');
        $this->setUser($sender);
        $sink = $this->redirectEmails();

        $result = $this->share($reportid, (string) $recipient->email);

        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * Enabled audience sharing rejects external recipients.
     */
    public function test_enabled_audience_sharing_rejects_external_recipient(): void {
        $sender = $this->getDataGenerator()->create_user();
        $reportid = $this->create_audience_report((int) $sender->id);
        set_config('allowaudiencesharing', 1, 'local_la');
        $this->setUser($sender);

        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage(get_string('shareinternalrecipientsonly', 'local_la'));
        $this->share($reportid, 'outside@example.com');
    }

    /**
     * Enabled audience sharing rejects suspended Moodle users.
     */
    public function test_enabled_audience_sharing_rejects_suspended_user(): void {
        $sender = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $reportid = $this->create_audience_report((int) $sender->id);
        set_config('allowaudiencesharing', 1, 'local_la');
        $this->setUser($sender);

        $this->expectException(\invalid_parameter_exception::class);
        $this->share($reportid, (string) $recipient->email);
    }

    /**
     * Managers can share externally without enabling audience sharing.
     */
    public function test_manager_can_share_with_external_recipient(): void {
        $this->setAdminUser();
        $reportid = $this->create_report();
        $sink = $this->redirectEmails();

        $result = $this->share($reportid, 'outside@example.com');

        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * Share one report row.
     *
     * @param int $reportid
     * @param string $email
     * @return array
     */
    private function share(int $reportid, string $email): array {
        return share::report(
            $reportid,
            $email,
            'Report data',
            'Please review this row.',
            ['Name'],
            [['Example']]
        );
    }

    /**
     * Create a report assigned to one audience user.
     *
     * @param int $userid
     * @return int
     */
    private function create_audience_report(int $userid): int {
        global $DB;

        $reportid = $this->create_report();
        $now = time();
        $DB->insert_record('local_la_report_audience', (object) [
            'reportid' => $reportid,
            'type' => 'user',
            'instanceid' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return $reportid;
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
            'name' => 'Share access report',
            'shortname' => 'share_' . random_string(12),
            'version' => '1.0',
            'plan' => 'core',
            'timesync' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
