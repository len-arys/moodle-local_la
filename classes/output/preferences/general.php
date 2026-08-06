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

namespace local_la\output\preferences;

defined('MOODLE_INTERNAL') || die();

use local_la\local\api;
use local_la\local\helper;
use moodle_url;

/**
 * Preferences general tab context.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class general {
    /**
     * Build tab context.
     *
     * @return array
     */
    public static function get_context(): array {
        global $DB;

        $defaults = require(__DIR__ . '/../../../config.php');
        $hasaiproviders = helper::supports_ai_providers();
        $license = helper::is_api_mode() ? api::check_license() : api::get_local_license();
        $hasplan = !empty($license['planname']);
        $statusstring = 'licensestatus_' . preg_replace('/[^a-z0-9_]/', '', (string) $license['status']);
        $updates = array_values(array_filter(array_map('strval', $license['updates'] ?? [])));
        $hasupdate = !empty($license['hasupdate']);
        $released = !empty($license['pluginreleased']) ?
            userdate((int) $license['pluginreleased'], get_string('strftimedate', 'langconfig')) :
            get_string('notset', 'local_la');
        $trialdays = !empty($license['trialends']) ? max(0, (int) ceil(((int) $license['trialends'] - time()) / DAYSECS)) : 0;
        $price = plan::format_price((string) $license['price']);
        $pricesuffix = plan::get_price_suffix((string) $license['currency'], (string) $license['billingperiod']);
        $plandetails = [
            [
                'label' => get_string('plandetailcurrentplan', 'local_la'),
                'value' => s($license['planlabel']),
            ],
        ];

        if (!empty($license['license'])) {
            $plandetails[] = [
                'label' => get_string('licensekey', 'local_la'),
                'licensekey' => true,
                'shortvalue' => s('••••••••' . substr((string) $license['license'], -5)),
                'fullvalue' => s($license['license']),
            ];
        }

        $plandetails[] = [
            'label' => get_string('aireportgeneration', 'local_la'),
            'value' => trim((string) get_config('local_la', 'aiprompt')) !== '' ?
                get_string('statuson', 'local_la') :
                get_string('statusoff', 'local_la'),
        ];

        if (!empty($license['trialends'])) {
            $plandetails[] = ['text' => get_string($trialdays === 1 ? 'plandetailtrialendsone' : 'plandetailtrialends',
                'local_la', (object) [
                    'days' => $trialdays,
                    'date' => userdate((int) $license['trialends'], get_string('strftimedate', 'langconfig')),
                ])];
        } else if (!empty($license['nextbilldate'])) {
            $plandetails[] = ['text' => get_string('plandetailnextbill', 'local_la',
                userdate((int) $license['nextbilldate']))];
        }

        foreach (helper::get_admins() as $admin) {
            if (!empty($admin['isbillingadmin'])) {
                $plandetails[] = [
                    'label' => get_string('plandetailbillingadmin', 'local_la'),
                    'value' => trim($admin['firstname'] . ' ' . $admin['lastname']),
                    'textafter' => '(' . $admin['email'] . ')',
                ];
                break;
            }
        }

        $plandetails[] = ['text' => get_string('plandetailappsinstalled', 'local_la',
            $DB->count_records('local_la_app'))];
        $plandetails[] = ['text' => get_string('plandetailreportsinstalled', 'local_la',
            $DB->count_records('local_la_report'))];
        $plandetails[] = ['text' => get_string('plandetailreportsinuse', 'local_la',
            $DB->count_records_sql('SELECT COUNT(DISTINCT reportid) FROM {local_la_report_users} WHERE status = 1'))];
        $plandetails[] = ['text' => get_string('plandetailschedules', 'local_la',
            $DB->count_records('local_la_report_schedule', ['status' => 1]))];

        return [
            'settings' => [
                'hasaiproviders' => $hasaiproviders,
                'aiprovidertext' => $hasaiproviders ? get_string('availableproviders', 'ai') : '',
                'aiproviderurl' => $hasaiproviders ?
                    (new moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(false) : '',
                'aiproviderunsupportedmessage' => $hasaiproviders ?
                    '' : get_string('erroraimoodleversion', 'local_la'),
                'docsurl' => (string) ($defaults['docsurl'] ?? ''),
                'hasprioritysupport' => helper::has_feature('priority_support'),
                'pluginsettingsurl' => (new moodle_url('/admin/settings.php', ['section' => 'local_la']))->out(false),
            ],
            'license' => [
                'hasplan' => $hasplan,
                'notregistered' => !$hasplan,
                'error' => s($license['error'] ?? ''),
                'haserror' => !empty($license['error']),
                'planname' => s($license['planname']),
                'plandescription' => s($license['plandescription']),
                'planstatus' => s(get_string_manager()->string_exists($statusstring, 'local_la') ?
                    get_string($statusstring, 'local_la') :
                    $license['status']),
                'planstatusclass' => s([
                    'active' => 'text-bg-success',
                    'trialing' => 'text-bg-warning',
                    'past_due' => 'text-bg-danger',
                    'cancelled' => 'text-bg-dark',
                ][$license['status']] ?? 'text-bg-secondary'),
                'planiconurl' => plan::get_icon_url((string) $license['plan']),
                'planrighttop' => s($price ?: $license['planlabel']),
                'planrightsuffix' => s($pricesuffix),
                'planrightbottom' => s(plan::get_right_bottom($license, $trialdays)),
                'plandetails' => $plandetails,
                'hasplandetails' => $hasplan,
                'apimode' => s($license['apimode'] === helper::API_MODE_LOCAL ?
                    get_string('pluginapi_manual', 'local_la') :
                    get_string('pluginapi_auto', 'local_la')),
                'apiurl' => s($license['apiurl'] ?: get_string('notset', 'local_la')),
                'key' => s($license['license'] ?: get_string('notset', 'local_la')),
                'status' => s($license['status']),
                'plan' => s($license['planlabel']),
                'trialends' => $license['trialends'],
                'hastrialends' => !empty($license['trialends']),
                'lastcheck' => $license['lastcheck'],
                'haslastcheck' => !empty($license['lastcheck']),
                'updates' => array_map(function(string $update): array {
                    return ['text' => s($update)];
                }, $updates),
                'hasupdates' => !empty($updates),
                'showupdates' => $hasupdate,
                'hasupdate' => $hasupdate,
                'hasupdateurl' => $hasupdate && !empty($defaults['downloadurl']),
                'updateurl' => (string) ($defaults['downloadurl'] ?? ''),
                'updatedetails' => $hasupdate ? s(get_string('updateversiondetails', 'local_la', (object) [
                    'current' => (string) get_config('local_la', 'version'),
                    'available' => (string) ($license['pluginversion'] ?? ''),
                    'released' => $released,
                ])) : '',
            ],
        ];
    }

}
