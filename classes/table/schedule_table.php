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

namespace local_la\table;

use html_writer;
use local_la\local\schedule;
use local_la\local\url;

/**
 * Report schedules table.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_table extends general_table {
    /** @var int */
    protected $reportid;

    /** @var string */
    protected $search;

    /**
     * Constructor.
     *
     * @param int $reportid
     * @param string $search
     */
    public function __construct(int $reportid, string $search = '') {
        parent::__construct('local-la-schedules-' . $reportid);
        $this->reportid = $reportid;
        $this->search = $search;
    }

    /**
     * Configure table.
     *
     * @return void
     */
    public function load(): void {
        [$from, $where, $params] = schedule::get_records_sql($this->reportid, $this->search);

        $this->define_columns([
            'status',
            'name',
            'timestart',
            'timelastsent',
            'format',
            'timecreated',
            'timemodified',
            'modifiedby',
            'actions',
        ]);
        $this->define_headers([
            '',
            get_string('name', 'core'),
            get_string('startingfrom', 'local_la'),
            get_string('timelastsent', 'local_la'),
            get_string('format', 'local_la'),
            get_string('timecreated'),
            get_string('timemodified', 'local_la'),
            get_string('modifiedby', 'local_la'),
            '',
        ]);
        $this->collapsible(false);
        $this->sortable(true);
        $this->no_sorting('status');
        $this->no_sorting('actions');
        $this->no_sorting('modifiedby');
        $this->is_downloadable(false);
        $this->column_class('actions', 'text-end');
        $this->define_baseurl(url::report_tab_url($this->reportid, [
            'tab' => 'schedules',
            'schedule_search' => $this->search,
        ]));
        $this->set_sql(
            "s.id, s.reportid, s.name, s.status, s.timestart, s.timelastsent, s.format,
                    s.timecreated, s.timemodified, s.usermodified, s.usercreated,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename",
            $from,
            $where,
            $params
        );
        $this->set_count_sql("SELECT COUNT(1) FROM {$from} WHERE {$where}", $params);
    }

    /**
     * Default table sort.
     *
     * @return string
     */
    public function get_sql_sort() {
        return parent::get_sql_sort() ?: 'timecreated DESC, id DESC';
    }

    /**
     * Status column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_status(\stdClass $row): string {
        return html_writer::div(
            html_writer::empty_tag('input', [
                'class' => 'form-check-input',
                'type' => 'checkbox',
                'role' => 'switch',
                'data-action' => 'toggle-schedule',
                'data-schedule-id' => (int) $row->id,
                'checked' => !empty($row->status) ? 'checked' : null,
            ]),
            'form-check form-switch mb-0'
        );
    }

    /**
     * Name column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_name(\stdClass $row): string {
        return html_writer::span(s($row->name), '', ['data-region' => 'schedule-name']);
    }

    /**
     * Start time column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timestart(\stdClass $row): string {
        return $this->format_time((int) $row->timestart);
    }

    /**
     * Time last sent column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timelastsent(\stdClass $row): string {
        return empty($row->timelastsent) ? get_string('never') : $this->format_time((int) $row->timelastsent);
    }

    /**
     * Format column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_format(\stdClass $row): string {
        $component = 'dataformat_' . $row->format;

        return get_string_manager()->string_exists('dataformat', $component) ?
            get_string('dataformat', $component) : s($row->format);
    }

    /**
     * Time created column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timecreated(\stdClass $row): string {
        return $this->format_time((int) $row->timecreated);
    }

    /**
     * Time modified column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_timemodified(\stdClass $row): string {
        return $this->format_time((int) $row->timemodified);
    }

    /**
     * Modified by column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_modifiedby(\stdClass $row): string {
        return empty($row->usermodified) ? '-' : fullname($row);
    }

    /**
     * Actions column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_actions(\stdClass $row): string {
        $name = format_string((string) $row->name);

        return html_writer::div(
            html_writer::div(
                html_writer::div(
                    html_writer::div(
                        html_writer::link(
                            '#',
                            html_writer::tag('i', '', [
                                'class' => 'icon fa fa-ellipsis-vertical fa-fw',
                                'aria-hidden' => 'true',
                            ]) .
                            html_writer::span(get_string('actions', 'core'), 'visually-hidden') .
                            html_writer::tag('b', '', ['class' => 'caret']),
                            [
                            'class' => 'btn btn-icon d-flex no-caret dropdown-toggle icon-no-margin',
                            'id' => 'la-schedule-action-menu-toggle-' . (int) $row->id,
                            'aria-label' => get_string('actions', 'core'),
                            'data-toggle' => 'dropdown',
                            'role' => 'button',
                            'aria-haspopup' => 'true',
                            'aria-expanded' => 'false',
                            'aria-controls' => 'la-schedule-action-menu-' . (int) $row->id . '-menu',
                            'title' => get_string('actions', 'core'),
                            ]
                        ) . $this->action_menu($row, $name),
                        'dropdown'
                    ),
                    'action-menu-trigger'
                ),
                'menubar d-flex justify-content-end',
                ['id' => 'la-schedule-action-menu-' . (int) $row->id . '-menubar']
            ),
            'action-menu moodle-actionmenu',
            [
                'id' => 'la-schedule-action-menu-' . (int) $row->id,
                'data-enhance' => 'moodle-core-actionmenu',
            ]
        );
    }

    /**
     * Build action menu.
     *
     * @param \stdClass $row
     * @param string $name
     * @return string
     */
    protected function action_menu(\stdClass $row, string $name): string {
        $items = [
            [
                'action' => 'schedule-edit',
                'icon' => 'fa-pen',
                'label' => get_string('editscheduledetails', 'local_la'),
                'class' => '',
            ],
            [
                'action' => 'schedule-send',
                'icon' => 'fa-play',
                'label' => get_string('sendschedule', 'local_la'),
                'class' => '',
            ],
            [
                'action' => 'schedule-delete',
                'icon' => 'fa-trash-can',
                'label' => get_string('deleteschedule', 'local_la'),
                'class' => ' text-danger',
            ],
        ];

        $links = '';
        foreach ($items as $item) {
            $attributes = [
                'class' => 'dropdown-item menu-action' . $item['class'],
                'data-action' => $item['action'],
                'data-schedule-id' => (int) $row->id,
                'aria-label' => $item['label'],
                'role' => 'menuitem',
                'tabindex' => '-1',
            ];
            if ($item['action'] === 'schedule-edit') {
                $attributes['data-report-id'] = (int) $row->reportid;
            } else {
                $attributes['data-schedule-name'] = $name;
            }

            $links .= html_writer::link('#', html_writer::tag('i', '', [
                'class' => 'icon fa ' . $item['icon'] . ' fa-fw',
                'aria-hidden' => 'true',
            ]) . html_writer::span($item['label'], 'menu-action-text'), $attributes);
        }

        return html_writer::div($links, 'dropdown-menu menu dropdown-menu-end', [
            'id' => 'la-schedule-action-menu-' . (int) $row->id . '-menu',
            'data-rel' => 'menu-content',
            'aria-labelledby' => 'la-schedule-action-menu-toggle-' . (int) $row->id,
            'role' => 'menu',
        ]);
    }

    /**
     * Format timestamp.
     *
     * @param int $time
     * @return string
     */
    protected function format_time(int $time): string {
        return $time > 0 ? userdate($time, get_string('strftimedatetime', 'langconfig')) : '-';
    }
}
