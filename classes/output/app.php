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

use local_la\local\repository;
use local_la\local\app as app_helper;
use local_la\local\url;
use renderable;
use renderer_base;
use templatable;

/**
 * App page renderable.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class app implements renderable, templatable {
    /** @var int */
    protected $id;

    /**
     * Constructor.
     *
     * @param int $id
     */
    public function __construct(int $id) {
        $this->id = $id;
    }

    /**
     * Export template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $PAGE;

        $PAGE->requires->js_call_amd('local_la/apps', 'init', [[
            'start' => get_string('startautorefresh', 'local_la'),
            'stop' => get_string('stopautorefresh', 'local_la'),
            'maximize' => get_string('maximize', 'local_la'),
            'minimize' => get_string('minimize', 'local_la'),
            'delete' => get_string('delete', 'core'),
            'deletewidget' => get_string('deletewidget', 'local_la'),
            'deletewidgetconfirm' => get_string('deletewidgetconfirm', 'local_la'),
        ]]);
        $PAGE->requires->js_call_amd('local_la/calendar', 'init');
        $PAGE->requires->js_call_amd('local_la/report_actions', 'init');

        $app = repository::get_app($this->id);

        if ($app) {
            $app->view_url = url::app((int) $app->id);
        }

        $start = microtime(true);
        $appcontext = $app ? app_helper::get_context($app) : null;
        $loadingtime = microtime(true) - $start;

        return [
            'header' => $output->render_from_template('local_la/header', $output->get_header_context('apps')),
            'head' => $appcontext ? $output->render_from_template('local_la/components/app_head', [
                'homeurl' => url::home(),
                'breadcrumbtitle' => get_string('apps', 'local_la'),
                'title' => format_string($appcontext['name']),
                'summary' => format_text($appcontext['info'] ?? '', FORMAT_HTML),
                'version' => $appcontext['version'] ?? '',
                'metrics' => [
                    [
                        'avatars' => $this->get_metric_avatars(),
                        'values' => $this->get_metric_values($loadingtime),
                    ],
                ],
            ]) : '',
            'app' => $app,
            'appcontext' => $appcontext,
        ];
    }

    /**
     * Build app metric avatar values.
     *
     * @return array
     */
    protected function get_metric_avatars(): array {
        global $PAGE, $USER;

        $picture = new \user_picture($USER);
        $picture->size = 23;

        return [
            [
                'imageurl' => $picture->get_url($PAGE)->out(false),
                'label' => fullname($USER) . ' (' . get_string('you', 'local_la') . ')',
                'active' => true,
            ],
        ];
    }

    /**
     * Build app metric values.
     *
     * @param float $loadingtime
     * @return array
     */
    protected function get_metric_values(float $loadingtime): array {
        return [
            [
                'key' => 'loadingtime',
                'value' => $this->format_loading_time($loadingtime),
                'label' => get_string('loadingtime', 'local_la'),
                'active' => true,
            ],
        ];
    }

    /**
     * Format app loading time.
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
}
