<?php

declare(strict_types=1);

// Executed via require inside index.php's AJAX run_tests handler.
// $vault is NOT in scope – read from session directly.
// Content-Type: application/json header already sent by caller.

use de\xqueue\maileon\api\client\account\AccountPlaceholder;
use de\xqueue\maileon\api\client\account\AccountService;
use de\xqueue\maileon\api\client\blacklists\BlacklistsService;
use de\xqueue\maileon\api\client\blacklists\mailings\MailingBlacklistExpressions;
use de\xqueue\maileon\api\client\blacklists\mailings\MailingBlacklistsService;
use de\xqueue\maileon\api\client\contactfilters\ContactFilter;
use de\xqueue\maileon\api\client\contactfilters\ContactfiltersService;
use de\xqueue\maileon\api\client\contacts\Contact;
use de\xqueue\maileon\api\client\contacts\ContactsService;
use de\xqueue\maileon\api\client\contacts\Permission;
use de\xqueue\maileon\api\client\contacts\Preference;
use de\xqueue\maileon\api\client\contacts\PreferenceCategory;
use de\xqueue\maileon\api\client\contacts\StandardContactField;
use de\xqueue\maileon\api\client\contacts\SynchronizationMode;
use de\xqueue\maileon\api\client\dataextensions\DataExtensionsService;
use de\xqueue\maileon\api\client\mailings\CustomProperty;
use de\xqueue\maileon\api\client\mailings\MailingsService;
use de\xqueue\maileon\api\client\media\MediaService;
use de\xqueue\maileon\api\client\reports\ReportsService;
use de\xqueue\maileon\api\client\targetgroups\TargetGroup;
use de\xqueue\maileon\api\client\targetgroups\TargetGroupsService;
use de\xqueue\maileon\api\client\transactions\AttributeType;
use de\xqueue\maileon\api\client\transactions\ContactReference;
use de\xqueue\maileon\api\client\transactions\DataType;
use de\xqueue\maileon\api\client\transactions\Transaction;
use de\xqueue\maileon\api\client\transactions\TransactionsService;
use de\xqueue\maileon\api\client\transactions\TransactionType;
use de\xqueue\maileon\api\client\utils\PingService;
use de\xqueue\maileon\api\client\webhooks\Webhook;
use de\xqueue\maileon\api\client\webhooks\WebhooksService;

// ── Config ──────────────────────────────────────────────────────────────────

$vault        = $_SESSION['vault'] ?? [];
$showDebugTab = !empty($vault['debug']);
$cfg          = [
    'API_KEY'  => $vault['api_key'] ?? '',
    'BASE_URI' => $vault['base_uri'] ?? 'https://api.maileon.com/1.0',
    'DEBUG'    => true,
];

if (empty($cfg['API_KEY'])) {
    echo json_encode(['error' => 'API key not configured.']);
    return;
}

$testEmail    = $vault['test_email']        ?? '';
$testEmail2   = $vault['test_email2']       ?? '';
$testExtId    = $vault['test_external_id']  ?? '';
$testExtId2   = $vault['test_external_id2'] ?? '';
$cfgMailingId = (int)($vault['test_mailing_id']   ?? 0);
$cfgCfId      = (int)($vault['test_cf_id']        ?? 0);
$cfgBlId      = (int)($vault['test_blacklist_id']  ?? 0);
$cfgDeId      = (int)($vault['test_de_id']         ?? 0);
$cfgTxTypeId  = (int)($vault['test_tx_type_id']   ?? 0);
$cfgTxId      = $vault['test_tx_id'] ?? '';
$cfgWebhookId = (int)($vault['test_webhook_id']   ?? 0);

// ── Per-run parameter overrides sent from the UI params panel ─────────────────
$p = (array)($_POST['params'] ?? []);
if (isset($p['test_email'])        && $p['test_email']        !== '') $testEmail    = trim($p['test_email']);
if (isset($p['test_email2'])       && $p['test_email2']       !== '') $testEmail2   = trim($p['test_email2']);
if (isset($p['test_external_id'])  && $p['test_external_id']  !== '') $testExtId    = trim($p['test_external_id']);
if (isset($p['test_external_id2']) && $p['test_external_id2'] !== '') $testExtId2   = trim($p['test_external_id2']);
if (isset($p['test_mailing_id'])   && $p['test_mailing_id']   !== '') $cfgMailingId = (int)$p['test_mailing_id'];
if (isset($p['test_cf_id'])        && $p['test_cf_id']        !== '') $cfgCfId      = (int)$p['test_cf_id'];
if (isset($p['test_blacklist_id']) && $p['test_blacklist_id'] !== '') $cfgBlId      = (int)$p['test_blacklist_id'];
if (isset($p['test_de_id'])        && $p['test_de_id']        !== '') $cfgDeId      = (int)$p['test_de_id'];
if (isset($p['test_tx_type_id'])   && $p['test_tx_type_id']   !== '') $cfgTxTypeId  = (int)$p['test_tx_type_id'];
if (isset($p['test_tx_id'])        && $p['test_tx_id']        !== '') $cfgTxId      = trim($p['test_tx_id']);
if (isset($p['test_webhook_id'])   && $p['test_webhook_id']   !== '') $cfgWebhookId = (int)$p['test_webhook_id'];

$selectedTests = (array)($_POST['tests'] ?? []);
if (empty($selectedTests)) {
    echo json_encode(['results' => []]);
    return;
}

// ── Canonical run order (mirrors $allTests in index.php) ─────────────────────

