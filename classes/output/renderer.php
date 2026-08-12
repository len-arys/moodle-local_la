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

use plugin_renderer_base;
use local_la\local\filters;
use local_la\local\helper;
use local_la\local\report as report_helper;
use local_la\local\repository;
use local_la\local\url;

/**
 * Renderer for local_la.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Build shared header template context.
     *
     * @param string $active
     * @return array
     */
    public function get_header_context(string $active = ''): array {
        $this->page->requires->js_call_amd('local_la/header', 'init');
        $canmanage = helper::is_admin();

        $reports = [
            'recent' => self::map_navigation_items(repository::get_user_reports(3), 'report'),
            'favorite' => self::map_navigation_items(repository::get_user_reports(3, true), 'report'),
        ];
        $apps = $canmanage ? self::map_navigation_items(repository::get_apps(3), 'app') : [];
        $appearance = (string) get_config('local_la', 'appearance');
        $appearance = in_array($appearance, ['system', 'light', 'dark'], true) ? $appearance : 'system';
        // phpcs:ignore moodle.Files.RequireLogin.Missing -- This loads plugin defaults, not Moodle bootstrap.
        $defaults = require(__DIR__ . '/../../config.php');

        return [
            'uniqid' => uniqid(),
            'companyname' => (string) ($defaults['companyname'] ?? 'Lenarys'),
            'darkstylesurl' => (new \moodle_url('/local/la/assets/css/darkstyles.css'))->out(false),
            'homeurl' => $canmanage ? url::home() : url::library(['tab' => 'reports']),
            'libraryurl' => url::library(),
            'preferencesurl' => url::preferences(),
            'closeurl' => url::close(),
            'canmanage' => $canmanage,
            'reports' => $reports,
            'reports_has_recent' => !empty($reports['recent']),
            'reports_has_favorite' => !empty($reports['favorite']),
            'apps' => $apps,
            'has_reports_menu' => !empty($reports['recent']) || !empty($reports['favorite']),
            'has_apps_menu' => !empty($apps),
            'is_home' => $active === 'home',
            'is_reports' => $active === 'reports',
            'is_apps' => $active === 'apps',
            'is_library' => $active === 'library',
            'is_darkmode' => $appearance === 'dark',
            'is_darkmode_auto' => $appearance === 'system',
        ];
    }

    /**
     * Build general head template context.
     *
     * @param string $page
     * @param array $tabs
     * @return array
     */
    public static function get_general_head_context(string $page = '', array $tabs = []): array {
        return [
            'title' => get_string($page, 'local_la'),
            'page' => $page,
            'tabs' => $tabs,
            'homeurl' => url::home(),
        ];
    }

    /**
     * Render home page.
     *
     * @param home $page
     * @return string
     */
    protected function render_home(home $page): string {
        return $this->render_from_template('local_la/pages/home', $page->export_for_template($this));
    }

    /**
     * Render library page.
     *
     * @param library $page
     * @return string
     */
    protected function render_library(library $page): string {
        $context = $page->export_for_template($this);

        return $this->render_from_template('local_la/pages/library/' . $page->get_tab(), $context);
    }

    /**
     * Render preferences page.
     *
     * @param preferences $page
     * @return string
     */
    protected function render_preferences(preferences $page): string {
        return $this->render_from_template('local_la/pages/preferences/' . $page->get_tab(), $page->export_for_template($this));
    }

    /**
     * Render report page.
     *
     * @param report $page
     * @return string
     */
    protected function render_report(report $page): string {
        $context = $page->export_for_template($this);
        return $this->render_from_template('local_la/pages/report/' . $page->get_tab(), $context);
    }

    /**
     * Render app page.
     *
     * @param app $page
     * @return string
     */
    protected function render_app(app $page): string {
        return $this->render_from_template('local_la/pages/apps', $page->export_for_template($this));
    }

    /**
     * Map records for header navigation.
     *
     * @param array $records
     * @param string $page
     * @return array
     */
    protected static function map_navigation_items(array $records, string $page): array {
        $items = [];

        foreach ($records as $record) {
            $itemurl = $page === 'app' ? url::app((int) $record->id) : url::report((int) $record->id);
            $searchurl = $page === 'report' ? self::get_report_search_url($record) : null;

            $items[] = [
                'id' => (int) $record->id,
                'name' => $record->name,
                'url' => $itemurl,
                'searchurl' => $searchurl ?: $itemurl,
                'hassearchurl' => !empty($searchurl),
            ];
        }
        return $items;
    }

    /**
     * Build report search URL for header favorites.
     *
     * @param \stdClass $report
     * @return string|null
     */
    protected static function get_report_search_url(\stdClass $report): ?string {
        $params = report_helper::decode_params((string) ($report->report_params ?? ''));
        $searchcolumn = filters::get_search_column($params);

        if (empty($searchcolumn['key'])) {
            return null;
        }

        $names = filters::get_param_names((string) $searchcolumn['key']);
        return url::report_tab_url((int) $report->id, [
            'tab' => 'view',
            $names['operator'] => 'contains',
        ])->out(false) . '&' . $names['value'] . '=';
    }
}
