<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;

class Customers
{
    private $store;
    private $operations;

    public function __construct($store, $operations)
    {
        $this->store = $store;
        $this->operations = $operations;
    }

    public function resolve($scope, $client, $provider)
    {
        $lock = 'customer:' . $scope['id'] . ':' . $client['client_id'];
        $lease = $this->store->acquire($lock);
        try {
            $mapped = $this->store->find('customers', $scope['id'], 'client:' . $client['client_id']);
            if ($mapped && $mapped['state'] === 'active') {
                return $mapped['data']['customer_id'];
            }
            $op = $this->operations->prepare(
                $scope,
                'customer:' . $client['client_id'],
                'POST',
                '/v1/customers',
                array('email' => $client['email'], 'name' => $client['name'],
                'metadata' => array('integration' => 'whmcs',
                'installation_id' => $scope['data']['installation'],
                'client_reference' => hash(
                    'sha256',
                    $scope['id'] . ':' . $client['client_id']
                )))
            );
            $response = $this->operations->send($op, $scope, $provider);
            if (!isset($response['customer_id'])) {
                throw new Failure('customer_contract_mismatch');
            }
            $id = Ids::opaque($response['customer_id']);
            $owner = $this->store->insert(
                'customers',
                $scope['id'],
                'provider:' . $id,
                array('customer_id' => $id, 'owner' => (int)$client['client_id']),
                'ownership'
            );
            if ((int)$owner['data']['owner'] !== (int)$client['client_id']) {
                throw new Failure('customer_mapping_conflict');
            }
            $this->store->put(
                'customers',
                $scope['id'],
                'client:' . $client['client_id'],
                array('customer_id' => $id),
                'active',
                array('client_id' => (int)$client['client_id'], 'provider_hash' => hash('sha256', $id))
            );
            return $id;
        } finally {
            $this->store->release($lock, $lease);
        }
    }
}
