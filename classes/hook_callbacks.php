<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_la;

defined('MOODLE_INTERNAL') || die();

use core\hook\output\before_footer_html_generation;
use core_user\hook\extend_user_menu;
use local_la\local\repository;
use local_la\local\tracker;
use local_la\local\url;

/**
 * Hook callbacks for local_la.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Add the reports library to the user menu when reports are available.
     *
     * @param extend_user_menu $hook
     * @return void
     */
    public static function extend_user_menu(extend_user_menu $hook): void {
        if (during_initial_install() || !isloggedin() || isguestuser() || repository::count_reports() === 0) {
            return;
        }

        $menuurl = url::library_url(['tab' => 'reports']);
        $title = get_string('lenarysreports', 'local_la');

        if (method_exists($hook, 'add_menu_item')) {
            $hook->add_menu_item(new \core_user\output\user_action_menu\link(
                new \core\url((string) $menuurl),
                $title,
            ));
            return;
        }

        $hook->add_navitem((object) [
            'itemtype' => 'link',
            'url' => $menuurl,
            'title' => $title,
            'titleidentifier' => 'lenarysreports,local_la',
        ]);
    }

    /**
     * Bootstrap learning time tracking on page footer generation.
     *
     * @param before_footer_html_generation $hook
     * @return void
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        if (during_initial_install()) {
            return;
        }

        if ((defined('CLI_SCRIPT') && CLI_SCRIPT) || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)) {
            return;
        }

        if (!isloggedin() || isguestuser() || !tracker::is_enabled()) {
            return;
        }

        if (empty($PAGE)) {
            return;
        }
        $config = tracker::get_client_config($PAGE);
        if (empty($config)) {
            return;
        }
        $PAGE->requires->js_call_amd('local_la/learning_time', 'init', [$config]);
    }
}
