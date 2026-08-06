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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.

/**
 * Default plugin configuration.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

return [
    'companyname' => 'lenArys',
    'appearance' => 'system',
    'billingadmins' => 0,
    'api' => 'api',
    'apicurlsettings' => [],
    'apiurl' => 'https://api.lenarys.com',
    'contacturl' => 'https://lenarys.com/en/contact-us/',
    'contactemail' => 'support@lenarys.com',
    'docsurl' => 'https://lenarys.com/docs',
    'downloadurl' => 'https://lenarys.com/en/get-started',
    'issuesurl' => 'https://github.com/len-arys/local_la/issues',
    'marketplaceurl' => 'https://marketplace.moodle.com/',
    'pricingurl' => 'https://lenarys.com/en/pricing',
    'debug' => 0,
    'licensefile' => 0,
    'learningtimeenabled' => 0,
    'learningtimeinterval' => 30,
    'learningtimeidletimeout' => 90,
    'allowaudiencedrilldown' => 0,
    'allowaudiencesharing' => 0,
    'schedulemaxrecipients' => 100,
    'restrictedtables' => \local_la\local\validator::get_default_restricted_tables(),
];
