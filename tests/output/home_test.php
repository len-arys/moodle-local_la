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

namespace local_la\output;

use advanced_testcase;
use context_system;

/**
 * Tests for home page output.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\output\home
 */
final class home_test extends advanced_testcase {
    /**
     * Empty chat history returns an assistant message.
     */
    public function test_empty_history_returns_message(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(context_system::instance());

        $context = (new home())->export_for_template($PAGE->get_renderer('local_la'));

        $this->assertSame([[
            'text' => get_string('nochathistory', 'local_la'),
            'class' => 'la-ai-report-message-assistant',
            'emptyhistory' => true,
        ]], $context['messages']);
    }
}
