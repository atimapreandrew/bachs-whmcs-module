<?php

namespace Bachs\Whmcs\Infrastructure\Bachs;

class CurlTransport
{
    public function send($method, $url, array $headers, $body)
    {
        if (!extension_loaded('curl')) {
            throw new ApiFailure('curl_required');
        }
        $curl = curl_init($url);
        $response = '';
        $responseHeaders = array();
        $oversized = false;
        curl_setopt_array($curl, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            }, CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$response, &$oversized) {
                if (strlen($response) + strlen($chunk) > 2097152) {
                    $oversized = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }));
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($ok === false) {
            throw new ApiFailure($oversized ? 'response_too_large' : 'transport_failure', 0, '', 30, $method !== 'GET');
        }
        return array('status' => $status, 'headers' => $responseHeaders, 'body' => $response);
    }
}
