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

namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Laravel API helper.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /**
     * Get marketplace report list from the active source.
     *
     * @return array
     */
    public static function get_marketplace_reports(): array {
        if (!helper::is_api_mode()) {
            return self::get_local_reports();
        }

        $state = self::get_api_state();

        if ($state['apiurl'] === '' || $state['license'] === '') {
            return [];
        }

        $response = self::post_json(rtrim($state['apiurl'], '/') . '/api/get_reports', [
            'license' => $state['license'],
        ]);

        return self::normalise_definitions($response['reports'] ?? $response);
    }

    /**
     * Get one full report definition from the active source.
     *
     * @param string $shortname
     * @return array
     */
    public static function get_report_definition(string $shortname): array {
        $shortname = trim($shortname);

        if ($shortname === '') {
            return [];
        }

        if (!helper::is_api_mode()) {
            return self::find_report_definition($shortname, self::get_local_reports());
        }

        $state = self::get_api_state();

        if ($state['apiurl'] === '' || $state['license'] === '') {
            return [];
        }

        $response = self::post_json(rtrim($state['apiurl'], '/') . '/api/get_report', [
            'license' => $state['license'],
            'report' => $shortname,
        ]);
        $definition = is_array($response['report'] ?? null) ? $response['report'] : $response;

        return !empty($definition['shortname']) ? $definition : [];
    }

    /**
     * Get marketplace app list from the active source.
     *
     * @return array
     */
    public static function get_marketplace_apps(): array {
        if (!helper::is_api_mode()) {
            return self::get_local_apps();
        }

        $state = self::get_api_state();

        if ($state['apiurl'] === '' || $state['license'] === '') {
            return [];
        }

        $response = self::post_json(rtrim($state['apiurl'], '/') . '/api/get_apps', [
            'license' => $state['license'],
        ]);

        return self::normalise_definitions($response['apps'] ?? $response);
    }

    /**
     * Get one full app definition from the active source.
     *
     * @param string $shortname
     * @return array
     */
    public static function get_app_definition(string $shortname): array {
        $shortname = trim($shortname);

        if ($shortname === '') {
            return [];
        }

        if (!helper::is_api_mode()) {
            return self::find_definition($shortname, self::get_local_apps());
        }

        $state = self::get_api_state();

        if ($state['apiurl'] === '' || $state['license'] === '') {
            return [];
        }

        $response = self::post_json(rtrim($state['apiurl'], '/') . '/api/get_app', [
            'license' => $state['license'],
            'app' => $shortname,
        ]);
        $definition = is_array($response['app'] ?? null) ? $response['app'] : $response;

        return !empty($definition['shortname']) ? $definition : [];
    }

    /**
     * Notify the API that a report was installed.
     *
     * @param string $shortname
     * @return void
     */
    public static function report_installed(string $shortname): void {
        global $CFG;

        if (!helper::is_api_mode() || $shortname === '') {
            return;
        }

        $state = self::get_api_state();
        if ($state['apiurl'] === '' || $state['license'] === '') {
            return;
        }

        self::post_json(rtrim($state['apiurl'], '/') . '/api/report/installed', [
            'license' => $state['license'],
            'url' => $CFG->wwwroot,
            'report' => $shortname,
        ]);
    }

    /**
     * Notify the API that a marketplace app was installed.
     *
     * @param string $shortname
     * @return void
     */
    public static function app_installed(string $shortname): void {
        global $CFG;

        if (!helper::is_api_mode() || $shortname === '') {
            return;
        }

        $state = self::get_api_state();
        if ($state['apiurl'] === '' || $state['license'] === '') {
            return;
        }

        self::post_json(rtrim($state['apiurl'], '/') . '/api/app/installed', [
            'license' => $state['license'],
            'url' => $CFG->wwwroot,
            'app' => $shortname,
        ]);
    }

    /**
     * Check license with Laravel API and update local cache.
     *
     * @return array
     */
    public static function check_license(): array {
        global $CFG;

        $state = helper::get_license();
        if (!helper::is_api_mode()) {
            return $state + ['error' => get_string('licenseapiselfhosted', 'local_la')];
        }

        if ($state['apiurl'] === '') {
            return $state + ['error' => get_string('licenseapiurlmissing', 'local_la')];
        }

        $response = self::post_json(rtrim($state['apiurl'], '/') . '/api/license/check', [
            'license' => $state['license'],
            'url' => $CFG->wwwroot,
            'moodleversion' => $CFG->release ?? '',
            'pluginversion' => (string) get_config('local_la', 'version'),
        ]);

        return self::apply_license_payload($response);
    }

    /**
     * Load local license JSON and update local cache.
     *
     * @return array
     */
    public static function get_local_license(): array {
        $context = \context_system::instance();
        $files = get_file_storage()->get_area_files($context->id, 'local_la', 'licensefile', 0, 'id DESC', false);

        if (empty($files)) {
            return self::clear_license(get_string('licensefilenotfound', 'local_la'));
        }

        $file = reset($files);
        $payload = json_decode($file->get_content(), true);
        if (!is_array($payload)) {
            return self::clear_license(get_string('licensefileinvalid', 'local_la'));
        }

        return self::apply_license_payload($payload);
    }

    /**
     * Get full reports from local license JSON.
     *
     * @return array
     */
    protected static function get_local_reports(): array {
        $license = self::get_local_license();

        return self::normalise_definitions($license['reports'] ?? []);
    }

    /**
     * Get full apps from local license JSON.
     *
     * @return array
     */
    protected static function get_local_apps(): array {
        $license = self::get_local_license();

        return self::normalise_definitions($license['apps'] ?? []);
    }

    /**
     * Apply license payload from API or local JSON.
     *
     * @param array $response
     * @return array
     */
    public static function apply_license_payload(array $response): array {
        if (empty($response['license'])) {
            return self::clear_license((string) ($response['error'] ?? get_string('licensecheckfailed', 'local_la')));
        }

        $plugin = is_array($response['plugin'] ?? null) ? $response['plugin'] : [];
        $pluginversion = trim((string) ($plugin['version'] ?? ''));
        $hasupdate = ($plugin['status'] ?? '') === 'published' && ctype_digit($pluginversion) &&
            (int) $pluginversion > (int) get_config('local_la', 'version');

        set_config('license', (string) $response['license'], 'local_la');
        set_config('licensestatus', (string) ($response['status'] ?? 'active'), 'local_la');
        set_config('licenseplans', self::encode_plans($response['plans'] ?? []), 'local_la');
        $plan = (string) ($response['plan'] ?? helper::DP);
        set_config('licenseplan', helper::normalize_plan($plan), 'local_la');
        set_config('licensetrialends', self::parse_time($response['trial_ends_at'] ?? null), 'local_la');
        set_config('licenselastcheck', time(), 'local_la');
        set_config('licenseplantime', self::parse_time($response['plan_time'] ?? null), 'local_la');
        set_config('licensefeatures', self::encode_features($response['features'] ?? []), 'local_la');
        set_config('aiprompt', (string) ($response['ai_promt'] ?? $response['ai_prompt'] ?? ''), 'local_la');
        set_config('aireportsample', self::encode_ai_report_sample($response['ai_report'] ?? null), 'local_la');

        return array_merge(helper::get_license(), [
            'planname' => (string) ($response['plan_name'] ?? ''),
            'plandescription' => (string) ($response['plan_description'] ?? ''),
            'price' => (string) ($response['price'] ?? ''),
            'currency' => (string) ($response['currency'] ?? ''),
            'billingperiod' => (string) ($response['billing_period'] ?? ''),
            'updates' => is_array($plugin['updates'] ?? null) ? $plugin['updates'] : [],
            'hasupdate' => $hasupdate,
            'pluginversion' => ctype_digit($pluginversion) ? $pluginversion : '',
            'pluginreleased' => self::parse_time($plugin['released'] ?? null),
            'reports' => is_array($response['reports'] ?? null) ? $response['reports'] : [],
            'apps' => is_array($response['apps'] ?? null) ? $response['apps'] : [],
            'nextbilldate' => self::parse_time($response['next_payment_at'] ?? null),
        ]);
    }

    /**
     * Clear cached license state.
     *
     * @param string $error
     * @return array
     */
    protected static function clear_license(string $error): array {
        set_config('license', '', 'local_la');
        set_config('licensestatus', '', 'local_la');
        set_config('licenseplan', '', 'local_la');
        set_config('licenseplans', '', 'local_la');
        set_config('licensetrialends', 0, 'local_la');
        set_config('licenselastcheck', time(), 'local_la');
        set_config('licenseplantime', 0, 'local_la');
        set_config('licensefeatures', '', 'local_la');
        set_config('aiprompt', '', 'local_la');
        set_config('aireportsample', '', 'local_la');

        return helper::get_license() + ['error' => $error];
    }

    /**
     * Encode feature payload for config storage.
     *
     * @param mixed $features
     * @return string
     */
    protected static function encode_features($features): string {
        if (!is_array($features)) {
            return '';
        }

        $json = json_encode($features, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    /**
     * Encode ordered plan keys for config storage.
     *
     * @param mixed $plans
     * @return string
     */
    protected static function encode_plans($plans): string {
        if (is_string($plans)) {
            $plans = explode(',', $plans);
        }

        if (!is_array($plans)) {
            return '';
        }

        $plans = array_values(array_filter(array_unique(array_map(function($plan): string {
            return strtolower(trim((string) $plan));
        }, $plans))));
        $json = json_encode($plans, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    /**
     * Parse API timestamp.
     *
     * @param mixed $value
     * @return int
     */
    protected static function parse_time($value): int {
        if (empty($value)) {
            return 0;
        }

        $time = strtotime((string) $value);

        return $time ? (int) $time : 0;
    }

    /**
     * Encode the AI sample report from the license payload.
     *
     * @param mixed $report
     * @return string
     */
    protected static function encode_ai_report_sample($report): string {
        if (!is_array($report)) {
            return '';
        }

        return json_encode($report, JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Normalise API/local definition arrays keyed by shortname or numeric indexes.
     *
     * @param mixed $definitions
     * @return array
     */
    protected static function normalise_definitions($definitions): array {
        if (!is_array($definitions)) {
            return [];
        }

        $items = [];
        foreach ($definitions as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (empty($definition['shortname']) && is_string($key)) {
                $definition['shortname'] = $key;
            }

            if (!empty($definition['shortname'])) {
                $items[] = $definition;
            }
        }

        return $items;
    }

    /**
     * Find one full report definition in a report list.
     *
     * @param string $shortname
     * @param array $reports
     * @return array
     */
    protected static function find_report_definition(string $shortname, array $reports): array {
        return self::find_definition($shortname, $reports);
    }

    /**
     * Find one full definition in a source list.
     *
     * @param string $shortname
     * @param array $definitions
     * @return array
     */
    protected static function find_definition(string $shortname, array $definitions): array {
        foreach ($definitions as $definition) {
            if ((string) ($definition['shortname'] ?? '') === $shortname) {
                return $definition;
            }
        }

        return [];
    }

    /**
     * Post JSON to API.
     *
     * @param string $url
     * @param array $payload
     * @return array
     */
    protected static function post_json(string $url, array $payload): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $defaults = require(__DIR__ . '/../../config.php');
        $curlsettings = $defaults['apicurlsettings'] ?? [];
        $configuredsettings = get_config('local_la', 'apicurlsettings');
        if ($configuredsettings !== false && trim($configuredsettings) !== '') {
            $parsedsettings = parse_ini_string($configuredsettings, false, INI_SCANNER_TYPED);
            if (is_array($parsedsettings)) {
                $curlsettings = $parsedsettings;
            }
        }
        $curl = new \curl($curlsettings);
        $result = $curl->post($url, json_encode($payload), [
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json', 'Accept: application/json'],
            'CURLOPT_TIMEOUT' => 10,
        ]);

        return self::decode_response($curl, (string) $result, $url);
    }

    /**
     * Get API state and register the site when needed.
     *
     * @return array
     */
    protected static function get_api_state(): array {
        $state = helper::get_license();

        if (helper::is_api_mode() && $state['license'] === '') {
            return self::check_license();
        }

        return $state;
    }

    /**
     * Decode API response.
     *
     * @param \curl $curl
     * @param string $result
     * @param string $url
     * @return array
     */
    protected static function decode_response(\curl $curl, string $result, string $url): array {
        $data = json_decode($result, true);
        if (is_array($data)) {
            $data['error'] = $data['message'] ?? $data['error'] ?? null;

            return $data;
        }

        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        $error = $curl->get_errno() ? 'cURL error ' . $curl->get_errno() : 'HTTP ' . $status;
        $debug = helper::is_debug_enabled();

        if ($debug) {
            logger::add('api_request_failed', 'api', 0, [
                'url' => self::safe_url($url),
                'status' => $status,
                'curlerrno' => $curl->get_errno(),
                'curlerror' => method_exists($curl, 'get_error') ? $curl->get_error() : '',
                'response' => substr($result, 0, 500),
            ]);
        }

        return [
            'error' => $debug ? get_string('licensecheckfaileddetail', 'local_la', $error) :
                get_string('licensecheckfailed', 'local_la'),
        ];
    }

    /**
     * Strip query params from URLs before logging.
     *
     * @param string $url
     * @return string
     */
    protected static function safe_url(string $url): string {
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return $url;
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ($parts['path'] ?? '');
    }
}
