<?php

namespace Bachs\Whmcs\Infrastructure\Bachs;

use Bachs\Whmcs\Domain\Failure;

final class Urls
{
    public static function hosted($url, $kind = 'checkout')
    {
        if (!is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new Failure('unsafe_provider_url');
        }
        $parts = parse_url($url);
        $hosts = $kind === 'portal' ? array('portal.bachs.io') : array('checkout.bachs.io');
        if (
            !$parts || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https' ||
            !in_array($parts['host'], $hosts, true) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['port']) || isset($parts['fragment'])
        ) {
            throw new Failure('unsafe_provider_url');
        }
        return $url;
    }

    public static function system($url)
    {
        $parts = parse_url($url);
        if (
            !$parts || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20]/', $url)
        ) {
            throw new Failure('https_system_url_required');
        }
        return rtrim($url, '/');
    }
}
