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

/**
 * Shared plugin helper methods.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** @var int Moodle 4.5, where the AI provider subsystem became available. */
    public const MOODLE_AI_MIN_VERSION = 2024100700;

    /** @var string Automatic API mode. */
    public const API_MODE_AUTO = 'api';

    /** @var string Local API mode. */
    public const API_MODE_LOCAL = 'manual';

    /** @var string Default plan key. */
    public const DP = 'core';

    /**
     * Check whether one user is the configured Billing Admin.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function is_billing_admin(?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;

        if ($userid <= 0) {
            return false;
        }

        return $userid === self::get_billing_admin_id();
    }

    /**
     * Check whether one user can manage Learning Analytics.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function is_admin(?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;

        if ($userid <= 0) {
            return false;
        }

        return self::is_billing_admin($userid) || has_capability('local/la:manage', \context_system::instance(), $userid);
    }

    /**
     * Check whether one user can use report drilldowns.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function can_use_drilldown(?int $userid = null): bool {
        return self::is_admin($userid) || !empty(get_config('local_la', 'allowaudiencedrilldown'));
    }

    /**
     * Check whether one user can share report rows.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function can_share_reports(?int $userid = null): bool {
        return self::is_admin($userid) || !empty(get_config('local_la', 'allowaudiencesharing'));
    }

    /**
     * Check whether a billing admin is configured.
     *
     * @return bool
     */
    public static function has_billing_admin(): bool {
        return self::get_billing_admin_id() > 0;
    }

    /**
     * Get Learning Analytics admins.
     *
     * @return array
     */
    public static function get_admins(): array {
        $context = \context_system::instance();
        $billingadminid = self::get_billing_admin_id();
        $users = get_users_by_capability(
            $context,
            'local/la:manage',
            'u.id, u.firstname, u.lastname, u.email',
            'u.firstname ASC, u.lastname ASC, u.id ASC'
        );

        if ($billingadminid > 0 && ($billingadmin = \core_user::get_user($billingadminid, 'id, firstname, lastname, email'))) {
            unset($users[$billingadminid]);
            array_unshift($users, $billingadmin);
        }

        return array_map(function (\stdClass $user) use ($billingadminid): array {
            $isbillingadmin = (int) $user->id === $billingadminid;

            return [
                'id' => (int) $user->id,
                'firstname' => s($user->firstname),
                'lastname' => s($user->lastname),
                'email' => s($user->email),
                'role' => s($isbillingadmin ? get_string('billingadmins', 'local_la') : 'local/la:manage'),
                'isbillingadmin' => $isbillingadmin,
            ];
        }, array_values($users));
    }

    /**
     * Run common plugin page checks.
     *
     * @param string $type Optional page object type.
     * @param string $plan Optional page object plan.
     * @return void
     */
    public static function init_page(string $type = '', string $plan = ''): void {
        if (self::has_billing_admin()) {
            self::require_page_plan($type, $plan);
            return;
        }

        redirect(
            new \moodle_url('/admin/settings.php', ['section' => 'local_la']),
            get_string('billingadminsrequired', 'local_la'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    /**
     * Check current plan against the page record.
     *
     * @param string $type
     * @param string $plan
     * @return void
     */
    protected static function require_page_plan(string $type, string $plan): void {
        if ($plan === '' || !in_array($type, ['report', 'app'], true)) {
            return;
        }

        if (self::has_plan($plan)) {
            return;
        }

        redirect(
            url::library_url(['tab' => $type === 'app' ? 'apps' : 'reports']),
            get_string('planrequired', 'local_la', self::get_plan_label($plan)),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    /**
     * Get plans.
     *
     * @return array
     */
    public static function get_plans(): array {
        $classes = ['text-bg-success', 'text-bg-dark', 'text-bg-warning', 'text-bg-primary', 'text-bg-secondary'];
        $plans = [];

        foreach (self::get_plan_keys() as $order => $plan) {
            $plans[$plan] = [
                'label' => self::get_plan_string($plan),
                'class' => $classes[$order] ?? 'text-bg-secondary',
                'order' => $order,
            ];
        }

        return $plans;
    }

    /**
     * Get API mode.
     *
     * @return string
     */
    public static function get_api_mode(): string {
        return get_config('local_la', 'api') === self::API_MODE_LOCAL ? self::API_MODE_LOCAL : self::API_MODE_AUTO;
    }

    /**
     * Check whether plugin should use Laravel API.
     *
     * @return bool
     */
    public static function is_api_mode(): bool {
        return self::get_api_mode() === self::API_MODE_AUTO;
    }

    /**
     * Check whether plugin debugging is enabled.
     *
     * @return bool
     */
    public static function is_debug_enabled(): bool {
        return !empty(get_config('local_la', 'debug'));
    }

    /**
     * Check whether this Moodle version supports AI providers.
     *
     * @return bool
     */
    public static function supports_ai_providers(): bool {
        global $CFG;

        return (float) ($CFG->version ?? 0) >= self::MOODLE_AI_MIN_VERSION;
    }

    /**
     * Get cached license state.
     *
     * @return array
     */
    public static function get_license(): array {
        $plan = self::normalize_plan((string) get_config('local_la', 'licenseplan'));
        $status = trim((string) get_config('local_la', 'licensestatus')) ?: 'inactive';
        $plantime = (int) get_config('local_la', 'licenseplantime');

        return [
            'apimode' => self::get_api_mode(),
            'apiurl' => trim((string) get_config('local_la', 'apiurl')),
            'license' => trim((string) get_config('local_la', 'license')),
            'status' => $status,
            'plan' => $plan,
            'planlabel' => self::get_plan_label($plan),
            'plantime' => $plantime,
            'features' => self::decode_license_features(),
            'planname' => '',
            'plandescription' => '',
            'price' => '',
            'currency' => '',
            'billingperiod' => '',
            'updates' => [],
            'hasupdate' => false,
            'pluginversion' => '',
            'pluginreleased' => 0,
            'trialends' => (int) get_config('local_la', 'licensetrialends'),
            'nextbilldate' => 0,
            'lastcheck' => (int) get_config('local_la', 'licenselastcheck'),
        ];
    }

    /**
     * Check whether current license includes the requested plan.
     *
     * @param string $plan
     * @return bool
     */
    public static function has_plan(string $plan): bool {
        $plans = self::get_plans();
        $license = self::get_license();
        if (empty($license['plantime']) || (int) $license['plantime'] < time()) {
            return false;
        }

        $current = $license['plan'];
        $required = strtolower(trim($plan)) ?: self::DP;
        if (!array_key_exists($required, $plans)) {
            return false;
        }

        return ($plans[$current]['order'] ?? 0) >= ($plans[$required]['order'] ?? 0);
    }

    /**
     * Check whether current license enables a feature.
     *
     * @param string $feature
     * @return bool
     */
    public static function has_feature(string $feature): bool {
        $license = self::get_license();
        if (empty($license['plantime']) || (int) $license['plantime'] < time()) {
            return false;
        }

        return !empty($license['features'][$feature]);
    }

    /**
     * Normalize billing plan.
     *
     * @param string $plan
     * @return string
     */
    public static function normalize_plan(string $plan): string {
        $plan = strtolower(trim($plan));
        return array_key_exists($plan, self::get_plans()) ? $plan : self::DP;
    }

    /**
     * Get a plan display label.
     *
     * @param string $plan
     * @return string
     */
    public static function get_plan_label(string $plan): string {
        $plan = strtolower(trim($plan)) ?: self::DP;
        $plans = self::get_plans();

        return $plans[$plan]['label'] ?? self::get_plan_string($plan);
    }

    /**
     * Get ordered plan keys from the cached license state.
     *
     * @return string[]
     */
    protected static function get_plan_keys(): array {
        $configured = json_decode((string) get_config('local_la', 'licenseplans'), true);
        $plans = is_array($configured) ? $configured : [];
        $default = self::DP;

        $plans = array_values(array_filter(array_unique(array_map(function ($plan): string {
            return strtolower(trim((string) $plan));
        }, $plans))));

        if (!in_array($default, $plans, true)) {
            array_unshift($plans, $default);
        }

        return $plans;
    }

    /**
     * Get a plan label without requiring a language string for future plans.
     *
     * @param string $plan
     * @return string
     */
    protected static function get_plan_string(string $plan): string {
        $identifier = 'plan_' . $plan;
        if (get_string_manager()->string_exists($identifier, 'local_la')) {
            return get_string($identifier, 'local_la');
        }

        return ucfirst(str_replace('_', ' ', $plan));
    }

    /**
     * Decode cached license feature flags.
     *
     * @return array
     */
    protected static function decode_license_features(): array {
        $features = json_decode((string) get_config('local_la', 'licensefeatures'), true);

        return is_array($features) ? $features : [];
    }

    /**
     * Get configured billing admin user id.
     *
     * @return int
     */
    protected static function get_billing_admin_id(): int {
        return (int) get_config('local_la', 'billingadmins');
    }
}
