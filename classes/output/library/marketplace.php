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

defined('MOODLE_INTERNAL') || die();

use local_la\local\helper;
use local_la\local\url;
use renderer_base;

/**
 * Library marketplace tab context.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marketplace {
    /** @var string[] */
    protected const SORT_NAMES = ['name', 'plan'];

    /** @var string[] */
    protected const TYPE_NAMES = ['reports', 'apps'];

    /**
     * Build tab context.
     *
     * @param renderer_base $output
     * @param string $plan
     * @param string $type
     * @param string $search
     * @param string $sort
     * @return array
     */
    public static function get_context(renderer_base $output, string $plan, string $type, string $search, string $sort): array {
        $controls = self::get_controls($plan, $type, $search, $sort);

        return [
            'controls' => $output->render_from_template('local_la/components/library_controls', $controls),
            'marketplacecontrols' => $controls,
        ];
    }

    /**
     * Normalize marketplace sort.
     *
     * @param string $sort
     * @return string
     */
    public static function normalize_sort(string $sort): string {
        return in_array($sort, self::SORT_NAMES, true) ? $sort : 'name';
    }

    /**
     * Normalize marketplace type.
     *
     * @param string $type
     * @return string
     */
    public static function normalize_type(string $type): string {
        return in_array($type, self::TYPE_NAMES, true) ? $type : 'reports';
    }

    /**
     * Build controls context.
     *
     * @param string $plan
     * @param string $type
     * @param string $search
     * @param string $sort
     * @return array
     */
    protected static function get_controls(string $plan, string $type, string $search, string $sort): array {
        $type = self::normalize_type($type);
        $baseparams = [
            'tab' => 'marketplace',
            'markettype' => $type,
            'marketsearch' => $search,
            'marketsort' => $sort,
        ];

        $currentfilter = get_string('all', 'core');
        $filteroptions = [[
            'name' => get_string('all', 'core'),
            'url' => url::library($baseparams + ['marketplan' => 'all']),
            'active' => $plan === 'all',
        ]];

        foreach (helper::get_plans() as $key => $item) {
            $name = $item['label'];
            if ($plan === $key) {
                $currentfilter = $name;
            }

            $filteroptions[] = [
                'name' => $name,
                'url' => url::library($baseparams + ['marketplan' => $key]),
                'active' => $plan === $key,
            ];
        }

        $typebaseparams = $baseparams + ['marketplan' => $plan];
        $typeoptions = [];
        $currenttype = get_string('reports');
        foreach ([
            'reports' => get_string('reports'),
            'apps' => get_string('apps', 'local_la'),
        ] as $key => $name) {
            if ($type === $key) {
                $currenttype = $name;
            }

            $typeoptions[] = [
                'name' => $name,
                'url' => url::library(array_merge($typebaseparams, ['markettype' => $key])),
                'active' => $type === $key,
            ];
        }

        $sortlabels = [
            'name' => get_string('name', 'core'),
            'plan' => get_string('plan', 'local_la'),
        ];
        $currentsort = get_string('name', 'core');
        $sortoptions = [];

        foreach ($sortlabels as $key => $name) {
            if ($sort === $key) {
                $currentsort = $name;
            }

            $sortoptions[] = [
                'name' => $name,
                'url' => url::library([
                    'tab' => 'marketplace',
                    'marketplan' => $plan,
                    'markettype' => $type,
                    'marketsearch' => $search,
                    'marketsort' => $key,
                ]),
                'active' => $sort === $key,
            ];
        }

        return [
            'hasfilter' => true,
            'hassecondaryfilter' => true,
            'hassearch' => true,
            'hassort' => true,
            'hasdirection' => false,
            'filterid' => 'la-library-filter',
            'secondaryfilterid' => 'la-library-type-filter',
            'searchid' => 'la-library-search',
            'searchname' => 'marketsearch',
            'searchvalue' => $search,
            'searchaction' => url::library(),
            'hiddenfields' => [
                ['name' => 'tab', 'value' => 'marketplace'],
                ['name' => 'marketplan', 'value' => $plan],
                ['name' => 'markettype', 'value' => $type],
                ['name' => 'marketsort', 'value' => $sort],
            ],
            'sortid' => 'la-library-sort',
            'currentfilter' => $currentfilter,
            'currentfiltervalue' => $plan,
            'currentsecondaryfilter' => $currenttype,
            'currentsecondaryfiltervalue' => $type,
            'currentsort' => $currentsort,
            'currentsortvalue' => $sort,
            'filteroptions' => $filteroptions,
            'secondaryfilteroptions' => $typeoptions,
            'sortoptions' => $sortoptions,
            'search' => $search,
        ];
    }
}
