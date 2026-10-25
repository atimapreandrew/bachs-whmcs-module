<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;

class Confirmations
{
    private $app;

    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    public function preview($scope, $actor, $action, $body, $context = array(), $ttl = 300)
    {
        $token = Ids::random();
        $row = $this->app->store->insert('settings', $scope['id'], 'confirmation:' . hash('sha256', $token), array('actor' => $actor,
            'action' => $action, 'expires_at' => $this->app->store->clock->now() + $ttl,
            'payload' => $this->app->settings->secrets->seal(json_encode(array('body' => $body, 'context' => $context)))), 'preview');
        return array('confirmation' => $token, 'preview' => $body, 'context' => $context, 'expires_at' => $row['data']['expires_at']);
    }

    public function consume($scope, $actor, $action, $token)
    {
        $row = $this->find($scope, $actor, $action, $token);
        $updated = $this->app->store->run("UPDATE mod_bachs_settings SET state='consumed' WHERE id=? AND state='preview'", array($row['id']))->rowCount();
        if (!$updated) {
            throw new Failure('confirmation_already_used');
        }
        $payload = json_decode($this->app->settings->secrets->open($row['data']['payload']), true);
        $payload['identity'] = 'confirmed:' . $row['id'];
        return $payload;
    }

    public function inspect($scope, $actor, $action, $token)
    {
        $row = $this->find($scope, $actor, $action, $token);
        if ($row['state'] !== 'preview') {
            throw new Failure('confirmation_already_used');
        }
        return json_decode($this->app->settings->secrets->open($row['data']['payload']), true);
    }

    private function find($scope, $actor, $action, $token)
    {
        $row = $this->app->store->find('settings', $scope['id'], 'confirmation:' . hash('sha256', $token));
        if (!$row || $row['data']['actor'] !== $actor || $row['data']['action'] !== $action || $row['data']['expires_at'] < $this->app->store->clock->now()) {
            throw new Failure('confirmation_invalid_or_expired');
        }
        return $row;
    }
}
