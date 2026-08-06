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

/**
 * Admin settings for local_la.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $defaults = require(__DIR__ . '/config.php');

    $ADMIN->add('reports', new admin_category('local_la_reports', get_string('adminmenusection', 'local_la')));
    $ADMIN->add('local_la_reports', new admin_externalpage(
        'local_la_getstarted',
        get_string('getstarted', 'local_la'),
        new moodle_url('/local/la/index.php')
    ));
    $ADMIN->add('local_la_reports', new admin_externalpage(
        'local_la_library',
        get_string('reports', 'local_la'),
        new moodle_url('/local/la/library.php')
    ));
    $ADMIN->add('local_la_reports', new admin_externalpage(
        'local_la_preferences',
        get_string('preferences', 'local_la'),
        new moodle_url('/local/la/preferences.php')
    ));
    $ADMIN->add('local_la_reports', new admin_externalpage(
        'local_la_settings',
        get_string('settings'),
        new moodle_url('/admin/settings.php', ['section' => 'local_la'])
    ));

    $settings = new admin_settingpage('local_la', get_string('pluginname', 'local_la'));

    $billingadminchoices = [];
    foreach (get_admins() as $admin) {
        $billingadminchoices[$admin->id] = fullname($admin);
    }

    $settings->add(new admin_setting_heading(
        'local_la/generalsettings',
        get_string('generalsettings', 'local_la'),
        get_string('generalsettings_desc', 'local_la')
    ));

    $settings->add(new admin_setting_configselect(
        'local_la/appearance',
        get_string('appearance', 'local_la'),
        get_string('appearance_desc', 'local_la'),
        $defaults['appearance'],
        [
            'system' => get_string('appearance_system', 'local_la'),
            'light' => get_string('appearance_light', 'local_la'),
            'dark' => get_string('appearance_dark', 'local_la'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'local_la/billingadmins',
        get_string('billingadmins', 'local_la'),
        get_string('billingadmins_desc', 'local_la'),
        $defaults['billingadmins'],
        $billingadminchoices
    ));

    $settings->add(new admin_setting_configselect(
        'local_la/api',
        get_string('pluginapi', 'local_la'),
        get_string('pluginapi_desc', 'local_la'),
        $defaults['api'],
        [
            'api' => get_string('pluginapi_auto', 'local_la'),
            'manual' => get_string('pluginapi_manual', 'local_la'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_la/apiurl',
        get_string('licenseapiurl', 'local_la'),
        get_string('licenseapiurl_desc', 'local_la'),
        $defaults['apiurl'],
        PARAM_RAW
    ));
    $settings->hide_if('local_la/apiurl', 'local_la/api', 'neq', 'api');

    $settings->add(new admin_setting_configcheckbox(
        'local_la/debug',
        get_string('debug', 'local_la'),
        get_string('debug_desc', 'local_la'),
        $defaults['debug']
    ));
    $settings->hide_if('local_la/debug', 'local_la/api', 'neq', 'api');

    $settings->add(new admin_setting_configstoredfile(
        'local_la/licensefile',
        get_string('licensefile', 'local_la'),
        get_string('licensefile_desc', 'local_la'),
        'licensefile',
        $defaults['licensefile'],
        ['accepted_types' => ['.json'], 'maxfiles' => 1]
    ));
    $settings->hide_if('local_la/licensefile', 'local_la/api', 'neq', 'manual');

    $settings->add(new admin_setting_heading(
        'local_la/learningtimesettings',
        get_string('learningtimesettings', 'local_la'),
        get_string('learningtimesettings_desc', 'local_la')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_la/learningtimeenabled',
        get_string('learningtimeenabled', 'local_la'),
        get_string('learningtimeenabled_desc', 'local_la'),
        $defaults['learningtimeenabled']
    ));

    $settings->add(new admin_setting_configtext(
        'local_la/learningtimeinterval',
        get_string('learningtimeinterval', 'local_la'),
        get_string('learningtimeinterval_desc', 'local_la'),
        $defaults['learningtimeinterval'],
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_la/learningtimeidletimeout',
        get_string('learningtimeidletimeout', 'local_la'),
        get_string('learningtimeidletimeout_desc', 'local_la'),
        $defaults['learningtimeidletimeout'],
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_la/securitysettings',
        get_string('securitysettings', 'local_la'),
        get_string('securitysettings_desc', 'local_la')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_la/allowaudiencedrilldown',
        get_string('allowaudiencedrilldown', 'local_la'),
        get_string('allowaudiencedrilldown_desc', 'local_la'),
        $defaults['allowaudiencedrilldown']
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_la/allowaudiencesharing',
        get_string('allowaudiencesharing', 'local_la'),
        get_string('allowaudiencesharing_desc', 'local_la'),
        $defaults['allowaudiencesharing']
    ));

    $defaultrestrictedtables = $defaults['restrictedtables'];
    if (get_config('local_la', 'restrictedtables') === false) {
        set_config('restrictedtables', implode(',', $defaultrestrictedtables), 'local_la');
    }

    $settings->add(new admin_setting_configmultiselect(
        'local_la/restrictedtables',
        get_string('restrictedtables', 'local_la'),
        get_string('restrictedtables_desc', 'local_la'),
        $defaultrestrictedtables,
        \local_la\local\validator::get_table_choices()
    ));

    $ADMIN->add('localplugins', $settings);
}
