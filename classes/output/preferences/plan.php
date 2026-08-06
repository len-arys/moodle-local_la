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

defined('MOODLE_INTERNAL') || die();

use local_la\local\helper;

/**
 * Preferences plan display helpers.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plan {
    /**
     * Format price amount.
     *
     * @param string $price
     * @return string
     */
    public static function format_price(string $price): string {
        if (is_numeric($price)) {
            return (string) (int) $price;
        }

        return trim($price);
    }

    /**
     * Format price suffix.
     *
     * @param string $currency
     * @param string $period
     * @return string
     */
    public static function get_price_suffix(string $currency, string $period): string {
        $suffix = strtoupper(trim($currency));
        if (trim($period) !== '') {
            $suffix = trim($suffix . ' / ' . trim($period), ' /');
        }

        return $suffix;
    }

    /**
     * Get plan card right-side detail.
     *
     * @param array $license
     * @param int $trialdays
     * @return string
     */
    public static function get_right_bottom(array $license, int $trialdays): string {
        if ($license['status'] === 'trialing' && !empty($license['trialends'])) {
            return get_string($trialdays === 1 ? 'trialdayremaining' : 'trialdaysremaining', 'local_la', $trialdays);
        }

        if ($license['status'] === 'active' && !empty($license['nextbilldate'])) {
            return get_string('nextpaymentdate', 'local_la',
                userdate((int) $license['nextbilldate'], get_string('strftimedate', 'langconfig')));
        }

        if ($license['status'] === 'past_due') {
            return get_string('paymentoverdue', 'local_la');
        }

        if ($license['status'] === 'cancelled') {
            return get_string('plancancelled', 'local_la');
        }

        return '';
    }

    /**
     * Get plan icon URL.
     *
     * @param string $plan
     * @return string
     */
    public static function get_icon_url(string $plan): string {
        global $OUTPUT;

        $plan = helper::normalize_plan($plan);

        return $OUTPUT->image_url($plan, 'local_la')->out(false);
    }
}
