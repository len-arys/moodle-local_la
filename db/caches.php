<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cache definitions for local_la.
 *
 * @package    local_la
 * @copyright  2026 Lenarys, LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'tracking' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
    ],
    'install_definitions' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'ttl' => HOURSECS,
    ],
];
