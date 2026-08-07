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
 * Plugin URL helpers.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url {
    /**
     * Build home URL.
     *
     * @return string
     */
    public static function home(): string {
        return self::home_url()->out(false);
    }

    /**
     * Build home moodle_url.
     *
     * @return \moodle_url
     */
    public static function home_url(): \moodle_url {
        return new \moodle_url('/local/la/index.php');
    }

    /**
     * Build close URL.
     *
     * @return string
     */
    public static function close(): string {
        return self::close_url()->out(false);
    }

    /**
     * Build close moodle_url.
     *
     * @return \moodle_url
     */
    public static function close_url(): \moodle_url {
        return new \moodle_url('/my');
    }

    /**
     * Build library URL.
     *
     * @param array $params
     * @return string
     */
    public static function library(array $params = []): string {
        return self::library_url($params)->out(false);
    }

    /**
     * Build library moodle_url.
     *
     * @param array $params
     * @return \moodle_url
     */
    public static function library_url(array $params = []): \moodle_url {
        return new \moodle_url('/local/la/library.php', $params);
    }

    /**
     * Build preferences URL.
     *
     * @param array $params
     * @return string
     */
    public static function preferences(array $params = []): string {
        return self::preferences_url($params)->out(false);
    }

    /**
     * Build preferences moodle_url.
     *
     * @param array $params
     * @return \moodle_url
     */
    public static function preferences_url(array $params = []): \moodle_url {
        return new \moodle_url('/local/la/preferences.php', $params);
    }

    /**
     * Build app URL.
     *
     * @param int $id
     * @return string
     */
    public static function app(int $id): string {
        return self::app_url($id)->out(false);
    }

    /**
     * Build app moodle_url.
     *
     * @param int $id
     * @return \moodle_url
     */
    public static function app_url(int $id): \moodle_url {
        return new \moodle_url('/local/la/app.php', ['id' => $id]);
    }

    /**
     * Build report URL.
     *
     * @param int $id
     * @return string
     */
    public static function report(int $id): string {
        return self::report_url($id)->out(false);
    }

    /**
     * Build report moodle_url.
     *
     * @param int $id
     * @return \moodle_url
     */
    public static function report_url(int $id): \moodle_url {
        return new \moodle_url('/local/la/report.php', ['id' => $id]);
    }

    /**
     * Build report URL with extra params.
     *
     * @param int $id
     * @param array $params
     * @return string
     */
    public static function report_tab(int $id, array $params = []): string {
        return self::report_tab_url($id, $params)->out(false);
    }

    /**
     * Build report moodle_url with extra params.
     *
     * @param int $id
     * @param array $params
     * @return \moodle_url
     */
    public static function report_tab_url(int $id, array $params = []): \moodle_url {
        return new \moodle_url('/local/la/report.php', ['id' => $id] + $params);
    }
}
