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
use core_user\hook\extend_user_menu;
use local_la\external\columns as external_columns;
use local_la\external\library as external_library;
use local_la\external\multiselect;
use local_la\hook_callbacks;
use local_la\output\report\access as report_access;
use local_la\table\access_table;
use local_la\table\report_table;

/**
 * Tests for audience-based report access.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\local\audience
 * @covers     \local_la\local\repository
 */
final class report_access_test extends advanced_testcase {
    /**
     * Prepare isolated access state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The report library returns only reports in the current user's audience.
     */
    public function test_library_reports_are_filtered_by_audience(): void {
        $user = $this->getDataGenerator()->create_user();
        $allowedreportid = $this->create_report('Allowed report');
        $this->create_report('Hidden report');
        $this->add_user_audience($allowedreportid, (int) $user->id);
        $this->setUser($user);

        $reports = repository::get_reports();

        $this->assertSame([$allowedreportid], array_map('intval', array_keys($reports)));
        $this->assertSame(1, repository::count_reports());
    }

    /**
     * The access list includes explicit audiences, managers, and the billing admin once.
     */
    public function test_access_list_matches_effective_report_access(): void {
        global $DB;

        $reportid = $this->create_report('Access report');
        $audienceuser = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $billingadmin = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, (int) $manager->id, \context_system::instance()->id);
        set_config('billingadmins', (int) $billingadmin->id, 'local_la');
        $this->add_user_audience($reportid, (int) $audienceuser->id);
        $this->add_user_audience($reportid, (int) $manager->id);

        [$from, $where, $params] = audience::get_access_users_sql($reportid);
        $userids = array_map('intval', $DB->get_fieldset_sql(
            "SELECT u.id FROM {$from} WHERE {$where}",
            $params
        ));

