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

namespace local_la\output\library;

defined('MOODLE_INTERNAL') || die();

use local_la\local\helper;
use local_la\local\repository;
use local_la\local\url;

/**
 * Library apps tab context.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apps {
    /**
     * Build tab context.
     *
     * @return array
     */
    public static function get_context(): array {
        $canmanage = helper::is_billing_admin();
        $items = [];
        foreach (repository::get_apps(0, 0, !$canmanage) as $app) {
            $items[] = self::get_item_context($app, $canmanage);
        }

        return [
            'items' => $items,
            'canmanage' => $canmanage,
        ];
    }

    /**
     * Build one app row context.
     *
     * @param \stdClass $app
     * @param bool $canmanage
     * @return array
     */
    protected static function get_item_context(\stdClass $app, bool $canmanage): array {
        $plan = (string) $app->plan;
        $isavailable = helper::has_plan($plan);

        return [
            'id' => (int) $app->id,
            'shortname' => (string) ($app->shortname ?? ''),
            'name' => (string) $app->name,
            'info' => (string) ($app->info ?? ''),
            'version' => (string) ($app->version ?? ''),
            'isavailable' => $isavailable,
            'islocked' => !$isavailable,
            'lockmessage' => get_string('planrequired', 'local_la', helper::get_plan_label($plan)),
            'status' => (int) ($app->status ?? 0),
            'visible' => !empty($app->status),
            'hidden' => empty($app->status),
            'timemodified' => (int) ($app->timemodified ?? 0),
            'view_url' => url::app((int) $app->id),
            'canmanage' => $canmanage,
        ];
    }
}
