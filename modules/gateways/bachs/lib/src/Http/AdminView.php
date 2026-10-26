<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Infrastructure\SafeData;

class AdminView
{
    private $app;
    private $link;
    private $token;
    private $scope;
    private $allowed = array();
    private static $pages = array('overview' => 'Status', 'payments' => 'Payments', 'refunds' => 'Refunds', 'saved' => 'Saved cards',
        'webhooks' => 'Webhooks', 'jobs' => 'Background jobs', 'reconciliation' => 'Review', 'diagnostics' => 'Diagnostics', 'audit' => 'Audit');

    public function __construct($app, $link)
    {
        $this->app = $app;
        $this->link = $link;
        $this->token = $app->bridge->identity->token();
        $this->refreshScope();
    }

    private function refreshScope()
    {
        try {
            $this->scope = $this->app->settings->activeScope();
        } catch (\Bachs\Whmcs\Domain\Failure $e) {
            if ($e->reason !== 'merchant_not_connected') {
                throw $e;
            }
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
            } catch (\Throwable $e) {
                $this->allowed[$cap] = false;
            }
        }
        return $this->allowed[$cap];
    }

    public function render()
    {
        $page = Input::text($_GET, 'bachs_page', 'overview', 30);
        if (!isset(self::$pages[$page])) {
            $page = 'overview';
        }
        $this->app->bridge->identity->admin('view_payments');
        if ($page === 'diagnostics') {
            $this->app->bridge->identity->admin('view_diagnostics');
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
        }
        $result = null;
        $error = null;
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bachs_action'])) {
            try {
                $input = $_POST;
                $input['action'] = Input::text($_POST, 'bachs_action', '', 60);
                $result = (new AdminActions($this->app))->run($input);
            } catch (\Throwable $e) {
                if ($e instanceof \Bachs\Whmcs\Domain\Failure) {
                    $error = Messages::error($e->reason);
                } else {
                    $report = \Bachs\Whmcs\Infrastructure\Diagnostics::report($e, 'admin_action');
                    $error = \Bachs\Whmcs\Infrastructure\Diagnostics::message($report);
                }
            }
            $this->refreshScope();
        }
        $s = '<div id="bachs-payment-tools"><p>Configure all payment settings under <a href="configgateways.php">Payment Gateways &gt; Bachs</a>.</p><ul class="nav nav-tabs">';
        foreach (self::$pages as $key => $label) {
            $s .= '<li' . ($key === $page ? ' class="active"' : '') . '><a href="' . $this->h($this->url(array('bachs_page' => $key))) . '">' . $this->h($label) . '</a></li>';
        }
        $s .= '</ul><div class="tab-content">';
        if ($error) {
            $s .= '<div class="alert alert-danger" role="alert">' . $this->h($error) . '</div>';
        }
        if ($result) {
            $s .= $this->result($result);
        }
        if ($page === 'overview') {
            $s .= $this->overview();
        } elseif ($page === 'webhooks') {
            $s .= $this->webhooks();
        } elseif ($page === 'diagnostics') {
            $s .= $this->diagnostics();
        } else {
            $tables = array('payments' => 'payments', 'refunds' => 'refunds', 'saved' => 'payment_methods', 'jobs' => 'jobs', 'reconciliation' => 'reconciliation_items', 'audit' => 'audit_log');
            $s .= $this->table($tables[$page]);
        }
        $s .= '</div></div>';
        return $s;
    }

    private function result($result)
    {
        $s = '<div class="alert alert-info" role="status">' . $this->h($result['message']) . '</div>';
        if (isset($result['confirmation'])) {
            $p = $result['confirmation'];
            $s .= '<section><h2>Confirm this action</h2><p>These details are fixed to your account and expire at ' . $this->h(gmdate('Y-m-d H:i:s', $p['expires_at'])) . ' UTC.</p>';
            $s .= $this->details(array('request' => $p['preview'], 'context' => $p['context']));
            $s .= $this->form($p['confirm_action'], array('confirmation' => $p['confirmation']), '<p>Confirming submits this exact request to Bachs.</p>', 'Confirm and submit') . '</section>';
        }
        if (isset($result['data'])) {
            $s .= '<section><h2>Provider result</h2>' . $this->details($result['data']) . '</section>';
        }
        if (isset($result['download'])) {
            $json = json_encode($result['download'], JSON_PRETTY_PRINT);
            $s .= '<section><h2>Redacted diagnostic export</h2><textarea class="form-control" readonly aria-label="Diagnostic JSON">' . $this->h($json) . '</textarea><button type="button" class="btn btn-default" data-bachs-download>Download JSON</button></section>';
        }
        return $s;
    }

    private function overview()
    {
        $a = $this->app;
        $error = $a->settings->get('configuration_error');
        $s = $error ? '<div class="alert alert-warning">' . $this->h(Messages::error($error)) . '</div>' : '';
        $reference = $a->settings->get('configuration_error_reference', '');
        if ($error && is_string($reference) && preg_match('/^[a-f0-9]{12}$/D', $reference)) {
            $s .= '<p>WHMCS Activity Log reference: <code>' . $this->h($reference) . '</code></p>';
        }
        $s .= '<dl><dt>Environment</dt><dd>' . $this->h($a->settings->get('environment', 'sandbox')) . '</dd>';
        $s .= '<dt>Merchant</dt><dd>' . ($this->scope ? $this->h($this->scope['data']['name']) : 'Not connected') . '</dd>';
        $s .= '<dt>New payments</dt><dd>' . ($a->settings->get('draining', false) ? 'Maintenance mode' : ($a->settings->get('enabled', false) ? 'Enabled' : 'Disabled')) . '</dd>';
        $s .= '<dt>Background processing</dt><dd>' . $this->workerHealth() . '</dd></dl>';
        $s .= $this->form('verify', array(), '<p>Save any changes in Payment Gateways before verifying the connection.</p>', 'Verify saved gateway settings');
        $s .= $this->form('run_checks', array(), '<p>Run a small batch of pending payment and refund checks now. The WHMCS system cron should run these automatically every five minutes.</p>', 'Run payment checks');
        $s .= '<p>Pending payments continue to reconcile when the client leaves. Request refunds from the Payments tab; record a native Record Only refund after Bachs confirms success.</p>';
        return $s;
    }

    private function webhooks()
    {
        $a = $this->app;
        $s = '<section><h2>Callback destinations</h2>';
        foreach (array('sandbox','live') as $env) {
            $s .= '<label class="form-group"><span>' . ucfirst($env) . ' URL</span><input class="form-control" readonly value="' . $this->h($a->bridge->systemUrl() . '/modules/gateways/callback/bachs.php?environment=' . $env) . '"></label>';
        }
        $s .= '<p class="text-muted">Register the destination in the Bachs Developer Portal. Signing secrets authenticate delivery.</p><details><summary>Required events</summary><ul>';
        foreach (\Bachs\Whmcs\Application\Webhooks::$events as $event) {
            $s .= '<li><code>' . $this->h($event) . '</code></li>';
        } $s .= '</ul></details></section>';
        if ($this->scope) {
            $saved = $a->settings->secret('signing_' . $this->scope['id']) !== '';
            $s .= '<p>' . ($saved ? 'A signing secret is saved.' : 'Set the webhook signing secret in Payment Gateways > Bachs.') . '</p>';

        }
        return $s . $this->table('webhook_events');
    }

    private function diagnostics()
    {
        $a = $this->app;
        $s = '<section><h2>Installation diagnostics</h2><dl class="dl-horizontal">';
        $values = array('Plugin' => '1.0.0', 'PHP' => PHP_VERSION, 'WHMCS' => $a->bridge->version, 'Worker' => strip_tags($this->workerHealth()),
            'Encryption' => 'WHMCS EncryptPassword / DecryptPassword');
        foreach (array('curl','openssl','json','pdo_mysql') as $ext) {
            $values[$ext] = extension_loaded($ext) ? 'Available' : 'Missing';
        }
        foreach ($values as $k => $v) {
            $s .= '<div><dt>' . $this->h($k) . '</dt><dd>' . $this->h($v) . '</dd></div>';
        }
        $s .= '</dl></section>';
        if ($this->can('export_diagnostics')) {
            $s .= '<section><h2>Export diagnostics</h2><p>Financial identities and status only. Keys, tokens, signed URLs, payer details and raw payloads are excluded.</p>' . $this->form('export', array(), '', 'Prepare redacted export') . '</section>';
        }
        return $s . $this->table('operations');
    }

    private function table($table, $limit = 25, $paginate = true)
    {
        $a = $this->app;
        $page = max(1, isset($_GET['bachs_p']) ? Input::number($_GET, 'bachs_p') : 1);
        $filters = array();
        $state = Input::text($_GET, 'bachs_state', '', 40);
        if ($state !== '') {
            $filters['state'] = $state;
        }
        $invoiceId = isset($_GET['bachs_invoice']) && $_GET['bachs_invoice'] !== '' ? Input::number($_GET, 'bachs_invoice') : 0;
        if ($invoiceId > 0) {
            $filters['invoice_id'] = $invoiceId;
        }
        $rows = $a->store->listing($table, $filters, $limit, $paginate ? ($page - 1) * $limit : 0);
        $title = ucwords(str_replace('_', ' ', $table));
        $s = '<section><div><h2>' . $this->h($title) . '</h2><span>Newest first · UTC</span></div>';
        if ($paginate) {
            $s .= '<form class="form-inline" method="get" action="' . $this->h($this->link) . '">' . $this->queryFields() . '<input type="hidden" name="bachs_page" value="' . $this->h(Input::text($_GET, 'bachs_page', 'overview')) . '"><label>State <input class="form-control" name="bachs_state" value="' . $this->h($state) . '" placeholder="All states"></label><label>Invoice <input class="form-control" name="bachs_invoice" inputmode="numeric" value="' . ($invoiceId ?: '') . '" placeholder="All invoices"></label><button class="btn btn-default" type="submit">Filter</button></form>';
        }
        if (!$rows) {
            return $s . '<div class="text-muted"><strong>No ' . $this->h(strtolower($title)) . ' to display</strong><p>New records will appear here after the related payment activity or a provider refresh.</p></div></section>';
        }
        $s .= '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr><th scope="col">Record / invoice</th><th scope="col">Amount / purpose</th><th scope="col">State</th><th scope="col">Received</th><th scope="col">Details & actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $d = $row['data'];
            $name = isset($d['event_id']) ? $d['event_id'] : (isset($d['payment_id']) ? $d['payment_id'] : (isset($d['refund_id']) ? $d['refund_id'] : $row['id']));
            $s .= '<tr><td><code>' . $this->h($name) . '</code>';
            if ($row['invoice_id']) {
                $s .= '<a href="invoices.php?action=edit&id=' . (int)$row['invoice_id'] . '">Invoice #' . (int)$row['invoice_id'] . '</a> ';
            }
            if ($row['client_id']) {
                $s .= '<a href="clientssummary.php?userid=' . (int)$row['client_id'] . '">Client #' . (int)$row['client_id'] . '</a> ';
            }
            $s .= '</td><td>' . $this->h(isset($d['amount']) ? (isset($d['currency']) ? $d['currency'] . ' ' : '') . $d['amount'] : (isset($d['type']) ? $d['type'] : (isset($d['action']) ? $d['action'] : (isset($d['brand']) ? $d['brand'] . ' •••• ' . $d['last4'] : '—')))) . '</td>';
            $s .= '<td><span class="label label-default">'  . $this->h(str_replace('_', ' ', $row['state'])) . '</span>';
            if ($table === 'refunds') {
                $s .= '<small>Provider: ' . $this->h(isset($d['provider_state']) ? $d['provider_state'] : 'unknown') . '<br>Accounting: ' . $this->h(isset($d['accounting_state']) ? $d['accounting_state'] : 'pending') . '</small>';
            }
            $s .= '</td><td>' . $this->h(gmdate('d M Y', $row['created_at'])) . '<small>' . $this->h(gmdate('H:i:s', $row['created_at'])) . '</small></td><td><details><summary>Inspect record</summary><div>' . $this->details(array_merge(array('local_id' => $row['id'], 'scope' => $row['scope']), $d));
            if ($table === 'payments') {
                $attempt = isset($d['attempt_id']) ? $d['attempt_id'] : '';
                $s .= $this->form('recheck', array('target' => $attempt, 'scope_id' => $row['scope']), '', 'Recheck payment');
                if ($row['state'] === 'posted' && $this->can('request_refunds')) {
                    $s .= '<h3>Request a refund</h3><p>Only one nonfailed refund is allowed, including a partial refund.</p>' . $this->form('refund_preview', array('target' => $row['id'], 'scope_id' => $row['scope']), $this->input('amount', 'Invoice currency amount', 'text', $d['amount']) . $this->input('reason', 'Reason') . $this->input('refund_address', 'Verified crypto address (crypto only)') . $this->input('network', 'Verified asset / network currency (crypto only)'), 'Review refund');
                }
            } elseif ($table === 'jobs' && in_array($row['state'], array('dead','retry'), true) && $this->can('manage_webhooks')) {
                $s .= $this->form('retry_job', array('target' => $row['id'], 'scope_id' => $row['scope']), '', 'Retry existing job');
            } elseif ($table === 'webhook_events' && $this->can('manage_webhooks')) {
                $s .= $this->form('replay', array('target' => $row['id'], 'scope_id' => $row['scope']), '', 'Replay verified event');
            } elseif ($table === 'refunds' && $this->can('finalize_refund_accounting')) {
                if ($row['state'] === 'accounting_pending' && isset($d['refund_id'], $d['amount'])) {
                    $s .= '<p>In the native invoice, use <strong>Manual Refund Processed Externally / Record Only</strong>. Use the displayed amount and link to the original payment. Transaction ID:</p><code>' . $this->h(\Bachs\Whmcs\Domain\Ids::transaction($row['scope'], $d['refund_id'], 'refund')) . '</code>';
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
            $base = $this->url(array('bachs_page' => Input::text($_GET, 'bachs_page', 'overview'), 'bachs_state' => $state, 'bachs_invoice' => $invoiceId));
            $s .= '<nav class="pager" aria-label="Records">';
            if ($page > 1) {
                $s .= '<a href="' . $this->h($base . '&bachs_p=' . ($page - 1)) . '">Previous</a> ';
            } $s .= '<span>Page ' . $page . '</span>';
            if (count($rows) === $limit) {
                $s .= '<a href="' . $this->h($base . '&bachs_p=' . ($page + 1)) . '">Next</a> ';
            } $s .= '</nav>';
        }
        return $s . '</section>';
    }

    private function details($data)
    {
        return '<pre>' . $this->h(json_encode(self::display($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    }

    public static function display($data, $depth = 0)
    {
        if ($depth > 10) {
            return '[depth limit]';
        }
        if (!is_array($data)) {
            return is_string($data) ? SafeData::shortText($data, 20000) : $data;
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
            $out[$k] = is_array($v) ? self::display($v, $depth + 1) : (is_string($v) ? SafeData::shortText($v, 20000) : $v);
        }
        return $out;
    }

    private function workerHealth()
    {
        $last = $this->app->settings->get('last_worker', 0);
        $fresh = $last && $this->app->store->clock->now() - $last <= 900;
        $status = '<span class="label ' . ($fresh ? 'label-success' : 'label-warning') . '">' . ($fresh ? 'Worker recently active' : 'Worker needs attention') . '</span>';
        $status .= $last ? ' <small>Last completed run: ' . $this->h(gmdate('Y-m-d H:i:s', $last)) . ' UTC</small>' : ' <small>No completed run yet.</small>';
        if (!$fresh) {
            $status .= '<p>Use Run payment checks below if available. If the timestamp stays unchanged after the next system cron, check the WHMCS Activity Log for Bachs cron_worker errors.</p>';
        }
        return $status;
    }

    private function form($action, $hidden, $fields, $label)
    {
        if (isset(AdminActions::$permissions[$action]) && !$this->can(AdminActions::$permissions[$action])) {
            return '';
        }
        $s = '<form class="form-group" action="' . $this->h($this->url(array('bachs_page' => Input::text($_GET, 'bachs_page', 'overview')))) . '" method="post">';
        $values = array_merge(array('bachs_action' => $action, 'token' => $this->token, 'scope_id' => $this->scope ? $this->scope['id'] : ''), $hidden);
        foreach ($values as $k => $v) {
            $s .= '<input type="hidden" name="' . $this->h($k) . '" value="' . $this->h($v) . '">';
        }
        return $s . $fields . '<button class="btn btn-primary" type="submit">' . $this->h($label) . '</button><span class="text-muted" role="status"></span></form>';
    }

    private function input($name, $label, $type = 'text', $value = '', $help = '')
    {
        return '<label class="form-group"><span>' . $this->h($label) . '</span><input class="form-control" name="' . $this->h($name) . '" type="' . $this->h($type) . '" value="' . $this->h($value) . '"' . ($type === 'password' ? ' autocomplete="new-password" spellcheck="false"' : '') . '>' . ($help ? '<small>' . $this->h($help) . '</small>' : '') . '</label>';
    }

    private function url(array $query)
    {
        return $this->link . (strpos($this->link, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function queryFields()
    {
        $query = array();
        parse_str((string)parse_url($this->link, PHP_URL_QUERY), $query);
        $html = '';
        foreach ($query as $key => $value) {
            if (is_string($value)) {
                $html .= '<input type="hidden" name="' . $this->h($key) . '" value="' . $this->h($value) . '">';
            }
        }
        return $html;
    }

}
