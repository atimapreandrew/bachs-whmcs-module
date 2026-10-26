<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Money;

class Bridge implements \Bachs\Whmcs\Domain\InvoiceReader, \Bachs\Whmcs\Domain\PaymentPoster, \Bachs\Whmcs\Domain\RefundFinalizer
{
    public $api;
    public $identity;
    public $version;
    private $root;
    private $pdo;
    private $settings;

    public function __construct(LocalApi $api, $settings, $pdo, $root)
    {
        $this->api = $api;
        $this->settings = $settings;
        $this->pdo = $pdo;
        $this->root = $root;
        $this->version = Environment::version();
        if (version_compare($this->version, '8.0', '<')) {
            throw new Failure('whmcs_version_not_supported');
        }
        $this->identity = new Identity($api);
    }

    public function invoice($id)
    {
        if ((int)$id <= 0) {
            throw new Failure('invalid_invoice');
        }
        $r = $this->api->call('GetInvoice', array('invoiceid' => (int)$id));
        $customer = $this->api->call('GetClientsDetails', array('clientid' => (int)$r['userid'], 'stats' => false));
        if (isset($customer['client'])) {
            $customer = $customer['client'];
        }
        $currencies = $this->api->call('GetCurrencies');
        $currency = null;
        if (isset($currencies['currencies']['currency'])) {
            foreach ($currencies['currencies']['currency'] as $c) {
                if ((int)$c['id'] === (int)$customer['currency']) {
                    $currency = $c['code'];
                    break;
                }
            }
        }
        if (!$currency) {
            throw new Failure('invoice_currency_unavailable');
        }
        $items = isset($r['items']['item']) ? $r['items']['item'] : array();
        foreach ($items as $item) {
            if (isset($item['type']) && in_array($item['type'], array('AddFunds','Invoice'), true)) {
                throw new Failure('special_invoice_not_supported');
            }
        }
        return array('invoice_id' => (int)$r['invoiceid'], 'client_id' => (int)$r['userid'], 'status' => $r['status'],
            'balance' => Money::normalize($r['balance']), 'total' => Money::normalize($r['total']), 'credit' => Money::normalize($r['credit']),
            'currency' => $currency, 'gateway' => $r['paymentmethod'], 'items_hash' => hash('sha256', json_encode($items)), 'due_date' => $r['duedate']);
    }

    public function customer($clientId)
    {
        $r = $this->api->call('GetClientsDetails', array('clientid' => (int)$clientId, 'stats' => false));
        if (isset($r['client'])) {
            $r = $r['client'];
        }
        return array('client_id' => (int)$clientId, 'email' => $r['email'], 'name' => trim($r['firstname'] . ' ' . $r['lastname']));
    }

    public function systemUrl()
    {
        $url = Environment::setting('SystemURL');
        return \Bachs\Whmcs\Infrastructure\Bachs\Urls::system($url);
    }

    public function assertGateway($gateway)
    {
        if (!in_array($gateway, array('bachs','bachs_card'), true)) {
            throw new Failure('gateway_not_bachs');
        }
        require_once $this->root . '/includes/gatewayfunctions.php';
        $variables = getGatewayVariables($gateway);
        if (empty($variables['type'])) {
            throw new Failure('native_gateway_inactive');
        }
        if (!empty($variables['convertto'])) {
            throw new Failure('native_currency_conversion_gate');
        }
    }

    public function gatewaySettings()
    {
        require_once $this->root . '/includes/gatewayfunctions.php';
        $values = getGatewayVariables('bachs');
        return is_array($values) ? $values : array();
    }

    public function transaction($id)
    {
        $r = $this->api->call('GetTransactions', array('transid' => $id, 'limitnum' => 10));
        $rows = isset($r['transactions']['transaction']) ? $r['transactions']['transaction'] : array();
        $matches = array();
        foreach ($rows as $row) {
            if (isset($row['transid']) && $row['transid'] === $id) {
                $matches[] = $row;
            }
        }
        if (count($matches) > 1) {
            throw new Failure('duplicate_native_transaction');
        }
        if (!$matches) {
            return null;
        }
        // Local API serializers differ in numeric coercion and refundid exposure.
        // Read the exact native DECIMAL/linkage fields inside this adapter only.
        $native = \WHMCS\Database\Capsule::table('tblaccounts')->where('id', (int)$matches[0]['id'])->where('transid', $id)->first(array('id','invoiceid','userid','gateway','transid','amountin','amountout','refundid'));
        if (!$native) {
            throw new Failure('native_transaction_readback_failed');
        }
        return (array)$native;
    }

    public function postingLock($invoiceId)
    {
        $name = 'bachs:' . substr(hash('sha256', $this->settings->get('installation_id', $this->root) . ':' . $invoiceId), 0, 48);
        $s = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $s->execute(array($name));
        if ((string)$s->fetchColumn() !== '1') {
            throw new Failure('native_posting_in_progress');
        }
        return $name;
    }

    public function releasePostingLock($name)
    {
        $s = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $s->execute(array($name));
    }

    public function post($invoice, $transaction, $amount, $fee, $gateway)
    {
        $amount = Money::positive($amount);
        $fee = Money::normalize($fee);
        require_once $this->root . '/includes/gatewayfunctions.php';
        require_once $this->root . '/includes/invoicefunctions.php';
        $this->assertGateway($gateway);
        if ($this->transaction($transaction)) {
            throw new Failure('native_transaction_already_exists');
        }
        checkCbInvoiceID((int)$invoice, $gateway);
        // The advisory lock and exact-identity lookup precede the helper's duplicate exit.
        checkCbTransID($transaction);
        logTransaction($gateway, array('invoice_id' => (int)$invoice, 'transaction_id' => $transaction, 'amount' => $amount), 'Verified');
        addInvoicePayment((int)$invoice, $transaction, $amount, $fee, $gateway);
    }

    public function enrollMethod($method)
    {
        if (!$this->settings->get('automatic_payments', false) || !$this->settings->get('saved_methods', false)) {
            return;
        }
        if ($method['scope'] !== $this->settings->activeScope()['id']) {
            return;
        }
        require_once $this->root . '/includes/gatewayfunctions.php';
        require_once $this->root . '/includes/invoicefunctions.php';
        (new TokenAdapter($this->settings, $this->api))->enroll($method);
    }

    public function finalizeRefund($refund, $payment)
    {
        return (new RefundAdapter($this))->finalize($refund, $payment);
    }
}
