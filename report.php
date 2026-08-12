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
 * Report page for local_la.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_la\local\audience;
use local_la\local\helper;
use local_la\local\repository;
use local_la\local\url;
use local_la\output\report as report_page;

require_once('../../config.php');
require_once($CFG->libdir . '/tablelib.php');

require_login();

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'view', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$report = repository::get_report($id);

$PAGE->set_context(context_system::instance());

if (!$report) {
    throw new moodle_exception('errorinvalidreportconfig', 'local_la');
}

if (!audience::has_access((int) $report->id)) {
    redirect(
        url::library_url(['tab' => 'reports']),
        get_string('noreportaccess', 'local_la', format_string($report->name)),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

helper::init_page('report', (string) $report->plan);

if (empty($report->userid)) {
    redirect(
        url::library_url(['tab' => 'reports']),
        get_string('enablereportfirst', 'local_la', format_string($report->name)),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

if (in_array($tab, ['audience', 'access', 'auditlogs'], true) && !helper::is_admin()) {
    throw new moodle_exception('nopermissions', 'error');
}

repository::update_report_access((int) $report->id, (int) $USER->id);

$PAGE->set_pagelayout('popup');
$PAGE->set_heading(get_string('pluginname', 'local_la'));
$PAGE->set_url(url::report_tab_url((int) $report->id, ['tab' => $tab]));
$PAGE->set_title(format_string($report->name));
$PAGE->requires->js_call_amd('local_la/report_actions', 'init');
if (helper::is_admin()) {
    $PAGE->requires->js_call_amd('local_la/report_details', 'init');
}
if (helper::is_admin()) {
    $PAGE->requires->js_call_amd('local_la/report_audience', 'init');
}
$PAGE->requires->js_call_amd('local_la/report_schedule', 'init');
$PAGE->requires->js_call_amd('local_la/calendar', 'init');
$PAGE->requires->js_call_amd('local_la/report_columns', 'init');
$PAGE->requires->js_call_amd('local_la/report_data', 'init');
$PAGE->requires->js_call_amd('local_la/report_filters', 'init');

$renderer = $PAGE->get_renderer('local_la');
$page = new report_page($report, $tab);
$page->report_download($download);

echo $OUTPUT->header();
echo $renderer->render($page);
echo $OUTPUT->footer();
