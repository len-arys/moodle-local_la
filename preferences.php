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
 * Preferences page for local_la.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');

use local_la\local\helper;
use local_la\output\preferences as preferences_page;

$tab = optional_param('tab', 'general', PARAM_ALPHA);

require_login();
helper::init_page();
if (!helper::is_billing_admin()) {
    throw new moodle_exception('nopermissions', 'error');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/la/preferences.php', ['tab' => $tab]));
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('preferences'));
$PAGE->set_heading(get_string('pluginname', 'local_la'));
$PAGE->requires->js_call_amd('local_la/preferences', 'init');

$renderer = $PAGE->get_renderer("local_la");

echo $OUTPUT->header();
echo $renderer->render(new preferences_page($tab));
echo $OUTPUT->footer();
