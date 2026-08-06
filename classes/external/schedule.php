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

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use editor_tiny\editor;
use editor_tiny\manager;
use local_la\local\audience;
use local_la\local\logger;
use local_la\local\schedule as schedule_helper;

/**
 * Report schedule external API.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule extends external_api {
    public static function modal_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report ID'),
            'scheduleid' => new external_value(PARAM_INT, 'Schedule ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function modal(int $reportid, int $scheduleid = 0): array {
        global $PAGE;

        $params = self::validate_parameters(self::modal_parameters(), [
            'reportid' => $reportid,
            'scheduleid' => $scheduleid,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_login();

        if (!audience::has_access((int) $params['reportid'])) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $renderer = $PAGE->get_renderer('local_la');
        $siteconfig = get_config('editor_tiny');
        $editor = new editor();

        return [
            'title' => $params['scheduleid'] ? get_string('editscheduledetails', 'local_la') : get_string('newschedule', 'local_la'),
            'html' => $renderer->render_from_template(
                'local_la/modal/schedule',
                schedule_helper::get_modal_context((int) $params['reportid'], (int) $params['scheduleid'])
            ),
            'editoroptions' => json_encode([
                'css' => $PAGE->theme->editor_css_url()->out(false),
                'context' => $context->id,
                'filepicker' => (object) [],
                'draftitemid' => 0,
                'currentLanguage' => current_language(),
                'branding' => property_exists($siteconfig, 'branding') ? !empty($siteconfig->branding) : true,
                'extended_valid_elements' => $siteconfig->extended_valid_elements ?? 'script[*],p[*],i[*]',
                'language' => [
                    'currentlang' => current_language(),
                    'installed' => get_string_manager()->get_list_of_translations(true),
                    'available' => get_string_manager()->get_list_of_languages(),
                ],
                'placeholderSelectors' => [],
                'plugins' => (new manager())->get_plugin_configuration($context, ['autosave' => false], [], $editor),
            ]),
        ];
    }

    public static function save_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report ID'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'format' => new external_value(PARAM_ALPHA, 'Format'),
            'timestart' => new external_value(PARAM_INT, 'Start time'),
            'recurrence' => new external_value(PARAM_ALPHA, 'Recurrence'),
            'subject' => new external_value(PARAM_TEXT, 'Subject'),
            'body' => new external_value(PARAM_RAW, 'Body'),
            'audiences' => new \external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Audience type'),
                'Audience types',
                VALUE_DEFAULT,
                []
            ),
            'emptyreport' => new external_value(PARAM_ALPHA, 'Empty report handling'),
            'scheduleid' => new external_value(PARAM_INT, 'Schedule ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function save(
        int $reportid,
        string $name,
        string $format,
        int $timestart,
        string $recurrence,
        string $subject,
        string $body,
        array $audiences = [],
        string $emptyreport = 'send',
        int $scheduleid = 0
    ): array {
        $params = self::validate_parameters(self::save_parameters(), [
            'reportid' => $reportid,
            'name' => $name,
            'format' => $format,
            'timestart' => $timestart,
            'recurrence' => $recurrence,
            'subject' => $subject,
            'body' => $body,
            'audiences' => $audiences,
            'emptyreport' => $emptyreport,
            'scheduleid' => $scheduleid,
        ]);

        self::validate_context(context_system::instance());
        require_login();

        if (!audience::has_access((int) $params['reportid'])) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        if (trim($params['name']) === '' || trim($params['subject']) === '' || trim($params['body']) === '') {
            throw new \invalid_parameter_exception('Missing required schedule field');
        }

        $savedid = schedule_helper::save($params);
        logger::add(empty($params['scheduleid']) ? 'create_schedule' : 'update_schedule', 'schedule', $savedid, [
            'reportid' => (int) $params['reportid'],
            'name' => $params['name'],
        ]);

        return ['success' => true];
    }

    public static function send_parameters(): external_function_parameters {
        return new external_function_parameters([
            'scheduleid' => new external_value(PARAM_INT, 'Schedule ID'),
        ]);
    }

    public static function send(int $scheduleid): array {
        $params = self::validate_parameters(self::send_parameters(), ['scheduleid' => $scheduleid]);
        self::validate_context(context_system::instance());
        require_login();

        $schedule = schedule_helper::send((int) $params['scheduleid']);
        logger::add('send_schedule', 'schedule', (int) $params['scheduleid'], [
            'reportid' => (int) $schedule->reportid,
            'name' => (string) $schedule->name,
            'recipients' => (int) $schedule->deliverysent,
            'rows' => (int) $schedule->deliveryrows,
            'skipped' => !empty($schedule->deliveryskipped),
        ]);

        return ['success' => true];
    }

    public static function delete_parameters(): external_function_parameters {
        return self::send_parameters();
    }

    public static function delete(int $scheduleid): array {
        $params = self::validate_parameters(self::delete_parameters(), ['scheduleid' => $scheduleid]);
        self::validate_context(context_system::instance());
        require_login();

        $schedule = schedule_helper::delete((int) $params['scheduleid']);
        logger::add('delete_schedule', 'schedule', (int) $params['scheduleid'], [
            'reportid' => (int) $schedule->reportid,
            'name' => (string) $schedule->name,
        ]);

        return ['success' => true];
    }

    public static function toggle_parameters(): external_function_parameters {
        return new external_function_parameters([
            'scheduleid' => new external_value(PARAM_INT, 'Schedule ID'),
            'status' => new external_value(PARAM_INT, 'Status'),
        ]);
    }

    public static function toggle(int $scheduleid, int $status): array {
        $params = self::validate_parameters(self::toggle_parameters(), [
            'scheduleid' => $scheduleid,
            'status' => $status,
        ]);

        self::validate_context(context_system::instance());
        require_login();

        $schedule = schedule_helper::toggle((int) $params['scheduleid'], (int) $params['status']);
        logger::add('toggle_schedule', 'schedule', (int) $params['scheduleid'], [
            'reportid' => (int) $schedule->reportid,
            'status' => (int) $params['status'] ? 1 : 0,
        ]);

        return ['success' => true];
    }

    public static function modal_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Modal HTML'),
            'editoroptions' => new external_value(PARAM_RAW, 'Editor options'),
        ]);
    }

    public static function save_returns(): external_single_structure {
        return new external_single_structure(['success' => new external_value(PARAM_BOOL, 'Success')]);
    }

    public static function toggle_returns(): external_single_structure {
        return self::save_returns();
    }

    public static function send_returns(): external_single_structure {
        return self::save_returns();
    }

    public static function delete_returns(): external_single_structure {
        return self::save_returns();
    }
}
