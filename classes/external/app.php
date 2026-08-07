<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\external;

defined('MOODLE_INTERNAL') || die();

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_la\local\app as app_helper;
use local_la\local\helper;
use local_la\local\logger;
use local_la\local\repository;

/**
 * App AJAX API.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class app extends external_api {
    /**
     * Widget parameters.
     *
     * @return external_function_parameters
     */
    public static function widget_parameters(): external_function_parameters {
        return new external_function_parameters([
            'appid' => new external_value(PARAM_INT, 'App id'),
            'widgetkey' => new external_value(PARAM_ALPHANUMEXT, 'Widget key'),
        ]);
    }

    /**
     * Get one refreshed widget body.
     *
     * @param int $appid
     * @param string $widgetkey
     * @return array
     */
    public static function widget(int $appid, string $widgetkey): array {
        global $PAGE;

        $params = self::validate_parameters(self::widget_parameters(), [
            'appid' => $appid,
            'widgetkey' => $widgetkey,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $app = repository::get_app((int) $params['appid']);
        if (!$app) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }
        if (!helper::has_plan((string) $app->plan)) {
            throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label((string) $app->plan));
        }

        $context = app_helper::get_widget_context_by_key($app, (string) $params['widgetkey']);
        if (!$context) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        $renderer = $PAGE->get_renderer('local_la');

        return [
            'html' => $renderer->render_from_template('local_la/components/app_widget_body', $context),
            'valueclass' => (string) ($context['value_class'] ?? ''),
        ];
    }

    /**
     * Widget returns.
     *
     * @return external_single_structure
     */
    public static function widget_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered widget body'),
            'valueclass' => new external_value(PARAM_ALPHANUMEXT, 'Value size class'),
        ]);
    }

    /**
     * Delete widget parameters.
     *
     * @return external_function_parameters
     */
    public static function delete_widget_parameters(): external_function_parameters {
        return new external_function_parameters([
            'appid' => new external_value(PARAM_INT, 'App id'),
            'widgetkey' => new external_value(PARAM_ALPHANUMEXT, 'Widget key'),
        ]);
    }

    /**
     * Delete one widget from an app definition.
     *
     * @param int $appid
     * @param string $widgetkey
     * @return array
     */
    public static function delete_widget(int $appid, string $widgetkey): array {
        global $DB;

        $params = self::validate_parameters(self::delete_widget_parameters(), [
            'appid' => $appid,
            'widgetkey' => $widgetkey,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $app = repository::get_app((int) $params['appid']);
        if (!$app) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }
        if (!helper::has_plan((string) $app->plan)) {
            throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label((string) $app->plan));
        }

        $widgetkey = (string) $params['widgetkey'];
        $definitionjson = app_helper::delete_widget((string) $app->definition, $widgetkey);
        if ($definitionjson === null) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        $app->definition = $definitionjson;
        $app->timemodified = time();
        $DB->update_record('local_la_app', $app);

        logger::add('delete_app_widget', 'app', (int) $app->id, [
            'widgetkey' => $widgetkey,
        ]);

        return ['success' => true];
    }

    /**
     * Delete widget returns.
     *
     * @return external_single_structure
     */
    public static function delete_widget_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
        ]);
    }

    /**
     * Update widget state parameters.
     *
     * @return external_function_parameters
     */
    public static function update_widget_state_parameters(): external_function_parameters {
        return new external_function_parameters([
            'appid' => new external_value(PARAM_INT, 'App id'),
            'widgetkey' => new external_value(PARAM_ALPHANUMEXT, 'Widget key'),
            'state' => new external_value(PARAM_ALPHA, 'Widget state'),
            'enabled' => new external_value(PARAM_BOOL, 'Enabled'),
        ]);
    }

    /**
     * Update one widget UI state flag.
     *
     * @param int $appid
     * @param string $widgetkey
     * @param string $state
     * @param bool $enabled
     * @return array
     */
    public static function update_widget_state(int $appid, string $widgetkey, string $state, bool $enabled): array {
        global $DB;

        $params = self::validate_parameters(self::update_widget_state_parameters(), [
            'appid' => $appid,
            'widgetkey' => $widgetkey,
            'state' => $state,
            'enabled' => $enabled,
        ]);
        self::validate_context(context_system::instance());
        require_login();

        if (!helper::is_admin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        $app = repository::get_app((int) $params['appid']);
        if (!$app) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }
        if (!helper::has_plan((string) $app->plan)) {
            throw new \moodle_exception('planrequired', 'local_la', '', helper::get_plan_label((string) $app->plan));
        }

        $definitionjson = app_helper::update_widget_state(
            (string) $app->definition,
            (string) $params['widgetkey'],
            (string) $params['state'],
            (bool) $params['enabled']
        );
        if ($definitionjson === null) {
            throw new \moodle_exception('errorinvalidappconfig', 'local_la');
        }

        $app->definition = $definitionjson;
        $app->timemodified = time();
        $DB->update_record('local_la_app', $app);

        return ['success' => true];
    }

    /**
     * Update widget state returns.
     *
     * @return external_single_structure
     */
    public static function update_widget_state_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
        ]);
    }
}
