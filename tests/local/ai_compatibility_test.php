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
use local_la\external\ai;
use local_la\output\preferences\general as preferences_general;

/**
 * Tests for Moodle AI provider version compatibility.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\local\helper
 * @covers     \local_la\external\ai
 * @covers     \local_la\output\preferences\general
 */
final class ai_compatibility_test extends advanced_testcase {
    /**
     * Reset configuration and use an administrator identity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The shared compatibility check uses the Moodle 4.5 boundary.
     */
    public function test_ai_provider_version_boundary(): void {
        global $CFG;

        $CFG->version = helper::MOODLE_AI_MIN_VERSION - 1;
        $this->assertFalse(helper::supports_ai_providers());

        $CFG->version = helper::MOODLE_AI_MIN_VERSION;
        $this->assertTrue(helper::supports_ai_providers());
    }

    /**
     * AI submission fails before accessing unavailable Moodle AI classes.
     */
    public function test_generate_report_rejects_unsupported_moodle_version(): void {
        global $CFG;

        $CFG->version = helper::MOODLE_AI_MIN_VERSION - 1;

        try {
            ai::generate_report('Create a course activity report');
            $this->fail('AI generation should reject unsupported Moodle versions.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('erroraimoodleversion', $exception->errorcode);
            $this->assertSame(
                get_string('erroraimoodleversion', 'local_la'),
                $exception->getMessage()
            );
        }
    }

    /**
     * Preferences replaces the provider link with the compatibility message.
     */
    public function test_preferences_hide_ai_provider_link_on_unsupported_version(): void {
        global $CFG;

        $CFG->version = helper::MOODLE_AI_MIN_VERSION - 1;
        set_config('api', helper::API_MODE_LOCAL, 'local_la');

        $context = preferences_general::get_context();
        $settings = $context['settings'];

        $this->assertFalse($settings['hasaiproviders']);
        $this->assertSame('', $settings['aiprovidertext']);
        $this->assertSame('', $settings['aiproviderurl']);
        $this->assertSame(
            get_string('erroraimoodleversion', 'local_la'),
            $settings['aiproviderunsupportedmessage']
        );
    }

    /**
     * Supported versions retain the Moodle AI provider settings link.
     */
    public function test_preferences_show_ai_provider_link_on_supported_version(): void {
        global $CFG;

        $CFG->version = helper::MOODLE_AI_MIN_VERSION;
        set_config('api', helper::API_MODE_LOCAL, 'local_la');

        $context = preferences_general::get_context();
        $settings = $context['settings'];

        $this->assertTrue($settings['hasaiproviders']);
        $this->assertSame(get_string('availableproviders', 'ai'), $settings['aiprovidertext']);
        $this->assertStringContainsString('section=aiprovider', $settings['aiproviderurl']);
        $this->assertSame('', $settings['aiproviderunsupportedmessage']);
    }
}
