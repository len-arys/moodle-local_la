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
 * App definition renderer helpers.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class app {
    /** @var array Supported widget types. */
    protected const TYPES = ['metric', 'duration', 'donut', 'totals'];

    /** @var array Widget types rendered as centered value cards. */
    protected const VALUE_TYPES = ['metric', 'duration', 'totals'];

    /** @var array */
    protected const COLORS = [
        'blue' => '#3f6fff',
        'lightblue' => '#b8ccff',
        'cyan' => '#159bd7',
        'green' => '#27b31a',
        'orange' => '#f08313',
        'red' => '#ef2f1d',
        'black' => '#212529',
        'muted' => '#e9ecef',
    ];

    /** @var array Dynamic segment color rotation. */
    protected const SEGMENT_COLORS = ['blue', 'orange', 'green', 'cyan', 'red', 'lightblue'];

    /**
     * Build template context for one app.
     *
     * @param \stdClass $record
     * @return array
     */
    public static function get_context(\stdClass $record): array {
        $definition = self::decode_definition((string) ($record->definition ?? ''));
        $widgets = [];

        foreach (($definition['widgets'] ?? []) as $widget) {
            if (!is_array($widget)) {
                continue;
            }

            $context = self::get_widget_context($widget);
            if ($context) {
                $context['appid'] = (int) ($record->id ?? 0);
                $widgets[] = $context;
            }
        }

        return [
            'id' => (int) ($record->id ?? 0),
            'name' => (string) ($definition['name'] ?? $record->name),
            'info' => (string) ($definition['info'] ?? $record->info ?? ''),
            'version' => (string) ($definition['version'] ?? $record->version),
            'widgets' => $widgets,
            'has_widgets' => !empty($widgets),
        ];
    }

    /**
     * Build one widget context by key.
     *
     * @param \stdClass $record
     * @param string $key
     * @return array|null
     */
    public static function get_widget_context_by_key(\stdClass $record, string $key): ?array {
        $definition = self::decode_definition((string) ($record->definition ?? ''));

        foreach (($definition['widgets'] ?? []) as $widget) {
            if (!is_array($widget) || (string) ($widget['key'] ?? '') !== $key) {
                continue;
            }

            $context = self::get_widget_context($widget);
            if ($context) {
                $context['appid'] = (int) ($record->id ?? 0);
            }

            return $context;
        }

        return null;
    }

    /**
     * Remove one widget from an app definition.
     *
     * @param string $definitionjson
     * @param string $key
     * @return string|null Updated JSON, or null if the widget was not found.
     */
    public static function delete_widget(string $definitionjson, string $key): ?string {
        $definition = self::decode_definition($definitionjson);
        if (empty($definition['widgets']) || !is_array($definition['widgets'])) {
            return null;
        }

        $widgets = [];
        $deleted = false;

        foreach ($definition['widgets'] as $widget) {
            if (!is_array($widget)) {
                continue;
            }

            if ((string) ($widget['key'] ?? '') === $key) {
                $deleted = true;
                continue;
            }

            $widgets[] = $widget;
        }

        if (!$deleted) {
            return null;
        }

        $definition['widgets'] = $widgets;
        $json = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json === false ? null : $json;
    }

    /**
     * Update one widget UI state flag.
     *
     * @param string $definitionjson
     * @param string $key
     * @param string $state
     * @param bool $enabled
     * @return string|null Updated JSON, or null if the widget/state was not found.
     */
    public static function update_widget_state(string $definitionjson, string $key, string $state, bool $enabled): ?string {
        $definition = self::decode_definition($definitionjson);
        if (empty($definition['widgets']) || !is_array($definition['widgets'])) {
            return null;
        }

        if (!in_array($state, ['fullwidth', 'autorefresh', 'active'], true)) {
            return null;
        }

        $updated = false;
        foreach ($definition['widgets'] as $index => $widget) {
            if (!is_array($widget) || (string) ($widget['key'] ?? '') !== $key) {
                continue;
            }

            $definition['widgets'][$index][$state] = $enabled;
            $updated = true;
            break;
        }

        if (!$updated) {
            return null;
        }

        $json = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json === false ? null : $json;
    }

    /**
     * Decode one app JSON definition.
     *
     * @param string $json
     * @return array
     */
    protected static function decode_definition(string $json): array {
        $definition = json_decode($json, true);

        return is_array($definition) ? $definition : [];
    }

    /**
     * Build one widget template context.
     *
     * @param array $widget
     * @return array|null
     */
    protected static function get_widget_context(array $widget): ?array {
        $type = (string) ($widget['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }

        $row = self::get_widget_row($widget);
        $valuefield = (string) ($widget['value'] ?? 'value');
        $value = (float) ($row->{$valuefield} ?? 0);
        $segments = self::get_segments($widget, $row);
        $displayvalue = self::format_value($value, self::get_value_format($widget, $type));

        return [
            'key' => clean_param((string) ($widget['key'] ?? uniqid('widget_', false)), PARAM_ALPHANUMEXT),
            'title' => (string) ($widget['title'] ?? ''),
            'type' => $type,
            'is_metric' => $type === 'metric',
            'is_totals' => $type === 'totals',
            'is_duration' => $type === 'duration',
            'is_donut' => $type === 'donut',
            'is_value' => in_array($type, self::VALUE_TYPES, true),
            'has_donut' => !empty($segments),
            'show_metric_value' => in_array($type, self::VALUE_TYPES, true) && empty($segments),
            'value' => $displayvalue,
            'value_class' => self::get_value_class($displayvalue),
            'segments' => $segments,
            'has_segments' => !empty($segments),
            'donutstyle' => !empty($segments) ? self::get_donut_style($segments) : '',
            'link' => self::get_link_context($widget['link'] ?? []),
            'is_active' => !empty($widget['active']),
            'is_maximized' => !empty($widget['fullwidth']),
            'is_auto_refreshing' => !empty($widget['autorefresh']),
        ];
    }

    /**
     * Execute widget SQL and return the first row.
     *
     * @param array $widget
     * @return \stdClass
     */
    protected static function get_widget_row(array $widget): \stdClass {
        global $DB;

        $sql = trim((string) ($widget['sql'] ?? ''));
        if ($sql === '' || !validator::passes($sql)) {
            return (object) [];
        }

        try {
            return $DB->get_record_sql($sql, self::get_query_params($widget), IGNORE_MULTIPLE) ?: (object) [];
        } catch (\Throwable $e) {
            return (object) [];
        }
    }

    /**
     * Build simple named query params for widgets.
     *
     * @param array $widget
     * @return array
     */
    protected static function get_query_params(array $widget): array {
        $params = [];

        foreach (($widget['params'] ?? []) as $name => $value) {
            $name = clean_param((string) $name, PARAM_ALPHANUMEXT);
            if ($name === '') {
                continue;
            }

            if (is_string($value) && preg_match('/^now-(\d+)$/', $value, $matches)) {
                $params[$name] = time() - (int) $matches[1];
                continue;
            }

            if ($value === 'now') {
                $params[$name] = time();
                continue;
            }

            $params[$name] = is_numeric($value) ? 0 + $value : (string) $value;
        }

        return $params;
    }

    /**
     * Build widget segments from one result row or segment SQL.
     *
     * @param array $widget
     * @param \stdClass $row
     * @return array
     */
    protected static function get_segments(array $widget, \stdClass $row): array {
        if (!empty($widget['segments_sql'])) {
            return self::get_sql_segments($widget);
        }

        $segments = [];
        $index = 0;

        foreach (($widget['segments'] ?? []) as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $valuefield = (string) ($segment['value'] ?? '');
            $value = (float) ($row->{$valuefield} ?? 0);
            $color = self::get_color((string) ($segment['color'] ?? self::SEGMENT_COLORS[$index % count(self::SEGMENT_COLORS)]));

            $segments[] = [
                'label' => (string) ($segment['label'] ?? ''),
                'detail' => (string) ($segment['detail'] ?? ''),
                'value' => $value,
                'displayvalue' => self::format_value($value, (string) ($segment['format'] ?? 'number')),
                'color' => $color,
                'dotstyle' => 'background-color: ' . $color . ';',
            ];
            $index++;
        }

        return $segments;
    }

    /**
     * Build segments from a SQL result set.
     *
     * @param array $widget
     * @return array
     */
    protected static function get_sql_segments(array $widget): array {
        global $DB;

        $sql = trim((string) $widget['segments_sql']);
        if ($sql === '' || !validator::passes($sql)) {
            return [];
        }

        try {
            $records = $DB->get_records_sql($sql, self::get_query_params($widget));
        } catch (\Throwable $e) {
            return [];
        }

        $segments = [];
        $index = 0;
        foreach ($records as $record) {
            $value = (float) ($record->value ?? 0);
            if ($value <= 0) {
                continue;
            }

            $color = self::get_color((string) ($record->color ?? self::SEGMENT_COLORS[$index % count(self::SEGMENT_COLORS)]));
            $segments[] = [
                'label' => (string) ($record->label ?? ''),
                'detail' => (string) ($record->detail ?? ''),
                'value' => $value,
                'displayvalue' => self::format_value($value, (string) ($widget['segments_format'] ?? 'number')),
                'color' => $color,
                'dotstyle' => 'background-color: ' . $color . ';',
            ];
            $index++;
        }

        return $segments;
    }

    /**
     * Format a widget value.
     *
     * @param float $value
     * @param string $format
     * @return string
     */
    protected static function format_value(float $value, string $format): string {
        if ($format === 'duration') {
            return self::format_duration_value((int) $value);
        }

        return self::format_number($value);
    }

    /**
     * Format dashboard duration values.
     *
     * @param int $seconds
     * @return string
     */
    protected static function format_duration_value(int $seconds): string {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = (int) floor($seconds / 3600);
        if ($hours >= 10000) {
            return self::format_compact_hours($hours) . ' h';
        }

        if ($hours >= 1000) {
            return number_format($hours) . 'h';
        }

        return calendar::format_duration_value($seconds);
    }

    /**
     * Format large hour values for compact dashboard cards.
     *
     * @param int $hours
     * @return string
     */
    protected static function format_compact_hours(int $hours): string {
        $value = number_format($hours / 1000, 1);

        return rtrim(rtrim($value, '0'), '.') . 'K';
    }

    /**
     * Resolve widget value format.
     *
     * @param array $widget
     * @param string $type
     * @return string
     */
    protected static function get_value_format(array $widget, string $type): string {
        if (!empty($widget['format'])) {
            return (string) $widget['format'];
        }

        return $type === 'duration' ? 'duration' : 'number';
    }

    /**
     * Resolve value size class.
     *
     * @param string $value
     * @return string
     */
    protected static function get_value_class(string $value): string {
        $length = strlen(preg_replace('/\s+/', '', $value));

        if ($length >= 9) {
            return 'la-app-value-xl';
        }

        if ($length >= 7) {
            return 'la-app-value-lg';
        }

        return '';
    }

    /**
     * Build CSS conic gradient for donut segments.
     *
     * @param array $segments
     * @return string
     */
    protected static function get_donut_style(array $segments): string {
        $total = array_sum(array_map(static fn($segment) => (float) $segment['value'], $segments));
        if ($total <= 0) {
            return 'background: conic-gradient(' . self::COLORS['muted'] . ' 0deg 360deg);';
        }

        $start = 0.0;
        $parts = [];
        foreach ($segments as $segment) {
            $end = $start + (((float) $segment['value'] / $total) * 360);
            $parts[] = $segment['color'] . ' ' . round($start, 2) . 'deg ' . round($end, 2) . 'deg';
            $start = $end;
        }

        return 'background: conic-gradient(' . implode(', ', $parts) . ');';
    }

    /**
     * Link context for widget footer.
     *
     * @param mixed $link
     * @return array
     */
    protected static function get_link_context($link): array {
        if (!is_array($link)) {
            return [];
        }

        if (($link['type'] ?? '') === 'modal' && !empty($link['method'])) {
            $paramsjson = json_encode($link['params'] ?? []);

            return [
                'ismodal' => true,
                'url' => '#',
                'label' => (string) ($link['label'] ?? get_string('viewmore', 'local_la')),
                'method' => (string) $link['method'],
                'paramsjson' => $paramsjson === false ? '{}' : $paramsjson,
                'title' => (string) ($link['title'] ?? $link['label'] ?? get_string('viewmore', 'local_la')),
            ];
        }

        $url = '';
        if (!empty($link['url'])) {
            $url = (string) $link['url'];
        } else if (!empty($link['report'])) {
            $reports = repository::get_all_reports();
            $report = $reports[(string) $link['report']] ?? null;
            if (!empty($report['id'])) {
                $url = url::report((int) $report['id']);
            }
        }

        if ($url === '') {
            return [];
        }

        return [
            'ismodal' => false,
            'url' => $url,
            'label' => (string) ($link['label'] ?? get_string('viewmore', 'local_la')),
        ];
    }

    /**
     * Resolve one dashboard color.
     *
     * @param string $color
     * @return string
     */
    protected static function get_color(string $color): string {
        if (preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $color)) {
            return $color;
        }

        return self::COLORS[$color] ?? self::COLORS['blue'];
    }

    /**
     * Format a compact number.
     *
     * @param float $value
     * @return string
     */
    protected static function format_number(float $value): string {
        return number_format($value, floor($value) == $value ? 0 : 2);
    }
}
