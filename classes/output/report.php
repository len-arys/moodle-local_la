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

namespace local_la\output;

defined('MOODLE_INTERNAL') || die();

use local_la\local\calendar;
use local_la\local\filters;
use local_la\local\helper;
use local_la\local\report as report_helper;
use local_la\local\repository;
use local_la\local\synthetic;
use local_la\local\url;
use local_la\table\report_table;
use renderer_base;
use renderable;
use templatable;

/**
 * Report page renderable.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report implements renderable, templatable {
    /** @var \stdClass */
    protected $report;

    /** @var string */
    protected $tab = 'view';

    /** @var report_table|null */
    protected $table = null;

    /** @var array */
    protected $filters = [];

    /**
     * Constructor.
     *
     * @param \stdClass $report
     * @param string $tab
     */
    public function __construct(\stdClass $report, string $tab = 'view') {
        $this->report = $report;
        $this->tab = $tab;
        $this->filters = filters::get_values($this->report->params);
    }

    /**
     * Get active tab.
     *
     * @return string
     */
    public function get_tab(): string {
        return $this->tab;
    }

    /**
     * Build configured report table.
     *
     * @return report_table
     */
    public function get_report_table(): report_table {
        if ($this->table !== null) {
            return $this->table;
        }

        $table = new report_table((int) $this->report->id, $this->filters);
        $table->load();
        $table->baseurl = url::report_tab_url((int) $this->report->id, filters::get_params(
            $this->filters,
            ['tab' => 'view'] + synthetic::get_url_params(),
            true
        ));

        $this->table = $table;

        return $this->table;
    }

    /**
     * Render report table HTML for the view tab.
     *
     * @return string
     */
    public function get_view_table_html(): string {
        $table = $this->get_report_table();

        ob_start();
        $table->out(25, false);
        $html = ob_get_clean();

        return $html === false ? '' : $html;
    }

    /**
     * Handle report download request.
     *
     * @param string $download
     * @return void
     */
    public function report_download(string $download = ''): void {
        if ($download === '') {
            return;
        }

        $table = $this->get_report_table();
        $table->is_downloading($download, clean_filename($this->report->name), format_string($this->report->name));

        if ($table->is_downloading()) {
            $table->out(0, false);
            exit;
        }
    }

    /**
     * Build metric avatars from real users.
     *
     * @return array
     */
    protected function get_metric_avatars(): array {
        global $PAGE, $USER;

        $users = repository::get_report_users((int) $this->report->id, 6);
        $avatars = [];

        if (empty($users)) {
            return [];
        }

        $lastindex = count($users) - 1;

        foreach ($users as $index => $user) {
            $picture = new \user_picture($user);
            $picture->size = 23;
            $label = fullname($user);

            if ((int) $user->id === (int) $USER->id) {
                $label .= ' (You)';
            }

            $avatars[] = [
                'imageurl' => $picture->get_url($PAGE)->out(false),
                'label' => $label,
                'active' => $index === $lastindex,
            ];
        }

        return $avatars;
    }

    /**
     * Build report metric values.
     *
     * @return array
     */
    protected function get_metric_values(float $loadingtime = 0): array {
        global $USER;

        $metrics = repository::get_report_time_metrics((int) $this->report->id, (int) $USER->id);

        return [
            [
                'key' => 'timeonreport',
                'value' => calendar::format_duration_value((int) $metrics['timesec']),
                'label' => get_string('timeonreport', 'local_la'),
            ],
            [
                'key' => 'visitsonreport',
                'value' => (string) $metrics['visits'],
                'label' => get_string('visitsonreport', 'local_la'),
            ],
            [
                'key' => 'loadingtime',
                'value' => $this->format_loading_time($loadingtime),
                'label' => get_string('loadingtime', 'local_la'),
                'active' => true,
            ],
        ];
    }

    /**
     * Format report generation time.
     *
     * @param float $seconds
     * @return string
     */
    protected function format_loading_time(float $seconds): string {
        if ($seconds <= 0) {
            return '-';
        }

        return number_format($seconds, 2) . 's';
    }

    /**
     * Build report page tabs.
     *
     * @return array
     */
    protected function get_tabs(): array {
        $tabs = [
            [
                'name' => get_string('view', 'core'),
                'url' => url::report_tab((int) $this->report->id, ['tab' => 'view']),
                'active' => $this->tab === 'view',
            ],
            [
                'name' => get_string('schedules', 'local_la'),
                'url' => url::report_tab((int) $this->report->id, ['tab' => 'schedules']),
                'active' => $this->tab === 'schedules',
            ],
        ];

        if (helper::is_admin()) {
            array_splice($tabs, 1, 0, [[
                'name' => get_string('audience', 'local_la'),
                'url' => url::report_tab((int) $this->report->id, ['tab' => 'audience']),
                'active' => $this->tab === 'audience',
            ]]);
            array_splice($tabs, 3, 0, [[
                'name' => get_string('access', 'local_la'),
                'url' => url::report_tab((int) $this->report->id, ['tab' => 'access']),
                'active' => $this->tab === 'access',
            ]]);
            $tabs[] = [
                'name' => get_string('auditlogs', 'local_la'),
                'url' => url::report_tab((int) $this->report->id, ['tab' => 'auditlogs']),
                'active' => $this->tab === 'auditlogs',
                'badge' => helper::has_feature('audit_logs') ? '' : get_string('upgrade', 'local_la'),
            ];
        }

        return $tabs;
    }

    /**
     * Get filter operator options for one filter type.
     *
     * @param string $type
     * @param string $current
     * @return array
     */
    protected function get_filter_operator_options(string $type, string $current): array {
        if (in_array($type, ['select', 'users', 'courses'], true)) {
            $operators = ['any', 'equal', 'notequal'];
        } else if ($type === 'date') {
            $operators = ['any', 'range', 'before', 'after'];
        } else {
            $operators = ['any', 'contains', 'notcontains', 'equal', 'notequal', 'startswith', 'endswith', 'empty', 'notempty'];
        }

        $items = [];

        foreach ($operators as $operator) {
            $items[] = [
                'value' => $operator,
                'name' => get_string('filter_' . $operator, 'local_la'),
                'selected' => $operator === $current,
            ];
        }

        return $items;
    }

    /**
     * Get select options for one filter definition.
     *
     * @param array $definition
     * @return array
     */
    protected function get_filter_select_options(array $definition): array {
        $source = (string) ($definition['source'] ?? '');

        if ($source !== '' && method_exists(repository::class, $source)) {
            $items = $source === 'get_filter_menu_options' ? repository::$source($definition) : repository::$source();

            $options = [];

            foreach ($items as $item) {
                $options[(string) $item['value']] = (string) $item['name'];
            }

            return $options;
        }

        $options = is_array($definition['options'] ?? null) ? $definition['options'] : [];

        foreach ($options as $value => $label) {
            $identifier = (string) $label;
            $options[$value] = get_string_manager()->string_exists($identifier, 'local_la') ?
                get_string($identifier, 'local_la') : $identifier;
        }

        return $options;
    }

    protected function get_filter_item(string $key, array $column, array $filter): array {
        $definition = $column['filter'] ?? [];
        $type = (string) ($definition['type'] ?? 'text');
        $operator = (string) ($filter['operator'] ?? 'any');
        $showvalue = !in_array($operator, ['any', 'empty', 'notempty', 'range'], true);
        $showrange = $operator === 'range';
        $names = filters::get_param_names($key);
        $options = [];

        if ($type === 'users') {
            $selectedvalues = is_array($filter['value'] ?? null) ? $filter['value'] : [];

            foreach (repository::get_filter_selected_users($selectedvalues) as $item) {
                $options[] = [
                    'value' => (string) $item['value'],
                    'name' => (string) $item['name'],
                    'selected' => true,
                ];
            }
        } else if ($type === 'courses') {
            $selectedvalues = is_array($filter['value'] ?? null) ? $filter['value'] : [];

            foreach (repository::get_filter_selected_courses($selectedvalues) as $item) {
                $options[] = [
                    'value' => (string) $item['value'],
                    'name' => (string) $item['name'],
                    'category' => (string) ($item['category'] ?? ''),
                    'selected' => true,
                ];
            }
        } else {
            $selectoptions = $this->get_filter_select_options($definition);

            foreach ($selectoptions as $value => $name) {
                $options[] = [
                    'value' => (string) $value,
                    'name' => (string) $name,
                    'selected' => (string) $value === (string) ($filter['value'] ?? ''),
                ];
            }
        }

        return [
            'active' => $operator !== 'any' && (
                in_array($operator, ['empty', 'notempty'], true) ||
                !empty($filter['value']) ||
                !empty($filter['from']) ||
                !empty($filter['to'])
            ),
            'key' => $key,
            'visible' => !array_key_exists('visible', $column) || !empty($column['visible']),
            'name' => (string) ($definition['name'] ?? ($column['name'] ?? ucwords(str_replace('_', ' ', $key)))),
            'is_search_default' => !empty($definition['search']),
            'can_search_default' => $type === 'text',
            'reportid' => (int) $this->report->id,
            'type' => $type,
            'operator_name' => $names['operator'],
            'value_name' => $names['value'],
            'from_name' => $names['from'],
            'to_name' => $names['to'],
            'value' => is_array($filter['value'] ?? null) ? '' : (string) ($filter['value'] ?? ''),
            'values' => is_array($filter['value'] ?? null) ? array_values(array_map('strval', $filter['value'])) : [],
            'from' => (string) ($filter['from'] ?? ''),
            'to' => (string) ($filter['to'] ?? ''),
            'value_disabled' => !$showvalue,
            'show_range' => $showrange,
            'is_text' => $type === 'text',
            'is_select' => in_array($type, ['select', 'users', 'courses'], true),
            'is_date' => $type === 'date',
            'is_users_autocomplete' => $type === 'users',
            'is_courses_multiselect' => $type === 'courses',
            'operators' => $this->get_filter_operator_options($type, $operator),
            'options' => $options,
            'search_courses_label' => get_string('searchcourses', 'local_la'),
            'loading_label' => get_string('loading', 'local_la'),
            'selected_suffix' => get_string('selectedsuffix', 'local_la'),
            'selected_count_label' => is_array($filter['value'] ?? null) && !empty($filter['value']) ?
                get_string('selectedcount', 'local_la', count($filter['value'])) :
                get_string('searchcourses', 'local_la'),
        ];
    }

    /**
     * Build toolbar column items.
     *
     * @return array
     */
    protected function get_column_items(): array {
        $items = [];
        $columns = $this->report->params['columns'] ?? [];

        uasort($columns, function(array $left, array $right): int {
            return ((int) ($left['order'] ?? 9999)) <=> ((int) ($right['order'] ?? 9999));
        });

        foreach ($columns as $key => $column) {
            if (empty($column['name'])) {
                continue;
            }

            if (!report_helper::is_column_enabled($column)) {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'name' => (string) $column['name'],
                'visible' => !array_key_exists('visible', $column) || !empty($column['visible']),
                'enabled' => report_helper::is_column_enabled($column),
            ];
        }

        return $items;
    }

    /**
     * Build toolbar filter context.
     *
     * @return array
     */
    protected function get_toolbar_context(): array {
        $items = [];
        $visiblefilteritems = [];
        $hiddenfilteritems = [];
        $activecount = 0;
        $columnitems = $this->get_column_items();
        $searchcolumn = filters::get_search_column($this->report->params);
        $searchterm = filters::get_search_term((string) ($searchcolumn['key'] ?? ''), $this->filters);
        $searchnames = !empty($searchcolumn['key']) ? filters::get_param_names((string) $searchcolumn['key']) : [];
        $syntheticparams = synthetic::get_url_params();
        $syntheticcontext = synthetic::get_toolbar_context($this->report->params);

        if (!empty($syntheticcontext['enabled'])) {
            $syntheticcontext['available'] = helper::has_feature('calendar');
            $syntheticcontext['unavailable_label'] = get_string('featureunavailable_calendar', 'local_la');
        }

        foreach (($this->report->params['columns'] ?? []) as $key => $column) {
            if (!report_helper::is_column_enabled($column)) {
                continue;
            }

            if (empty($column['filter']) || !is_array($column['filter'])) {
                continue;
            }

            $item = $this->get_filter_item($key, $column, $this->filters[$key] ?? []);

            if (!empty($item['active'])) {
                $activecount++;
            }

            unset($item['active']);
            if (!empty($item['visible'])) {
                $visiblefilteritems[] = $item;
            } else {
                $hiddenfilteritems[] = $item;
            }
            $items[] = $item;
        }

        return [
            'has_filters' => !empty($items),
            'filter_count' => $activecount,
            'filter_count_label' => $activecount > 9 ? '10+' : (string) $activecount,
            'show_filter_count' => $activecount > 0,
            'filters' => $items,
            'columns' => $columnitems,
            'has_columns' => !empty($columnitems),
            'visible_filters' => $visiblefilteritems,
            'hidden_filters' => $hiddenfilteritems,
            'has_hidden_filters' => !empty($hiddenfilteritems),
            'column_order' => implode(',', array_column($columnitems, 'key')),
            'column_add_params' => json_encode(['id' => (int) $this->report->id]),
            'reportid' => (int) $this->report->id,
            'can_manage' => helper::is_admin(),
            'can_share' => helper::can_share_reports(),
            'insights_available' => helper::has_feature('insights'),
            'patterns_available' => helper::has_feature('patterns'),
            'insights_unavailable_label' => get_string('featureunavailable_insights', 'local_la'),
            'patterns_unavailable_label' => get_string('featureunavailable_patterns', 'local_la'),
            'action' => url::report((int) $this->report->id),
            'reseturl' => url::report_tab((int) $this->report->id, ['tab' => 'view']),
            'search_hidden_params' => array_merge(
                filters::get_search_hidden_param_items(
                    (int) $this->report->id,
                    $this->report->params,
                    $this->filters
                ),
                filters::get_hidden_param_items($syntheticparams)
            ),
            'synthetic' => $syntheticcontext,
            'synthetic_hidden_params' => filters::get_hidden_param_items(filters::get_params($this->filters, [
                'id' => (int) $this->report->id,
                'tab' => 'view',
            ], true)),
            'synthetic_current_params' => filters::get_hidden_param_items($syntheticparams),
            'has_search' => !empty($searchcolumn),
            'search_term' => $searchterm,
            'search_name' => (string) ($searchcolumn['name'] ?? ''),
            'search_operator_name' => (string) ($searchnames['operator'] ?? ''),
            'search_value_name' => (string) ($searchnames['value'] ?? ''),
        ];
    }

    /**
     * Export template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $tablehtml = '';
        $loadingtime = 0;

        if ($this->tab === 'view') {
            $start = microtime(true);
            $tablehtml = $this->get_view_table_html();
            $loadingtime = microtime(true) - $start;
        }

        $canmanage = helper::is_admin();
        $context = [
            'header' => $output->render_from_template('local_la/header', renderer::get_header_context('reports')),
            'head' => $output->render_from_template('local_la/components/report_head', [
                'homeurl' => url::home(),
                'breadcrumbtitle' => get_string('reports'),
                'reportid' => (int) $this->report->id,
                'can_manage' => $canmanage,
                'title' => format_string($this->report->name),
                'summary' => format_text($this->report->info ?? '', FORMAT_HTML),
                'version' => $this->report->version,
                'metrics' => $this->tab === 'view' ? [
                    [
                        'avatars' => $this->get_metric_avatars(),
                        'values' => $this->get_metric_values($loadingtime),
                    ],
                ] : [],
                'bookmark' => [
                    'reportid' => (int) $this->report->id,
                    'isfavorite' => !empty($this->report->favorite),
                ],
                'reset' => [
                    'reportid' => (int) $this->report->id,
                ],
                'delete' => [
                    'reportid' => (int) $this->report->id,
                    'title' => get_string('disablereporttitle', 'local_la'),
                    'message' => get_string('disablereportconfirm', 'local_la', format_string($this->report->name)),
                    'buttonlabel' => get_string('disablereport', 'local_la'),
                    'returnurl' => url::library(['tab' => 'reports']),
                ],
                'tabs' => $this->get_tabs(),
            ]),
        ];

        if ($this->tab === 'view') {
            $context['toolbar'] = $output->render_from_template(
                'local_la/components/report_toolbar',
                $this->get_toolbar_context()
            );
            $context['table'] = $tablehtml;
        } else if ($this->tab === 'audience') {
            $context['audience'] = report\audience::get_context($this->report);
        } else if ($this->tab === 'access') {
            $context['access'] = report\access::get_context($this->report);
        } else if ($this->tab === 'schedules') {
            $context['schedules'] = report\schedules::get_context($this->report);
        } else if ($this->tab === 'auditlogs') {
            $context['auditlogs'] = report\auditlogs::get_context($this->report);
        }

        return $context;
    }
}
