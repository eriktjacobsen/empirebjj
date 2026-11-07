<?php
require __DIR__ . '/../mail/contact_handler.php';

$checks = 0;
function check($condition, $message)
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

function scenario(array $options = [])
{
    $input = $options['input'] ?? ['name' => '<b>Example</b> User', 'email' => 'visitor@example.invalid',
        'phone' => '123', 'message' => 'private message that must not enter logs', 'g-recaptcha-response' => 'private-token'];
    $config = $options['config'] ?? ['secret' => 'private-secret', 'apiKey' => 'private-key-us1', 'listID' => 'private-list'];
    $logs = [];
    $calls = [];
    $services = [
        'log' => function ($event, $id, $details) use (&$logs) { $logs[] = [$event, $id, $details]; },
        'verify' => function () use (&$calls, $options) {
            $calls[] = 'verify';
            if (!empty($options['verifyThrows'])) throw new RuntimeException('secret response');
            return $options['verification'] ?? ['success' => true, 'unavailable' => false];
        },
        'send' => function ($to, $subject, $body, $headers) use (&$calls, $options) {
            $stage = count(array_filter($calls, function ($call) { return $call === 'primary' || $call === 'autoresponse'; })) === 0 ? 'primary' : 'autoresponse';
            $calls[] = $stage;
            if (!empty($options[$stage . 'Throws'])) throw new RuntimeException($body);
            return $options[$stage] ?? true;
        },
        'subscribe' => function () use (&$calls, $options) {
            $calls[] = 'subscribe';
            if (!empty($options['subscribeThrows'])) throw new RuntimeException('private-key');
            return $options['subscription'] ?? ['http_status' => 200, 'error_code' => 0];
        },
    ];
    [$status, $body] = contact_handle($options['method'] ?? 'POST', $input, $config, $services, 'request-123');
    $encoded = json_encode($logs);
    foreach (['visitor@example.invalid', '<b>Example</b>', 'private message', 'private-token', 'private-secret', 'private-key', 'private-list'] as $private) {
        check(strpos($encoded, $private) === false, 'Private data entered logs');
    }
    check($body['request_id'] === 'request-123', 'Missing correlation ID');
    return [$status, $body, array_column($logs, 0), $calls, $logs];
}

[$code, $body, $logs, $calls] = scenario();
check($code === 200 && $body['success'] === true, 'Successful submission failed');
check($calls === ['verify', 'primary', 'autoresponse', 'subscribe'], 'Side-effect order changed');
check($logs === ['primary_mail_accepted'], 'Unexpected success logging');

foreach ([['method' => 'GET'], ['input' => []], ['input' => ['name' => ['invalid']]] ] as $options) {
    [$code, $body, $logs, $calls] = scenario($options);
    check($code >= 400 && !$body['success'] && $calls === [], 'Invalid request caused side effects');
}
[$code, $body, $logs, $calls] = scenario(['config' => ['secret' => '']]);
check($code === 503 && $logs === ['captcha_configuration_failed'] && !$calls, 'Configuration failure mishandled');

foreach ([['success' => false, 'unavailable' => false], ['success' => false, 'unavailable' => true]] as $verification) {
    [$code, $body, $logs, $calls] = scenario(['verification' => $verification]);
    check($code === ($verification['unavailable'] ? 503 : 422) && !$body['success'], 'CAPTCHA failure reported success');
    check($calls === ['verify'] && count($logs) === 1, 'CAPTCHA failure sent mail or lacked log');
}
[$code, $body, $logs] = scenario(['verifyThrows' => true]);
check($code === 503 && $logs === ['captcha_service_failed'], 'Verification exception unhandled');

foreach ([['primary' => false], ['primaryThrows' => true]] as $options) {
    [$code, $body, $logs, $calls] = scenario($options);
    check($code === 502 && !$body['success'] && $logs === ['primary_mail_failed'], 'Primary mail failure hidden');
    check($calls === ['verify', 'primary'], 'Primary mail failure caused secondary work');
}
foreach ([['autoresponse' => false], ['autoresponseThrows' => true]] as $options) {
    [$code, $body, $logs, $calls] = scenario($options);
    check($code === 200 && $body['success'] && in_array('autoresponse_failed', $logs, true), 'Autoresponse failure invited duplicate enquiry');
    check(in_array('subscribe', $calls, true), 'Autoresponse failure stopped independent subscription');
}
foreach ([['subscription' => ['http_status' => 400, 'error_code' => 0]], ['subscription' => ['http_status' => 0, 'error_code' => 28]], ['subscribeThrows' => true]] as $options) {
    [$code, $body, $logs] = scenario($options);
    check($code === 200 && $body['success'] && in_array('subscription_failed', $logs, true), 'Subscription failure invited duplicate enquiry or lacked log');
}
[$code, $body, $logs, $calls] = scenario(['config' => ['secret' => 'private-secret', 'apiKey' => '', 'listID' => '']]);
check($code === 200 && !in_array('subscribe', $calls, true), 'Disabled subscription caused work or failure');
[$code, $body, $logs] = scenario(['config' => ['secret' => 'private-secret', 'apiKey' => 'private-key', 'listID' => '']]);
check($code === 200 && in_array('subscription_configuration_failed', $logs, true), 'Partial subscription configuration not logged');

// Verify the actual PHP logger, not just the injected test logger.
$logFile = tempnam(sys_get_temp_dir(), 'contact-log-');
$oldLog = ini_get('error_log');
ini_set('error_log', $logFile);
contact_log('subscription_failed', 'request-123', ['http_status' => 400, 'transport_code' => 0]);
ini_set('error_log', $oldLog);
$line = file_get_contents($logFile);
unlink($logFile);
check(strpos($line, '"component":"contact_form"') !== false && strpos($line, '"request_id":"request-123"') !== false, 'PHP error log did not receive structured event');
echo "$checks checks passed; all email, CAPTCHA and subscription services were faked.\n";
