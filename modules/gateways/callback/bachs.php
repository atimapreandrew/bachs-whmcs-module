<?php

require_once dirname(dirname(dirname(__DIR__))) . '/init.php';
header('Cache-Control: no-store');
header('Content-Type: application/json');
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '{"error":"post_required"}';
    exit;
}
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 1048576) {
    http_response_code(413);
    echo '{"error":"body_too_large"}';
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 1048577);
$headers = array();
foreach (array('HTTP_X_BACHS_SIGNATURE_V2' => 'X-Bachs-Signature-V2') as $key => $name) {
    if (isset($_SERVER[$key])) {
        $headers[$name] = $_SERVER[$key];
    }
}
try {
    require_once dirname(__DIR__) . '/bachs/bootstrap.php';
    $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
    (new \Bachs\Whmcs\Http\RateLimit($app->store))->check('webhook:' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'), 600);
    $environment = isset($_GET['environment']) && is_string($_GET['environment']) ? $_GET['environment'] : '';
    (new \Bachs\Whmcs\Application\Webhooks($app))->ingest($environment, $raw, $headers);
    http_response_code(202);
    echo '{"received":true}';
} catch (\Bachs\Whmcs\Domain\Failure $e) {
    $safe = array('invalid_signature' => 401, 'invalid_environment' => 400, 'event_scope_mismatch' => 403, 'webhook_too_large' => 413, 'event_identity_collision' => 409, 'malformed_signed_event' => 400, 'rate_limited' => 429);
    if (isset($safe[$e->reason])) {
        http_response_code($safe[$e->reason]);
        echo json_encode(array('error' => $e->reason));
    } else {
        \Bachs\Whmcs\Infrastructure\Diagnostics::unavailable($e, 'webhook_storage', true);
    }
} catch (Throwable $e) {
    require_once dirname(__DIR__) . '/bachs/lib/src/Infrastructure/Diagnostics.php';
    \Bachs\Whmcs\Infrastructure\Diagnostics::unavailable($e, 'webhook_startup', true);
}
