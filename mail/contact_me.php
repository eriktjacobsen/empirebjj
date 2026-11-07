<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/contact_handler.php';

// Secrets intentionally stay out of this public repository. Existing private
// production assignments can remain here; environment variables are optional.
$secret = "";
// MailChimp API credentials may be supplied by the existing production setup.
$secret = getenv('RECAPTCHA_SECRET') ?: $secret;
$apiKey = getenv('MAILCHIMP_API_KEY') ?: ($apiKey ?? '');
$listID = getenv('MAILCHIMP_LIST_ID') ?: ($listID ?? '');

$requestId = bin2hex(random_bytes(8));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Request-ID: ' . $requestId);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') header('Allow: POST');

// Convert PHP transport warnings into failures handled by the stage-specific
// logger. Raw warning text can contain submitted data and must not be logged.
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException('Contact transport warning', 0, $severity, $file, $line);
});
try {
    [$status, $response] = contact_handle($_SERVER['REQUEST_METHOD'] ?? '', $_POST,
        ['secret' => $secret, 'apiKey' => $apiKey, 'listID' => $listID],
        ['verify' => 'contact_verify', 'send' => 'mail', 'subscribe' => 'contact_subscribe', 'log' => 'contact_log'],
        $requestId);
} catch (Throwable $error) {
    contact_log('handler_failed', $requestId);
    [$status, $response] = contact_response(503, false, 'The contact form is temporarily unavailable. Please contact us by phone.', $requestId);
} finally {
    restore_error_handler();
}
http_response_code($status);
echo json_encode($response);
