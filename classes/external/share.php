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
use external_multiple_structure;
use external_single_structure;
use external_value;
use html_writer;
use local_la\local\audience;
use local_la\local\helper;
use local_la\local\logger;

/**
 * Share selected report rows.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class share extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function report_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'to' => new external_value(PARAM_TEXT, 'Recipient email addresses'),
            'subject' => new external_value(PARAM_TEXT, 'Email subject'),
            'body' => new external_value(PARAM_RAW, 'Email body'),
            'headers' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Column header')
            ),
            'rows' => new external_multiple_structure(
                new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Cell value')
                )
            ),
        ]);
    }

    /**
     * Send selected report rows by email.
     *
     * @param int $reportid
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param array $headers
     * @param array $rows
     * @return array
     */
    public static function report(int $reportid, string $to, string $subject, string $body, array $headers, array $rows): array {
        global $USER;

        $params = self::validate_parameters(self::report_parameters(), [
            'reportid' => $reportid,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'headers' => $headers,
            'rows' => $rows,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!audience::has_access((int) $params['reportid']) || !helper::can_share_reports()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $emails = self::get_recipient_emails((string) $params['to']);
        $headers = array_slice(array_values($params['headers']), 0, 30);
        $rows = array_slice(array_values($params['rows']), 0, 100);

        if (empty($emails) || trim($params['subject']) === '' || trim($params['body']) === '' ||
                empty($headers) || empty($rows)) {
            throw new \invalid_parameter_exception('Missing required share field');
        }

        if (helper::is_admin()) {
            $recipients = array_map([self::class, 'get_email_user'], $emails);
        } else {
            $recipients = self::get_internal_recipients($emails);

            if (count($recipients) !== count($emails)) {
                throw new \invalid_parameter_exception(get_string('shareinternalrecipientsonly', 'local_la'));
            }
        }

        $bodyhtml = format_text($params['body'], FORMAT_MOODLE, [
            'trusted' => false,
            'noclean' => false,
            'para' => true,
        ]);
        $messagehtml = $bodyhtml . self::render_table($headers, $rows);
        $messagetext = html_to_text($messagehtml);
        $sent = 0;

        foreach ($recipients as $recipient) {
            if (email_to_user($recipient, $USER, $params['subject'], $messagetext, $messagehtml)) {
                $sent++;
            }
        }

        logger::add('share_report_rows', 'report', (int) $params['reportid'], [
            'recipients' => $sent,
            'rows' => count($rows),
        ]);

        return ['success' => $sent > 0, 'sent' => $sent];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function report_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether at least one email was sent'),
            'sent' => new external_value(PARAM_INT, 'Sent email count'),
        ]);
    }

    /**
     * Parse and validate recipient emails.
     *
     * @param string $to
     * @return array
     */
    protected static function get_recipient_emails(string $to): array {
        $emails = preg_split('/[;,\\s]+/', $to, -1, PREG_SPLIT_NO_EMPTY);
        $valid = [];

        foreach ($emails as $email) {
            $email = trim($email);

            if ($email !== '' && validate_email($email)) {
                $valid[strtolower($email)] = $email;
            }
        }

        return array_slice(array_values($valid), 0, 20);
    }

    /**
     * Get active Moodle users for recipient emails.
     *
     * @param string[] $emails
     * @return \stdClass[]
     */
    protected static function get_internal_recipients(array $emails): array {
        global $CFG, $DB;

        $conditions = [];
        $params = ['guestid' => (int) ($CFG->siteguest ?? 0)];

        foreach ($emails as $index => $email) {
            $param = 'email' . $index;
            $conditions[] = $DB->sql_equal('email', ':' . $param, false);
            $params[$param] = $email;
        }

        $users = $DB->get_records_select(
            'user',
            '(' . implode(' OR ', $conditions) . ')
                AND deleted = 0
                AND suspended = 0
                AND confirmed = 1
                AND emailstop = 0
                AND id <> :guestid',
            $params,
            'id ASC'
        );
        $recipients = [];

        foreach ($users as $user) {
            $email = \core_text::strtolower((string) $user->email);

            if (!array_key_exists($email, $recipients)) {
                $recipients[$email] = $user;
            }
        }

        return $recipients;
    }

    /**
     * Render selected rows as an email-safe table.
     *
     * @param array $headers
     * @param array $rows
     * @return string
     */
    protected static function render_table(array $headers, array $rows): string {
        $table = new \html_table();
        $table->attributes['style'] = 'width:100%;border-collapse:collapse;margin-top:16px;';
        $table->head = array_map(static function($header): string {
            return html_writer::tag('strong', s((string) $header));
        }, $headers);
        $table->data = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach (array_slice(array_values($row), 0, count($headers)) as $cell) {
                $cells[] = s((string) $cell);
            }

            $table->data[] = $cells;
        }

        return html_writer::table($table);
    }

    /**
     * Build a lightweight recipient object for email_to_user().
     *
     * @param string $email
     * @return \stdClass
     */
    protected static function get_email_user(string $email): \stdClass {
        $user = new \stdClass();
        $user->id = -99;
        $user->email = $email;
        $user->firstname = $email;
        $user->lastname = '';
        $user->firstnamephonetic = '';
        $user->lastnamephonetic = '';
        $user->middlename = '';
        $user->alternatename = '';
        $user->maildisplay = true;
        $user->mailformat = 1;
        $user->deleted = 0;
        $user->suspended = 0;
        $user->auth = 'manual';

        return $user;
    }
}
