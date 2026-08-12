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
 * Tests for report SQL compatibility helpers.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_la\local\report
 * @covers     \local_la\local\validator
 */
final class report_compatibility_test extends advanced_testcase {
    /**
     * Prepare isolated runtime state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        synthetic::clear_runtime_params();
    }

    /**
     * Missing Moodle tables fail validation while existing tables pass.
     */
    public function test_missing_table_validation(): void {
        $missing = 'local_la_table_that_does_not_exist';

        $this->assertSame(
            [$missing],
            validator::get_missing_tables_used("SELECT x.id FROM {{$missing}} x")
        );
        $this->assertFalse(validator::passes("SELECT x.id FROM {{$missing}} x"));
        $this->assertSame([], validator::get_missing_tables_used('SELECT u.id FROM {user} u'));
    }

    /**
     * SELECT statements and read-only CTEs pass validation.
     */
    public function test_read_only_sql_validation(): void {
        $queries = [
            'SELECT u.id FROM {user} u',
            "WITH labels AS (SELECT ')' AS label FROM {course}) SELECT label FROM labels",
            'WITH active AS (SELECT u.id FROM {user} u) SELECT id FROM active',
            'WITH first AS (SELECT 1 AS id), second AS (SELECT id FROM first) SELECT id FROM second',
            'WITH RECURSIVE sequence(value) AS (' .
                'SELECT 1 UNION ALL SELECT value + 1 FROM sequence WHERE value < 3' .
                ') SELECT value FROM sequence',
        ];

        foreach ($queries as $sql) {
            $this->assertTrue(validator::passes($sql), $sql);
        }

        $fragment = 'LEFT JOIN {course} c ON c.id = u.id';
        $this->assertTrue(validator::passes_fragment($fragment));
        $this->assertFalse(validator::passes($fragment));
    }

    /**
     * Non-read-only and multiple statements fail validation.
     */
    public function test_non_read_only_sql_validation(): void {
        $queries = [
            'CALL harmless_proc()',
            'ANALYZE TABLE {course}',
            'LOCK TABLES {course} READ',
            'UNLOCK TABLES',
            'SET @local_la_test = 1',
            'START TRANSACTION',
            'BEGIN',
            'COMMIT',
            'ROLLBACK',
            'WITH changed AS (UPDATE {course} SET visible = 1 RETURNING id) SELECT id FROM changed',
            'WITH selected AS (SELECT id FROM {course}) DELETE FROM {course}',
            'SELECT id INTO copied_course FROM {course}',
            'SELECT id FROM {course} FOR UPDATE',
            'SELECT GET_LOCK(\'local_la\', 1)',
            'SELECT 1; CALL harmless_proc()',
        ];

        foreach ($queries as $sql) {
            $this->assertFalse(validator::passes($sql), $sql);
        }
    }

    /**
     * SQL_NOW is replaced with a portable Unix timestamp literal.
     */
    public function test_sql_now_replacement(): void {
        $sql = report::build_sql(
            'SELECT SQL_COLUMNS FROM {badge_issued} bi SQL_JOIN WHERE bi.id > 0 SQL_WHERE',
            [
                'columns' => [
                    'id' => [
                        'enabled' => true,
                        'order' => 1,
                        'sql' => [
                            'column' => 'bi.id',
                        ],
                    ],
                    'status' => [
                        'enabled' => true,
                        'order' => 2,
                        'sql' => [
                            'column' => 'CASE WHEN bi.dateexpire < SQL_NOW THEN 1 ELSE 0 END',
                        ],
                    ],
                ],
            ]
        );

        $this->assertStringNotContainsString('SQL_NOW', $sql);
        $this->assertMatchesRegularExpression('/bi\.dateexpire < \d+/', $sql);
    }

    /**
     * Grouped reports aggregate generated date columns.
     */
    public function test_grouped_synthetic_columns_are_aggregated(): void {
        synthetic::set_runtime_params([
            synthetic::PARAM_PRESET => 'this_week',
            synthetic::PARAM_METRIC => 'timesec',
        ]);

        $params = synthetic::apply([
            'columns' => [],
            'synthetic' => [
                'type' => 'dates',
                'order' => 100,
                'aggregate' => true,
            ],
        ]);

        $syntheticcolumns = array_filter($params['columns'], static function (array $column): bool {
            return !empty($column['synthetic']);
        });

        $this->assertNotEmpty($syntheticcolumns);
        foreach ($syntheticcolumns as $column) {
            $this->assertStringStartsWith('MAX(', (string) $column['sql']['column']);
        }
    }

    /**
     * Numeric select filters reject text-only operators.
     */
    public function test_numeric_select_filters_reject_text_operators(): void {
        $params = [
            'columns' => [
                'course_id' => [
                    'filter' => ['type' => 'courses'],
                    'sql' => ['column' => 'tp.courseid'],
                ],
            ],
        ];

        foreach (['contains', 'notempty'] as $operator) {
            $filter = report::get_filter_sql($params, [
                'course_id' => [
                    'operator' => $operator,
                    'value' => '2',
                ],
            ]);

            $this->assertSame('', $filter['sql']);
            $this->assertSame([], $filter['params']);
        }
    }

    /**
     * Numeric select filters retain valid multi-value comparisons.
     */
    public function test_numeric_select_filters_accept_equal_operator(): void {
        $params = [
            'columns' => [
                'course_id' => [
                    'filter' => ['type' => 'courses'],
                    'sql' => ['column' => 'tp.courseid'],
                ],
            ],
        ];
        $filter = report::get_filter_sql($params, [
            'course_id' => [
                'operator' => 'equal',
                'value' => [2, 3],
            ],
        ]);

        $this->assertStringContainsString('tp.courseid', $filter['sql']);
        $this->assertCount(2, $filter['params']);
        $this->assertSame(['2', '3'], array_values($filter['params']));
    }

    /**
     * Text filters retain empty-value operators.
     */
    public function test_text_filters_accept_empty_operator(): void {
        $params = [
            'columns' => [
                'email' => [
                    'filter' => ['type' => 'text'],
                    'sql' => ['column' => 'u.email'],
                ],
            ],
        ];
        $filter = report::get_filter_sql($params, [
            'email' => [
                'operator' => 'empty',
                'value' => '',
            ],
        ]);

        $this->assertSame("(u.email IS NULL OR u.email = '')", $filter['sql']);
        $this->assertSame([], $filter['params']);
    }

    /**
     * Invalid operators do not activate hidden dynamic columns.
     */
    public function test_invalid_operator_does_not_activate_dynamic_column(): void {
        $sql = report::build_sql(
            'SELECT SQL_COLUMNS FROM {user} u SQL_JOIN WHERE u.deleted = 0 SQL_WHERE',
            [
                'columns' => [
                    'id' => [
                        'enabled' => true,
                        'sql' => ['column' => 'u.id'],
                    ],
                    'course_id' => [
                        'enabled' => true,
                        'visible' => false,
                        'dynamic' => true,
                        'filter' => ['type' => 'courses'],
                        'sql' => [
                            'column' => 'tp.courseid',
                            'source' => 'join',
                            'require' => 'tracking_course',
                        ],
                    ],
                ],
            ],
            [
                'tracking_course' => (object) [
                    'code' => 'JOIN {course} tp ON tp.id = u.id',
                ],
            ],
            [
                'course_id' => [
                    'operator' => 'notempty',
                    'value' => '',
                ],
            ]
        );

        $this->assertStringNotContainsString('tp.courseid', $sql);
        $this->assertStringNotContainsString('JOIN {course}', $sql);
    }
}
