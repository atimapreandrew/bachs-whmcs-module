<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;

/** Runs bounded, recoverable payment and refund checks outside the client browser. */
class Worker
{
    private $app;

    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    public function run($limit = null, $seconds = null)
    {
        $a = $this->app;
        $started = microtime(true);
        $count = 0;
        if ($limit === null) {
            $limit = $a->settings->get('worker_batch', 50);
        }
        if ($seconds === null) {
            $seconds = $a->settings->get('worker_seconds', 20);
        }
        $this->schedule();
        while ($count < max(1, min(100, (int)$limit)) && microtime(true) - $started < max(1, min(50, (int)$seconds))) {
            $job = $a->queue->claim();
            if (!$job) {
                break;
            }
            try {
                $pending = $this->dispatch($job);
                $a->queue->finish($job, $pending ? min(3600, 30 * pow(2, min(6, $job['data']['tries']))) : null, $pending ? 'provider_pending' : null);
            } catch (\Throwable $e) {
                $reason = $e instanceof Failure ? $e->reason : 'worker_internal_error';
                $delay = $e instanceof ApiFailure ? $e->retryAfter : min(3600, 30 * pow(2, min(6, $job['data']['tries'])));
                $a->queue->finish($job, $delay + random_int(0, 10), $reason);
                $a->store->exception($job['scope'], $reason, $job['data']['aggregate']);
            }
            ++$count;
        }
        $a->settings->set('last_worker', $a->store->clock->now());
        return array('processed' => $count, 'seconds' => round(microtime(true) - $started, 3));
    }

