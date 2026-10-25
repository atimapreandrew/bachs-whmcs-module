<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Domain\Failure;

class RateLimit
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function check($identity, $limit, $window = 60)
    {
        $key = 'rate:' . hash('sha256', $identity) . ':' . (int)floor($this->store->clock->now() / $window);
        $token = $this->store->acquire($key, 10);
        try {
            $r = $this->store->find('settings', '', $key);
            $count = $r ? (int)$r['data']['value'] : 0;
            if ($count >= $limit) {
                throw new Failure('rate_limited');
            }
            $this->store->put('settings', '', $key, array('value' => $count + 1), 'rate');
        } finally {
            $this->store->release($key, $token);
        }
    }
}