        $this->assertContains((int) $audienceuser->id, $userids);
        $this->assertContains((int) $manager->id, $userids);
        $this->assertContains((int) $billingadmin->id, $userids);
        $this->assertNotContains((int) $outsider->id, $userids);
        $this->assertSame(1, count(array_keys($userids, (int) $manager->id, true)));
    }

    /**
     * Access roles reflect audience, manager, and billing admin permissions.
     */
    public function test_access_roles_match_permissions(): void {
        global $DB;

        $viewer = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $billingadmin = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, (int) $manager->id, \context_system::instance()->id);
        set_config('billingadmins', (int) $billingadmin->id, 'local_la');
        $table = new access_table(1);

        $this->assertSame(
            get_string('accessroleviewer', 'local_la'),
            $table->col_role((object) ['id' => $viewer->id])
        );
        $this->assertSame(
            get_string('accessrolemanager', 'local_la'),
            $table->col_role((object) ['id' => $manager->id])
        );
        $this->assertSame(
            get_string('accessroleadmin', 'local_la'),
            $table->col_role((object) ['id' => $billingadmin->id])
        );
    }

    /**
     * Billing admins receive the Manage access link.
     */
    public function test_billing_admin_receives_manage_access_link(): void {
        global $PAGE;

        $billingadmin = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report('Billing access report');
        set_config('billingadmins', (int) $billingadmin->id, 'local_la');
        $this->setUser($billingadmin);
        $PAGE->set_url(url::report_tab_url($reportid, ['tab' => 'access']));

        $context = report_access::get_context((object) ['id' => $reportid]);

        $this->assertSame(url::preferences(['tab' => 'access']), $context['manageaccessurl']);
    }

    /**
     * Audience members see only courses Moodle makes visible to them.
     */
    public function test_audience_member_can_load_report_course_filters(): void {
        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report('Filter report');
        $this->add_user_audience($reportid, (int) $user->id);
        $visiblecourse = $this->getDataGenerator()->create_course();
        $hiddencourse = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->setUser($user);

        $result = multiselect::get_courses($reportid);
        $courseids = [];
        foreach ($result['groups'] as $group) {
            $courseids = array_merge($courseids, array_column($group['options'], 'value'));
        }

        $this->assertContains((string) $visiblecourse->id, $courseids);
        $this->assertNotContains((string) $hiddencourse->id, $courseids);
    }

    /**
     * Users with Moodle permission to view hidden courses retain that visibility.
     */
    public function test_site_admin_can_load_hidden_course_filters(): void {
        $reportid = $this->create_report('Admin filter report');
        $hiddencourse = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->setAdminUser();

        $result = multiselect::get_courses($reportid);
        $courseids = [];
        foreach ($result['groups'] as $group) {
            $courseids = array_merge($courseids, array_column($group['options'], 'value'));
        }

        $this->assertContains((string) $hiddencourse->id, $courseids);
    }

    /**
     * Users outside the audience cannot load report filter options.
     */
    public function test_non_audience_user_cannot_load_report_course_filters(): void {
        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report('Restricted report');
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        multiselect::get_courses($reportid);
    }

    /**
     * Guest users cannot access all-users reports.
     */
    public function test_guest_cannot_access_all_users_report(): void {
        global $CFG, $DB;

        $guest = $DB->get_record('user', ['id' => (int) $CFG->siteguest], '*', MUST_EXIST);
        $reportid = $this->create_report('All users report');
        $this->add_audience($reportid, 'all', 0);
        $this->setUser($guest);

        $this->assertFalse(audience::has_access($reportid));
        $this->assertSame([], repository::get_reports());
        $this->assertSame(0, repository::count_reports());
    }

    /**
     * Audience members cannot manage report columns.
     */
    public function test_audience_member_cannot_manage_report_columns(): void {
        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report('Columns report', $this->get_report_params());
        $this->add_user_audience($reportid, (int) $user->id);
        $this->setUser($user);

        $checks = [
            fn() => external_columns::execute($reportid),
            fn() => external_columns::save_builder($reportid, []),
            fn() => external_columns::get_settings($reportid, 'email'),
            fn() => external_columns::save_settings($reportid, 'email', [
                'enabled' => 1,
                'name' => 'Email',
                'type' => 'text',
                'formula' => '',
                'condition' => '',
                'visible' => 1,
                'sortable' => 1,
            ]),
            fn() => external_columns::save_search_default($reportid, 'email'),
        ];

        foreach ($checks as $check) {
            try {
                $check();
                $this->fail('Expected column management to be denied.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('nopermissions', $exception->errorcode);
            }
        }
    }

    /**
     * Audience members can change only existing column visibility and order.
     */
    public function test_audience_member_can_save_column_display_preferences(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report('Preferences report', $this->get_report_params());
        $this->add_user_audience($reportid, (int) $user->id);
        $now = time();
        $DB->insert_record('local_la_report_users', (object) [
            'reportid' => $reportid,
            'userid' => (int) $user->id,
            'status' => 1,
            'favorite' => 0,
            'user_params' => json_encode($this->get_report_params()),
            'timeaccess' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->setUser($user);

        external_columns::save_preferences($reportid, [[
            'key' => 'email',
            'order' => 20,
            'name' => 'Changed name',
            'visible' => 0,
            'enabled' => 0,
        ]]);

        $report = repository::get_report($reportid, (int) $user->id);
        $column = $report->params['columns']['email'];
        $this->assertSame(20, (int) $column['order']);
        $this->assertSame(0, (int) $column['visible']);
        $this->assertSame('Email', $column['name']);
        $this->assertSame(1, (int) $column['enabled']);
    }

    /**
     * Audience members do not receive column management controls.
     */
    public function test_audience_member_column_toolbar_is_view_only(): void {
        global $OUTPUT;

        $context = [
            'has_columns' => true,
            'columns' => [[
                'key' => 'email',
                'name' => 'Email',
                'enabled' => true,
                'visible' => true,
            ]],
            'reportid' => 1,
            'can_manage' => false,
            'can_share' => false,
        ];
        $html = $OUTPUT->render_from_template('local_la/components/report_toolbar', $context);

        $this->assertStringContainsString('name="columns[email][visible]"', $html);
        $this->assertStringNotContainsString('data-action="column-settings"', $html);
        $this->assertStringNotContainsString('data-method="local_la_get_columns"', $html);
        $this->assertStringNotContainsString('<option value="share">', $html);

        $context['can_manage'] = true;
        $context['can_share'] = true;
        $html = $OUTPUT->render_from_template('local_la/components/report_toolbar', $context);
        $this->assertStringContainsString('data-action="column-settings"', $html);
        $this->assertStringContainsString('data-method="local_la_get_columns"', $html);
        $this->assertStringContainsString('<option value="share">', $html);
    }

    /**
     * Audience drilldown links become plain values when disabled.
     */
    public function test_audience_drilldown_links_are_hidden_when_disabled(): void {
        $table = new class (1) extends report_table {
            /**
             * Set the table state needed to render one column.
             *
             * @param bool $candrilldown
             */
            public function configure(bool $candrilldown): void {
                $this->candrilldown = $candrilldown;
                $this->config = [
                    'columns' => [
                        'name' => [
                            'name' => 'Name',
                            'link' => [
                                'type' => 'modal',
                                'method' => 'local_la_get_calendar',
                            ],
                        ],
                    ],
                ];
            }
        };
        $row = (object) ['id' => 1, 'name' => 'Example'];

        $table->configure(false);
        $this->assertSame('Example', $table->other_cols('name', $row));

        $table->configure(true);
        $this->assertStringContainsString('data-method="local_la_get_calendar"', $table->other_cols('name', $row));
    }

    /**
     * Audience members cannot preview report SQL.
     */
    public function test_audience_member_cannot_preview_report_sql(): void {
        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report('SQL report');
        $this->add_user_audience($reportid, (int) $user->id);
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('nopermissions', 'error'));
        external_library::execute_sql($reportid);
    }

    /**
     * The user menu links to reports when the user has an audience report.
     */
    public function test_user_menu_contains_reports_link_for_audience_member(): void {
        $user = $this->getDataGenerator()->create_user();
        $reportid = $this->create_report('Menu report');
        $this->add_user_audience($reportid, (int) $user->id);
        $this->setUser($user);
        $hook = new extend_user_menu();

        hook_callbacks::extend_user_menu($hook);

        $items = $hook->get_navitems();
        $this->assertCount(1, $items);
        $this->assertSame('link', $items[0]->itemtype);
        $this->assertSame(get_string('lenarysreports', 'local_la'), $items[0]->title);
        $this->assertSame(url::library(['tab' => 'reports']), $items[0]->url->out(false));
    }

    /**
     * The user menu omits the reports link when the user has no audience reports.
     */
    public function test_user_menu_omits_reports_link_without_audience_reports(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $hook = new extend_user_menu();

        hook_callbacks::extend_user_menu($hook);

        $this->assertSame([], $hook->get_navitems());
    }

    /**
     * Create a minimal report.
     *
     * @param string $name
     * @param array $params
     * @return int
     */
    private function create_report(string $name, array $params = []): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_la_report', (object) [
            'name' => $name,
            'shortname' => 'access_' . random_string(12),
            'version' => '1.0',
            'plan' => 'core',
            'report_params' => json_encode($params),
            'timesync' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Add a manual user audience.
     *
     * @param int $reportid
     * @param int $userid
     */
    private function add_user_audience(int $reportid, int $userid): void {
        $this->add_audience($reportid, 'user', $userid);
    }

    /**
     * Add one report audience.
     *
     * @param int $reportid
     * @param string $type
     * @param int $instanceid
     */
    private function add_audience(int $reportid, string $type, int $instanceid): void {
        global $DB;

        $now = time();
        $DB->insert_record('local_la_report_audience', (object) [
            'reportid' => $reportid,
            'type' => $type,
            'instanceid' => $instanceid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Get minimal report parameters with one column.
     *
     * @return array
     */
    private function get_report_params(): array {
        return [
            'columns' => [
                'email' => [
                    'name' => 'Email',
                    'order' => 10,
                    'enabled' => 1,
                    'visible' => 1,
                    'sortable' => 1,
                    'type' => 'text',
                    'sql' => [
                        'table' => 'user',
                        'column' => 'email',
                    ],
                    'filter' => [
                        'type' => 'text',
                    ],
                ],
            ],
        ];
    }
}
