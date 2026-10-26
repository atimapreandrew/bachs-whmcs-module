<?php

namespace Bachs\Whmcs\Infrastructure;

/** Report failures without logging exception messages, SQL, arguments or credentials. */
final class Diagnostics
{
    private static $reported = array();

    public static function report(\Throwable $error, $context)
    {
        $context = preg_match('/^[a-z_]{1,48}$/D', $context) ? $context : 'runtime';
        $code = self::code($error);
        $key = $context . ':' . get_class($error) . ':' . $error->getFile() . ':' . $error->getLine() . ':' . $code;
        if (isset(self::$reported[$key])) {
            return self::$reported[$key];
        }
        try {
            $reference = bin2hex(random_bytes(6));
        } catch (\Throwable $ignored) {
            $reference = substr(hash('sha256', uniqid('', true)), 0, 12);
        }
        $result = array('code' => $code, 'reference' => $reference);
        self::$reported[$key] = $result;
        $line = 'Bachs [' . $reference . '] ' . $context . ': ' . $code
            . '; ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine();
        $calls = self::calls($error);
        if ($calls !== '') {
            $line .= '; calls=' . $calls;
        }
        if ($error instanceof \PDOException && isset($error->errorInfo[0], $error->errorInfo[1])) {
            $state = preg_replace('/[^A-Z0-9]/', '', substr((string)$error->errorInfo[0], 0, 5));
            $line .= '; SQLSTATE=' . $state . '; driver=' . (int)$error->errorInfo[1];
        }
        try {
            if (function_exists('logActivity')) {
                logActivity($line);
            } else {
                error_log($line);
            }
        } catch (\Throwable $ignored) {
            try {
                @error_log($line);
            } catch (\Throwable $ignoredAgain) {
                // Diagnostics must never replace the original failure.
            }
        }
        return $result;
    }

    private static function calls(\Throwable $error)
    {
        $calls = array();
        foreach (array_slice($error->getTrace(), 0, 4) as $frame) {
            $class = isset($frame['class']) ? $frame['class'] : '';
            $method = isset($frame['function']) ? $frame['function'] : '';
            // Only identifiers, never arguments, messages or absolute paths.
            if (!is_string($class) || !is_string($method) || strlen($class) > 160
                || ($class !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', str_replace('\\', '_', $class)))
                || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,80}$/D', $method)) {
                continue;
            }
            $calls[] = ($class === '' ? '' : $class . '::') . $method;
        }
        return implode(' > ', $calls);
    }

    private static function code($error)
    {
        if (isset($error->reason) && is_string($error->reason) && preg_match('/^[a-zA-Z0-9_]{1,100}$/D', $error->reason)
            && !preg_match('/sk_|whsec_|token|password/i', $error->reason)) {
            return $error->reason;
        }
        if ($error instanceof \PDOException) {
            $driver = isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : 0;
            if (in_array($driver, array(1044, 1045, 1142, 1143), true)) {
                return 'database_permission_denied';
            }
            return 'database_error';
        }
        $message = $error->getMessage();
        foreach (array('Bachs partial deployment' => 'incomplete_upload', 'Bachs component integrity' => 'incomplete_upload',
            'Bachs component version' => 'incomplete_upload', 'Bachs runtime library is missing' => 'runtime_library_missing',
            'Bachs requires WHMCS' => 'whmcs_version_not_supported', 'Bachs requires PHP' => 'php_version_not_supported') as $prefix => $code) {
            if (strpos($message, $prefix) === 0) {
                return $code;
            }
        }
        return $error instanceof \TypeError ? 'php_type_error' : 'unexpected_error';
    }

    public static function message(array $report)
    {
        $messages = array('incomplete_upload' => 'The Bachs files do not match. Upload the complete installation ZIP.',
            'module_schema_newer' => 'The database belongs to a newer Bachs module. Upload a matching or newer complete package.',
            'runtime_library_missing' => 'The Bachs runtime files are missing. Upload the complete modules folder.',
            'whmcs_version_not_supported' => 'Bachs requires WHMCS 8.0 or later.',
            'php_version_not_supported' => 'Use a PHP version supported by your WHMCS release, with PHP 7.2 or later.',
            'whmcs_version_unavailable' => 'WHMCS could not provide its version. Check the installation configuration.',
            'admin_access_denied' => 'Sign in to WHMCS as an active administrator.',
            'action_permission_denied' => 'Your administrator role does not have permission for this action.',
            'https_system_url_required' => 'Set an HTTPS System URL in WHMCS General Settings.',
            'database_permission_denied' => 'The WHMCS database user cannot create or access the Bachs tables.',
            'database_error' => 'Bachs could not access its database tables. Check the WHMCS Activity Log.');
        $message = isset($messages[$report['code']]) ? $messages[$report['code']] : 'Bachs could not complete this request. Check the WHMCS Activity Log.';
        return $message . ' Reference: ' . $report['reference'];
    }

    public static function unavailable(\Throwable $error, $context, $json = false)
    {
        $report = self::report($error, $context);
        $message = 'Bachs is temporarily unavailable. Please try again later. Reference: ' . $report['reference'];
        http_response_code(503);
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
        echo $json ? json_encode(array('error' => 'unavailable', 'message' => $message, 'reference' => $report['reference'])) : $message;
    }
}
