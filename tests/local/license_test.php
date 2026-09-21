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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace local_la\local;

use advanced_testcase;

/**
 * Tests for license checks and cached entitlements.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\local\api
 * @covers     \local_la\local\helper
 */
final class license_test extends advanced_testcase {
    /**
     * Set up an isolated license configuration.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('apiurl', 'https://api.example.com', 'local_la');
        require_once($CFG->libdir . '/filelib.php');
    }

    /**
     * Empty-license registration saves the generated trial identity and dates.
     */
    public function test_first_activation_saves_free_trial(): void {
        $payload = $this->payload('free', 'trialing');
        $payload['trial_ends_at'] = time() + 30 * DAYSECS;
        $payload['plan_time'] = $payload['trial_ends_at'];
        $payload['next_payment_at'] = null;
        \curl::mock_response(json_encode($payload));

        $state = api::check_license();

        $this->assertSame($payload['license'], get_config('local_la', 'license'));
        $this->assertSame('free', $state['plan']);
        $this->assertSame('Free Trial', $state['planlabel']);
        $this->assertSame('Free Trial', $state['planname']);
        $this->assertSame($payload['trial_ends_at'], $state['trialends']);
        $this->assertTrue(helper::has_plan('free'));
        $this->assertFalse(helper::has_plan('core'));
        $this->assertTrue(helper::has_feature('calendar'));
    }

    /**
     * A paid key replaces a trial and all returned metadata survives cache reads.
     */
    public function test_paid_license_check_caches_state(): void {
        api::apply_license_payload($this->payload('free', 'trialing'));
        $payload = $this->payload('core', 'active');
        $payload['license'] = str_repeat('b', 64);
        $payload['trial_ends_at'] = null;
        \curl::mock_response(json_encode($payload));

        set_config('license', $payload['license'], 'local_la');
        $state = api::check_license();

        $this->assertSame($payload['license'], $state['license']);
        $this->assertSame('core', $state['plan']);
        $this->assertTrue(helper::has_plan('core'));
        $this->assertFalse(helper::has_plan('pro'));
        $cached = helper::get_license();
        $this->assertSame($state, $cached + ['reports' => [], 'apps' => []]);
        $this->assertSame($payload['next_payment_at'], $cached['nextbilldate']);
        $this->assertSame('published', $cached['pluginstatus']);
        $this->assertSame(['License fixes'], $cached['updates']);
    }

    /**
     * A failed request keeps the paid identity for retry, but grants no access.
     */
    public function test_failed_check_retains_key_without_entitlements(): void {
        api::apply_license_payload($this->payload());
        \curl::mock_response('Service unavailable');
        $state = api::check_license();
        $this->assertNotEmpty($state['error']);
        $this->assertSame(str_repeat('a', 64), get_config('local_la', 'license'));
        $this->assertFalse(helper::has_plan('core'));
        $this->assertFalse(helper::has_feature('calendar'));
    }

    /**
     * HTTP errors cannot be accepted as successful JSON responses.
     */
    public function test_http_errors_have_clear_messages_and_reject_payloads(): void {
        $method = new \ReflectionMethod(api::class, 'decode_response');
        $method->setAccessible(true);
        foreach ([401 => 'licenseinvalidrequired', 409 => 'licenseboundelsewhere', 500 => 'licensecheckfailed'] as $code => $key) {
            $curl = $this->getMockBuilder(\curl::class)->disableOriginalConstructor()
                ->onlyMethods(['get_info', 'get_errno'])->getMock();
            $curl->method('get_info')->willReturn(['http_code' => $code]);
            $curl->method('get_errno')->willReturn(0);
            foreach (['<html>Error</html>', json_encode($this->payload())] as $body) {
                $result = $method->invoke(null, $curl, $body, 'https://api.example.com/api/license/check');
                $this->assertSame(get_string($key, 'local_la'), $result['error']);
                $this->assertArrayNotHasKey('license', $result);
            }
        }
    }

    /**
     * Revoked statuses, expired dates, and missing plan time all deny access.
     */
    public function test_entitlement_checks_enforce_status_and_dates(): void {
        $cases = [
            ['status' => 'past_due'],
            ['status' => 'cancelled'],
            ['status' => 'inactive'],
            ['plan_time' => null],
            ['plan_time' => time() - 1],
            ['next_payment_at' => time() - 1],
            ['plan' => 'free', 'status' => 'trialing', 'trial_ends_at' => time() - 1],
        ];
        foreach ($cases as $overrides) {
            $payload = array_replace($this->payload(), $overrides);
            api::apply_license_payload($payload);
            $this->assertFalse(helper::has_plan($payload['plan']), json_encode($overrides));
            $this->assertFalse(helper::has_feature('calendar'), json_encode($overrides));
        }
    }

    /**
     * A valid paid license cannot implicitly enable absent features.
     */
    public function test_features_still_come_from_api(): void {
        api::apply_license_payload($this->payload());
        $this->assertTrue(helper::has_feature('calendar'));
        $this->assertFalse(helper::has_feature('priority_support'));
        $payload = $this->payload();
        $payload['features'] = ['calendar' => true, 'priority_support' => false];
        api::apply_license_payload($payload);
        $this->assertTrue(helper::has_feature('calendar'));
        $this->assertFalse(helper::has_feature('priority_support'));
    }

    /**
     * Build a license-check response.
     *
     * @param string $plan
     * @param string $status
     * @return array
     */
    private function payload(string $plan = 'core', string $status = 'active'): array {
        return [
            'license' => str_repeat('a', 64),
            'plan' => $plan,
            'plan_name' => ucfirst($plan),
            'plans' => ['free', 'core', 'pro', 'max'],
            'status' => $status,
            'plan_time' => time() + DAYSECS,
            'next_payment_at' => time() + DAYSECS,
            'trial_ends_at' => null,
            'features' => ['calendar'],
            'plugin' => [
                'version' => '2099010100',
                'updates' => ['License fixes'],
                'released' => '2026-09-08T12:00:00Z',
                'status' => 'published',
            ],
        ];
    }
}
