<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;

class Acceptance
{
    private $profile;

    public function __construct($path, $webRoot)
    {
        $this->profile = array();
        if (!$path) {
            return;
        }
        $real = realpath($path);
        $root = realpath($webRoot);
        if (!$real || !$root || strpos($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0 || filesize($real) > 65536) {
            throw new Failure('acceptance_file_must_be_private');
        }
        $profile = json_decode(file_get_contents($real), true);
        if (!is_array($profile)) {
            throw new Failure('invalid_acceptance_profile');
        }
        $this->profile = $profile;
    }

    public function assertFeature($feature, $environment, $version)
    {
        if (!isset($this->profile['whmcs_version'], $this->profile['php_version']) || $this->profile['whmcs_version'] !== $version || $this->profile['php_version'] !== PHP_VERSION) {
            throw new Failure('installation_acceptance_required');
        }
        if (empty($this->profile['features'][$environment][$feature])) {
            throw new Failure($feature . '_acceptance_required');
        }
    }

    public function sandboxInvoice($id)
    {
        return !empty($this->profile['isolated_staging']) || in_array((int)$id, isset($this->profile['nonfulfilling_test_invoices']) ? array_map('intval', $this->profile['nonfulfilling_test_invoices']) : array(), true);
    }

    public function has($feature, $environment, $version)
    {
        try {
            $this->assertFeature($feature, $environment, $version);
            return true;
        } catch (Failure $e) {
            return false;
        }
    }

    public function collectionPrecision($currency)
    {
        if (!isset($this->profile['collection_precision'][$currency]) || !is_int($this->profile['collection_precision'][$currency]) || $this->profile['collection_precision'][$currency] < 0 || $this->profile['collection_precision'][$currency] > 18) {
            throw new Failure('collection_precision_acceptance_required');
        }
        return $this->profile['collection_precision'][$currency];
    }
}
