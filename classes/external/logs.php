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
use local_la\local\helper;

/**
 * Audit log external API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logs extends external_api {
    /**
     * Details parameters.
     *
     * @return external_function_parameters
     */
    public static function details_parameters(): external_function_parameters {
        return new external_function_parameters([
            'logid' => new external_value(PARAM_INT, 'Log id'),
        ]);
    }

    /**
     * Get audit log details modal data.
     *
     * @param int $logid
     * @return array
     */
    public static function details(int $logid): array {
        global $DB, $PAGE;

        $params = self::validate_parameters(self::details_parameters(), ['logid' => $logid]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin() || !helper::has_feature('audit_logs')) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $fields = 'l.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email';
        $log = $DB->get_record_sql(
            "SELECT {$fields}
               FROM {local_la_logs} l
          LEFT JOIN {user} u ON u.id = l.userid
              WHERE l.id = :id",
            ['id' => (int) $params['logid']],
            MUST_EXIST
        );

        $details = self::format_details((string) ($log->details ?? ''));
        $renderer = $PAGE->get_renderer('local_la');

        return [
            'title' => get_string('details'),
            'html' => $renderer->render_from_template('local_la/modal/log_details', [
                'items' => [
                    ['label' => get_string('time', 'local_la'), 'value' => userdate((int) $log->timecreated, get_string('strftimedatetime', 'langconfig'))],
                    ['label' => get_string('user'), 'value' => trim(fullname($log))],
                    ['label' => get_string('email'), 'value' => (string) ($log->email ?? '')],
                    ['label' => get_string('action'), 'value' => (string) $log->action],
                    ['label' => get_string('id', 'local_la'), 'value' => (string) $log->objectid],
                    ['label' => get_string('ipaddress', 'local_la'), 'value' => (string) $log->ip],
                ],
                'details' => $details,
                'hasdetails' => $details !== '',
                'nodetails' => get_string('notset', 'local_la'),
            ]),
        ];
    }

    /**
     * Format details JSON for display.
     *
     * @param string $details
     * @return string
     */
    protected static function format_details(string $details): string {
        $details = trim($details);
        if ($details === '') {
            return '';
        }

        $decoded = json_decode($details, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $details;
        }

        $decoded = self::decode_nested_json($decoded);

        return json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '';
    }

    /**
     * Decode nested JSON strings so audit details are easier to read.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function decode_nested_json(mixed $value): mixed {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::decode_nested_json($item);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !in_array($trimmed[0], ['{', '['], true)) {
            return $value;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return self::decode_nested_json($decoded);
    }

    /**
     * Details returns.
     *
     * @return external_single_structure
     */
    public static function details_returns(): external_single_structure {
        return new external_single_structure([
            'title' => new external_value(PARAM_TEXT, 'Modal title'),
            'html' => new external_value(PARAM_RAW, 'Modal HTML'),
        ]);
    }
}
