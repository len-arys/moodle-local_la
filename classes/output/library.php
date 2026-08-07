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

use renderer_base;
use renderable;
use templatable;
use local_la\local\helper;
use local_la\local\url;

/**
 * Library page renderable.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library implements renderable, templatable {
    /** @var string[] */
    protected const TAB_NAMES = ['reports', 'apps', 'marketplace'];

    /** @var string[] */
    protected const SORT_NAMES = ['name', 'version', 'favorite', 'timesync', 'timecreated', 'timemodified'];

    /** @var string */
    protected $tab;

    /** @var int */
    protected $page;

    /** @var string */
    protected $sort;

    /** @var string */
    protected $dir;

    /** @var string */
    protected $marketplan;

    /** @var string */
    protected $markettype;

    /** @var string */
    protected $marketsearch;

    /** @var string */
    protected $marketsort;

    /** @var string */
    protected $reportsearch;

    /**
     * Constructor.
     *
     * @param string $tab
     * @param int $page
     * @param string $sort
     * @param string $dir
     * @param string $marketplan
     * @param string $markettype
     * @param string $marketsearch
     * @param string $marketsort
     * @param string $reportsearch
     */
    public function __construct(
        string $tab = 'reports',
        int $page = 0,
        string $sort = 'name',
        string $dir = 'asc',
        string $marketplan = 'all',
        string $markettype = 'reports',
        string $marketsearch = '',
        string $marketsort = 'name',
        string $reportsearch = ''
    ) {
        $this->tab = self::normalize_tab($tab);
        $this->page = max(0, $page);
        $this->sort = self::normalize_sort($sort);
        $this->dir = ($dir === 'desc') ? 'desc' : 'asc';
        $this->marketplan = $marketplan;
        $this->markettype = library\marketplace::normalize_type($markettype);
        $this->marketsearch = trim($marketsearch);
        $this->marketsort = library\marketplace::normalize_sort($marketsort);
        $this->reportsearch = trim($reportsearch);
    }

    /**
     * Export template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $tabcontext = [];
        if ($this->tab === 'reports') {
            $tabcontext = library\reports::get_context($output, $this->page, $this->sort, $this->dir, $this->reportsearch);
        } else if ($this->tab === 'apps') {
            $tabcontext = library\apps::get_context();
        } else if ($this->tab === 'marketplace') {
            $tabcontext = library\marketplace::get_context(
                $output,
                $this->marketplan,
                $this->markettype,
                $this->marketsearch,
                $this->marketsort
            );
        }

        return $tabcontext + [
            'header' => $output->render_from_template('local_la/header', renderer::get_header_context('library')),
            'head' => $output->render_from_template('local_la/components/general_head',
                renderer::get_general_head_context('library', $this->get_tabs())),
            'can_manage' => helper::is_admin(),
            'tab' => $this->tab,
        ];
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
     * Normalize library tab.
     *
     * @param string $tab
     * @return string
     */
    protected static function normalize_tab(string $tab): string {
        if (!in_array($tab, self::TAB_NAMES, true)) {
            return 'reports';
        }

        return $tab;
    }

    /**
     * Normalize library sort.
     *
     * @param string $sort
     * @return string
     */
    protected static function normalize_sort(string $sort): string {
        if (!in_array($sort, self::SORT_NAMES, true)) {
            return 'name';
        }

        return $sort;
    }

    /**
     * Build library tabs.
     *
     * @return array
     */
    protected function get_tabs(): array {
        $tabs = [
            [
                'name' => get_string('reports'),
                'url' => url::library(['tab' => 'reports']),
                'active' => $this->tab === 'reports',
            ],
        ];

        if (helper::is_admin()) {
            $tabs[] = [
                'name' => get_string('apps', 'local_la'),
                'url' => url::library(['tab' => 'apps']),
                'active' => $this->tab === 'apps',
            ];
        }

        if (helper::is_billing_admin()) {
            $tabs[] = [
                'name' => get_string('marketplace', 'local_la'),
                'url' => url::library(['tab' => 'marketplace']),
                'active' => $this->tab === 'marketplace',
            ];
        }

        return $tabs;
    }
}
