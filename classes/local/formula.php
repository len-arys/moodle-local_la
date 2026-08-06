<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Formula helper.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class formula {
    /**
     * Evaluate one simple math formula against the current row.
     *
     * @param string $formula
     * @param \stdClass $row
     * @return float|int|null
     */
    public static function evaluate(string $formula, \stdClass $row) {
        if (!preg_match('/^[a-zA-Z0-9_+\-*\/().\s]+$/', $formula)) {
            return null;
        }

        $expression = preg_replace_callback('/\b[a-zA-Z_][a-zA-Z0-9_]*\b/', function(array $matches) use ($row): string {
            $value = $row->{$matches[0]} ?? 0;

            return is_numeric($value) ? (string) $value : '0';
        }, $formula);

        if ($expression === null || preg_match('/[a-zA-Z_]/', $expression)) {
            return null;
        }

        $tokens = preg_split('/\s*([+\-*\/()])\s*/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (!is_array($tokens) || $tokens === []) {
            return null;
        }

        $values = [];
        $operators = [];

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $values[] = (float) $token;
                continue;
            }

            if ($token === '(') {
                $operators[] = $token;
                continue;
            }

            if ($token === ')') {
                while (!empty($operators) && end($operators) !== '(') {
                    if (!self::apply_operator($values, array_pop($operators))) {
                        return null;
                    }
                }

                if (empty($operators) || array_pop($operators) !== '(') {
                    return null;
                }

                continue;
            }

            if (!in_array($token, ['+', '-', '*', '/'], true)) {
                return null;
            }

            while (!empty($operators) && self::get_precedence((string) end($operators)) >= self::get_precedence($token)) {
                if (!self::apply_operator($values, array_pop($operators))) {
                    return null;
                }
            }

            $operators[] = $token;
        }

        while (!empty($operators)) {
            if (!self::apply_operator($values, array_pop($operators))) {
                return null;
            }
        }

        return count($values) === 1 ? $values[0] : null;
    }

    /**
     * Get formula operator precedence.
     *
     * @param string $operator
     * @return int
     */
    protected static function get_precedence(string $operator): int {
        if ($operator === '+' || $operator === '-') {
            return 1;
        }

        if ($operator === '*' || $operator === '/') {
            return 2;
        }

        return 0;
    }

    /**
     * Apply one formula operator.
     *
     * @param array $values
     * @param string $operator
     * @return bool
     */
    protected static function apply_operator(array &$values, string $operator): bool {
        if (count($values) < 2) {
            return false;
        }

        $right = array_pop($values);
        $left = array_pop($values);

        if ($operator === '+') {
            $values[] = $left + $right;
            return true;
        }

        if ($operator === '-') {
            $values[] = $left - $right;
            return true;
        }

        if ($operator === '*') {
            $values[] = $left * $right;
            return true;
        }

        if ($operator === '/') {
            if ((float) $right == 0.0) {
                return false;
            }

            $values[] = $left / $right;
            return true;
        }

        return false;
    }
}
