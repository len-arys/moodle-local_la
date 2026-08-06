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

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/tablelib.php');

use html_writer;
use local_la\local\formula;
use local_la\local\filters;
use local_la\local\helper;
use local_la\local\report as report_helper;
use local_la\local\repository;
use local_la\local\table as table_helper;
use local_la\local\calendar as calendar_manager;
use local_la\local\url;
use paging_bar;

/**
 * Report table.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_table extends \table_sql {
    /** @var bool Whether to wrap the table for horizontal scrolling. */
    public bool $responsive = true;

    /** @var int */
    protected $reportid;

    /** @var \stdClass */
    protected $report;

    /** @var array */
    protected $config = [];

    /** @var string */
    protected $reportsql = '';

    /** @var array */
    protected $filters = [];

    /** @var bool */
    protected $ispreview = false;

    /** @var bool */
    protected $candrilldown = false;

    /** @var array */
    protected $reports = [];

    /**
     * Constructor.
     *
     * @param int $reportid
     * @param array $filters
     * @param bool $ispreview
     */
    public function __construct(int $reportid, array $filters = [], bool $ispreview = false) {
        parent::__construct('local-la-report-' . $reportid);
        $this->reportid = $reportid;
        $this->filters = $filters;
        $this->ispreview = $ispreview;
        $this->pagesize = $ispreview ? 100 : 25;
    }

    /**
     * Load report data and configure the table.
     *
     * @param \stdClass|null $report
     * @return void
     */
    public function load(?\stdClass $report = null): void {
        $report = $report ?? repository::get_report($this->reportid);

        if (!$report) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $context = report_helper::get_context($report, $this->filters);

        if (!$context) {
            throw new \moodle_exception('errorinvalidreportconfig', 'local_la');
        }

        $this->report = $context->report;
        $this->config = $context->params;
        $this->reportsql = $context->sql;
        $this->reports = repository::get_all_reports();
        $this->candrilldown = helper::can_use_drilldown();
        $columns = $this->get_table_columns($context->columns);
        $headers = [];

        foreach ($columns as $column) {
            $headers[] = $this->get_column_label($column);
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->collapsible(false);
        $this->sortable(!$this->ispreview);
        $this->is_downloadable(!$this->ispreview);
        $this->show_download_buttons_at($this->ispreview ? [] : [TABLE_P_TOP, TABLE_P_BOTTOM]);
        $this->no_sorting('selectrow');
        $this->no_sorting('actions');
        $this->column_class('selectrow', 'text-center align-middle');
        $this->column_class('actions', 'text-end align-middle');

        foreach ($columns as $column) {
            $columnconfig = $this->get_column_config($column);
            $columnclass = trim(($columnconfig['class'] ?? '') . ' ' . ($columnconfig['attributes']['class'] ?? ''));

            if ($columnclass !== '') {
                $this->column_class($column, $columnclass);
            }

            if (!in_array($column, $context->columns, true) ||
                (array_key_exists('sortable', $columnconfig) && empty($columnconfig['sortable']))) {
                $this->no_sorting($column);
            }
        }

        $fields = [];
        foreach (($context->selectcolumns ?? $context->columns) as $column) {
            $fields[] = "reportdata.{$column}";
        }

        $this->set_sql(implode(', ', $fields), "({$this->reportsql}) reportdata", '1 = 1', $context->queryparams);
    }

    /**
     * Get one column config.
     *
     * @param string $column
     * @return array
     */
    protected function get_column_config(string $column): array {
        if (isset($this->config['columns'][$column])) {
            return $this->config['columns'][$column];
        }

        foreach (($this->config['columns'] ?? []) as $key => $columnconfig) {
            if ($this->get_column_output_name((string) $key, $columnconfig) === $column) {
                return $columnconfig;
            }
        }

        return [];
    }

    /**
     * Get output column name for one configured column.
     *
     * @param string $key
     * @param array $columnconfig
     * @return string
     */
    protected function get_column_output_name(string $key, array $columnconfig): string {
        $alias = trim((string) (($columnconfig['sql'] ?? [])['alias'] ?? ''));

        return $alias !== '' ? $alias : $key;
    }

    /**
     * Get column label.
     *
     * @param string $column
     * @return string
     */
    protected function get_column_label(string $column): string {
        $columnconfig = $this->get_column_config($column);

        if (!empty($columnconfig['name'])) {
            return (string) $columnconfig['name'];
        }

        if ($column === 'selectrow') {
            return '<input type="checkbox" class="form-check-input" data-region="select-all-rows" aria-label="' .
                s(get_string('selectall')) . '">';
        }

        if ($column === 'actions') {
            return '';
        }

        return ucwords(str_replace('_', ' ', $column));
    }

    /**
     * Build visible table columns.
     *
     * @param string[] $sqlcolumns
     * @return string[]
     */
    protected function get_table_columns(array $sqlcolumns): array {
        $columns = [];

        if (!$this->ispreview && !$this->is_downloading() && !empty($this->config['has_checkbox'])) {
            $columns[] = 'selectrow';
        }

        $ordered = [];
        foreach ($sqlcolumns as $index => $column) {
            $columnconfig = $this->get_column_config($column);

            if (!report_helper::is_column_enabled($columnconfig)) {
                continue;
            }

            if (array_key_exists('visible', $columnconfig) && empty($columnconfig['visible'])) {
                continue;
            }

            $ordered[$column] = (int) ($columnconfig['order'] ?? (($index + 1) * 10));
        }

        foreach (($this->config['columns'] ?? []) as $column => $columnconfig) {
            $outputname = $this->get_column_output_name((string) $column, $columnconfig);

            if (in_array($outputname, $sqlcolumns, true)) {
                continue;
            }

            if (!report_helper::is_column_enabled($columnconfig)) {
                continue;
            }

            if (array_key_exists('visible', $columnconfig) && empty($columnconfig['visible'])) {
                continue;
            }

            $ordered[$outputname] = (int) ($columnconfig['order'] ?? 9999);
        }

        asort($ordered);
        $columns = array_merge($columns, array_keys($ordered));

        if (!$this->ispreview && !$this->is_downloading() && !empty($this->config['menu']['enable'])) {
            $columns[] = 'actions';
        }

        return $columns;
    }

    /**
     * Render generic configured columns.
     *
     * @param string $column
     * @param \stdClass $row
     * @return string|null
     */
    public function other_cols($column, $row) {
        if ($column === 'selectrow' || $column === 'actions') {
            return null;
        }

        $columnconfig = $this->get_column_config($column);
        $value = $this->get_display_value($column, $row);
        $linktype = (string) ($columnconfig['link']['type'] ?? '');

        if (!empty($columnconfig['synthetic'])) {
            return html_writer::span(
                (string) $value,
                '',
                ['data-synthetic-value' => (string) $this->get_synthetic_numeric_value($row, $column)]
            );
        }

        if ($linktype === 'url' && !empty($columnconfig['link']['url'])) {
            $url = table_helper::interpolate_value_template((string) $columnconfig['link']['url'], $row);
            $attributes = [];

            if (!empty($columnconfig['link']['target'])) {
                $attributes['target'] = $columnconfig['link']['target'];
            }

            return html_writer::link($url, (string) $value, $attributes);
        }

        if (!$this->candrilldown && in_array($linktype, ['modal', 'report'], true)) {
            return $value === null ? null : (string) $value;
        }

        if ($linktype === 'modal') {
            $classes = trim('la-modal-link ' . (string) ($columnconfig['link']['class'] ?? ''));
            $url = !empty($columnconfig['link']['url']) ?
                table_helper::interpolate_value_template((string) $columnconfig['link']['url'], $row) : '#';
            $method = (string) ($columnconfig['link']['method'] ?? ('local_la_get_report_' . $column));
            $params = table_helper::interpolate_template_value($columnconfig['link']['params'] ?? [], $row);
            if ($method === 'local_la_get_calendar') {
                $params = $this->normalise_calendar_link_params($params);
            }
            $paramsjson = json_encode($params);

            if (!empty($columnconfig['link']['id'])) {
                $id = (int) table_helper::interpolate_value_template((string) $columnconfig['link']['id'], $row);
            } else if (!empty($columnconfig['link']['userid'])) {
                $id = (int) table_helper::interpolate_value_template((string) $columnconfig['link']['userid'], $row);
            } else {
                $id = (int) ($row->id ?? 0);
            }

            return html_writer::link($url, (string) $value, [
                'class' => $classes,
                'data-action' => 'open-report-modal-link',
                'data-url' => $url,
                'data-method' => $method,
                'data-id' => $id,
                'data-params' => $paramsjson === false ? '{}' : $paramsjson,
                'data-title' => (string) ($columnconfig['name'] ?? $value),
            ]);
        }

        if ($linktype === 'report') {
            $classes = trim('la-modal-link ' . (string) ($columnconfig['link']['class'] ?? ''));
            $filters = table_helper::interpolate_template_value($columnconfig['link']['filters'] ?? [], $row);
            $columns = table_helper::interpolate_template_value($columnconfig['link']['columns'] ?? [], $row);
            $metrics = table_helper::interpolate_template_value($columnconfig['link']['metrics'] ?? [], $row);
            $params = table_helper::interpolate_template_value($columnconfig['link']['params'] ?? [], $row);

            $filtersjson = json_encode($filters);
            $columnsjson = json_encode($columns);
            $metricsjson = json_encode($metrics);
            $paramsjson = json_encode($params);
            $summary = table_helper::build_report_link_summary($columnconfig['link']['filters'] ?? [], $row);
            $summaryjson = json_encode($summary);

            return html_writer::link('#', (string) $value, [
                'class' => $classes,
                'data-action' => 'open-report-modal-link',
                'data-report' => (string) ($columnconfig['link']['report'] ?? ''),
                'data-filters' => $filtersjson === false ? '{}' : $filtersjson,
                'data-columns' => $columnsjson === false ? '[]' : $columnsjson,
                'data-metrics' => $metricsjson === false ? '[]' : $metricsjson,
                'data-params' => $paramsjson === false ? '{}' : $paramsjson,
                'data-title' => (string) ($columnconfig['name'] ?? $value),
                'data-summary' => $summaryjson === false ? '{}' : $summaryjson,
            ]);
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Make calendar link params safe when optional report columns are not selected.
     *
     * @param array $params
     * @return array
     */
    protected function normalise_calendar_link_params(array $params): array {
        foreach (['userid', 'courseid', 'activityid', 'instanceid'] as $key) {
            $value = (string) ($params[$key] ?? '0');
            $params[$key] = preg_match('/^\{\{[^}]+\}\}$/', $value) ? 0 : (int) $value;
        }

        if (($params['scope'] ?? '') === 'user_activity' && empty($params['activityid'])) {
            $params['scope'] = empty($params['courseid']) ? 'user' : 'user_course';
        }

        return $params;
    }

    /**
     * Get display-ready value for a configured column.
     *
     * @param string $column
     * @param \stdClass $row
     * @return mixed
     */
    protected function get_display_value(string $column, \stdClass $row) {
        $value = $row->$column ?? null;
        $columnconfig = $this->get_column_config($column);
        $formula = trim((string) ($columnconfig['formula'] ?? ''));

        if ($formula !== '') {
            $computed = formula::evaluate($formula, $row);

            if ($computed !== null) {
                $value = $computed;
            }
        }

        if (($columnconfig['type'] ?? '') === 'time') {
            $timestamp = (int) $value;
            $value = $timestamp > 0 ? userdate($timestamp) : '-';
        } else if (($columnconfig['type'] ?? '') === 'bool') {
            $value = !empty($value) ? get_string('yes') : get_string('no');
        }

        if (!empty($columnconfig['processor']) && method_exists($this, $columnconfig['processor'])) {
            $value = $this->{$columnconfig['processor']}($row, $column, $value);
        }

        return $value;
    }
    /**
     * Checkbox column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_selectrow(\stdClass $row): string {
        $payload = [
            'id' => (string) ($row->id ?? ''),
        ];

        foreach (($this->config['columns'] ?? []) as $key => $columnconfig) {
            if (empty($columnconfig['name'])) {
                continue;
            }

            $outputname = $this->get_column_output_name((string) $key, $columnconfig);
            $value = $this->get_display_value($outputname, $row);

            if (is_string($value)) {
                $value = trim(strip_tags($value));
            }

            $payload[$outputname] = $value === null ? '' : (string) $value;

            if ($outputname !== $key) {
                $payload[$key] = $payload[$outputname];
            }
        }

        $json = json_encode($payload);

        return html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'class' => 'form-check-input',
            'value' => (string) ($row->id ?? ''),
            'data-region' => 'select-row',
            'data-row' => $json === false ? '{}' : $json,
            'aria-label' => get_string('select'),
        ]);
    }

    /**
     * Placeholder actions column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_actions(\stdClass $row): string {
        $groups = $this->get_action_menu($row);

        if (empty($groups)) {
            return '';
        }

        $toggleid = 'la-report-actions-toggle-' . (int) ($row->id ?? random_int(1, 999999));

        $button = html_writer::tag('button',
            '<i class="fa fa-ellipsis-vertical"></i>',
            [
                'type' => 'button',
                'class' => 'btn btn-icon btn-link text-decoration-none p-0 text-primary',
                'data-toggle' => 'dropdown',
                'aria-haspopup' => 'true',
                'aria-expanded' => 'false',
                'aria-label' => get_string('actions', 'core'),
                'id' => $toggleid,
            ]
        );

        $menu = html_writer::start_div('dropdown-menu dropdown-menu-end dropdown-menu-right la-report-row-actions-menu', [
            'aria-labelledby' => $toggleid,
        ]);

        foreach ($groups as $group) {
            if (!empty($group['header'])) {
                $menu .= html_writer::tag('h6', (string) $group['header'], ['class' => 'dropdown-header']);
            }

            foreach (($group['items'] ?? []) as $item) {
                $attributes = ['class' => 'dropdown-item'];

                if (!empty($item['target'])) {
                    $attributes['target'] = (string) $item['target'];
                    $attributes['rel'] = 'noopener noreferrer';
                }

                $label = (string) ($item['label'] ?? '');

                $marker = !empty($item['moodle']) ?
                    html_writer::span('m', 'opacity-25 small') :
                    html_writer::span('›', 'opacity-25', ['aria-hidden' => 'true']);

                $label = html_writer::span($label) .
                    html_writer::span($marker, 'ms-auto ml-auto');
                $attributes['class'] .= ' d-flex align-items-center gap-2 la-report-row-action';

                $menu .= html_writer::link(
                    table_helper::interpolate_value_template((string) ($item['url'] ?? '#'), $row),
                    $label,
                    $attributes
                );
            }
        }

        $menu .= html_writer::end_div();

        return html_writer::div($button . $menu, 'dropdown');
    }

    /**
     * Build row action menu groups.
     *
     * @param \stdClass $row
     * @return array
     */
    protected function get_action_menu(\stdClass $row): array {
        $groups = [];
        $menuconfig = $this->config['menu'] ?? [];

        foreach (($menuconfig['items'] ?? []) as $groupconfig) {
            $items = [];

            foreach (($groupconfig['items'] ?? []) as $itemconfig) {
                $resolveditems = $this->get_menu_items($row, $itemconfig);

                foreach ($resolveditems as $item) {
                    if (!empty($item)) {
                        $items[] = $item;
                    }
                }
            }

            if (!empty($items)) {
                $groups[] = [
                    'header' => (string) ($groupconfig['header'] ?? ''),
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }

    /**
     * Build one or more menu items from config.
     *
     * @param \stdClass $row
     * @param array $itemconfig
     * @return array
     */
    protected function get_menu_items(\stdClass $row, array $itemconfig): array {
        $type = trim((string) ($itemconfig['type'] ?? ''));

        if ($type === 'preset') {
            $preset = trim((string) ($itemconfig['preset'] ?? ''));
            return table_helper::get_menu_preset_items($preset, $row);
        }

        if ($type === 'url') {
            if (empty($itemconfig['label']) || empty($itemconfig['url'])) {
                return [];
            }

            return [[
                'label' => (string) $itemconfig['label'],
                'url' => (string) $itemconfig['url'],
                'target' => (string) ($itemconfig['target'] ?? ''),
                'moodle' => !empty($itemconfig['moodle']),
            ]];
        }

        if ($type === '' || $type === 'report') {
            $item = $this->get_report_menu_item($row, $itemconfig);
            return !empty($item) ? [$item] : [];
        }

        return [];
    }

    /**
     * Build one report menu item.
     *
     * @param \stdClass $row
     * @param array $itemconfig
     * @return array
     */
    protected function get_report_menu_item(\stdClass $row, array $itemconfig): array {
        $reportshortname = trim((string) ($itemconfig['report'] ?? ''));
        $configuredfilters = $itemconfig['filters'] ?? [];
        $filterkey = trim((string) ($itemconfig['filter'] ?? ''));
        $sourcecolumn = trim((string) ($itemconfig['column'] ?? '')) ?: $filterkey;

        if ($reportshortname === '') {
            return [];
        }

        if (empty($this->reports[$reportshortname]['id']) || empty($this->reports[$reportshortname]['name'])) {
            return [];
        }

        $reportconfig = $this->reports[$reportshortname];
        $filterparams = [];

        if (!empty($configuredfilters) && is_array($configuredfilters)) {
            foreach ($configuredfilters as $key => $value) {
                $resolved = table_helper::interpolate_template_value($value, $row);

                if (is_array($resolved) && isset($resolved['value'])) {
                    $filterparams[(string) $key] = $resolved;
                    continue;
                }

                if ($resolved === null || $resolved === '') {
                    continue;
                }

                $filterparams[(string) $key] = [
                    'operator' => 'equal',
                    'value' => is_array($resolved) ? $resolved : (string) $resolved,
                ];
            }
        } else {
            if ($filterkey === '' || $sourcecolumn === '') {
                return [];
            }

            $filtervalue = $row->{$sourcecolumn} ?? null;
            if ($filtervalue === null || $filtervalue === '') {
                return [];
            }

            $filterparams[$filterkey] = [
                'operator' => 'equal',
                'value' => is_array($filtervalue) ? $filtervalue : (string) $filtervalue,
            ];
        }

        if (empty($filterparams)) {
            return [];
        }

        return [
            'label' => (string) ($itemconfig['label'] ?? $reportconfig['name']),
            'url' => url::report_tab(
                (int) $reportconfig['id'],
                filters::get_params($filterparams, ['tab' => 'view'], true)
            ),
        ];
    }

    /**
     * Format placeholder progress column.
     *
     * @param \stdClass $row
     * @param string $column
     * @param mixed $value
     * @return string
     */
    protected function format_progress(\stdClass $row, string $column, $value): string {
        $columnconfig = $this->get_column_config($column);
        $values = array_values($columnconfig['values'] ?? []);
        $totalfield = (string) ($values[0] ?? '');
        $completedfield = (string) ($values[1] ?? '');
        $total = $totalfield !== '' ? (float) ($row->$totalfield ?? 0) : 0.0;
        $completed = $completedfield !== '' ? (float) ($row->$completedfield ?? 0) : 0.0;

        if ($total < 0) {
            $total = 0.0;
        }

        if ($completed < 0) {
            $completed = 0.0;
        }

        if ($completed > $total && $total > 0) {
            $completed = $total;
        }

        $percent = 0;
        if ($total > 0) {
            $percent = (int) round(($completed / $total) * 100);
            $percent = max(0, min(100, $percent));
        }

        $bucket = (int) (ceil($percent / 10) * 10);
        $bucket = max(0, min(100, $bucket));

        return html_writer::div(
            html_writer::div((int) $completed . ' / ' . (int) $total, 'small mb-2') .
            html_writer::div(
                html_writer::div('', 'progress-bar bg-warning rounded-pill la-report-progress-fill-' . $bucket),
                'progress rounded-pill bg-light'
            ),
            'la-report-progress-cell'
        );
    }

    /**
     * Format user status from machine key to visible text.
     *
     * @param \stdClass $row
     * @param string $column
     * @param mixed $value
     * @return string
     */
    protected function format_user_status(\stdClass $row, string $column, $value): string {
        $status = trim((string) $value);

        if ($status === '') {
            return '-';
        }

        $labels = [
            'active' => get_string('active'),
            'suspended' => get_string('suspended'),
            'deleted' => get_string('deleted'),
            'completed' => get_string('completed'),
            'inprogress' => get_string('inprogress', 'local_la'),
            'notstarted' => get_string('notstarted', 'local_la'),
            'visible' => get_string('visible'),
            'hidden' => get_string('hidden', 'grades'),
        ];
        $label = $labels[$status] ?? ucfirst($status);
        $safeclass = preg_replace('/[^a-z0-9_-]/', '', strtolower($status)) ?: 'default';

        return html_writer::span($label, 'la-status-pill la-status-pill-' . $safeclass);
    }

    /**
     * Format activity visibility from raw bool to visible text.
     *
     * @param \stdClass $row
     * @param string $column
     * @param mixed $value
     * @return string
     */
    protected function format_visibility_status(\stdClass $row, string $column, $value): string {
        return $this->format_user_status($row, $column, !empty($row->$column) ? 'visible' : 'hidden');
    }

    /**
     * Format user name with avatar.
     *
     * @param \stdClass $row
     * @param string $column
     * @param mixed $value
     * @return string
     */
    protected function format_user_avatar_name(\stdClass $row, string $column, $value): string {
        global $PAGE;

        $userid = (int) ($row->user_id ?? $row->userid ?? $row->id ?? 0);
        $user = (object) [
            'id' => $userid,
            'firstname' => (string) ($row->firstname ?? ''),
            'lastname' => (string) ($row->lastname ?? ''),
            'firstnamephonetic' => (string) ($row->firstnamephonetic ?? ''),
            'lastnamephonetic' => (string) ($row->lastnamephonetic ?? ''),
            'middlename' => (string) ($row->middlename ?? ''),
            'alternatename' => (string) ($row->alternatename ?? ''),
            'picture' => (int) ($row->picture ?? 0),
            'imagealt' => (string) ($row->imagealt ?? ''),
            'email' => (string) ($row->email ?? ''),
        ];

        $picture = new \user_picture($user);
        $picture->size = 30;
        $imageurl = $picture->get_url($PAGE)->out(false);
        $label = trim((string) $value) ?: fullname($user);

        $avatar = html_writer::empty_tag('img', [
            'src' => $imageurl,
            'alt' => $label,
            'class' => 'la-user-avatar',
            'loading' => 'lazy',
        ]);

        return html_writer::div(
            $avatar . html_writer::span($label, 'la-user-avatar-name'),
            'la-user-avatar-cell'
        );
    }

    /**
     * Format seconds to readable duration.
     *
     * @param \stdClass $row
     * @param string $column
     * @param mixed $value
     * @return string
     */
    protected function format_duration(\stdClass $row, string $column, $value): string {
        return calendar_manager::format_duration_value((int) $value);
    }

    /**
     * Format synthetic total from already loaded synthetic columns.
     *
     * @param \stdClass $row
     * @param string $column
     * @param mixed $value
     * @return string
     */
    protected function format_synthetic_total(\stdClass $row, string $column, $value): string {
        $total = $this->get_synthetic_numeric_value($row, $column);
        $columnconfig = $this->get_column_config($column);

        if (($columnconfig['metric'] ?? '') === 'timesec') {
            return calendar_manager::format_duration_value($total);
        }

        return (string) $total;
    }

    /**
     * Get raw numeric value for one synthetic column.
     *
     * @param \stdClass $row
     * @param string $column
     * @return int
     */
    protected function get_synthetic_numeric_value(\stdClass $row, string $column): int {
        if ($column !== 'synthetic_total') {
            return (int) ($row->$column ?? 0);
        }

        $total = 0;

        foreach (($this->config['columns'] ?? []) as $key => $columnconfig) {
            if (empty($columnconfig['synthetic'])) {
                continue;
            }

            $outputname = $this->get_column_output_name((string) $key, $columnconfig);
            if ($outputname === 'synthetic_total') {
                continue;
            }

            $total += (int) ($row->$outputname ?? 0);
        }

        return $total;
    }

    /**
     * Start table HTML without top controls.
     *
     * @return void
     */
    public function start_html() {
        echo $this->get_dynamic_table_html_start();
        $this->wrap_html_start();

        if ($this->responsive) {
            echo html_writer::start_tag('div', ['class' => 'table-responsive']);
        }

        echo html_writer::start_tag('table', $this->attributes) . $this->render_caption();
    }

    /**
     * Finish table HTML with footer controls only.
     *
     * @return void
     */
    public function finish_html() {
        global $OUTPUT;

        if (!$this->started_output) {
            $this->print_nothing_to_display();
            return;
        }

        $emptyrow = array_fill(0, count($this->columns), '');
        while ($this->currentrow < $this->pagesize) {
            $this->print_row($emptyrow, 'emptyrow');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');

        if ($this->responsive) {
            echo html_writer::end_tag('div');
        }

        $this->wrap_html_finish();

        echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3');

        if (in_array(TABLE_P_BOTTOM, $this->showdownloadbuttonsat)) {
            echo html_writer::div($this->download_buttons(), 'order-2 order-md-1');
        }

        if ($this->use_pages) {
            $pagingbar = new paging_bar($this->totalrows, $this->currpage, $this->pagesize, $this->baseurl);
            $pagingbar->pagevar = $this->request[TABLE_VAR_PAGE];
            echo html_writer::div($OUTPUT->render($pagingbar), 'order-1 order-md-2 ms-md-auto');
        }

        echo html_writer::end_div();
        echo $this->get_debug_sql_html();
        echo $this->get_dynamic_table_html_end();
    }

    /**
     * Render final SQL below the table for local debugging.
     *
     * @return string
     */
    protected function get_debug_sql_html(): string {
        if (!helper::is_debug_enabled() || $this->reportsql === '') {
            return '';
        }

        return html_writer::tag('details',
            html_writer::tag('summary', 'SQL') .
            html_writer::tag('pre', s($this->reportsql), ['class' => 'la-table-sql-debug mb-0']),
            ['class' => 'la-table-sql-debug-wrap mt-3']
        );
    }
}
