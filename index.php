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
 * Home page for local_la.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');

use local_la\local\url;
use local_la\local\helper;
use local_la\output\home as home_page;

require_login();
if (!helper::is_admin()) {
    redirect(url::library_url(['tab' => 'reports']));
}
helper::init_page();

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('home'));
$PAGE->set_heading(get_string('pluginname', 'local_la'));
$PAGE->set_url(url::home_url());
$PAGE->requires->css('/local/la/styles.css');
$PAGE->requires->js_call_amd('local_la/home', 'init');

$renderer = $PAGE->get_renderer("local_la");


echo $OUTPUT->header();
echo $renderer->render(new home_page());
echo $OUTPUT->footer();