$canonicalOrder = [
    'ping_get', 'ping_put', 'ping_post', 'ping_delete',
    'contact_count', 'contact_get_by_email', 'contact_get_by_ext_id',
    'contact_list', 'contact_list_update_after', 'contact_blocked',
    'contact_custom_fields', 'contact_create', 'contact_create_ext_id',
    'contact_update', 'contact_sync', 'contact_create_custom_field',
    'contact_rename_custom_field', 'contact_del_custom_field_vals',
    'contact_del_custom_field', 'contact_del_std_field_vals',
    'contact_unsubscribe_email', 'contact_unsubscribe_id',
    'contact_delete', 'contact_delete_ext_id',
    'pref_cat_list', 'pref_cat_create', 'pref_cat_get', 'pref_cat_update', 'pref_cat_delete',
    'pref_list', 'pref_create', 'pref_get', 'pref_update', 'pref_delete',
    'cf_count', 'cf_list', 'cf_get', 'cf_create', 'cf_update', 'cf_refresh', 'cf_delete',
    'tg_count', 'tg_list', 'tg_create', 'tg_get', 'tg_delete',
    'mail_list', 'mail_list_state', 'mail_subject', 'mail_sender', 'mail_sender_alias',
    'mail_replyto', 'mail_preview', 'mail_tags', 'mail_locale', 'mail_html',
    'mail_archive_url', 'mail_report_url', 'mail_domain', 'mail_state', 'mail_type',
    'mail_cf_restrictions', 'mail_custom_props', 'mail_exists',
    'mail_create', 'mail_set_html', 'mail_set_sender', 'mail_set_replyto',
    'mail_set_preview', 'mail_set_tags', 'mail_set_locale', 'mail_add_custom_prop',
    'mail_upd_custom_prop', 'mail_del_custom_prop', 'mail_disable_qos', 'mail_copy', 'mail_delete',
    'media_templates', 'media_cms2_templates',
    'rep_recipients', 'rep_opens', 'rep_unique_opens', 'rep_clicks', 'rep_unique_clicks',
    'rep_bounces', 'rep_unique_bounces', 'rep_unsubs', 'rep_unsub_reasons',
    'rep_subscribers', 'rep_blocks', 'rep_conversions', 'rep_uniq_conv',
    'tx_type_count', 'tx_type_list', 'tx_type_get', 'tx_type_create', 'tx_type_create2',
    'tx_send', 'tx_send_multi', 'tx_recent', 'tx_get', 'tx_delete',
    'tx_delete_by_date', 'tx_type_delete',
    'bl_list', 'bl_get', 'bl_entries',
    'mbl_list', 'mbl_create', 'mbl_get', 'mbl_update', 'mbl_entries', 'mbl_get_entries', 'mbl_delete',
    'acc_info', 'acc_ph_list', 'acc_ph_set', 'acc_ph_update', 'acc_ph_delete', 'acc_domains',
    'wh_list', 'wh_get', 'wh_create', 'wh_get_created', 'wh_update', 'wh_delete',
    'de_list', 'de_list_paged', 'de_get', 'de_get_fields', 'de_records', 'de_records_desc',
    'de_records_filtered', 'de_sync_upsert', 'de_sync_insert_ign', 'de_sync_empty',
];

$selectedSet = array_flip($selectedTests);
$toRun       = array_filter($canonicalOrder, static fn($k) => isset($selectedSet[$k]));

// ── Helpers ──────────────────────────────────────────────────────────────────

function _ser(mixed $value): mixed
{
    if ($value === null || is_scalar($value) || is_bool($value)) {
        return $value;
    }
    if ($value instanceof \SimpleXMLElement) {
        return (string) $value;
    }
    if (is_array($value)) {
        return array_map('_ser', array_values($value));
    }
    if (is_object($value)) {
        $out = [];
        foreach (get_object_vars($value) as $k => $v) {
            $out[$k] = _ser($v);
        }
        return $out;
    }
    return (string) $value;
}

function _res(string $label, ?object $response): array
{
    $reqHeaders = [
        'Content-Type'  => 'application/vnd.maileon.api+xml',
        'Accept'        => 'application/vnd.maileon.api+xml',
        'Authorization' => 'Basic [redacted]',
        'Expect'        => '',
    ];

    if ($response === null) {
        return [
            'label'       => $label,
            'success'     => false,
            'data'        => null,
            'status'      => 0,
            'skipped'     => false,
            'message'     => 'null response',
            'req_headers' => $reqHeaders,
            'res_headers' => null,
        ];
    }

    $rawHeaders = $response->getResponseHeaders();
    $httpLine   = $rawHeaders['http_code'] ?? null;
    unset($rawHeaders['http_code']);

    return [
        'label'       => $label,
        'success'     => $response->isSuccess(),
        'data'        => _ser($response->getResult()),
        'body'        => $response->getBodyData(),
        'status'      => $response->getStatusCode(),
        'http_line'   => $httpLine,
        'skipped'     => false,
        'message'     => $response->isSuccess() ? '' : ('HTTP ' . $response->getStatusCode()),
        'req_headers' => $reqHeaders,
        'res_headers' => empty($rawHeaders) ? null : $rawHeaders,
    ];
}

function _skp(string $label, string $reason): array
{
    return [
        'label'   => $label,
        'success' => true,
        'data'    => null,
        'status'  => 0,
        'skipped' => true,
        'message' => $reason,
    ];
}

function _parse_debug_log(string $raw): array
{
    $text   = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $method = null;
    $path   = null;
    $url    = null;
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line, "\r");
        if (preg_match('/^> (GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS) (\S+) HTTP\//', $line, $m)) {
            $method = $m[1];
            $path   = $m[2];
        } elseif ($path !== null && preg_match('/^> Host: (.+)$/', $line, $m)) {
            $url = 'https://' . trim($m[1]) . $path;
            break;
        }
    }
    return ['method' => $method, 'url' => $url];
}

function _clean_debug_log(string $raw): string
{
    $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/^(> Authorization:)\s*.+$/m', '$1 [redacted]', $text);
    return trim($text);
}

// ── Per-request shared state ──────────────────────────────────────────────────

$st = [
    'contact_id'     => null,
    'mailing_id'     => null,
    'cf_id'          => null,
    'tg_id'          => null,
    'tx_type_id'     => null,
    'tx_type_id2'    => null,
    'webhook_id'     => null,
    'mbl_id'         => null,
    'pref_cat_name'  => 'php-ui-test-cat',
    'pref_name'      => 'php-ui-test-pref',
    'cf_field_name'  => 'PhpUiTestField',
    'cf_filter_name' => 'php-ui-test-filter',
    'mbl_name'       => 'php-ui-test-mbl',
    'tx_type_name'   => 'php_ui_test_type',
    'tx_type_name2'  => 'php_ui_test_type2',
    'tg_name'        => 'php-ui-test-tg',
    'mail_name'      => 'php-ui-test-mailing',
    'mail_subject'   => 'UI Test Subject',
    'webhook_url'    => '',
];

// Apply name/string overrides from UI params panel
if (isset($p['pref_cat_name'])  && $p['pref_cat_name']  !== '') $st['pref_cat_name']  = trim($p['pref_cat_name']);
if (isset($p['pref_name'])      && $p['pref_name']       !== '') $st['pref_name']      = trim($p['pref_name']);
if (isset($p['cf_field_name'])  && $p['cf_field_name']   !== '') $st['cf_field_name']  = trim($p['cf_field_name']);
if (isset($p['cf_filter_name']) && $p['cf_filter_name']  !== '') $st['cf_filter_name'] = trim($p['cf_filter_name']);
if (isset($p['mbl_name'])       && $p['mbl_name']        !== '') $st['mbl_name']       = trim($p['mbl_name']);
if (isset($p['tx_type_name'])   && $p['tx_type_name']    !== '') $st['tx_type_name']   = trim($p['tx_type_name']);
if (isset($p['tx_type_name2'])  && $p['tx_type_name2']   !== '') $st['tx_type_name2']  = trim($p['tx_type_name2']);
if (isset($p['tg_name'])        && $p['tg_name']         !== '') $st['tg_name']        = trim($p['tg_name']);
if (isset($p['mail_name'])      && $p['mail_name']       !== '') $st['mail_name']      = trim($p['mail_name']);
if (isset($p['mail_subject'])   && $p['mail_subject']    !== '') $st['mail_subject']   = trim($p['mail_subject']);
if (isset($p['webhook_url'])    && $p['webhook_url']     !== '') $st['webhook_url']    = trim($p['webhook_url']);

