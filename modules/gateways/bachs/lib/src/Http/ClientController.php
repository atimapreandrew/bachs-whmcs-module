<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Application\Checkout;
use Bachs\Whmcs\Application\SavedMethods;
use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Infrastructure\SafeData;

class ClientController
{
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function checkout()
    {
        $a = $this->app;
        $actor = $a->bridge->identity->client();
        $a->bridge->identity->csrf();
        (new RateLimit($a->store))->check('checkout:' . $actor['client_id'], 10);
        $invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
        $gateway = isset($_POST['gateway']) && $_POST['gateway'] === 'bachs_card' ? 'bachs_card' : 'bachs';
        $r = (new Checkout($a))->start($invoiceId, $actor['client_id'], !empty($_POST['save_method']), $actor['actor'], $gateway);
        if ($this->wantsJson()) {
            $this->json($r);
        } else {
            $this->redirect($r['checkout_url']);
        }
    }

    public function status()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new Failure('get_required');
        }
        $a = $this->app;
        $actor = $a->bridge->identity->client();
        (new RateLimit($a->store))->check('status:' . $actor['client_id'], 60);
        $id = isset($_GET['attempt_id']) && is_string($_GET['attempt_id']) ? $_GET['attempt_id'] : '';
        $attempt = $a->store->get('attempts', $id);
        if (!$attempt || (int)$attempt['client_id'] !== (int)$actor['client_id']) {
            throw new Failure('invoice_access_denied');
        }
        if ($attempt['data']['purpose'] === 'setup') {
            $this->json(array('state' => $attempt['state'], 'message' => Messages::state($attempt['state']), 'invoice_paid' => false,
                'invoice_url' => ClientView::methodsUrl($a)));
            return;
        }
        $invoice = $a->bridge->invoice($attempt['invoice_id']);
        if ((int)$invoice['client_id'] !== (int)$actor['client_id']) {
            throw new Failure('invoice_access_denied');
        }
        $this->json(array('state' => $attempt['state'], 'message' => Messages::state($attempt['state']),
            'invoice_paid' => $invoice['status'] === 'Paid', 'remaining_amount' => $invoice['balance'], 'currency' => $invoice['currency'],
            'invoice_url' => $a->bridge->systemUrl() . '/viewinvoice.php?id=' . (int)$attempt['invoice_id']));
    }

    public function returnPage()
    {
        $a = $this->app;
        $parts = explode('/', trim(isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '', '/'));
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{48}$/D', $parts[0]) || !preg_match('/^[a-f0-9]{48}$/D', $parts[1])) {
            throw new Failure('invalid_return_state');
        }
        // Login is always required before revealing invoice data. The bearer state cannot substitute for it.
        try {
            $actor = $a->bridge->identity->client();
        } catch (Failure $e) {
            $this->page('Sign in to continue', '<p>Sign in to your billing account, then return to this payment page.</p><a href="' . SafeData::html($a->bridge->systemUrl() . '/clientarea.php') . '">Sign in</a>');
            return;
        }
        $attempt = $a->store->get('attempts', $parts[0]);
        if (
            !$attempt || (int)$attempt['client_id'] !== (int)$actor['client_id'] || empty($attempt['data']['state_hash']) ||
            !hash_equals($attempt['data']['state_hash'], hash('sha256', $parts[1])) || $attempt['created_at'] + 86400 < $a->store->clock->now()
        ) {
            throw new Failure('invalid_return_state');
        }
        if (isset($_GET['checkout_id']) && (!isset($attempt['data']['checkout_id']) || !is_string($_GET['checkout_id']) || $_GET['checkout_id'] !== $attempt['data']['checkout_id'])) {
            throw new Failure('return_checkout_mismatch');
        }
        $setup = $attempt['data']['purpose'] === 'setup';
        if (!$setup) {
            $invoice = $a->bridge->invoice($attempt['invoice_id']);
            if ((int)$invoice['client_id'] !== (int)$actor['client_id']) {
                throw new Failure('invoice_access_denied');
            }
        }
        $url = $setup ? ClientView::methodsUrl($a) : $a->bridge->systemUrl() . '/viewinvoice.php?id=' . (int)$attempt['invoice_id'];
        $this->redirect($url . '&bachs_attempt=' . rawurlencode($attempt['id']));
    }

    public function card()
    {
        $a = $this->app;
        $actor = $a->bridge->identity->client();
        $a->bridge->identity->csrf();
        $step = Input::text($_POST, 'step', 'confirm', 16);
        if (!in_array($step, array('review', 'confirm'), true)) {
            throw new Failure('unknown_client_action');
        }
        if ($step === 'confirm' && empty($_POST['consent'])) {
            throw new Failure('automatic_payment_consent_required');
        }
        $a->assertCardBilling();
        $scope = $a->settings->activeScope();
        (new RateLimit($a->store))->check('card:' . $actor['client_id'], 10);
        $confirmation = Input::text($_POST, 'confirmation', '', 48);
        $confirmations = new \Bachs\Whmcs\Application\Confirmations($a);
        $intent = $step === 'review' ? $confirmations->inspect($scope, $actor['actor'], 'card_input', $confirmation) : $confirmations->consume($scope, $actor['actor'], 'card_input', $confirmation);
        $body = $intent['body'];
        if ((int)$body['client_id'] !== (int)$actor['client_id']) {
            throw new Failure('invoice_access_denied');
        }
        if ($body['invoice_id']) {
            $invoice = $a->bridge->invoice($body['invoice_id']);
            if ($body['snapshot'] !== $a->snapshot($invoice)) {
                throw new Failure('invoice_changed_during_payment');
            }
        }
        if ($step === 'review') {
            $this->redirect(ClientView::methodsUrl($a) . '&bachs_card_intent=' . rawurlencode($confirmation));
            return;
        }
        if ($body['invoice_id']) {
            $result = (new Checkout($a))->start($body['invoice_id'], $actor['client_id'], true, $actor['actor'], 'bachs_card');
            $url = $result['checkout_url'];
        } else {
            $url = (new SavedMethods($a))->setup($actor['client_id'], $actor['actor']);
        }
        $this->redirect($url);
    }

    public function manage()
    {
        $a = $this->app;
        $actor = $a->bridge->identity->client();
        $a->bridge->identity->csrf();
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        if ($action === 'portal') {
            $this->redirect((new SavedMethods($a))->portal($actor['client_id']));
            return;
        }
        if ($action === 'setup') {
            if (empty($_POST['consent'])) {
                throw new Failure('automatic_payment_consent_required');
            }
            (new RateLimit($a->store))->check('setup:' . $actor['client_id'], 5);
            $this->redirect((new SavedMethods($a))->setup($actor['client_id'], $actor['actor']));
            return;
        }
        if ($action === 'revoke') {
            (new SavedMethods($a))->revoke(isset($_POST['method_id']) ? $_POST['method_id'] : '', $actor['client_id']);
            $this->redirect(ClientView::methodsUrl($a));
            return;
        }
        throw new Failure('unknown_client_action');
    }

    public function fail($error)
    {
        $reason = $error instanceof Failure ? $error->reason : 'internal_error';
        $status = $reason === 'rate_limited' ? 429 : (strpos($reason, 'access_denied') !== false ? 403 : 400);
        http_response_code($status);
        if ($this->wantsJson()) {
            $this->json(array('error' => $reason, 'message' => Messages::error($reason)), $status);
        } else {
            $this->page('Payment request', '<p>' . SafeData::html(Messages::error($reason)) . '</p>');
        }
    }

    private function wantsJson()
    {
        return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    }

    private function headers()
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
    }

    private function json($value, $status = 200)
    {
        $this->headers();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function redirect($url)
    {
        $this->headers();
        header('Location: ' . $url, true, 303);
    }

    private function page($title, $content)
    {
        // Show trusted, escaped module messages in WHMCS's active theme.
        $_SESSION['bachs_notice'] = '<strong>' . SafeData::html($title) . '</strong>' . $content;
        $this->redirect(ClientView::methodsUrl($this->app));
    }
}
