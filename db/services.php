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
 * External service definitions.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_la_get_courses' => [
        'classname' => 'local_la\\external\\multiselect',
        'methodname' => 'get_courses',
        'description' => 'Get lazy-loaded course filter options for one report.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_header_search' => [
        'classname' => 'local_la\\external\\search',
        'methodname' => 'execute',
        'description' => 'Search users or courses from the plugin header.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_agent_complete' => [
        'classname' => 'local_la\\external\\agent',
        'methodname' => 'complete',
        'description' => 'Mark the first-run agent as completed.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_agent_check_setup' => [
        'classname' => 'local_la\\external\\agent',
        'methodname' => 'check_setup',
        'description' => 'Check license setup for guided report installation.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_agent_get_reports' => [
        'classname' => 'local_la\\external\\agent',
        'methodname' => 'get_reports',
        'description' => 'Get available reports for guided installation.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_agent_install_report' => [
        'classname' => 'local_la\\external\\agent',
        'methodname' => 'install_report',
        'description' => 'Install one report from guided setup.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_get_report' => [
        'classname' => 'local_la\\external\\report',
        'methodname' => 'execute',
        'description' => 'Get generic report modal data.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_get_grade' => [
        'classname' => 'local_la\\external\\grade',
        'methodname' => 'execute',
        'description' => 'Get grade modal data.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_get_columns' => [
        'classname' => 'local_la\\external\\columns',
        'methodname' => 'execute',
        'description' => 'Get columns modal data.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_get_column_settings' => [
        'classname' => 'local_la\\external\\columns',
        'methodname' => 'get_settings',
        'description' => 'Get report column settings.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_save_column_settings' => [
        'classname' => 'local_la\\external\\columns',
        'methodname' => 'save_settings',
        'description' => 'Save report column settings.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_save_columns' => [
        'classname' => 'local_la\\external\\columns',
        'methodname' => 'save_preferences',
        'description' => 'Save report columns dropdown preferences.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_save_builder' => [
        'classname' => 'local_la\\external\\columns',
        'methodname' => 'save_builder',
        'description' => 'Save add-column modal builder selections.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_save_search_default' => [
        'classname' => 'local_la\\external\\columns',
        'methodname' => 'save_search_default',
        'description' => 'Save default report search column.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_track_learning_time' => [
        'classname' => 'local_la\\external\\tracker',
        'methodname' => 'execute',
        'description' => 'Track active learning time on one Moodle page.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_get_timesec' => [
        'classname' => 'local_la\\external\\calendar',
        'methodname' => 'execute_timesec',
        'description' => 'Get learning time modal data.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_get_visits' => [
        'classname' => 'local_la\\external\\calendar',
        'methodname' => 'execute_visits',
        'description' => 'Get visits modal data.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_get_calendar' => [
        'classname' => 'local_la\\external\\calendar',
        'methodname' => 'execute',
        'description' => 'Get calendar modal data.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_get_app_widget' => [
        'classname' => 'local_la\\external\\app',
        'methodname' => 'widget',
        'description' => 'Get refreshed app widget html.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_delete_app_widget' => [
        'classname' => 'local_la\\external\\app',
        'methodname' => 'delete_widget',
        'description' => 'Delete an app widget.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_update_app_widget_state' => [
        'classname' => 'local_la\\external\\app',
        'methodname' => 'update_widget_state',
        'description' => 'Update an app widget UI state.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_get_marketplace' => [
        'classname' => 'local_la\\external\\marketplace',
        'methodname' => 'execute',
        'description' => 'Get marketplace html.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_marketplace_install_modal' => [
        'classname' => 'local_la\\external\\marketplace',
        'methodname' => 'install_modal',
        'description' => 'Get marketplace install review modal.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_marketplace_app_install_modal' => [
        'classname' => 'local_la\\external\\marketplace',
        'methodname' => 'app_install_modal',
        'description' => 'Get marketplace app install review modal.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_generated_install_modal' => [
        'classname' => 'local_la\\external\\marketplace',
        'methodname' => 'generated_install_modal',
        'description' => 'Get generated report install review modal.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_generate_report' => [
        'classname' => 'local_la\\external\\ai',
        'methodname' => 'generate_report',
        'description' => 'Generate a report definition using Moodle AI providers.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_share_report' => [
        'classname' => 'local_la\\external\\share',
        'methodname' => 'report',
        'description' => 'Share selected report rows by email.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_analyze_report_rows' => [
        'classname' => 'local_la\\external\\analysis',
        'methodname' => 'execute',
        'description' => 'Analyze selected report rows.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_clear_ai_history' => [
        'classname' => 'local_la\\external\\ai',
        'methodname' => 'clear_history',
        'description' => 'Clear AI report generation chat history.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_log_details' => [
        'classname' => 'local_la\\external\\logs',
        'methodname' => 'details',
        'description' => 'Get audit log details.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_library' => [
        'classname' => 'local_la\\external\\library',
        'methodname' => 'execute',
        'description' => 'Run library report action.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_library_sql' => [
        'classname' => 'local_la\\external\\library',
        'methodname' => 'execute_sql',
        'description' => 'Get library report SQL preview html.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_library_app_params' => [
        'classname' => 'local_la\\external\\library',
        'methodname' => 'execute_app_params',
        'description' => 'Get library app params preview html.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_update_sql_status' => [
        'classname' => 'local_la\\external\\library',
        'methodname' => 'update_sql_status',
        'description' => 'Update library SQL snippet status.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_report_details_modal' => [
        'classname' => 'local_la\\external\\report',
        'methodname' => 'modal',
        'description' => 'Get report details modal.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_save_report_details' => [
        'classname' => 'local_la\\external\\report',
        'methodname' => 'save',
        'description' => 'Save report details.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_delete_audience' => [
        'classname' => 'local_la\\external\\audience',
        'methodname' => 'delete',
        'description' => 'Delete one report audience type.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_save_audience' => [
        'classname' => 'local_la\\external\\audience',
        'methodname' => 'save',
        'description' => 'Save one report audience type.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_schedule_modal' => [
        'classname' => 'local_la\\external\\schedule',
        'methodname' => 'modal',
        'description' => 'Get report schedule modal.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_la_save_schedule' => [
        'classname' => 'local_la\\external\\schedule',
        'methodname' => 'save',
        'description' => 'Save report schedule.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_toggle_schedule' => [
        'classname' => 'local_la\\external\\schedule',
        'methodname' => 'toggle',
        'description' => 'Toggle report schedule.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_send_schedule' => [
        'classname' => 'local_la\\external\\schedule',
        'methodname' => 'send',
        'description' => 'Queue report schedule.',
        'type' => 'write',
        'ajax' => true,
    ],
    'local_la_delete_schedule' => [
        'classname' => 'local_la\\external\\schedule',
        'methodname' => 'delete',
        'description' => 'Delete report schedule.',
        'type' => 'write',
        'ajax' => true,
    ],
];
