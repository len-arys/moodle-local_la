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
 * Lightweight SQL safety checks.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {
    /** @var string[] Sensitive SQL identifiers that should never be exposed by reports. */
    protected const SENSITIVE_IDENTIFIERS = [
        'password',
        'passhash',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'clientsecret',
        'client_secret',
        'apikey',
        'api_key',
        'privatekey',
        'private_key',
        'publickey',
        'public_key',
        'sesskey',
        'sessionid',
        'session_id',
        'cookie',
        'salt',
        'mfa',
        'totp',
        'oauth',
    ];

    /**
     * Get validation rules for display.
     *
     * @param string $sql
     * @return array
     */
    public static function get_rules(string $sql): array {
        $rules = [];

        foreach (self::get_checks($sql) as $key => $passed) {
            $rules[] = [
                'key' => $key,
                'name' => get_string('sqlvalidation_' . $key, 'local_la'),
                'class' => $passed ? 'text-success' : 'text-danger',
                'icon' => $passed ? 'fa-check' : 'fa-xmark',
                'passed' => $passed,
                'failed' => !$passed,
            ];
        }

        return $rules;
    }

    /**
     * Check whether SQL passes all rules.
     *
     * @param string $sql
     * @return bool
     */
    public static function passes(string $sql): bool {
        return self::passes_fragment($sql) && self::is_read_only_query($sql);
    }

    /**
     * Check whether an SQL fragment passes all non-query rules.
     *
     * @param string $sql
     * @return bool
     */
    public static function passes_fragment(string $sql): bool {
        return !in_array(false, self::get_checks($sql), true);
    }

    /**
     * Check whether a Moodle table is allowed for AI report SQL.
     *
     * @param string $table
     * @return bool
     */
    public static function validate_table(string $table): bool {
        return !in_array(\core_text::strtolower($table), self::get_restricted_tables(), true);
    }

    /**
     * Get table choices for settings.
     *
     * @return array
     */
    public static function get_table_choices(): array {
        global $DB;

        static $choices = null;
        if ($choices !== null) {
            return $choices;
        }

        $tables = $DB->get_tables();
        sort($tables);

        $choices = array_combine($tables, $tables) ?: [];
        return $choices;
    }

    /**
     * Get default restricted AI report tables.
     *
     * @return string[]
     */
    public static function get_default_restricted_tables(): array {
        return array_values(array_filter(array_keys(self::get_table_choices()), static function (string $table): bool {
            return self::is_default_restricted_table($table);
        }));
    }

    /**
     * Get raw validation checks.
     *
     * @param string $sql
     * @return array
     */
    protected static function get_checks(string $sql): array {
        return [
            'sqlinjection' => !preg_match('/(;|--|#|\/\*|\*\/|\{\{|\}\})/', $sql),
            'writestatements' => !preg_match(
                '/\b(insert|update|delete|drop|truncate|alter|create|replace|merge|grant|revoke|call|execute|' .
                    'analyze|optimize|repair|lock|unlock|set|begin|commit|rollback|savepoint)\b/i',
                $sql
            ) && !preg_match('/\bstart\s+transaction\b/i', $sql),
            'multistatements' => strpos($sql, ';') === false,
            'tableplaceholders' => !preg_match('/\bmdl_[a-z0-9_]+\b/i', $sql),
            'externalaccess' => !preg_match('/\b(into\s+outfile|load_file|xp_cmdshell|pg_sleep|benchmark|sleep)\b/i', $sql),
            'sensitivedata' => !self::uses_sensitive_identifiers($sql),
            'existingtables' => empty(self::get_missing_tables_used($sql)),
            'restrictedtables' => !self::uses_restricted_tables($sql),
        ];
    }

    /**
     * Check for one SELECT statement, optionally using read-only CTEs.
     *
     * @param string $sql
     * @return bool
     */
    protected static function is_read_only_query(string $sql): bool {
        $sql = preg_replace([
            '/\'(?:\'\'|[^\'])*\'/s',
            '/"(?:""|[^"])*"/s',
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- SQL backtick identifiers must be removed.
            '/`(?:``|[^`])*`/s',
            '/\[(?:\]\]|[^\]])*\]/s',
        ], '', trim($sql));

        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Remaining SQL backticks make the query invalid.
        if ($sql === null || preg_match('/[\'"`\[\]]/', $sql)) {
            return false;
        }

        preg_match_all('/[a-z_][a-z0-9_]*|:=|@|[(),]/i', $sql, $matches);
        $tokens = array_map('strtolower', $matches[0]);

        return self::is_read_only_tokens($tokens);
    }

    /**
     * Check tokenized SELECT and CTE statements.
     *
     * @param string[] $tokens
     * @return bool
     */
    protected static function is_read_only_tokens(array $tokens): bool {
        $blocked = [
            'insert', 'update', 'delete', 'drop', 'truncate', 'alter', 'create', 'replace', 'merge',
            'grant', 'revoke', 'into', 'lock', 'get_lock', 'release_lock', 'nextval', 'setval',
            'updlock', 'xlock', 'holdlock', 'tablockx', ':=', '@',
        ];

        if (empty($tokens) || array_intersect($blocked, $tokens)) {
            return false;
        }

        foreach ($tokens as $index => $token) {
            if (
                (str_starts_with($token, 'pg_') && str_ends_with($token, 'lock'))
                    || ($token === 'for' && ($tokens[$index + 1] ?? '') === 'share')
            ) {
                return false;
            }
        }

        if ($tokens[0] === 'select') {
            return true;
        }

        if ($tokens[0] !== 'with') {
            return false;
        }

        $index = ($tokens[1] ?? '') === 'recursive' ? 2 : 1;
        $count = count($tokens);

        while ($index < $count) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $tokens[$index] ?? '')) {
                return false;
            }
            $index++;

            if (($tokens[$index] ?? '') === '(') {
                $index = self::find_closing_parenthesis($tokens, $index) + 1;
                if ($index === 0) {
                    return false;
                }
            }

            if (($tokens[$index] ?? '') !== 'as') {
                return false;
            }
            $index++;

            if (($tokens[$index] ?? '') === 'not') {
                $index++;
            }
            if (($tokens[$index] ?? '') === 'materialized') {
                $index++;
            }

            if (($tokens[$index] ?? '') !== '(') {
                return false;
            }

            $end = self::find_closing_parenthesis($tokens, $index);
            if ($end < 0 || !self::is_read_only_tokens(array_slice($tokens, $index + 1, $end - $index - 1))) {
                return false;
            }

            $index = $end + 1;
            if (($tokens[$index] ?? '') !== ',') {
                return ($tokens[$index] ?? '') === 'select';
            }
            $index++;
        }

        return false;
    }

    /**
     * Find the matching closing parenthesis.
     *
     * @param string[] $tokens
     * @param int $start
     * @return int
     */
    protected static function find_closing_parenthesis(array $tokens, int $start): int {
        $depth = 0;

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === '(') {
                $depth++;
            } else if ($tokens[$index] === ')' && --$depth === 0) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Check SQL for sensitive identifier names.
     *
     * @param string $sql
     * @return bool
     */
    protected static function uses_sensitive_identifiers(string $sql): bool {
        foreach (self::SENSITIVE_IDENTIFIERS as $identifier) {
            if (preg_match('/(?<![a-z0-9_])' . preg_quote($identifier, '/') . '(?![a-z0-9_])/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check SQL for restricted Moodle table placeholders.
     *
     * @param string $sql
     * @return bool
     */
    protected static function uses_restricted_tables(string $sql): bool {
        return !empty(self::get_restricted_tables_used($sql));
    }

    /**
     * Get restricted Moodle tables referenced by one SQL snippet.
     *
     * @param string $sql
     * @return string[]
     */
    public static function get_restricted_tables_used(string $sql): array {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/i', $sql, $matches);
        $restricted = [];

        foreach ($matches[1] ?? [] as $table) {
            if (!self::validate_table($table)) {
                $restricted[] = \core_text::strtolower($table);
            }
        }

        return array_values(array_unique($restricted));
    }

    /**
     * Get Moodle tables referenced by SQL that are absent from this site.
     *
     * @param string $sql
     * @return string[]
     */
    public static function get_missing_tables_used(string $sql): array {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/i', $sql, $matches);
        $available = self::get_table_choices();
        $missing = [];

        foreach ($matches[1] ?? [] as $table) {
            $table = \core_text::strtolower($table);

            if (!array_key_exists($table, $available)) {
                $missing[] = $table;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Get configured restricted AI report tables.
     *
     * @return string[]
     */
    protected static function get_restricted_tables(): array {
        static $restricted = null;
        if ($restricted !== null) {
            return $restricted;
        }

        $tables = get_config('local_la', 'restrictedtables');

        if ($tables === false || $tables === null) {
            $restricted = self::get_default_restricted_tables();
            return $restricted;
        }

        if (trim((string) $tables) === '') {
            $restricted = [];
            return $restricted;
        }

        $restricted = array_values(array_filter(array_map(
            static fn($table) => \core_text::strtolower(trim($table)),
            explode(',', (string) $tables),
        )));
        return $restricted;
    }

    /**
     * Check whether a table should be restricted by default.
     *
     * @param string $table
     * @return bool
     */
    protected static function is_default_restricted_table(string $table): bool {
        $prefixes = [
            'adminpresets', 'ai', 'backup', 'cache', 'config', 'external', 'lock', 'mnet',
            'oauth2', 'portfolio', 'profiling', 'registration_hubs', 'repository', 'search',
            'sessions', 'stored_progress', 'task', 'upgrade', 'webservice',
        ];

        foreach ($prefixes as $prefix) {
            if ($table === $prefix || str_starts_with($table, $prefix . '_')) {
                return true;
            }
        }

        return (bool) preg_match(
            '/(access_token|consumer|datakey|linked_login|mfa|nonce|oauth|password|payment|paygw|paypal|' .
                'private_key|refresh_token|secret|share_key|sso|token)/',
            $table
        );
    }
}
