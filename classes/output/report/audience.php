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

namespace local_la\output\report;

defined('MOODLE_INTERNAL') || die();

use local_la\local\audience as audience_helper;
use local_la\local\helper;
use local_la\local\url;

/**
 * Report audience tab context.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience {
    /**
     * Build tab context.
     *
     * @param \stdClass $report
     * @return array
     */
    public static function get_context(\stdClass $report): array {
        $options = audience_helper::get_options();
        $cards = audience_helper::get_report_audiences((int) $report->id);
        $activetypes = array_map(function(array $card): string {
            return (string) ($card['type'] ?? '');
        }, $cards);

        foreach ($cards as $index => $card) {
            $cards[$index]['showormessage'] = $index > 0;
        }

        $groups = [
            [
                'name' => get_string('site', 'core'),
                'key' => 'site',
                'expanded' => true,
                'items' => [
                    [
                        'name' => get_string('audienceallusers', 'local_la'),
                        'icon' => 'fa-plus',
                        'type' => 'all',
                    ],
                    [
                        'name' => get_string('audienceassignedsystemrole', 'local_la'),
                        'icon' => 'fa-plus',
                        'type' => 'role',
                        'autocomplete' => true,
                        'options' => $options['roles'],
                        'selectid' => 'la-audience-role',
                        'placeholder' => get_string('search', 'core'),
                    ],
                    [
                        'name' => get_string('audiencemanuallyaddedusers', 'local_la'),
                        'icon' => 'fa-plus',
                        'type' => 'user',
                        'autocomplete' => true,
                        'selectid' => 'la-audience-user',
                        'placeholder' => get_string('searchusers', 'local_la'),
                        'usersource' => 'core_user/form_user_selector',
                    ],
                    [
                        'name' => get_string('audiencesiteadministrators', 'local_la'),
                        'icon' => 'fa-plus',
                        'type' => 'admin',
                    ],
                ],
            ],
        ];

        foreach ($groups as $groupindex => $group) {
            foreach ($group['items'] as $itemindex => $item) {
                $groups[$groupindex]['items'][$itemindex]['disabled'] =
                    in_array((string) $item['type'], $activetypes, true);
            }
        }

        return [
            'reportid' => (int) $report->id,
            'action' => url::report_tab((int) $report->id, ['tab' => 'audience']),
            'can_manage' => helper::is_admin(),
            'sesskey' => sesskey(),
            'cards' => $cards,
            'has_cards' => !empty($cards),
            'forms' => audience_helper::get_forms((int) $report->id),
            'groups' => $groups,
        ];
    }
}
