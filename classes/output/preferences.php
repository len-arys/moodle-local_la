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
 * Preferences page renderable.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preferences implements renderable, templatable {
    /** @var string */
    protected $tab;

    /**
     * Constructor.
     *
     * @param string $tab
     */
    public function __construct(string $tab = 'general') {
        $this->tab = self::normalize_tab($tab);
    }

    /**
     * Export template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $tabs = $this->get_tabs();
        $context = [
            'header' => $output->render_from_template('local_la/header', renderer::get_header_context('preferences')),
            'head' => $output->render_from_template('local_la/components/general_head', renderer::get_general_head_context('preferences', $tabs)),
            'tab' => $this->tab,
            'settingsurl' => (new \moodle_url('/admin/settings.php', ['section' => 'local_la']))->out(false),
        ];

        if ($this->tab === 'general') {
            $context += preferences\general::get_context();
        } else if ($this->tab === 'access') {
            $context += preferences\access::get_context();
        } else if ($this->tab === 'billing') {
            $context += preferences\billing::get_context();
        } else if ($this->tab === 'audit') {
            $context += preferences\audit::get_context();
        }

        return $context;
    }

    /**
     * Get current tab.
     *
     * @return string
     */
    public function get_tab(): string {
        return $this->tab;
    }

    /**
     * Normalize preferences tab.
     *
     * @param string $tab
     * @return string
     */
    protected static function normalize_tab(string $tab): string {
        return in_array($tab, ['general', 'billing', 'access', 'audit'], true) ? $tab : 'general';
    }

    /**
     * Build preferences tabs.
     *
     * @return array
     */
    protected function get_tabs(): array {
        return [
            [
                'name' => get_string('general', 'core'),
                'url' => url::preferences(['tab' => 'general']),
                'active' => $this->tab === 'general',
            ],
            [
                'name' => get_string('billing', 'local_la'),
                'url' => url::preferences(['tab' => 'billing']),
                'active' => $this->tab === 'billing',
            ],
            [
                'name' => get_string('access', 'local_la'),
                'url' => url::preferences(['tab' => 'access']),
                'active' => $this->tab === 'access',
            ],
            [
                'name' => get_string('auditlogs', 'local_la'),
                'url' => url::preferences(['tab' => 'audit']),
                'active' => $this->tab === 'audit',
                'badge' => helper::has_feature('audit_logs') ? '' : get_string('upgrade', 'local_la'),
            ],
        ];
    }
}
