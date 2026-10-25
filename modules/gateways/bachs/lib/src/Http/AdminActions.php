<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Application\Confirmations;
use Bachs\Whmcs\Application\Refunds;
use Bachs\Whmcs\Domain\Failure;

class AdminActions
{
    private $app;
    public static $permissions = array('verify' => 'manage_settings',
        'replay' => 'manage_webhooks', 'recheck' => 'view_payments', 'refund_preview' => 'request_refunds', 'refund_confirm' => 'request_refunds',
        'refund_recheck' => 'finalize_refund_accounting', 'refund_map' => 'finalize_refund_accounting', 'resolve' => 'view_payments', 'revoke' => 'manage_settings',
        'export' => 'export_diagnostics');

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function run(array $input)
    {
        $a = $this->app;
        $action = Input::text($input, 'action', '', 60);
        if (!isset(self::$permissions[$action])) {
            throw new Failure('unknown_admin_action');
        }
        $actor = $a->bridge->identity->admin(self::$permissions[$action]);
        $a->bridge->identity->csrf();
        (new RateLimit($a->store))->check('admin:' . $actor['id'], 40);
        $who = $actor['actor'];
        $scope = null;
        if (!in_array($action, array('verify','export'), true)) {
            $scopeId = Input::text($input, 'scope_id', '', 48);
            $scope = $scopeId ? $a->settings->scope($scopeId) : $a->settings->activeScope();
        }
        $confirmation = Input::text($input, 'confirmation', '', 48);
        $target = Input::text($input, 'target', '', 255);
        if ($action === 'verify') {
            (new \Bachs\Whmcs\Infrastructure\Whmcs\GatewayConfiguration($a))->synchronize($a->bridge->gatewaySettings(), true);
            $error = $a->settings->get('configuration_error');
            if ($error) {
                throw new Failure($error);
            }
            return array('message' => 'Saved gateway settings verified. No money was moved.');
        }
        if ($action === 'replay') {
            $event = $a->store->get('webhook_events', $target);
            if (!$event || $event['scope'] !== $scope['id'] || $event['state'] === 'quarantined') {
                throw new Failure('authenticated_event_required');
            }
            $a->store->update('webhook_events', $target, array(), 'received');
            $job = $a->queue->enqueue('event', $scope['id'], $target);
            $a->queue->wake($job, true);
            $a->store->audit($who, 'authenticated_event_replayed', $scope['id'], $target);
            return array('message' => 'Verified event queued for reprocessing.');
        }
        if ($action === 'recheck') {
            $attempt = $a->store->get('attempts', $target);
            if (!$attempt || $attempt['scope'] !== $scope['id']) {
                throw new Failure('attempt_not_found');
            }
            $job = $a->queue->enqueue('attempt', $scope['id'], $target);
            $a->queue->wake($job, true);
            return array('message' => 'Provider recheck queued.');
        }
        if ($action === 'refund_preview') {
            $payment = $a->store->get('payments', $target);
            if (!$payment || $payment['scope'] !== $scope['id']) {
                throw new Failure('payment_not_found');
            }
            $body = array('payment_id' => $target, 'amount' => Input::text($input, 'amount', '', 60),
                'reason' => Input::text($input, 'reason', '', 500), 'address' => Input::text($input, 'refund_address', '', 255),
                'network' => Input::text($input, 'network', '', 40));
            $resource = $a->provider($scope)->request('GET', '/v1/payments/' . rawurlencode($payment['data']['payment_id']));
            $preview = (new Confirmations($a))->preview($scope, $who, 'refund', $body, array('payment' => $resource,
                'warning' => 'One nonfailed refund is allowed per payment. A partial refund prevents a later refund of the remainder.', 'invoice_amount' => $payment['data']['amount'], 'invoice_currency' => $payment['data']['currency']));
            return $this->preview($preview, 'refund_confirm');
        }
        if ($action === 'refund_confirm') {
            $p = (new Confirmations($a))->consume($scope, $who, 'refund', $confirmation);
            $b = $p['body'];
            $r = (new Refunds($a))->request($b['payment_id'], $b['amount'], $b['reason'], $who, $b['address'], $b['network']);
            return array('message' => 'Refund request tracked. Provider and accounting status are shown separately.', 'data' => array('id' => $r['id'], 'state' => $r['state']));
        }
        if ($action === 'refund_recheck') {
            $r = $a->store->get('refunds', $target);
            if (!$r || $r['scope'] !== $scope['id']) {
                throw new Failure('refund_not_found');
            }
            $job = $a->queue->enqueue('refund', $scope['id'], $target);
            $a->queue->wake($job, true);
            return array('message' => 'Refund and native accounting recheck queued.');
        }
        if ($action === 'refund_map') {
            $r = $a->store->get('refunds', $target);
            if (!$r || $r['scope'] !== $scope['id']) {
                throw new Failure('refund_not_found');
            }
            (new Refunds($a))->mapAccounting($target, Input::text($input, 'amount'), Input::text($input, 'evidence', '', 3000), $who);
            return array('message' => 'Invoice-currency accounting mapping saved. Record the verified external refund in WHMCS, then recheck.');
        }
        if ($action === 'resolve') {
            $r = $a->store->get('reconciliation_items', $target);
            if (!$r || $r['scope'] !== $scope['id']) {
                throw new Failure('reconciliation_item_not_found');
            }
            $note = Input::text($input, 'note', '', 2000);
            if (strlen(trim($note)) < 10) {
                throw new Failure('resolution_evidence_required');
            }
            $a->store->update('reconciliation_items', $target, array('resolution' => $a->settings->secrets->seal($note), 'resolved_by' => $who), 'resolved');
            $a->store->audit($who, 'reconciliation_annotated', $scope['id'], $target);
            return array('message' => 'Resolution evidence saved. Financial entries are unchanged.');
        }
        if ($action === 'revoke') {
            $r = $a->store->get('payment_methods', $target);
            if (!$r || $r['scope'] !== $scope['id']) {
                throw new Failure('saved_method_not_found');
            }
            (new \Bachs\Whmcs\Application\SavedMethods($a))->revoke($target, $r['client_id']);
            return array('message' => 'Local automatic collection disabled for this method.');
        }
        if ($action === 'export') {
            return $this->export($who);
        }
        throw new Failure('unknown_admin_action');
    }

    private function preview($data, $confirmAction)
    {
        $data['confirm_action'] = $confirmAction;
        return array('message' => 'Review these exact details before confirming.', 'confirmation' => $data);
    }

    private function export($actor)
    {
        $data = array('module' => 'bachs', 'version' => '1.0.0', 'generated_at' => gmdate('c'), 'last_worker' => $this->app->settings->get('last_worker', 0), 'records' => array());
        foreach (array('attempts','payments','refunds','webhook_events','reconciliation_items','jobs') as $table) {
            $data['records'][$table] = array();
            foreach ($this->app->store->listing($table, array(), 500) as $r) {
                $data['records'][$table][] = array('id' => $r['id'], 'scope' => $r['scope'], 'state' => $r['state'], 'data' => \Bachs\Whmcs\Infrastructure\SafeData::redact($r['data']));
            }
        }
        $this->app->store->audit($actor, 'diagnostics_exported', '', 'diagnostics');
        return array('message' => 'Redacted diagnostic export (up to 500 rows per table).', 'download' => $data);
    }
}
