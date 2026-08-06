<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

declare(strict_types=1);

namespace local_la\output;

use advanced_testcase;

/**
 * Tests for the billing summary.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class billing_test extends advanced_testcase {
    /**
     * The removed payment service is not registered.
     */
    public function test_payment_service_is_not_registered(): void {
        global $DB;

        $this->assertFalse($DB->record_exists('external_functions', ['name' => 'local_la_billing_url']));
    }

    /**
     * Billing is read-only inside Moodle.
     */
    public function test_billing_summary_has_no_payment_actions(): void {
        global $OUTPUT;

        $defaults = require(__DIR__ . '/../../config.php');
        $contacturl = 'mailto:' . $defaults['contactemail'];
        $marketplaceurl = $defaults['marketplaceurl'];
        $pricingurl = $defaults['pricingurl'];
        $context = [
            'header' => '',
            'head' => '',
            'contacturl' => $contacturl,
            'hascontacturl' => true,
            'hasbillingactions' => true,
            'marketplaceurl' => $marketplaceurl,
            'hasmarketplaceurl' => true,
            'pricingurl' => $pricingurl,
            'haspricingurl' => true,
            'settingsurl' => '/settings',
        ];

        $html = $OUTPUT->render_from_template('local_la/pages/preferences/billing', $context);

        $this->assertStringContainsString('<ul class="mb-0">', $html);
        $this->assertStringNotContainsString('class="alert', $html);
        $this->assertStringContainsString('href="' . $contacturl . '"', $html);
        $this->assertSame(2, substr_count($html, 'href="' . $marketplaceurl . '"'));
        $this->assertStringContainsString('class="btn btn-primary"', $html);
        $this->assertStringContainsString('href="' . $pricingurl . '"', $html);
        $this->assertStringNotContainsString('data-action="billing-checkout"', $html);
        $this->assertStringNotContainsString('data-action="billing-portal"', $html);
    }
}
