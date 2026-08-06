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

/**
 * Preferences billing tab context.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class billing {
    /**
     * Build tab context.
     *
     * @return array
     */
    public static function get_context(): array {
        $defaults = require(__DIR__ . '/../../../config.php');
        $contactemail = clean_param((string) ($defaults['contactemail'] ?? ''), PARAM_EMAIL);
        $contacturl = $contactemail === '' ? '' : 'mailto:' . $contactemail;
        $marketplaceurl = clean_param((string) ($defaults['marketplaceurl'] ?? ''), PARAM_URL);
        $pricingurl = clean_param((string) ($defaults['pricingurl'] ?? ''), PARAM_URL);

        return [
            'contacturl' => s($contacturl),
            'hascontacturl' => $contacturl !== '',
            'hasbillingactions' => $contacturl !== '' || $marketplaceurl !== '',
            'marketplaceurl' => s($marketplaceurl),
            'hasmarketplaceurl' => $marketplaceurl !== '',
            'pricingurl' => s($pricingurl),
            'haspricingurl' => $pricingurl !== '',
        ];
    }

}
