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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_la\local;

use moodle_url;

/**
 * Learning time tracking helper.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tracker {
    /** @var int */
    protected const DEFAULT_INTERVAL = 30;

    /** @var int */
    protected const DEFAULT_IDLE_TIMEOUT = 90;

    /** @var int */
    protected const MAX_TRACKED_SECONDS = 120;

    /** @var int Maximum lifetime of a page tracking token. */
    protected const TOKEN_TTL = 86400;

    /** @var int Maximum active page tokens retained in one Moodle session. */
    protected const MAX_SESSION_TOKENS = 50;

    /**
     * Whether learning time tracking is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return !empty(get_config('local_la', 'learningtimeenabled'));
    }

    /**
     * Heartbeat interval in seconds.
     *
     * @return int
     */
    public static function get_interval(): int {
        $value = (int) get_config('local_la', 'learningtimeinterval');

        return max(15, min(120, $value ?: self::DEFAULT_INTERVAL));
    }

    /**
     * Idle timeout in seconds.
     *
     * @return int
     */
    public static function get_idle_timeout(): int {
        $value = (int) get_config('local_la', 'learningtimeidletimeout');

        return max(30, min(600, $value ?: self::DEFAULT_IDLE_TIMEOUT));
    }

    /**
     * Build client config for one Moodle page.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public static function get_client_config(\moodle_page $page): ?array {
        global $USER;

        if (!self::is_enabled()) {
            return null;
        }

        $pageinfo = self::resolve_page($page);
        if (empty($pageinfo)) {
            return null;
        }

        return [
            'interval' => self::get_interval(),
            'idletimeout' => self::get_idle_timeout(),
            'debug' => helper::is_debug_enabled() && is_siteadmin(),
            'token' => self::create_session_token((int) $USER->id, $pageinfo),
            'ajaxurl' => (new moodle_url('/lib/ajax/service.php', [
                'sesskey' => sesskey(),
                'info' => 'local_la_track_learning_time',
            ]))->out(false),
            'page' => $pageinfo,
        ];
    }

    /**
     * Create a bounded, server-side page token for the current Moodle session.
     *
     * @param int $userid
     * @param array $pageinfo
     * @return string
     */
    protected static function create_session_token(int $userid, array $pageinfo): string {
        $now = time();
        $cache = \cache::make('local_la', 'tracking');
        $tokens = $cache->get('tokens');
        $tokens = is_array($tokens) ? $tokens : [];

        foreach ($tokens as $key => $state) {
            if (empty($state['created']) || (int) $state['created'] < $now - self::TOKEN_TTL) {
                unset($tokens[$key]);
            }
        }
        while (count($tokens) >= self::MAX_SESSION_TOKENS) {
            array_shift($tokens);
        }

        if (empty($tokens) || $cache->get('lastaccounted') === false) {
            $cache->set('lastaccounted', $now);
        }

        do {
            $token = random_string(32);
        } while (isset($tokens[$token]));

        $tokens[$token] = [
            'userid' => $userid,
            'page' => $pageinfo,
            'created' => $now,
            'lastheartbeat' => $now,
            'visitcounted' => false,
        ];
        $cache->set('tokens', $tokens);

        return $token;
    }

    /**
     * Resolve canonical page metadata for tracking.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public static function resolve_page(\moodle_page $page): ?array {
        if (!$page->has_set_url()) {
            return null;
        }
        $path = self::get_local_path($page->url);
        if ($path === '') {
            return null;
        }
        $courseid = !empty($page->course->id) ? (int) $page->course->id : 0;
        $name = self::resolve_name($page, $path);
        $instanceid = self::resolve_instanceid($page, $name, $courseid);

        return [
            'name' => self::truncate($name, 255),
            'instanceid' => $instanceid,
            'courseid' => $courseid,
        ];
    }

    /**
     * Persist one learning time heartbeat.
     *
     * @param int $userid
     * @param string $token
     * @param int $trackedseconds
     * @return void
     */
    public static function track(int $userid, string $token, int $trackedseconds): void {
        global $DB;

        $now = time();
        $cache = \cache::make('local_la', 'tracking');
        $tokens = $cache->get('tokens');
        $tokens = is_array($tokens) ? $tokens : [];
        $state = $tokens[$token] ?? null;

        if (
            !is_array($state) || (int) ($state['userid'] ?? 0) !== $userid ||
                empty($state['page']['name']) ||
                (int) ($state['created'] ?? 0) < $now - self::TOKEN_TTL
        ) {
            unset($tokens[$token]);
            $cache->set('tokens', $tokens);
            throw new \invalid_parameter_exception('Invalid or expired page tracking token');
        }

        $elapsed = max(0, $now - (int) ($state['lastheartbeat'] ?? $now));
        $lastaccounted = $cache->get('lastaccounted');
        if ($lastaccounted === false) {
            $lastaccounted = $now;
            $cache->set('lastaccounted', $now);
        }
        $sessionelapsed = max(0, $now - (int) $lastaccounted);
        $trackedseconds = max(0, min(
            self::MAX_TRACKED_SECONDS,
            self::get_interval() * 2,
            $elapsed,
            $sessionelapsed,
            $trackedseconds
        ));
        $countvisit = empty($state['visitcounted']);
        $pageinfo = $state['page'];

        if (!$countvisit && $trackedseconds === 0) {
            $state['lastheartbeat'] = $now;
            $tokens[$token] = $state;
            $cache->set('tokens', $tokens);
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        $pageid = self::upsert_page($pageinfo);

        $total = $DB->get_record('local_la_time_total', [
            'userid' => $userid,
            'pageid' => $pageid,
        ]);

        if (!$total) {
            $total = (object) [
                'userid' => $userid,
                'pageid' => $pageid,
                'visits' => 0,
                'timesec' => 0,
                'params' => '',
                'firstaccess' => $now,
                'lastaccess' => 0,
            ];
            $total->id = $DB->insert_record('local_la_time_total', $total);
        }

        if ($countvisit) {
            $total->visits = (int) $total->visits + 1;
        }

        if ($trackedseconds > 0) {
            $total->timesec = (int) $total->timesec + $trackedseconds;
        }

        if (empty($total->firstaccess)) {
            $total->firstaccess = $now;
        }

        $total->lastaccess = $now;
        $total->params = json_encode(self::get_tracking_params());
        $DB->update_record('local_la_time_total', $total);

        self::update_day_hour((int) $total->id, $trackedseconds, $countvisit, $now);

        $transaction->allow_commit();

        $state['lastheartbeat'] = $now;
        $state['visitcounted'] = true;
        $tokens[$token] = $state;
        $cache->set('tokens', $tokens);
        if ($trackedseconds > 0) {
            $cache->set('lastaccounted', $now);
        }
    }

    /**
     * Update day and hour aggregates.
     *
     * @param int $totalid
     * @param int $trackedseconds
     * @param bool $countvisit
     * @param int $now
     * @return void
     */
    protected static function update_day_hour(int $totalid, int $trackedseconds, bool $countvisit, int $now): void {
        global $DB;

        $daystamp = usergetmidnight($now);
        $hour = (int) userdate($now, '%H');

        $day = $DB->get_record('local_la_time_day', [
            'totalid' => $totalid,
            'daystamp' => $daystamp,
        ]);

        if (!$day) {
            $day = (object) [
                'totalid' => $totalid,
                'daystamp' => $daystamp,
                'visits' => 0,
                'timesec' => 0,
            ];
            $day->id = $DB->insert_record('local_la_time_day', $day);
        }

        if ($countvisit) {
            $day->visits = (int) $day->visits + 1;
        }

        if ($trackedseconds > 0) {
            $day->timesec = (int) $day->timesec + $trackedseconds;
        }

        $DB->update_record('local_la_time_day', $day);

        $hourrecord = $DB->get_record('local_la_time_hour', [
            'dayid' => $day->id,
            'hour' => $hour,
        ]);

        if (!$hourrecord) {
            $hourrecord = (object) [
                'dayid' => $day->id,
                'hour' => $hour,
                'visits' => 0,
                'timesec' => 0,
            ];
            $hourrecord->id = $DB->insert_record('local_la_time_hour', $hourrecord);
        }

        if ($countvisit) {
            $hourrecord->visits = (int) $hourrecord->visits + 1;
        }

        if ($trackedseconds > 0) {
            $hourrecord->timesec = (int) $hourrecord->timesec + $trackedseconds;
        }

        $DB->update_record('local_la_time_hour', $hourrecord);
    }

    /**
     * Create or update one tracked page row.
     *
     * @param array $pageinfo
     * @return int
     */
    protected static function upsert_page(array $pageinfo): int {
        global $DB;

        $record = $DB->get_record('local_la_time_page', [
            'name' => (string) ($pageinfo['name'] ?? ''),
            'instanceid' => (int) ($pageinfo['instanceid'] ?? 0),
            'courseid' => (int) ($pageinfo['courseid'] ?? 0),
        ]);

        if ($record) {
            $record->name = self::truncate((string) ($pageinfo['name'] ?? ''), 255);
            $record->instanceid = (int) ($pageinfo['instanceid'] ?? 0);
            $record->courseid = (int) ($pageinfo['courseid'] ?? 0);
            $DB->update_record('local_la_time_page', $record);

            return (int) $record->id;
        }

        $record = (object) [
            'name' => self::truncate((string) ($pageinfo['name'] ?? ''), 255),
            'instanceid' => (int) ($pageinfo['instanceid'] ?? 0),
            'courseid' => (int) ($pageinfo['courseid'] ?? 0),
        ];

        return (int) $DB->insert_record('local_la_time_page', $record);
    }

    /**
     * Get local path from one Moodle URL.
     *
     * @param \moodle_url $url
     * @return string
     */
    protected static function get_local_path(\moodle_url $url): string {
        $local = $url->out_as_local_url(false);
        $parts = parse_url($local);

        return trim((string) ($parts['path'] ?? ''));
    }

    /**
     * Resolve simplified logical page name.
     *
     * @param \moodle_page $page
     * @param string $path
     * @return string
     */
    protected static function resolve_name(\moodle_page $page, string $path): string {
        if ($path === '/local/la/report.php') {
            return 'la_report';
        }

        if (!empty($page->cm->id)) {
            return 'activity';
        }

        if (!empty($page->course->id) && (int) $page->course->id !== SITEID) {
            return 'course';
        }

        if (strpos($path, '/user/') !== false) {
            return 'profile';
        }

        if (strpos($path, '/local/') !== false) {
            $start = strpos($path, '/', strpos($path, '/local/') + 1) + 1;
            $end = strpos($path, '/', $start);
            $plugin = $end !== false ? substr($path, $start, $end - $start) : substr($path, $start);

            return $plugin !== '' ? 'local_' . $plugin : 'local';
        }

        if (strpos($path, '/grade/') !== false) {
            return 'grades';
        }

        if (strpos($path, '/report/') !== false) {
            return 'report';
        }

        return 'site';
    }

    /**
     * Resolve fallback instance id.
     *
     * @param \moodle_page $page
     * @param string $name
     * @param int $courseid
     * @return int
     */
    protected static function resolve_instanceid(\moodle_page $page, string $name, int $courseid): int {
        if ($name === 'la_report') {
            $reportid = (int) $page->url->param('id');

            if ($reportid > 0) {
                return $reportid;
            }

            return optional_param('id', 0, PARAM_INT);
        }

        if ($name === 'activity' && !empty($page->cm->id)) {
            return (int) $page->cm->id;
        }

        if ($name === 'course' && $courseid > 0) {
            return $courseid;
        }

        if ($name === 'profile') {
            foreach (['id', 'userid', 'user'] as $key) {
                $value = $page->url->param($key, null);

                if ($value !== null && (int) $value > 0) {
                    return (int) $value;
                }
            }
        }

        foreach (['id', 'course', 'courseid', 'userid', 'user', 'itemid'] as $key) {
            $value = $page->url->param($key, null);

            if ($value !== null && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * Build the stored tracking params.
     *
     * @return array
     */
    protected static function get_tracking_params(): array {
        $agent = (string) \core_useragent::get_user_agent_string();

        return [
            'ip' => self::truncate((string) getremoteaddr(), 255),
            'browser' => self::truncate(self::detect_browser($agent), 255),
            'os' => self::truncate(self::detect_os($agent), 255),
        ];
    }

    /**
     * Detect browser from user agent.
     *
     * @param string $agent
     * @return string
     */
    protected static function detect_browser(string $agent): string {
        $agent = trim($agent);

        if ($agent === '') {
            return 'Unknown';
        }

        $map = [
            'Edg/' => 'Edge',
            'OPR/' => 'Opera',
            'Opera/' => 'Opera',
            'Chrome/' => 'Chrome',
            'Firefox/' => 'Firefox',
            'Safari/' => 'Safari',
            'MSIE ' => 'Internet Explorer',
            'Trident/' => 'Internet Explorer',
        ];

        foreach ($map as $needle => $browser) {
            if (strpos($agent, $needle) !== false) {
                return $browser;
            }
        }

        return 'Unknown';
    }

    /**
     * Detect operating system from user agent.
     *
     * @param string $agent
     * @return string
     */
    protected static function detect_os(string $agent): string {
        $agent = trim($agent);

        if ($agent === '') {
            return 'Unknown';
        }

        $map = [
            'Windows NT' => 'Windows',
            'Mac OS X' => 'macOS',
            'Android' => 'Android',
            'iPhone' => 'iOS',
            'iPad' => 'iOS',
            'Linux' => 'Linux',
            'CrOS' => 'ChromeOS',
        ];

        foreach ($map as $needle => $os) {
            if (strpos($agent, $needle) !== false) {
                return $os;
            }
        }

        return 'Unknown';
    }

    /**
     * Truncate strings safely.
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    protected static function truncate(string $value, int $length): string {
        if (\core_text::strlen($value) <= $length) {
            return $value;
        }

        return \core_text::substr($value, 0, $length);
    }
}
