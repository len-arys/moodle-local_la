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
 * Apps page for local_la.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');

use local_la\local\helper;
use local_la\local\repository;
use local_la\local\url;
use local_la\output\app as app_page;

$id = optional_param('id', 0, PARAM_INT);

require_login();

$PAGE->set_context(context_system::instance());

$app = repository::get_app($id);

if (!$app) {
    redirect(url::library_url(['tab' => 'apps']));
}

helper::init_page('app', (string) $app->plan);

if (!helper::is_admin()) {
    throw new moodle_exception('nopermissions', 'error');
}

$PAGE->set_url(new moodle_url('/local/la/app.php', ['id' => $id]));
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('apps', 'local_la'));
$PAGE->set_heading(get_string('pluginname', 'local_la'));

$renderer = $PAGE->get_renderer('local_la');

echo $OUTPUT->header();
echo $renderer->render(new app_page($id));
echo $OUTPUT->footer();
