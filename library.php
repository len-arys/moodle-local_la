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
 * Library page for local_la.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');

use local_la\local\helper;
use local_la\local\url;
use local_la\output\library as library_page;

$tab = optional_param('tab', 'reports', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$sort = optional_param('sort', 'name', PARAM_ALPHA);
$dir = optional_param('dir', 'asc', PARAM_ALPHA);
$reportsearch = optional_param('reportsearch', '', PARAM_TEXT);
$marketplan = optional_param('marketplan', 'all', PARAM_ALPHA);
$markettype = optional_param('markettype', 'reports', PARAM_ALPHA);
$marketsearch = optional_param('marketsearch', '', PARAM_TEXT);
$marketsort = optional_param('marketsort', 'name', PARAM_ALPHA);

require_login();
helper::init_page();

if ($tab !== 'reports' && !helper::is_admin()) {
    redirect(url::library_url(['tab' => 'reports']));
}

$PAGE->set_url(url::library_url([
    'tab' => $tab,
    'page' => $page,
    'sort' => $sort,
    'dir' => $dir,
    'reportsearch' => $reportsearch,
    'marketplan' => $marketplan,
    'markettype' => $markettype,
    'marketsearch' => $marketsearch,
    'marketsort' => $marketsort,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('library', 'local_la'));
$PAGE->set_heading(get_string('pluginname', 'local_la'));
$PAGE->requires->js_call_amd('local_la/library_actions', 'init');
if (helper::is_admin()) {
    $PAGE->requires->js_call_amd('local_la/report_details', 'init');
}

$renderer = $PAGE->get_renderer("local_la");

echo $OUTPUT->header();
echo $renderer->render(new library_page(
    $tab,
    $page,
    $sort,
    $dir,
    $marketplan,
    $markettype,
    $marketsearch,
    $marketsort,
    $reportsearch
));
echo $OUTPUT->footer();
