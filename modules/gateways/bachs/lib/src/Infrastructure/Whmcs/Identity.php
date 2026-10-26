<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;

class Identity implements \Bachs\Whmcs\Domain\IdentityResolver
{
    private $api;
    public static $permissions = array('view_payments' => 'List Transactions', 'manage_settings' => 'Configure Payment Gateways',
        'manage_webhooks' => 'Configure Payment Gateways', 'request_refunds' => 'Refund Invoice Payments',
        'finalize_refund_accounting' => 'Refund Invoice Payments', 'view_diagnostics' => 'View Gateway Log', 'export_diagnostics' => 'Client Data Export');

    public function __construct(?LocalApi $api = null)
    {
        $this->api = $api === null ? new LocalApi() : $api;
    }

    public function client($clientId = null)
    {
        $current = new \WHMCS\Authentication\CurrentUser();
        $client = $current->client();
        $user = $current->user();
        if (!$client || !$user) {
            throw new Failure('client_login_required');
        }
        $id = (int)$client->id;
        $actor = 'user:' . (int)$user->id;
        if ($clientId !== null && (int)$clientId !== $id) {
            throw new Failure('client_payment_access_denied');
        }
        // The client account is not the logged-in User. Its hasPermission()
        // method is an interface stub that throws in WHMCS 8.
        if (!$client->isOwnedBy($user) && !$this->canManageInvoices((int)$user->id, $id)) {
            throw new Failure('client_payment_access_denied');
        }
        return array('client_id' => $id, 'actor' => $actor);
    }

    private function canManageInvoices($userId, $clientId)
    {
        try {
            $response = $this->api->call('GetUserPermissions', array('user_id' => $userId, 'client_id' => $clientId));
        } catch (Failure $error) {
            throw new Failure('client_permissions_unavailable');
        }
        if (!isset($response['permissions'])) {
            throw new Failure('client_permissions_unavailable');
        }
        $permissions = $response['permissions'];
        if (is_array($permissions) && isset($permissions['permission'])) {
            $permissions = $permissions['permission'];
        }
        if (is_string($permissions)) {
            $permissions = array_map('trim', explode(',', $permissions));
        }
        if (!is_array($permissions)) {
            throw new Failure('client_permissions_unavailable');
        }
        if (array_key_exists('invoices', $permissions)) {
            return in_array($permissions['invoices'], array(true, 1, '1'), true);
        }
        return in_array('invoices', $permissions, true);
    }

    public function admin($capability)
    {
        if (!isset(self::$permissions[$capability])) {
            throw new Failure('admin_access_denied');
        }
        $current = new \WHMCS\Authentication\CurrentUser();
        $admin = $current->admin();
        if (!$current->isAuthenticatedAdmin() || !$admin || !empty($admin->disabled)) {
            throw new Failure('admin_access_denied');
        }
        if (!$admin->hasPermission(self::$permissions[$capability])) {
            throw new Failure('action_permission_denied');
        }
        return array('id' => (int)$admin->id, 'role_id' => (int)$admin->roleId, 'actor' => 'admin:' . (int)$admin->id);
    }

    public function csrf()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Failure('post_required');
        }
        if (!function_exists('check_token') || !isset($_POST['token']) || !is_string($_POST['token'])) {
            throw new Failure('csrf_required');
        }
        check_token('WHMCS.default');
    }

    public function token()
    {
        if (!function_exists('generate_token')) {
            throw new Failure('csrf_unavailable');
        }
        return generate_token('plain');
    }
}
