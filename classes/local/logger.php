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

namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * User action logger.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logger {
    /**
     * Start one grouped AI log trace.
     *
     * @param array $details
     * @return string
     */
    public static function start_ai(array $details): string {
        global $USER;

        $requestid = clean_param(uniqid('ai_', true), PARAM_ALPHANUMEXT);
        self::add_ai($requestid, 'start', ['userid' => (int) ($USER->id ?? 0)] + $details);

        return $requestid;
    }

    /**
     * Add one grouped AI log event.
     *
     * @param string $requestid
     * @param string $event
     * @param array $details
     * @return void
     */
    public static function add_ai(string $requestid, string $event, array $details = []): void {
        if ($requestid === '') {
            return;
        }

        self::add('ai_' . $event, 'ai', 0, ['requestid' => $requestid] + $details);
    }

    /**
     * Add one log record.
     *
     * @param string $action
     * @param string $objecttype
     * @param int $objectid
     * @param array $details
     * @param int|null $userid
     */
    public static function add(
        string $action,
        string $objecttype = '',
        int $objectid = 0,
        array $details = [],
        ?int $userid = null
    ): void {
        global $DB, $USER;

        $DB->insert_record('local_la_logs', (object) [
            'userid' => $userid ?? (int) ($USER->id ?? 0),
            'action' => $action,
            'objecttype' => $objecttype,
            'objectid' => $objectid,
            'details' => json_encode($details),
            'ip' => substr((string) getremoteaddr(), 0, 45),
            'timecreated' => time(),
        ]);
    }
}