    private function schedule()
    {
        $a = $this->app;
        $now = $a->store->clock->now();
        $sets = array('attempts' => array('type' => 'attempt', 'states' => array('creating','open','pending','outcome_unknown','review')),
            'refunds' => array('type' => 'refund', 'states' => array('requested','submitting','outcome_unknown','processing','accounting_pending')),
            'webhook_events' => array('type' => 'event', 'states' => array('received')));
        foreach ($sets as $table => $set) {
            $args = $set['states'];
            $args[] = $now;
            $sql = 'SELECT id,scope FROM mod_bachs_' . $table . ' WHERE state IN (' . implode(',', array_fill(0, count($set['states']), '?')) . ') AND next_run<=? ORDER BY next_run,id LIMIT 20';
            foreach ($a->store->run($sql, $args)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $a->job($set['type'], $row['scope'], $row['id']);
                $a->store->update($table, $row['id'], array(), null, array('next_run' => $now + 300));
            }
        }
        if ($a->settings->get('automatic_payments', false) && $a->settings->get('saved_methods', false)) {
            $scope = $a->settings->activeScope();
            $rows = $a->store->run("SELECT id,data FROM mod_bachs_payment_methods WHERE scope=? AND state='active' AND next_run<=? ORDER BY next_run,id LIMIT 20", array($scope['id'], $now))->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $data = json_decode($row['data'], true);
                if (empty($data['pay_method_id']) || !isset($data['native_enrollment']) || $data['native_enrollment'] !== 'completed') {
                    $a->job('enroll', $scope['id'], $row['id']);
                }
                $a->store->update('payment_methods', $row['id'], array(), null, array('next_run' => $now + 300));
            }
        }
        foreach ($a->store->listing('merchant_scopes', array(), 100) as $scope) {
            $last = $a->settings->get('sweep_at_' . $scope['id'], 0);
            if ($now - $last > 300) {
                $a->job('sweep', $scope['id'], $scope['id']);
                $a->settings->set('sweep_at_' . $scope['id'], $now);
            }
        }
    }

    private function dispatch($job)
    {
        $a = $this->app;
        $id = $job['data']['aggregate'];
        $type = $job['data']['type'];
        if ($type === 'event') {
            (new Webhooks($a))->process($id);
            return false;
        }
        if ($type === 'attempt') {
            $attempt = $a->store->get('attempts', $id);
            if (!$attempt) {
                return false;
            }
            if (!empty($attempt['data']['operation_id']) && empty($attempt['data']['checkout_id']) && empty($attempt['data']['payment_id'])) {
                $op = $a->store->get('operations', $attempt['data']['operation_id']);
                if ($op && $op['state'] === 'succeeded' && isset($op['data']['response'])) {
                    $response = json_decode($a->settings->secrets->open($op['data']['response']), true);
                    if (isset($response['checkout_id'])) {
                        (new Checkout($a))->accept($attempt, $response);
                    } elseif (isset($response['payment_id'])) {
                        $a->store->update('attempts', $id, array('payment_id' => $response['payment_id']), 'pending');
                    }
                } elseif ($op && in_array($op['state'], array('sending','outcome_unknown'), true)) {
                    $a->store->exception($attempt['scope'], 'write_outcome_unknown', $op['id']);
                }
            }
            return (new PaymentRecognition($a))->reconcile($id) === 'pending';
        }
        if ($type === 'refund') {
            return (new Refunds($a))->reconcile($id) === 'pending';
        }
        if ($type === 'enroll') {
            if (!$a->settings->get('automatic_payments', false) || !$a->settings->get('saved_methods', false) || $a->settings->activeScope()['id'] !== $job['scope']) {
                return false;
            }
            $method = $a->store->get('payment_methods', $id);
            if (!$method || $method['state'] !== 'active') {
                return false;
            }
            (new SavedMethods($a))->owned($id, $job['scope'], $method['client_id']);
            $a->bridge->enrollMethod($method);
            return false;
        }
        if ($type === 'sweep') {
            $this->sweep($a->settings->scope($job['scope']));
            return false;
        }
        throw new Failure('unknown_job_type');
    }

    private function sweep($scope)
    {
        $a = $this->app;
        $cursorKey = 'payment_cursor_' . $scope['id'];
        $offset = (int)$a->settings->get($cursorKey, 0);
        $list = $a->provider($scope)->request('GET', '/v1/payments?limit=50&offset=' . $offset);
        if (!isset($list['items'], $list['pagination']) || !is_array($list['items'])) {
            throw new Failure('payment_list_contract_mismatch');
        }
        foreach ($list['items'] as $item) {
            if (!isset($item['id'])) {
                continue;
            }
            $payment = $a->store->byProvider('payments', $scope['id'], $item['id']);
            if ($payment) {
                $fields = array();
                foreach (array('settlement_amount','settlement_currency') as $k) {
                    if (isset($item[$k]) && is_string($item[$k])) {
                        $fields[$k] = $item[$k];
                    }
                }
                if ($fields) {
                    $a->store->update('payments', $payment['id'], $fields);
                }
                if (in_array(isset($item['status']) ? $item['status'] : '', array('refunded','partially_refunded','auto_refunded'), true)) {
                    $r = (new Refunds($a))->byCharge($scope, $item['id']);
                    if ($r) {
                        (new Refunds($a))->import($scope, $payment, $r);
                    }
                }
            } elseif (!empty($item['reference'])) {
                $ref = $a->store->find('settings', $scope['id'], 'attempt_reference:' . $item['reference']);
                if ($ref) {
                    (new PaymentRecognition($a))->reconcile($ref['data']['attempt_id'], $item['id']);
                } else {
                    $a->store->exception($scope['id'], 'unmatched_provider_payment', $item['id']);
                }
            } else {
                $a->store->exception($scope['id'], 'unallocated_deposit', $item['id']);
            }
        }
        // Durable offset with a 10-record overlap. Stop a scan at its previous high-water ID;
        // the next scan always starts at the newest receipts to recover late updates.
        $hasMore = !empty($list['pagination']['has_more']);
        $highKey = 'payment_highwater_' . $scope['id'];
        $previous = $a->settings->get($highKey);
        if ($offset === 0 && !empty($list['items'][0]['id'])) {
            $a->settings->set('payment_new_highwater_' . $scope['id'], $list['items'][0]['id']);
        }
        $reached = false;
        foreach ($list['items'] as $item) {
            if (isset($item['id']) && $item['id'] === $previous) {
                $reached = true;
            }
        }
        $a->settings->set($cursorKey, $hasMore && !$reached ? $offset + max(1, count($list['items']) - 10) : 0);
        if (!$hasMore || $reached) {
            $a->settings->set($highKey, $a->settings->get('payment_new_highwater_' . $scope['id']));
        }
    }

    public function cleanup()
    {
        $a = $this->app;
        $cutoff = $a->store->clock->now() - 86400 * $a->settings->get('sensitive_retention_days', 30);
        // Preserve financial identities and audit history. Remove only credentials no longer needed for resumption.
        $cursor = $a->settings->get('retention_cursor', '');
        $rows = $a->store->run("SELECT id,data FROM mod_bachs_attempts WHERE created_at<? AND id>? AND state IN ('posted','failed','expired_verified','cancelled_verified','setup_completed') ORDER BY id LIMIT 100", array($cutoff, $cursor))->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $data = json_decode($row['data'], true);
            if (!empty($data['checkout_url']) || !empty($data['return_state'])) {
                $a->store->update('attempts', $row['id'], array('checkout_url' => null, 'return_state' => null));
            }
            $cursor = $row['id'];
        }
        $a->settings->set('retention_cursor', count($rows) === 100 ? $cursor : '');
        $a->store->run("DELETE FROM mod_bachs_settings WHERE state IN ('preview','consumed') AND updated_at<?", array($a->store->clock->now() - 86400));
        $a->store->run("DELETE FROM mod_bachs_settings WHERE state='rate' AND updated_at<?", array($a->store->clock->now() - 86400));
        $a->store->run('DELETE FROM mod_bachs_locks WHERE lease_until=0 AND updated_at<?', array($cutoff));
    }
}
