<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Deliver due Learning Analytics report schedules.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_report_schedules extends \core\task\scheduled_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksendreportschedules', 'local_la');
    }

    /**
     * Execute scheduled deliveries.
     */
    public function execute(): void {
        \local_la\local\schedule::process_due_schedules();
    }
}