$results = [];

// ── Test execution ────────────────────────────────────────────────────────────

foreach ($toRun as $key) {
    $t0 = microtime(true);
    ob_start();
    try {
        $r = null;

        // ── Ping ──────────────────────────────────────────────────────────────
        if ($key === 'ping_get') {
            $r = _res('GET', (new PingService($cfg))->pingGet());

        } elseif ($key === 'ping_put') {
            $r = _res('PUT', (new PingService($cfg))->pingPut());

        } elseif ($key === 'ping_post') {
            $r = _res('POST', (new PingService($cfg))->pingPost());

        } elseif ($key === 'ping_delete') {
            $r = _res('DELETE', (new PingService($cfg))->pingDelete());

        // ── Contacts – read ───────────────────────────────────────────────────
        } elseif ($key === 'contact_count') {
            $r = _res('Count', (new ContactsService($cfg))->getContactsCount());

        } elseif ($key === 'contact_get_by_email') {
            if (!$testEmail) {
                $r = _skp('Get by email', 'test_email not configured');
            } else {
                $svc  = new ContactsService($cfg);
                $resp = $svc->getContactByEmail($testEmail, [StandardContactField::$FIRSTNAME, StandardContactField::$LASTNAME, StandardContactField::$GENDER]);
                if ($resp->isSuccess() && $resp->getResult()) {
                    $st['contact_id'] = (int) $resp->getResult()->id;
                }
                $r = _res('Get by email', $resp);
            }

        } elseif ($key === 'contact_get_by_ext_id') {
            if (!$testExtId) {
                $r = _skp('Get by external ID', 'test_external_id not configured');
            } else {
                $r = _res('Get by external ID', (new ContactsService($cfg))->getContactsByExternalId($testExtId));
            }

        } elseif ($key === 'contact_list') {
            $r = _res('List (paginated)', (new ContactsService($cfg))->getContacts(1, 10));

        } elseif ($key === 'contact_list_update_after') {
            $ago30d = strtotime('-30 days') * 1000;
            $r = _res('List (updated after)', (new ContactsService($cfg))->getContacts(1, 10, [], [], $ago30d));

        } elseif ($key === 'contact_blocked') {
            $r = _res('Blocked contacts', (new ContactsService($cfg))->getBlockedContacts(1, 10));

        } elseif ($key === 'contact_custom_fields') {
            $r = _res('Custom fields list', (new ContactsService($cfg))->getCustomFields());

        // ── Contacts – write ──────────────────────────────────────────────────
        } elseif ($key === 'contact_create') {
            if (!$testEmail) {
                $r = _skp('Create', 'test_email not configured');
            } else {
                $contact              = new Contact();
                $contact->email       = $testEmail;
                $contact->permission  = Permission::$DOI;
                $contact->external_id = $testExtId ?: null;
                $r = _res('Create', (new ContactsService($cfg))->createContact($contact, SynchronizationMode::$UPDATE));
            }

        } elseif ($key === 'contact_create_ext_id') {
            if (!$testEmail2 || !$testExtId2) {
                $r = _skp('Create by external ID', 'test_email2 or test_external_id2 not configured');
            } else {
                $contact              = new Contact();
                $contact->email       = $testEmail2;
                $contact->permission  = Permission::$SOI;
                $contact->external_id = $testExtId2;
                $r = _res('Create by external ID', (new ContactsService($cfg))->createContactByExternalId($contact, SynchronizationMode::$UPDATE));
            }

        } elseif ($key === 'contact_update') {
            if (!$testEmail) {
                $r = _skp('Update', 'test_email not configured');
            } else {
                $contact              = new Contact();
                $contact->email       = $testEmail;
                $contact->permission  = Permission::$DOI;
                $contact->standard_fields = [StandardContactField::$FIRSTNAME => 'UITest'];
                $r = _res('Update', (new ContactsService($cfg))->updateContactByEmail($testEmail, $contact));
            }

        } elseif ($key === 'contact_sync') {
            if (!$testEmail) {
                $r = _skp('Synchronize', 'test_email not configured');
            } else {
                $contact              = new Contact();
                $contact->email       = $testEmail;
                $contact->permission  = Permission::$DOI;
                $contact->standard_fields = [StandardContactField::$FIRSTNAME => 'UISync'];
                $r = _res('Synchronize', (new ContactsService($cfg))->synchronizeContacts([$contact], null, SynchronizationMode::$UPDATE));
            }

        } elseif ($key === 'contact_create_custom_field') {
            $r = _res('Create custom field', (new ContactsService($cfg))->createCustomField($st['cf_field_name'], 'String'));

        } elseif ($key === 'contact_rename_custom_field') {
            $newName = $st['cf_field_name'] . 'Renamed';
            $resp = (new ContactsService($cfg))->renameCustomField($st['cf_field_name'], $newName);
            if ($resp && $resp->isSuccess()) {
                $st['cf_field_name'] = $newName;
            }
            $r = _res('Rename custom field', $resp);

        } elseif ($key === 'contact_del_custom_field_vals') {
            $r = _res('Delete custom field values', (new ContactsService($cfg))->deleteCustomFieldValues($st['cf_field_name']));

        } elseif ($key === 'contact_del_custom_field') {
            $r = _res('Delete custom field', (new ContactsService($cfg))->deleteCustomField($st['cf_field_name']));

        } elseif ($key === 'contact_del_std_field_vals') {
            $r = _res('Delete std field values', (new ContactsService($cfg))->deleteStandardFieldValues('FIRSTNAME'));

        // ── Contacts – destructive ────────────────────────────────────────────
        } elseif ($key === 'contact_unsubscribe_email') {
            if (!$testEmail) {
                $r = _skp('Unsubscribe (by email)', 'test_email not configured');
            } else {
                $r = _res('Unsubscribe (by email)', (new ContactsService($cfg))->unsubscribeContactByEmail($testEmail));
            }

        } elseif ($key === 'contact_unsubscribe_id') {
            if (!$st['contact_id']) {
                $r = _skp('Unsubscribe (by ID)', 'contact_id not available – run "Get by email" first');
            } else {
                $r = _res('Unsubscribe (by ID)', (new ContactsService($cfg))->unsubscribeContactById($st['contact_id']));
            }

        } elseif ($key === 'contact_delete') {
            if (!$testEmail) {
                $r = _skp('Delete (by email)', 'test_email not configured');
            } else {
                $r = _res('Delete (by email)', (new ContactsService($cfg))->deleteContactByEmail($testEmail));
            }

        } elseif ($key === 'contact_delete_ext_id') {
            if (!$testExtId2) {
                $r = _skp('Delete (by external ID)', 'test_external_id2 not configured');
            } else {
                $r = _res('Delete (by external ID)', (new ContactsService($cfg))->deleteContactsByExternalId($testExtId2));
            }

        // ── Preference categories ─────────────────────────────────────────────
        } elseif ($key === 'pref_cat_list') {
            $r = _res('List preference categories', (new ContactsService($cfg))->getContactPreferenceCategories());

        } elseif ($key === 'pref_cat_create') {
            $cat              = new PreferenceCategory();
            $cat->name        = $st['pref_cat_name'];
            $cat->description = 'Created by UI test runner';
            $r = _res('Create preference category', (new ContactsService($cfg))->createContactPreferenceCategory($cat));

        } elseif ($key === 'pref_cat_get') {
            $r = _res('Get preference category', (new ContactsService($cfg))->getContactPreferenceCategoryByName($st['pref_cat_name']));

        } elseif ($key === 'pref_cat_update') {
            $cat              = new PreferenceCategory();
            $cat->name        = $st['pref_cat_name'];
            $cat->description = 'Updated by UI test runner';
            $r = _res('Update preference category', (new ContactsService($cfg))->updateContactPreferenceCategory($st['pref_cat_name'], $cat));

        } elseif ($key === 'pref_cat_delete') {
            $r = _res('Delete preference category', (new ContactsService($cfg))->deleteContactPreferenceCategory($st['pref_cat_name']));

        } elseif ($key === 'pref_list') {
            $r = _res('List preferences', (new ContactsService($cfg))->getPreferencesOfContactPreferencesCategory($st['pref_cat_name']));

        } elseif ($key === 'pref_create') {
            $pref              = new Preference();
            $pref->name        = $st['pref_name'];
            $pref->description = 'Created by UI test runner';
            $r = _res('Create preference', (new ContactsService($cfg))->createContactPreference($st['pref_cat_name'], $pref));

        } elseif ($key === 'pref_get') {
            $r = _res('Get preference', (new ContactsService($cfg))->getContactPreference($st['pref_cat_name'], $st['pref_name']));

        } elseif ($key === 'pref_update') {
            $pref              = new Preference();
            $pref->name        = $st['pref_name'];
            $pref->description = 'Updated by UI test runner';
            $r = _res('Update preference', (new ContactsService($cfg))->updateContactPreference($st['pref_cat_name'], $st['pref_name'], $pref));

        } elseif ($key === 'pref_delete') {
            $r = _res('Delete preference', (new ContactsService($cfg))->deleteContactPreference($st['pref_cat_name'], $st['pref_name']));

        // ── Contact filters ───────────────────────────────────────────────────
        } elseif ($key === 'cf_count') {
            $r = _res('Count', (new ContactfiltersService($cfg))->getContactFiltersCount());

        } elseif ($key === 'cf_list') {
            $r = _res('List', (new ContactfiltersService($cfg))->getContactFilters(1, 10));

        } elseif ($key === 'cf_get') {
            $id = $cfgCfId ?: $st['cf_id'];
            if (!$id) {
                $r = _skp('Get (config ID)', 'test_cf_id not configured and cf_create not yet run');
            } else {
                $r = _res('Get (config ID)', (new ContactfiltersService($cfg))->getContactFilter($id));
            }

        } elseif ($key === 'cf_create') {
            $filter       = new ContactFilter();
            $filter->name = $st['cf_filter_name'];
            $resp = (new ContactfiltersService($cfg))->createContactFilter($filter, false);
            if ($resp && $resp->isSuccess()) {
                $st['cf_id'] = (int) $resp->getResult();
            }
            $r = _res('Create', $resp);

        } elseif ($key === 'cf_update') {
            $id = $st['cf_id'];
            if (!$id) {
                $r = _skp('Update name', 'cf_create not yet run in this session');
            } else {
                $filter       = new ContactFilter();
                $filter->name = $st['cf_filter_name'] . '-updated';
                $r = _res('Update name', (new ContactfiltersService($cfg))->updateContactFilter($id, $filter));
            }

        } elseif ($key === 'cf_refresh') {
            $id = $st['cf_id'] ?: $cfgCfId;
            if (!$id) {
                $r = _skp('Refresh', 'no contact filter ID available');
            } else {
                $r = _res('Refresh', (new ContactfiltersService($cfg))->refreshContactFilterContacts($id, null));
            }

        } elseif ($key === 'cf_delete') {
            $id = $st['cf_id'];
            if (!$id) {
                $r = _skp('Delete created', 'cf_create not yet run in this session');
            } else {
                $r = _res('Delete created', (new ContactfiltersService($cfg))->deleteContactFilter($id));
            }

        // ── Target groups ─────────────────────────────────────────────────────
        } elseif ($key === 'tg_count') {
            $r = _res('Count', (new TargetGroupsService($cfg))->getTargetGroupsCount());

        } elseif ($key === 'tg_list') {
            $r = _res('List', (new TargetGroupsService($cfg))->getTargetGroups(1, 10));

        } elseif ($key === 'tg_create') {
            $tg       = new TargetGroup();
            $tg->name = $st['tg_name'];
            $tg->type = 'contact_filter';
            $resp = (new TargetGroupsService($cfg))->createTargetGroup($tg);
            if ($resp && $resp->isSuccess()) {
                $st['tg_id'] = (int) $resp->getResult();
            }
            $r = _res('Create', $resp);

        } elseif ($key === 'tg_get') {
            if (!$st['tg_id']) {
                $r = _skp('Get', 'tg_create not yet run in this session');
            } else {
                $r = _res('Get', (new TargetGroupsService($cfg))->getTargetGroup($st['tg_id']));
            }

        } elseif ($key === 'tg_delete') {
            if (!$st['tg_id']) {
                $r = _skp('Delete', 'tg_create not yet run in this session');
            } else {
                $r = _res('Delete', (new TargetGroupsService($cfg))->deleteTargetGroup($st['tg_id']));
            }

        // ── Mailings – read ───────────────────────────────────────────────────
        } elseif ($key === 'mail_list') {
            $r = _res('List (by type)', (new MailingsService($cfg))->getMailingsByTypes(1, 10, ['regular']));

        } elseif ($key === 'mail_list_state') {
            $r = _res('List (by state)', (new MailingsService($cfg))->getMailingsByStates(1, 10, ['draft']));

        } elseif ($key === 'mail_subject') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get subject', 'no mailing ID – run mail_create or set test_mailing_id');
            } else {
                $r = _res('Get subject', (new MailingsService($cfg))->getSubject($id));
            }

        } elseif ($key === 'mail_sender') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get sender', 'no mailing ID');
            } else {
                $r = _res('Get sender', (new MailingsService($cfg))->getSender($id));
            }

        } elseif ($key === 'mail_sender_alias') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get sender alias', 'no mailing ID');
            } else {
                $r = _res('Get sender alias', (new MailingsService($cfg))->getSenderAlias($id));
            }

        } elseif ($key === 'mail_replyto') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get reply-to', 'no mailing ID');
            } else {
                $r = _res('Get reply-to', (new MailingsService($cfg))->getReplyToAddress($id));
            }

        } elseif ($key === 'mail_preview') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get preview text', 'no mailing ID');
            } else {
                $r = _res('Get preview text', (new MailingsService($cfg))->getPreviewText($id));
            }

        } elseif ($key === 'mail_tags') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get tags', 'no mailing ID');
            } else {
                $r = _res('Get tags', (new MailingsService($cfg))->getTags($id));
            }

        } elseif ($key === 'mail_locale') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get locale', 'no mailing ID');
            } else {
                $r = _res('Get locale', (new MailingsService($cfg))->getLocale($id));
            }

        } elseif ($key === 'mail_html') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get HTML', 'no mailing ID');
            } else {
                $r = _res('Get HTML', (new MailingsService($cfg))->getHTMLContent($id));
            }

        } elseif ($key === 'mail_archive_url') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get archive URL', 'no mailing ID');
            } else {
                $r = _res('Get archive URL', (new MailingsService($cfg))->getArchiveUrl($id));
            }

        } elseif ($key === 'mail_report_url') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get report URL', 'no mailing ID');
            } else {
                $r = _res('Get report URL', (new MailingsService($cfg))->getReportUrl($id));
            }

        } elseif ($key === 'mail_domain') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get domain', 'no mailing ID');
            } else {
                $r = _res('Get domain', (new MailingsService($cfg))->getMailingDomain($id));
            }

        } elseif ($key === 'mail_state') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get state', 'no mailing ID');
            } else {
                $r = _res('Get state', (new MailingsService($cfg))->getState($id));
            }

        } elseif ($key === 'mail_type') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get type', 'no mailing ID');
            } else {
                $r = _res('Get type', (new MailingsService($cfg))->getType($id));
            }

        } elseif ($key === 'mail_cf_restrictions') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('CF restrictions count', 'no mailing ID');
            } else {
                $r = _res('CF restrictions count', (new MailingsService($cfg))->getContactFilterRestrictionsCount($id));
            }

        } elseif ($key === 'mail_custom_props') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Get custom properties', 'no mailing ID');
            } else {
                $r = _res('Get custom properties', (new MailingsService($cfg))->getCustomProperties($id));
            }

        } elseif ($key === 'mail_exists') {
            $r = _res('Check exists by name', (new MailingsService($cfg))->getMailingIdByName($st['mail_name']));

        // ── Mailings – write ──────────────────────────────────────────────────
        } elseif ($key === 'mail_create') {
            $resp = (new MailingsService($cfg))->createMailing($st['mail_name'], $st['mail_subject']);
            if ($resp && $resp->isSuccess()) {
                $st['mailing_id'] = (int) $resp->getResult();
            }
            $r = _res('Create draft', $resp);

        } elseif ($key === 'mail_set_html') {
            if (!$st['mailing_id']) {
                $r = _skp('Set HTML', 'mail_create not yet run');
            } else {
                $html = '<html><body><p>UI Test Content [unsubscribe]</p></body></html>';
                $r = _res('Set HTML', (new MailingsService($cfg))->setHTMLContent($st['mailing_id'], $html));
            }

        } elseif ($key === 'mail_set_sender') {
            if (!$st['mailing_id']) {
                $r = _skp('Set sender', 'mail_create not yet run');
            } else {
                $r = _res('Set sender', (new MailingsService($cfg))->setSender($st['mailing_id'], $testEmail ?: 'noreply@example.com'));
            }

        } elseif ($key === 'mail_set_replyto') {
            if (!$st['mailing_id']) {
                $r = _skp('Set reply-to', 'mail_create not yet run');
            } else {
                $r = _res('Set reply-to', (new MailingsService($cfg))->setReplyToAddress($st['mailing_id'], false, $testEmail ?: 'noreply@example.com'));
            }

        } elseif ($key === 'mail_set_preview') {
            if (!$st['mailing_id']) {
                $r = _skp('Set preview text', 'mail_create not yet run');
            } else {
                $r = _res('Set preview text', (new MailingsService($cfg))->setPreviewText($st['mailing_id'], 'UI test preview'));
            }

        } elseif ($key === 'mail_set_tags') {
            if (!$st['mailing_id']) {
                $r = _skp('Set tags', 'mail_create not yet run');
            } else {
                $r = _res('Set tags', (new MailingsService($cfg))->setTags($st['mailing_id'], ['ui-test']));
            }

        } elseif ($key === 'mail_set_locale') {
            if (!$st['mailing_id']) {
                $r = _skp('Set locale', 'mail_create not yet run');
            } else {
                $r = _res('Set locale', (new MailingsService($cfg))->setLocale($st['mailing_id'], 'de_DE'));
            }

        } elseif ($key === 'mail_add_custom_prop') {
            if (!$st['mailing_id']) {
                $r = _skp('Add custom prop', 'mail_create not yet run');
            } else {
                $prop        = new CustomProperty();
                $prop->key   = 'ui_test_prop';
                $prop->value = 'hello';
                $r = _res('Add custom prop', (new MailingsService($cfg))->addCustomProperties($st['mailing_id'], [$prop]));
            }

        } elseif ($key === 'mail_upd_custom_prop') {
            if (!$st['mailing_id']) {
                $r = _skp('Update custom prop', 'mail_create not yet run');
            } else {
                $prop        = new CustomProperty();
                $prop->key   = 'ui_test_prop';
                $prop->value = 'world';
                $r = _res('Update custom prop', (new MailingsService($cfg))->updateCustomProperty($st['mailing_id'], $prop));
            }

        } elseif ($key === 'mail_del_custom_prop') {
            if (!$st['mailing_id']) {
                $r = _skp('Delete custom prop', 'mail_create not yet run');
            } else {
                $r = _res('Delete custom prop', (new MailingsService($cfg))->deleteCustomProperty($st['mailing_id'], 'ui_test_prop'));
            }

        } elseif ($key === 'mail_disable_qos') {
            if (!$st['mailing_id']) {
                $r = _skp('Disable QoS checks', 'mail_create not yet run');
            } else {
                $r = _res('Disable QoS checks', (new MailingsService($cfg))->disableQosChecks($st['mailing_id']));
            }

        } elseif ($key === 'mail_copy') {
            $id = $st['mailing_id'] ?: $cfgMailingId;
            if (!$id) {
                $r = _skp('Copy mailing', 'no mailing ID');
            } else {
                $r = _res('Copy mailing', (new MailingsService($cfg))->copyMailing($id));
            }

        } elseif ($key === 'mail_delete') {
            if (!$st['mailing_id']) {
                $r = _skp('Delete created', 'mail_create not yet run');
            } else {
                $r = _res('Delete created', (new MailingsService($cfg))->deleteMailing($st['mailing_id']));
            }

        // ── Media ─────────────────────────────────────────────────────────────
        } elseif ($key === 'media_templates') {
            $r = _res('CMS1 templates', (new MediaService($cfg))->getMailingTemplates());

        } elseif ($key === 'media_cms2_templates') {
            $r = _res('CMS2 templates', (new MediaService($cfg))->getCms2MailingTemplates());

        // ── Reports ───────────────────────────────────────────────────────────
        } elseif ($key === 'rep_recipients') {
            if (!$cfgMailingId) {
                $r = _skp('Recipients', 'test_mailing_id not configured');
            } else {
                $r = _res('Recipients', (new ReportsService($cfg))->getRecipientsCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_opens') {
            if (!$cfgMailingId) {
                $r = _skp('Opens', 'test_mailing_id not configured');
            } else {
                $r = _res('Opens', (new ReportsService($cfg))->getOpensCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_unique_opens') {
            if (!$cfgMailingId) {
                $r = _skp('Unique opens', 'test_mailing_id not configured');
            } else {
                $r = _res('Unique opens', (new ReportsService($cfg))->getUniqueOpensCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_clicks') {
            if (!$cfgMailingId) {
                $r = _skp('Clicks', 'test_mailing_id not configured');
            } else {
                $r = _res('Clicks', (new ReportsService($cfg))->getClicksCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_unique_clicks') {
            if (!$cfgMailingId) {
                $r = _skp('Unique clicks', 'test_mailing_id not configured');
            } else {
                $r = _res('Unique clicks', (new ReportsService($cfg))->getUniqueClicksCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_bounces') {
            if (!$cfgMailingId) {
                $r = _skp('Bounces', 'test_mailing_id not configured');
            } else {
                $r = _res('Bounces', (new ReportsService($cfg))->getBouncesCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_unique_bounces') {
            if (!$cfgMailingId) {
                $r = _skp('Unique bounces', 'test_mailing_id not configured');
            } else {
                $r = _res('Unique bounces', (new ReportsService($cfg))->getUniqueBouncesCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_unsubs') {
            if (!$cfgMailingId) {
                $r = _skp('Unsubscribers', 'test_mailing_id not configured');
            } else {
                $r = _res('Unsubscribers', (new ReportsService($cfg))->getUnsubscribersCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_unsub_reasons') {
            $r = _res('Unsubscriber reasons', (new ReportsService($cfg))->getUnsubscriberReasons());

        } elseif ($key === 'rep_subscribers') {
            if (!$cfgMailingId) {
                $r = _skp('Subscribers', 'test_mailing_id not configured');
            } else {
                $r = _res('Subscribers', (new ReportsService($cfg))->getSubscribersCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_blocks') {
            if (!$cfgMailingId) {
                $r = _skp('Blocks', 'test_mailing_id not configured');
            } else {
                $r = _res('Blocks', (new ReportsService($cfg))->getBlocksCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_conversions') {
            if (!$cfgMailingId) {
                $r = _skp('Conversions', 'test_mailing_id not configured');
            } else {
                $r = _res('Conversions', (new ReportsService($cfg))->getConversionsCount(null, null, [$cfgMailingId]));
            }

        } elseif ($key === 'rep_uniq_conv') {
            if (!$cfgMailingId) {
                $r = _skp('Unique conversions', 'test_mailing_id not configured');
            } else {
                $r = _res('Unique conversions', (new ReportsService($cfg))->getUniqueConversionsCount(null, null, [$cfgMailingId]));
            }

        // ── Transactions ──────────────────────────────────────────────────────
        } elseif ($key === 'tx_type_count') {
            $r = _res('Type count', (new TransactionsService($cfg))->getTransactionTypesCount());

        } elseif ($key === 'tx_type_list') {
            $r = _res('Type list', (new TransactionsService($cfg))->getTransactionTypes(1, 10));

        } elseif ($key === 'tx_type_get') {
            $id = $cfgTxTypeId;
            if (!$id) {
                $r = _skp('Get type (config ID)', 'test_tx_type_id not configured');
            } else {
                $r = _res('Get type (config ID)', (new TransactionsService($cfg))->getTransactionType($id));
            }

        } elseif ($key === 'tx_type_create') {
            $trt       = new TransactionType();
            $trt->name = $st['tx_type_name'];
            $a          = new AttributeType();
            $a->name    = 'order_id';
            $a->type    = DataType::$STRING;
            $a->required = false;
            $trt->attributes = [$a];
            $resp = (new TransactionsService($cfg))->createTransactionType($trt);
            if ($resp && $resp->isSuccess()) {
                $st['tx_type_id'] = (int) $resp->getResult();
            }
            $r = _res('Create type', $resp);

        } elseif ($key === 'tx_type_create2') {
            $trt       = new TransactionType();
            $trt->name = $st['tx_type_name2'];
            $a1         = new AttributeType();
            $a1->name   = 'transaction_id';
            $a1->type   = DataType::$STRING;
            $a1->required = false;
            $a2         = new AttributeType();
            $a2->name   = 'amount';
            $a2->type   = DataType::$DOUBLE;
            $a2->required = false;
            $trt->attributes = [$a1, $a2];
            $resp = (new TransactionsService($cfg))->createTransactionType($trt);
            if ($resp && $resp->isSuccess()) {
                $st['tx_type_id2'] = (int) $resp->getResult();
            }
            $r = _res('Create complex type', $resp);

        } elseif ($key === 'tx_send') {
            $typeId = $st['tx_type_id'] ?: $cfgTxTypeId;
            if (!$typeId || !$testEmail) {
                $r = _skp('Send transaction', !$typeId ? 'no tx type ID' : 'test_email not configured');
            } else {
                $contact        = new ContactReference();
                $contact->email = $testEmail;
                $tx             = new Transaction();
                $tx->contact    = $contact;
                $tx->typeid     = $typeId;
                $tx->content    = ['order_id' => 'ui-test-001'];
                $r = _res('Send transaction', (new TransactionsService($cfg))->createTransactions([$tx], true, false));
            }

        } elseif ($key === 'tx_send_multi') {
            $typeId = $st['tx_type_id'] ?: $cfgTxTypeId;
            if (!$typeId || !$testEmail) {
                $r = _skp('Send 3 transactions', !$typeId ? 'no tx type ID' : 'test_email not configured');
            } else {
                $txs = [];
                foreach (['ui-test-002', 'ui-test-003', 'ui-test-004'] as $oid) {
                    $contact        = new ContactReference();
                    $contact->email = $testEmail;
                    $tx             = new Transaction();
                    $tx->contact    = $contact;
                    $tx->typeid     = $typeId;
                    $tx->content    = ['order_id' => $oid];
                    $txs[]          = $tx;
                }
                $r = _res('Send 3 transactions', (new TransactionsService($cfg))->createTransactions($txs, true, false));
            }

        } elseif ($key === 'tx_recent') {
            $typeId = $st['tx_type_id'] ?: $cfgTxTypeId;
            if (!$typeId) {
                $r = _skp('Get recent', 'no tx type ID');
            } else {
                $r = _res('Get recent', (new TransactionsService($cfg))->getRecentTransactions($typeId, 10));
            }

        } elseif ($key === 'tx_get') {
            if (!$cfgTxTypeId || !$cfgTxId) {
                $r = _skp('Get (config ID)', 'test_tx_type_id and test_tx_id required');
            } else {
                $resp = (new TransactionsService($cfg))->getTransaction($cfgTxTypeId, $cfgTxId);
                $r = _res('Get (config ID)', $resp);
                if ($resp && !$resp->isSuccess() && $resp->getStatusCode() === 404) {
                    $r['success'] = true;
                    $r['message'] = 'Not found (archival may not be configured)';
                }
            }

        } elseif ($key === 'tx_delete') {
            if (!$cfgTxTypeId || !$cfgTxId) {
                $r = _skp('Delete (config ID)', 'test_tx_type_id and test_tx_id required');
            } else {
                $resp = (new TransactionsService($cfg))->deleteTransaction($cfgTxTypeId, $cfgTxId);
                $r = _res('Delete (config ID)', $resp);
                if ($resp && !$resp->isSuccess() && $resp->getStatusCode() === 404) {
                    $r['success'] = true;
                    $r['message'] = 'Not found (already deleted or archival not configured)';
                }
            }

        } elseif ($key === 'tx_delete_by_date') {
            $typeId = $st['tx_type_id'] ?: $cfgTxTypeId;
            if (!$typeId) {
                $r = _skp('Delete by date', 'no tx type ID');
            } else {
                $beforeMs = strtotime('1970-01-02') * 1000;
                $r = _res('Delete by date', (new TransactionsService($cfg))->deleteTransactions($typeId, $beforeMs));
            }

        } elseif ($key === 'tx_type_delete') {
            if (!$st['tx_type_id'] && !$st['tx_type_id2']) {
                $r = _skp('Delete created type', 'tx_type_create not yet run');
            } else {
                $svc  = new TransactionsService($cfg);
                $data = [];
                foreach ([$st['tx_type_id'], $st['tx_type_id2']] as $tid) {
                    if ($tid) {
                        $resp   = $svc->deleteTransactionType($tid);
                        $data[] = ['id' => $tid, 'status' => $resp ? $resp->getStatusCode() : 0];
                    }
                }
                $r = ['label' => 'Delete created type', 'success' => true, 'data' => $data, 'status' => 0, 'skipped' => false, 'message' => ''];
            }

        // ── Blacklists ────────────────────────────────────────────────────────
        } elseif ($key === 'bl_list') {
            $r = _res('List', (new BlacklistsService($cfg))->getBlacklists());

        } elseif ($key === 'bl_get') {
            if (!$cfgBlId) {
                $r = _skp('Get (config ID)', 'test_blacklist_id not configured');
            } else {
                $r = _res('Get (config ID)', (new BlacklistsService($cfg))->getBlacklist($cfgBlId));
            }

        } elseif ($key === 'bl_entries') {
            if (!$cfgBlId) {
                $r = _skp('Add entry', 'test_blacklist_id not configured');
            } else {
                $r = _res('Add entry', (new BlacklistsService($cfg))->addEntriesToBlacklist($cfgBlId, ['ui-test@invalid.example']));
            }

        // ── Mailing blacklists ────────────────────────────────────────────────
        } elseif ($key === 'mbl_list') {
            $r = _res('List', (new MailingBlacklistsService($cfg))->getMailingBlacklists());

        } elseif ($key === 'mbl_create') {
            $resp = (new MailingBlacklistsService($cfg))->createMailingBlacklist($st['mbl_name']);
            if ($resp && $resp->isSuccess()) {
                $st['mbl_id'] = (int) $resp->getResult();
            }
            $r = _res('Create', $resp);

        } elseif ($key === 'mbl_get') {
            $id = $st['mbl_id'];
            if (!$id) {
                $r = _skp('Get', 'mbl_create not yet run');
            } else {
                $r = _res('Get', (new MailingBlacklistsService($cfg))->getMailingBlacklist($id));
            }

        } elseif ($key === 'mbl_update') {
            $id = $st['mbl_id'];
            if (!$id) {
                $r = _skp('Update', 'mbl_create not yet run');
            } else {
                $r = _res('Update', (new MailingBlacklistsService($cfg))->updateMailingBlacklist($id, $st['mbl_name'] . '-updated'));
            }

        } elseif ($key === 'mbl_entries') {
            $id = $st['mbl_id'];
            if (!$id) {
                $r = _skp('Add entries', 'mbl_create not yet run');
            } else {
                $expr              = new MailingBlacklistExpressions();
                $expr->expressions = ['@bounce-test.invalid', 'spam@ui-test.invalid'];
                $r = _res('Add entries', (new MailingBlacklistsService($cfg))->addEntriesToBlacklist($id, $expr));
            }

        } elseif ($key === 'mbl_get_entries') {
            $id = $st['mbl_id'];
            if (!$id) {
                $r = _skp('Get entries', 'mbl_create not yet run');
            } else {
                $r = _res('Get entries', (new MailingBlacklistsService($cfg))->getEntriesForBlacklist($id));
            }

        } elseif ($key === 'mbl_delete') {
            $id = $st['mbl_id'];
            if (!$id) {
                $r = _skp('Delete', 'mbl_create not yet run');
            } else {
                $r = _res('Delete', (new MailingBlacklistsService($cfg))->deleteMailingBlacklist($id));
            }

        // ── Account ───────────────────────────────────────────────────────────
        } elseif ($key === 'acc_info') {
            $r = _res('Account info', (new AccountService($cfg))->getAccountInfo());

        } elseif ($key === 'acc_ph_list') {
            $r = _res('List placeholders', (new AccountService($cfg))->getAccountPlaceholders());

        } elseif ($key === 'acc_ph_set') {
            $ph        = new AccountPlaceholder();
            $ph->key   = 'ui_test_placeholder';
            $ph->value = 'hello from UI test';
            $r = _res('Set placeholders', (new AccountService($cfg))->setAccountPlaceholders([$ph]));

        } elseif ($key === 'acc_ph_update') {
            $ph        = new AccountPlaceholder();
            $ph->key   = 'ui_test_placeholder';
            $ph->value = 'updated by UI test';
            $r = _res('Update placeholders', (new AccountService($cfg))->updateAccountPlaceholders([$ph]));

        } elseif ($key === 'acc_ph_delete') {
            $r = _res('Delete placeholder', (new AccountService($cfg))->deleteAccountPlaceholder('ui_test_placeholder'));

        } elseif ($key === 'acc_domains') {
            $r = _res('Mailing domains', (new AccountService($cfg))->getAccountMailingDomains());

        // ── Webhooks ──────────────────────────────────────────────────────────
        } elseif ($key === 'wh_list') {
            $r = _res('List', (new WebhooksService($cfg))->getWebhooks());

        } elseif ($key === 'wh_get') {
            if (!$cfgWebhookId) {
                $r = _skp('Get (config ID)', 'test_webhook_id not configured');
            } else {
                $r = _res('Get (config ID)', (new WebhooksService($cfg))->getWebhook($cfgWebhookId));
            }

        } elseif ($key === 'wh_create') {
            $wh        = new Webhook();
            $wh->url   = $st['webhook_url'] ?: ('https://webhook.site/ui-test-' . substr(md5((string) time()), 0, 8));
            $wh->event = Webhook::$EVENT_UNSUBSCRIPTION;
            $resp = (new WebhooksService($cfg))->createWebhook($wh);
            if ($resp && $resp->isSuccess()) {
                $st['webhook_id'] = (int) $resp->getResult();
            }
            $r = _res('Create', $resp);

        } elseif ($key === 'wh_get_created') {
            if (!$st['webhook_id']) {
                $r = _skp('Get created', 'wh_create not yet run');
            } else {
                $r = _res('Get created', (new WebhooksService($cfg))->getWebhook($st['webhook_id']));
            }

        } elseif ($key === 'wh_update') {
            if (!$st['webhook_id']) {
                $r = _skp('Update', 'wh_create not yet run');
            } else {
                $wh        = new Webhook();
                $wh->url   = 'https://webhook.site/ui-test-updated';
                $wh->event = Webhook::$EVENT_BOUNCE;
                $r = _res('Update', (new WebhooksService($cfg))->updateWebhook($st['webhook_id'], $wh));
            }

        } elseif ($key === 'wh_delete') {
            if (!$st['webhook_id']) {
                $r = _skp('Delete created', 'wh_create not yet run');
            } else {
                $r = _res('Delete created', (new WebhooksService($cfg))->deleteWebhook($st['webhook_id']));
            }

        // ── Data Extensions ───────────────────────────────────────────────────
        } elseif ($key === 'de_list') {
            $r = _res('List extensions', (new DataExtensionsService($cfg))->listDataExtensions(1, 20));

        } elseif ($key === 'de_list_paged') {
            $r = _res('List (page 2)', (new DataExtensionsService($cfg))->listDataExtensions(2, 20));

        } elseif ($key === 'de_get') {
            if (!$cfgDeId) {
                $r = _skp('Get extension', 'test_de_id not configured');
            } else {
                $r = _res('Get extension', (new DataExtensionsService($cfg))->getDataExtension($cfgDeId));
            }

        } elseif ($key === 'de_get_fields') {
            if (!$cfgDeId) {
                $r = _skp('Verify fields', 'test_de_id not configured');
            } else {
                $resp = (new DataExtensionsService($cfg))->getDataExtension($cfgDeId);
                if ($resp && $resp->isSuccess()) {
                    $ext    = $resp->getResult();
                    $fields = array_column((array)($ext->fields ?? []), 'name');
                    $r = ['label' => 'Verify fields', 'success' => !empty($fields), 'data' => $fields, 'status' => $resp->getStatusCode(), 'skipped' => false, 'message' => empty($fields) ? 'No fields found' : ''];
                } else {
                    $r = _res('Verify fields', $resp);
                }
            }

        } elseif ($key === 'de_records') {
            if (!$cfgDeId) {
                $r = _skp('Get records', 'test_de_id not configured');
            } else {
                $r = _res('Get records', (new DataExtensionsService($cfg))->getDataExtensionRecords($cfgDeId, 1, 10, true));
            }

        } elseif ($key === 'de_records_desc') {
            if (!$cfgDeId) {
                $r = _skp('Get records (desc)', 'test_de_id not configured');
            } else {
                $r = _res('Get records (desc)', (new DataExtensionsService($cfg))->getDataExtensionRecords($cfgDeId, 1, 10, false));
            }

        } elseif ($key === 'de_records_filtered') {
            if (!$cfgDeId) {
                $r = _skp('Get records (filtered)', 'test_de_id not configured');
            } else {
                $svc  = new DataExtensionsService($cfg);
                $meta = $svc->getDataExtension($cfgDeId);
                $fields = [];
                if ($meta && $meta->isSuccess() && $meta->getResult()) {
                    $rawFields = (array)($meta->getResult()->fields ?? []);
                    $fields    = array_slice(array_column($rawFields, 'name'), 0, 2);
                }
                $r = _res('Get records (filtered)', $svc->getDataExtensionRecords($cfgDeId, 1, 10, true, $fields));
            }

        } elseif ($key === 'de_sync_upsert') {
            if (!$cfgDeId) {
                $r = _skp('Sync UPSERT', 'test_de_id not configured');
            } else {
                $svc    = new DataExtensionsService($cfg);
                $meta   = $svc->getDataExtension($cfgDeId);
                $record = [];
                if ($meta && $meta->isSuccess() && $meta->getResult()) {
                    $rawFields = (array)($meta->getResult()->fields ?? []);
                    foreach ($rawFields as $f) {
                        $record[(string)$f->name] = 'ui-test';
                        break;
                    }
                }
                if (empty($record)) {
                    $r = _skp('Sync UPSERT', 'extension has no fields');
                } else {
                    $r = _res('Sync UPSERT', $svc->synchronizeRecords($cfgDeId, [$record], 'UPSERT'));
                }
            }

        } elseif ($key === 'de_sync_insert_ign') {
            if (!$cfgDeId) {
                $r = _skp('Sync INSERT_IGNORE', 'test_de_id not configured');
            } else {
                $svc    = new DataExtensionsService($cfg);
                $meta   = $svc->getDataExtension($cfgDeId);
                $record = [];
                if ($meta && $meta->isSuccess() && $meta->getResult()) {
                    $rawFields = (array)($meta->getResult()->fields ?? []);
                    foreach ($rawFields as $f) {
                        $record[(string)$f->name] = 'ui-test-ign';
                        break;
                    }
                }
                if (empty($record)) {
                    $r = _skp('Sync INSERT_IGNORE', 'extension has no fields');
                } else {
                    $r = _res('Sync INSERT_IGNORE', $svc->synchronizeRecords($cfgDeId, [$record], 'INSERT_IGNORE_DUPLICATES'));
                }
            }

        } elseif ($key === 'de_sync_empty') {
            if (!$cfgDeId) {
                $r = _skp('Sync empty (guard)', 'test_de_id not configured');
            } else {
                $resp    = (new DataExtensionsService($cfg))->synchronizeRecords($cfgDeId, [], 'UPSERT');
                $isNull  = $resp === null;
                $r = ['label' => 'Sync empty (guard)', 'success' => $isNull, 'data' => ['response_is_null' => $isNull], 'status' => 0, 'skipped' => false, 'message' => $isNull ? '' : 'Expected null response for empty records'];
            }

        } else {
            $r = _skp($key, 'Unknown test key');
        }

        $results[] = $r;

    } catch (\Throwable $e) {
        $results[] = [
            'label'   => $key,
            'success' => false,
            'data'    => ['exception' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()],
            'status'  => 0,
            'skipped' => false,
            'message' => $e->getMessage(),
        ];
    }
    $debugRaw = ob_get_clean() ?: '';
    $elapsed  = (int) round((microtime(true) - $t0) * 1000);
    $parsed   = _parse_debug_log($debugRaw);
    $last     = count($results) - 1;
    if ($last >= 0) {
        $results[$last]['elapsed_ms'] = $elapsed;
        $results[$last]['req_method'] = $parsed['method'];
        $results[$last]['req_url']    = $parsed['url'];
        if ($showDebugTab && $debugRaw !== '') {
            $results[$last]['debug_log'] = _clean_debug_log($debugRaw);
        }
    }
}

echo json_encode(['results' => $results, 'show_debug_tab' => $showDebugTab], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
