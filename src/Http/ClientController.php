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
        $invoice = $a->bridge->invoice($attempt['invoice_id']);
        if ((int)$invoice['client_id'] !== (int)$actor['client_id']) {
            throw new Failure('invoice_access_denied');
        }
        $message = Messages::state($attempt['state']);
        $root = $a->bridge->systemUrl() . '/modules/gateways/bachs';
        $content = '<section class="bachs-payment" data-bachs-return data-attempt="' . SafeData::html($attempt['id']) . '" data-status-url="' . SafeData::html($root . '/status.php') . '">'
            . '<span class="bachs-eyebrow">Invoice #' . (int)$attempt['invoice_id'] . '</span><h1>Payment status</h1><p role="status" aria-live="polite" data-bachs-status>' . SafeData::html($message) . '</p>'
            . '<a class="bachs-button" href="' . SafeData::html($a->bridge->systemUrl() . '/viewinvoice.php?id=' . (int)$attempt['invoice_id']) . '">Return to invoice</a></section>';
        $this->page('Payment status', $content);
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
            $this->redirect($a->bridge->systemUrl() . '/index.php?m=bachs_admin');
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
        $this->headers();
        header('Content-Type: text/html; charset=utf-8');
        $assets = SafeData::html($this->app->bridge->systemUrl() . '/modules/gateways/bachs/assets');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>' . SafeData::html($title) . '</title><link rel="stylesheet" href="' . $assets . '/bachs.css"></head><body class="bachs-return-shell"><main>' . $content . '</main><script src="' . $assets . '/bachs.js" defer></script></body></html>';
    }
}
