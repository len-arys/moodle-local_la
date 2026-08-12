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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_la\output\preferences;

use local_la\local\helper;

/**
 * Preferences access tab context.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Build tab context.
     *
     * @return array
     */
    public static function get_context(): array {
        $context = \context_system::instance();
        $users = helper::get_admins();

        return [
            'accessdata' => [
                'capability' => 'local/la:manage',
                'manageurl' => (new \moodle_url('/admin/roles/assign.php', ['contextid' => $context->id]))->out(false),
                'settingsurl' => (new \moodle_url('/admin/settings.php', ['section' => 'local_la']))->out(false),
                'users' => $users,
                'hasusers' => !empty($users),
            ],
        ];
    }
}
