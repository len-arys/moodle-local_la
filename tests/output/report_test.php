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

/**
 * Tests for report output.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\output\report
 */
final class report_test extends advanced_testcase {
    /**
     * Literal filter labels are not treated as language identifiers.
     */
    public function test_filter_options_accept_literal_labels(): void {
        $report = new class ((object) ['params' => []]) extends report {
            /**
             * Get report filter options.
             *
             * @param array $definition Filter definition.
             * @return array
             */
            public function get_filter_options_for_test(array $definition): array {
                return $this->get_filter_select_options($definition);
            }
        };

        $this->assertSame([
            'translated' => 'Yes',
            'literal' => 'Custom label',
        ], $report->get_filter_options_for_test([
            'options' => [
                'translated' => 'yes',
                'literal' => 'Custom label',
            ],
        ]));
    }
}
