<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Infrastructure\SafeData;
use Bachs\Whmcs\Domain\Money;

class AdminView
{
    private $app;
    private $link;
    private $token;
    private $scope;
    private $allowed = array();
    private static $pages = array('overview' => 'Overview', 'settings' => 'Connection & settings', 'payments' => 'Payments', 'refunds' => 'Refunds',
        'saved' => 'Saved methods', 'webhooks' => 'Webhooks', 'reconciliation' => 'Reconciliation', 'diagnostics' => 'Diagnostics', 'audit' => 'Audit');

    public function __construct($app, $link)
    {
        $this->app = $app;
        $this->link = $link;
        $this->token = $app->bridge->identity->token();
        try {
            $this->scope = $app->settings->activeScope();
        } catch (\Exception $e) {
            $this->scope = null;
        }
    }

    private function h($v)
    {
        return SafeData::html($v);
    }

    private function can($cap)
    {
        if (!isset($this->allowed[$cap])) {
            try {
                $this->app->bridge->identity->admin($cap);
                $this->allowed[$cap] = true;
            } catch (\Exception $e) {
                $this->allowed[$cap] = false;
            }
        }
        return $this->allowed[$cap];
    }

    public function render()
    {
        $page = Input::text($_GET, 'page', 'overview', 30);
        if (!isset(self::$pages[$page])) {
            $page = 'overview';
        }
        $required = $page === 'settings' ? 'manage_settings' : ($page === 'diagnostics' ? 'view_diagnostics' : 'view_payments');
        $this->app->bridge->identity->admin($required);
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
        }
        $result = null;
        $error = null;
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $result = (new AdminActions($this->app))->run($_POST);
            } catch (\Exception $e) {
                $error = Messages::error($e instanceof \Bachs\Whmcs\Domain\Failure ? $e->reason : 'internal_error');
            }
        }
        $assets = $this->app->bridge->systemUrl() . '/modules/gateways/bachs/assets';
        $s = '<link rel="stylesheet" href="' . $this->h($assets . '/bachs.css') . '"><div class="bachs-admin">';
        $s .= '<header class="bachs-app-header"><div><span class="bachs-wordmark">bachs<span aria-hidden="true">↗</span></span><span class="bachs-header-label">Payments for WHMCS</span></div><div class="bachs-header-meta"><span class="bachs-badge">' . $this->h(strtoupper($this->app->settings->get('environment', 'sandbox'))) . '</span><span class="bachs-connection">' . ($this->scope ? '● Merchant connected' : '○ Setup required') . '</span></div></header>';
        $s .= '<div class="bachs-app-layout"><nav class="bachs-nav" aria-label="Bachs administration">';
        foreach (self::$pages as $key => $label) {
            if ($key === 'settings' && !$this->can('manage_settings')) {
                continue;
            }
            $s .= '<a href="' . $this->h($this->link . '&page=' . $key) . '"' . ($key === $page ? ' aria-current="page"' : '') . '>' . $this->h($label) . '</a>';
        }
        $s .= '<div class="bachs-nav-foot">Independent integration<br>Version 1.0.0</div></nav><main class="bachs-main"><div class="bachs-page-heading"><div><p class="bachs-eyebrow">Payment operations</p><h1>' . $this->h(self::$pages[$page]) . '</h1></div><span class="bachs-muted">' . ($this->scope ? $this->h($this->scope['data']['name']) : 'Connect your merchant account') . '</span></div>';
        if ($this->app->settings->get('environment', 'sandbox') === 'sandbox') {
            $s .= '<div class="bachs-notice">Sandbox environment. Use this only on a separate WHMCS test installation.</div>';
        }
        if ($error) {
            $s .= '<div class="bachs-notice bachs-error" role="alert">' . $this->h($error) . '</div>';
        }
        if ($result) {
            $s .= $this->result($result);
        }
        if ($page === 'overview') {
            $s .= $this->overview();
        } elseif ($page === 'settings') {
            $s .= $this->settings();
        } elseif ($page === 'webhooks') {
            $s .= $this->webhooks();
        } elseif ($page === 'diagnostics') {
            $s .= $this->diagnostics();
        } else {
            $tables = array('payments' => 'payments', 'refunds' => 'refunds', 'saved' => 'payment_methods', 'reconciliation' => 'reconciliation_items', 'audit' => 'audit_log');
            $s .= $this->table($tables[$page]);
        }
        $s .= '</main></div></div><script src="' . $this->h($assets . '/admin.js') . '" defer></script>';
        return $s;
    }

    private function result($result)
    {
        $s = '<div class="bachs-notice" role="status">' . $this->h($result['message']) . '</div>';
        if (isset($result['confirmation'])) {
            $p = $result['confirmation'];
            $s .= '<section class="bachs-panel bachs-review"><h2>Confirm this action</h2><p>These details are fixed to your account and expire at ' . $this->h(gmdate('Y-m-d H:i:s', $p['expires_at'])) . ' UTC.</p>';
            $s .= $this->details(array('request' => $p['preview'], 'context' => $p['context']));
            $s .= $this->form($p['confirm_action'], array('confirmation' => $p['confirmation']), '<p>Confirming submits this exact request to Bachs.</p>', 'Confirm and submit') . '</section>';
        }
        if (isset($result['data'])) {
            $s .= '<section class="bachs-panel"><h2>Provider result</h2>' . $this->details($result['data']) . '</section>';
        }
        if (isset($result['download'])) {
            $json = json_encode($result['download'], JSON_PRETTY_PRINT);
            $s .= '<section class="bachs-panel"><h2>Redacted diagnostic export</h2><textarea class="bachs-export" readonly aria-label="Diagnostic JSON">' . $this->h($json) . '</textarea><button type="button" class="bachs-secondary" data-bachs-download>Download JSON</button></section>';
        }
        return $s;
    }

    private function overview()
    {
        $a = $this->app;
        $s = '<div class="bachs-stat-grid">';
        foreach (
            array('Recorded payments' => $a->store->count('payments', 'posted'), 'Needs review' => $a->store->count('reconciliation_items', 'review'),
            'Refund accounting pending' => $a->store->count('refunds', 'accounting_pending')) as $label => $count
        ) {
            $s .= '<article class="bachs-stat"><span>' . $this->h($label) . '</span><strong>' . $count . '</strong><small>All retained records</small></article>';
        }
        $s .= '</div><section class="bachs-panel"><div class="bachs-panel-heading"><h2>Confirmation pipeline</h2><span>Each stage has its own evidence</span></div><div class="bachs-pipeline">';
        $signed = $a->store->count('webhook_events');
        $processed = $a->store->count('webhook_events', 'processed');
        $posted = $a->store->count('payments', 'posted');
        foreach (array('Signed deliveries' => $signed, 'Events processed' => $processed, 'Native payments recorded' => $posted) as $label => $count) {
            $s .= '<div><strong>' . $count . '</strong><span>' . $this->h($label) . '</span></div>';
        }
        $s .= '</div></section><div class="bachs-two-col"><section class="bachs-panel"><h2>Worker health</h2><p>' . $this->workerHealth() . '</p><p class="bachs-muted">Pending payments continue to reconcile when the customer closes their browser.</p></section><section class="bachs-panel"><h2>Checkout availability</h2><p>' . ($a->settings->get('draining', false) ? 'Draining existing payments' : ($a->settings->get('enabled', false) ? 'Enabled' : 'Disabled')) . '</p><p class="bachs-muted">' . ($this->scope ? 'Last connection check: ' . $this->h(gmdate('Y-m-d H:i', $this->scope['data']['verified_at'])) . ' UTC' : 'Complete connection setup to begin.') . '</p></section></div>';
        $totals = array();
        $rows = $a->store->run("SELECT data FROM mod_bachs_allocations WHERE state='posted' ORDER BY id");
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            $d = json_decode($row['data'], true);
            $currency = $d['currency'];
            $totals[$currency] = Money::add(isset($totals[$currency]) ? $totals[$currency] : '0', $d['amount']);
        }
        $s .= '<section class="bachs-panel"><h2>Invoice allocations by currency</h2><p class="bachs-muted">All retained allocations. Refunds are reported separately.</p>';
        if (!$totals) {
            $s .= '<p>No invoice payments have been allocated yet.</p>';
        }
        foreach ($totals as $currency => $amount) {
            $s .= '<p class="bachs-currency-total"><span>' . $this->h($currency) . '</span><strong>' . $this->h($amount) . '</strong></p>';
        }
        return $s . '</section>' . $this->table('payments', 5, false);
    }

    private function settings()
    {
        $a = $this->app;
        $env = $a->settings->get('environment', 'sandbox');
        $s = '<section class="bachs-panel"><h2>Connect your Bachs account</h2><p>Use your own secret key for the selected environment. Blank keeps the saved key.</p>';
        $s .= $this->form('connect', array(), $this->select('environment', 'Environment', array('sandbox' => 'Sandbox', 'live' => 'Live'), $env) . $this->input('api_key', 'Secret API key', 'password'), 'Verify connection') . '</section>';
        $fields = $this->select('environment', 'Payment environment', array('sandbox' => 'Sandbox', 'live' => 'Live'), $env);
        $fields .= $this->select('checkout_mode', 'Checkout style', array('redirect' => 'Hosted checkout', 'overlay' => 'Overlay with redirect fallback'), $a->settings->get('checkout_mode', 'redirect'));
        $fields .= $this->input('button_label', 'Payment button text', 'text', $a->settings->get('button_label', 'Pay with Bachs'));
        $fields .= $this->check('test_installation', 'This is a separate WHMCS test installation (required for sandbox payments)', $a->settings->get('test_installation', false));
        $fields .= $this->check('saved_methods', 'Let clients save cards with their consent', $a->settings->get('saved_methods', false));
        $fields .= $this->check('automatic_payments', 'Allow automatic card payments for WHMCS renewal invoices', $a->settings->get('automatic_payments', false));
        $fields .= '<p class="bachs-muted">Automatic card payments also need the bachs_card gateway, WHMCS 7.9 or later, and client consent. Test this flow before enabling it.</p>';
        $fields .= $this->check('enabled', 'Enable new payments', $a->settings->get('enabled', false));
        $fields .= $this->check('draining', 'Maintenance: finish pending payments and stop new payments', $a->settings->get('draining', false));
        $fields .= '<input type="hidden" name="currencies_present" value="1"><fieldset><legend>Invoice currencies</legend><p>Enable the currencies you accept. Add the same currency codes in WHMCS currency settings.</p><div class="bachs-choice-grid">';
        foreach (\Bachs\Whmcs\Application\PaymentOptions::currencies() as $currency => $name) {
            $fields .= $this->check('currencies[]', $currency . ' — ' . $name, in_array($currency, $a->settings->get('currencies', array_keys(\Bachs\Whmcs\Application\PaymentOptions::currencies())), true), $currency);
        }
        $selected = $a->settings->get('methods', array());
        $fields .= '</div></fieldset><fieldset><legend>Payment methods</legend>';
        $fields .= $this->select('methods_mode', 'Offer', array('all' => 'All available Bachs methods', 'selected' => 'Only the methods selected below'), $a->settings->get('methods_mode', $selected ? 'selected' : 'all'));
        $fields .= '<div class="bachs-choice-grid">';
        foreach (\Bachs\Whmcs\Application\PaymentOptions::methods() as $value => $label) {
            $fields .= $this->check('methods[]', $label, in_array($value, $selected ? $selected : array_keys(\Bachs\Whmcs\Application\PaymentOptions::methods()), true), $value);
        }
        $fields .= '</div><p>Availability depends on your Bachs account, invoice currency and payment minimums. Card wallets such as Apple Pay and Google Pay appear where Bachs supports them. Crypto covers USDT on Tron, BNB Chain, Ethereum and Solana; USDC on BNB Chain; ETH on Ethereum; SOL on Solana; and BNB on BNB Chain. Select individual crypto assets and networks in your Bachs checkout settings.</p></fieldset>';
        $fields .= $this->check('adaptive_allocation', 'Let clients pay USD invoices in another supported currency, including crypto', $a->settings->get('adaptive_allocation', true));
        $fields .= '<p class="bachs-muted">USD invoices keep their USD amount when Bachs converts the payment. Other invoice currencies collect in their own currency. Conversion also needs adaptive pricing enabled in Bachs. Saving a card uses the invoice currency and supports USD or NGN.</p>';
        $fields .= '<details><summary>Advanced payment settings</summary>';
        $fields .= $this->input('expiry_minutes', 'Checkout expiry in minutes', 'number', $a->settings->get('expiry_minutes', 60));
        $fields .= $this->input('sensitive_retention_days', 'Keep completed checkout links for this many days', 'number', $a->settings->get('sensitive_retention_days', 30)) . '</details>';
        $s .= '<section class="bachs-panel"><h2>Payment settings</h2><p>Save the webhook signing secret and run the worker before enabling payments. <a href="' . $this->h($this->link . '&page=webhooks') . '">Webhook settings</a></p>' . $this->form('settings', array(), $fields, 'Save payment settings') . '</section>';
        return $s;
    }

    private function webhooks()
    {
        $a = $this->app;
        $s = '<section class="bachs-panel"><h2>Callback destinations</h2>';
        foreach (array('sandbox','live') as $env) {
            $s .= '<label class="bachs-field"><span>' . ucfirst($env) . ' URL</span><input readonly value="' . $this->h($a->bridge->systemUrl() . '/modules/gateways/callback/bachs.php?environment=' . $env) . '"></label>';
        }
        $s .= '<p class="bachs-muted">Register the destination in the Bachs Developer Portal. Signing secrets authenticate delivery.</p><details><summary>Required events</summary><ul>';
        foreach (\Bachs\Whmcs\Application\Webhooks::$events as $event) {
            $s .= '<li><code>' . $this->h($event) . '</code></li>';
        } $s .= '</ul></details></section>';
        if ($this->scope && $this->can('manage_webhooks')) {
            $saved = $a->settings->secret('signing_' . $this->scope['id']) !== '';
            $s .= '<section class="bachs-panel"><h2>Webhook signing secret</h2><p>' . ($saved ? 'A signing secret is saved and encrypted.' : 'No signing secret has been saved.') . '</p>' . $this->form('signing', array(), $this->input('signing_secret', 'Signing secret', 'password', '', 'Blank keeps the saved value. A replacement keeps the previous value for up to 24 hours.'), 'Save signing secret') . '</section>';
        }
        return $s . $this->table('webhook_events') . $this->table('jobs', 10, false);
    }

    private function diagnostics()
    {
        $a = $this->app;
        $s = '<section class="bachs-panel"><h2>Installation diagnostics</h2><dl class="bachs-definition">';
        $values = array('Plugin' => '1.0.0', 'PHP' => PHP_VERSION, 'WHMCS' => $a->bridge->version, 'Worker' => strip_tags($this->workerHealth()),
            'Encryption' => getenv('BACHS_ENCRYPTION_KEY') ? 'Dedicated authenticated encryption key' : 'WHMCS EncryptPassword / DecryptPassword');
        foreach (array('curl','openssl','json','pdo_mysql') as $ext) {
            $values[$ext] = extension_loaded($ext) ? 'Available' : 'Missing';
        }
        foreach ($values as $k => $v) {
            $s .= '<div><dt>' . $this->h($k) . '</dt><dd>' . $this->h($v) . '</dd></div>';
        }
        $s .= '</dl></section>';
        if ($this->can('export_diagnostics')) {
            $s .= '<section class="bachs-panel"><h2>Export diagnostics</h2><p>Financial identities and status only. Keys, tokens, signed URLs, payer details and raw payloads are excluded.</p>' . $this->form('export', array(), '', 'Prepare redacted export') . '</section>';
        }
        return $s . $this->table('operations');
    }

    private function table($table, $limit = 25, $paginate = true)
    {
        $a = $this->app;
        $page = max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1);
        $filters = array();
        $state = Input::text($_GET, 'state', '', 40);
        if ($state !== '') {
            $filters['state'] = $state;
        }
        if (isset($_GET['invoice']) && (int)$_GET['invoice'] > 0) {
            $filters['invoice_id'] = (int)$_GET['invoice'];
        }
        $rows = $a->store->listing($table, $filters, $limit, $paginate ? ($page - 1) * $limit : 0);
        $title = ucwords(str_replace('_', ' ', $table));
        $s = '<section class="bachs-panel bachs-table-panel"><div class="bachs-panel-heading"><h2>' . $this->h($title) . '</h2><span>Newest first · UTC</span></div>';
        if ($paginate) {
            $s .= '<form class="bachs-filter" method="get"><input type="hidden" name="module" value="bachs_admin"><input type="hidden" name="page" value="' . $this->h(Input::text($_GET, 'page', 'overview')) . '"><label>State <input name="state" value="' . $this->h($state) . '" placeholder="All states"></label><label>Invoice <input name="invoice" inputmode="numeric" value="' . (isset($_GET['invoice']) ? (int)$_GET['invoice'] : '') . '" placeholder="All invoices"></label><button class="bachs-secondary" type="submit">Filter</button></form>';
        }
        if (!$rows) {
            return $s . '<div class="bachs-empty"><strong>No ' . $this->h(strtolower($title)) . ' to display</strong><p>New records will appear here after the related payment activity or a provider refresh.</p></div></section>';
        }
        $s .= '<div class="bachs-table-scroll"><table><thead><tr><th scope="col">Record / invoice</th><th scope="col">Amount / purpose</th><th scope="col">State</th><th scope="col">Received</th><th scope="col">Details & actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $d = $row['data'];
            $name = isset($d['event_id']) ? $d['event_id'] : (isset($d['payment_id']) ? $d['payment_id'] : (isset($d['refund_id']) ? $d['refund_id'] : $row['id']));
            $s .= '<tr><td><code class="bachs-id">' . $this->h($name) . '</code>';
            if ($row['invoice_id']) {
                $s .= '<a href="invoices.php?action=edit&id=' . (int)$row['invoice_id'] . '">Invoice #' . (int)$row['invoice_id'] . '</a>';
            }
            if ($row['client_id']) {
                $s .= '<a href="clientssummary.php?userid=' . (int)$row['client_id'] . '">Client #' . (int)$row['client_id'] . '</a>';
            }
            $s .= '</td><td>' . $this->h(isset($d['amount']) ? (isset($d['currency']) ? $d['currency'] . ' ' : '') . $d['amount'] : (isset($d['type']) ? $d['type'] : (isset($d['action']) ? $d['action'] : (isset($d['brand']) ? $d['brand'] . ' •••• ' . $d['last4'] : '—')))) . '</td>';
            $s .= '<td><span class="bachs-state bachs-state-' . $this->h(in_array($row['state'], array('posted','completed','processed','active','review','outcome_unknown','failed','dead'), true) ? $row['state'] : 'neutral') . '">' . $this->h(str_replace('_', ' ', $row['state'])) . '</span>';
            if ($table === 'refunds') {
                $s .= '<small>Provider: ' . $this->h(isset($d['provider_state']) ? $d['provider_state'] : 'unknown') . '<br>Accounting: ' . $this->h(isset($d['accounting_state']) ? $d['accounting_state'] : 'pending') . '</small>';
            }
            $s .= '</td><td class="bachs-nowrap">' . $this->h(gmdate('d M Y', $row['created_at'])) . '<small>' . $this->h(gmdate('H:i:s', $row['created_at'])) . '</small></td><td><details><summary>Inspect record</summary><div class="bachs-record-details">' . $this->details(array_merge(array('local_id' => $row['id'], 'scope' => $row['scope']), $d));
            if ($table === 'payments') {
                $attempt = isset($d['attempt_id']) ? $d['attempt_id'] : '';
                $s .= $this->form('recheck', array('target' => $attempt, 'scope_id' => $row['scope']), '', 'Recheck payment');
                if ($row['state'] === 'posted' && $this->can('request_refunds')) {
                    $s .= '<h3>Request a refund</h3><p>Only one nonfailed refund is allowed, including a partial refund.</p>' . $this->form('refund_preview', array('target' => $row['id'], 'scope_id' => $row['scope']), $this->input('amount', 'Invoice currency amount', 'text', $d['amount']) . $this->input('reason', 'Reason') . $this->input('refund_address', 'Verified crypto address (crypto only)') . $this->input('network', 'Verified asset / network currency (crypto only)'), 'Review refund');
                }
            } elseif ($table === 'webhook_events' && $this->can('manage_webhooks')) {
                $s .= $this->form('replay', array('target' => $row['id'], 'scope_id' => $row['scope']), '', 'Replay verified event');
            } elseif ($table === 'refunds' && $this->can('finalize_refund_accounting')) {
                if ($row['state'] === 'accounting_pending' && isset($d['refund_id'], $d['amount'])) {
                    $s .= '<p>In the native invoice, use <strong>Manual Refund Processed Externally / Record Only</strong>. Use the displayed amount and link to the original payment. Transaction ID:</p><code class="bachs-id">' . $this->h(\Bachs\Whmcs\Domain\Ids::transaction($row['scope'], $d['refund_id'], 'refund')) . '</code>';
                }
                if (!empty($d['external']) && !isset($d['amount'])) {
                    $s .= '<p>External refund value requires a verified invoice-currency mapping. Review the provider amount before recording any native refund.</p>' . $this->form('refund_map', array('target' => $row['id'], 'scope_id' => $row['scope']), $this->input('amount', 'Verified invoice-currency refund amount') . $this->input('evidence', 'Mapping evidence (minimum 20 characters)'), 'Save reviewed accounting mapping');
                }
                $s .= $this->form('refund_recheck', array('target' => $row['id'], 'scope_id' => $row['scope']), '', 'Recheck provider & accounting');
            } elseif ($table === 'payment_methods' && $this->can('manage_settings') && $row['state'] === 'active') {
                $s .= $this->form('revoke', array('target' => $row['id'], 'scope_id' => $row['scope']), '', 'Disable local automatic payments');
            } elseif ($table === 'reconciliation_items' && $row['state'] !== 'resolved') {
                $s .= $this->form('resolve', array('target' => $row['id'], 'scope_id' => $row['scope']), $this->input('note', 'Resolution evidence (minimum 10 characters)'), 'Save reviewed resolution');
            }
            $s .= '</div></details></td></tr>';
        }
        $s .= '</tbody></table></div>';
        if ($paginate) {
            $base = $this->link . '&page=' . rawurlencode(Input::text($_GET, 'page', 'overview')) . '&state=' . rawurlencode($state);
            $s .= '<nav class="bachs-pagination" aria-label="Records">';
            if ($page > 1) {
                $s .= '<a href="' . $this->h($base . '&p=' . ($page - 1)) . '">← Previous</a>';
            } $s .= '<span>Page ' . $page . '</span>';
            if (count($rows) === $limit) {
                $s .= '<a href="' . $this->h($base . '&p=' . ($page + 1)) . '">Next →</a>';
            } $s .= '</nav>';
        }
        return $s . '</section>';
    }

    private function details($data)
    {
        return '<pre class="bachs-json">' . $this->h(json_encode(self::display($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    }

    public static function display($data, $depth = 0)
    {
        if ($depth > 10) {
            return '[depth limit]';
        }
        if (!is_array($data)) {
            return is_string($data) ? substr($data, 0, 20000) : $data;
        }
        $out = array();
        foreach ($data as $k => $v) {
            if (preg_match('/token|secret|password|authorization|signing|checkout_url|return_state|state_hash|api_key|payload|request$|response$|resource$|reason$/i', (string)$k) && !in_array($k, array('request','resource'), true)) {
                $out[$k] = '[protected]';
                continue;
            }
            // Encrypted containers are never decrypted by generic record inspection.
            if (is_string($v) && preg_match('/^(v1:|whmcs:)|sk_(live|sandbox)_|whsec_|\bpm_[A-Za-z0-9_-]+|https:\/\/(checkout|portal|connect)\.bachs\.io/i', $v)) {
                $out[$k] = '[protected]';
                continue;
            }
            $out[$k] = is_array($v) ? self::display($v, $depth + 1) : (is_string($v) ? substr($v, 0, 20000) : $v);
        }
        return $out;
    }

    private function workerHealth()
    {
        $last = $this->app->settings->get('last_worker', 0);
        $fresh = $last && $this->app->store->clock->now() - $last < 900;
        return '<span class="bachs-state ' . ($fresh ? 'bachs-state-active' : 'bachs-state-review') . '">' . ($fresh ? 'Worker recently active' : 'Worker needs attention') . '</span>' . ($last ? ' <small>' . $this->h(gmdate('Y-m-d H:i:s', $last)) . ' UTC</small>' : ' <small>Run the CLI worker or configure WHMCS cron.</small>');
    }

    private function form($action, $hidden, $fields, $label)
    {
        if (isset(AdminActions::$permissions[$action]) && !$this->can(AdminActions::$permissions[$action])) {
            return '';
        }
        $s = '<form class="bachs-form" action="' . $this->h($this->link . '&page=' . Input::text($_GET, 'page', 'overview')) . '" method="post">';
        $values = array_merge(array('action' => $action, 'token' => $this->token, 'scope_id' => $this->scope ? $this->scope['id'] : ''), $hidden);
        foreach ($values as $k => $v) {
            $s .= '<input type="hidden" name="' . $this->h($k) . '" value="' . $this->h($v) . '">';
        }
        return $s . $fields . '<button class="bachs-button" type="submit">' . $this->h($label) . '<span aria-hidden="true">→</span></button><span class="bachs-form-status" role="status"></span></form>';
    }

    private function input($name, $label, $type = 'text', $value = '', $help = '')
    {
        return '<label class="bachs-field"><span>' . $this->h($label) . '</span><input name="' . $this->h($name) . '" type="' . $this->h($type) . '" value="' . $this->h($value) . '"' . ($type === 'password' ? ' autocomplete="new-password" spellcheck="false"' : '') . '>' . ($help ? '<small>' . $this->h($help) . '</small>' : '') . '</label>';
    }

    private function select($name, $label, $options, $selected = null)
    {
        $s = '<label class="bachs-field"><span>' . $this->h($label) . '</span><select name="' . $this->h($name) . '">';
        foreach ($options as $key => $value) {
            $s .= '<option value="' . $this->h($key) . '"' . ($selected === $key ? ' selected' : '') . '>' . $this->h($value) . '</option>';
        }
        return $s . '</select></label>';
    }

    private function check($name, $label, $checked, $value = '1')
    {
        return '<label class="bachs-check"><input type="checkbox" name="' . $this->h($name) . '" value="' . $this->h($value) . '"' . ($checked ? ' checked' : '') . '><span>' . $this->h($label) . '</span></label>';
    }
}
