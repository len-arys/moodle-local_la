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

namespace local_la\output\library;

use local_la\local\audience;
use local_la\local\helper;
use local_la\local\repository;
use local_la\local\url;
use renderer_base;

/**
 * Library reports tab context.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reports {
    /** @var int */
    protected const PERPAGE = 10;

    /**
     * Build tab context.
     *
     * @param renderer_base $output
     * @param int $page
     * @param string $sort
     * @param string $dir
     * @param string $search
     * @return array
     */
    public static function get_context(renderer_base $output, int $page, string $sort, string $dir, string $search): array {
        $totalcount = repository::count_reports($search);
        $records = repository::get_reports(self::PERPAGE, $page * self::PERPAGE, $sort, $dir, $search);

        return [
            'controls' => $output->render_from_template(
                'local_la/components/library_controls',
                self::get_controls($sort, $dir, $search)
            ),
            'items' => self::get_items($records),
            'pagingbar' => self::get_paging_bar($output, $totalcount, $page, $sort, $dir, $search),
        ];
    }

    /**
     * Build controls context.
     *
     * @param string $sort
     * @param string $dir
     * @param string $search
     * @return array
     */
    protected static function get_controls(string $sort, string $dir, string $search): array {
        $labels = [
            'name' => get_string('name', 'core'),
            'version' => get_string('version', 'core_plugin'),
            'favorite' => get_string('favorite', 'local_la'),
            'timesync' => get_string('timesync', 'local_la'),
            'timecreated' => get_string('timecreated'),
            'timemodified' => get_string('timemodified', 'local_la'),
        ];

        $sortoptions = [];
        $currentsort = get_string('name', 'core');

        foreach ($labels as $column => $label) {
            if ($sort === $column) {
                $currentsort = $label;
            }

            $sortoptions[] = [
                'name' => $label,
                'url' => url::library([
                    'tab' => 'reports',
                    'page' => 0,
                    'sort' => $column,
                    'dir' => $dir,
                    'reportsearch' => $search,
                ]),
                'active' => $sort === $column,
            ];
        }

        $currentdirection = ($dir === 'desc') ? get_string('descending', 'local_la') : get_string('ascending', 'local_la');
        $diroptions = [];
        foreach (['asc' => 'ascending', 'desc' => 'descending'] as $value => $string) {
            $diroptions[] = [
                'name' => get_string($string, 'local_la'),
                'url' => url::library([
                    'tab' => 'reports',
                    'page' => 0,
                    'sort' => $sort,
                    'dir' => $value,
                    'reportsearch' => $search,
                ]),
                'active' => $dir === $value,
            ];
        }

        return [
            'hasfilter' => false,
            'hassearch' => true,
            'hassort' => true,
            'hasdirection' => true,
            'searchid' => 'la-library-search',
            'searchname' => 'reportsearch',
            'searchvalue' => $search,
            'searchaction' => url::library(),
            'hiddenfields' => [
                ['name' => 'tab', 'value' => 'reports'],
                ['name' => 'sort', 'value' => $sort],
                ['name' => 'dir', 'value' => $dir],
            ],
            'sortid' => 'la-library-sort',
            'directionid' => 'la-library-direction',
            'currentsort' => $currentsort,
            'currentdirection' => $currentdirection,
            'sortoptions' => $sortoptions,
            'directionoptions' => $diroptions,
        ];
    }

    /**
     * Build item context.
     *
     * @param array $records
     * @return array
     */
    protected static function get_items(array $records): array {
        $items = [];

        foreach ($records as $record) {
            if (!audience::has_access((int) $record->id)) {
                continue;
            }

            $isadded = !empty($record->relationid);
            $name = (string) ($record->name ?? '');
            $baseurl = url::report((int) $record->id);
            $plan = (string) $record->plan;
            $isavailable = helper::has_plan($plan);
            $lockmessage = get_string('planrequired', 'local_la', helper::get_plan_label($plan));

            $items[] = [
                'id' => $record->id,
                'name' => $name,
                'shortname' => $record->shortname ?? '',
                'info' => $record->info ?? '',
                'version' => $record->version ?? '',
                'isavailable' => $isavailable,
                'islocked' => !$isavailable,
                'lockmessage' => $lockmessage,
                'sql_name' => (string) ($record->sql_name ?? ''),
                'timesync' => $record->timesync ?? 0,
                'timecreated' => $record->timecreated ?? 0,
                'timemodified' => $record->timemodified ?? 0,
                'icon' => 'bars',
                'icon_class' => 'text-bg-warning',
                'url' => $baseurl,
                'reportid' => $record->id,
                'ishidden' => $isadded && (int) ($record->relationstatus ?? 1) === 0,
                'isadded' => $isadded,
                'isfavorite' => $isadded && !empty($record->favorite),
                'preview_url' => $baseurl,
                'view_url' => $baseurl,
                'delete_title' => get_string('disablereporttitle', 'local_la'),
                'delete_message' => get_string('disablereportconfirm', 'local_la', $name),
            ];
        }

        return $items;
    }

    /**
     * Build paging bar html.
     *
     * @param renderer_base $output
     * @param int $totalcount
     * @param int $page
     * @param string $sort
     * @param string $dir
     * @param string $search
     * @return string
     */
    protected static function get_paging_bar(
        renderer_base $output,
        int $totalcount,
        int $page,
        string $sort,
        string $dir,
        string $search
    ): string {
        if ($totalcount <= self::PERPAGE) {
            return '';
        }

        return $output->render(new \paging_bar($totalcount, $page, self::PERPAGE, url::library_url([
            'tab' => 'reports',
            'sort' => $sort,
            'dir' => $dir,
            'reportsearch' => $search,
        ])));
    }
}
