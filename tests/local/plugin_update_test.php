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
use local_la\output\preferences\general as preferences_general;

/**
 * Tests for plugin update metadata.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugin_update_test extends advanced_testcase {
    /**
     * Reset configuration before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('version', 2026080302, 'local_la');
    }

    /**
     * Only a newer published version is an update.
     */
    public function test_update_requires_newer_published_version(): void {
        $cases = [
            ['version' => '2026080303', 'status' => 'published', 'expected' => true],
            ['version' => '2026080302', 'status' => 'published', 'expected' => false],
            ['version' => '2026080301', 'status' => 'published', 'expected' => false],
            ['version' => '2026080303', 'status' => 'draft', 'expected' => false],
            ['version' => 'invalid', 'status' => 'published', 'expected' => false],
        ];

        foreach ($cases as $case) {
            $license = api::apply_license_payload($this->get_payload([
                'version' => $case['version'],
                'updates' => ['Bug fixes'],
                'released' => '2026-08-04T15:00:00.000000Z',
                'status' => $case['status'],
            ]));

            $this->assertSame($case['expected'], $license['hasupdate']);
            $this->assertSame(['Bug fixes'], $license['updates']);
        }
    }

    /**
     * Preferences includes versions and the localized release date.
     */
    public function test_preferences_show_update_details(): void {
        set_config('api', helper::API_MODE_LOCAL, 'local_la');
        $released = '2026-08-04T15:00:00.000000Z';
        $payload = $this->get_payload([
            'version' => '2026080303',
            'updates' => [],
            'released' => $released,
            'status' => 'published',
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_la',
            'filearea' => 'licensefile',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'license.json',
        ], json_encode($payload));

        $license = preferences_general::get_context()['license'];
        $expecteddate = userdate(strtotime($released), get_string('strftimedate', 'langconfig'));

        $this->assertTrue($license['showupdates']);
        $this->assertFalse($license['hasupdates']);
        $this->assertTrue($license['hasupdate']);
        $this->assertTrue($license['hasupdateurl']);
        $defaults = require(__DIR__ . '/../../config.php');
        $this->assertSame($defaults['downloadurl'], $license['updateurl']);
        $this->assertSame(get_string('updateversiondetails', 'local_la', (object) [
            'current' => '2026080302',
            'available' => '2026080303',
            'released' => $expecteddate,
        ]), $license['updatedetails']);
    }

    /**
     * An equal version does not show updates even when release notes exist.
     */
    public function test_preferences_hide_updates_when_version_is_not_newer(): void {
        set_config('api', helper::API_MODE_LOCAL, 'local_la');
        $payload = $this->get_payload([
            'version' => '2026080302',
            'updates' => ['Bug fixes'],
            'released' => '2026-08-04T15:00:00.000000Z',
            'status' => 'published',
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_la',
            'filearea' => 'licensefile',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'license.json',
        ], json_encode($payload));

        $license = preferences_general::get_context()['license'];

        $this->assertFalse($license['showupdates']);
        $this->assertFalse($license['hasupdate']);
        $this->assertTrue($license['hasupdates']);
    }

    /**
     * Build a valid license payload.
     *
     * @param array $plugin Plugin metadata.
     * @return array
     */
    private function get_payload(array $plugin): array {
        return [
            'license' => 'test-license',
            'status' => 'active',
            'plan' => helper::DP,
            'plugin' => $plugin,
        ];
    }
}
