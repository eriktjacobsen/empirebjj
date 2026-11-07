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
    $config['archivePaths'] = $options['archivePaths'] ?? [];
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
            $stage = [
                'info@empirejiujitsu.com' => 'primary',
                'scovelbjj@gmail.com' => 'backup',
                'eriktjacobsen@gmail.com' => 'test',
                'visitor@example.invalid' => 'autoresponse',
            ][$to] ?? 'unexpected_recipient';
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
        check(strpos($encoded, $private) === false, 'Private data entered the PHP error log');
    }
    check($body['request_id'] === 'request-123', 'Missing correlation ID');
    return [$status, $body, array_column($logs, 0), $calls, $logs];
}

[$code, $body, $logs, $calls] = scenario();
check($code === 200 && $body['success'] === true, 'Successful submission failed');
check($calls === ['verify', 'primary', 'backup', 'test', 'autoresponse', 'subscribe'], 'Expected all three recipients before autoresponse and subscription');
check($logs === ['primary_mail_accepted', 'backup_mail_accepted', 'test_mail_accepted'], 'Missing recipient acceptance logs');

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
    check($code === 200 && $body['success'], 'Backup acceptance did not prevent a duplicate retry');
    check($logs === ['primary_mail_failed', 'backup_mail_accepted', 'test_mail_accepted'], 'Primary failure or backup acceptance was not logged');
    check($calls === ['verify', 'primary', 'backup', 'test', 'autoresponse', 'subscribe'], 'Primary failure prevented another send');
}
[$code, $body, $logs] = scenario(['backupThrows' => true, 'test' => false]);
check($code === 200 && $body['success'], 'Copy failures overrode primary acceptance');
check($logs === ['primary_mail_accepted', 'backup_mail_failed', 'test_mail_failed'], 'Copy failures were not logged');

[$code, $body, $logs, $calls] = scenario(['primary' => false, 'backup' => false]);
check($code === 502 && !$body['success'], 'Test acceptance alone was reported as success');
check($logs === ['primary_mail_failed', 'backup_mail_failed', 'test_mail_accepted'], 'Missing logs when both gym sends failed');
check($calls === ['verify', 'primary', 'backup', 'test'], 'Both gym sends failed but autoresponse or subscription still ran');
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

$httpdArchive = tempnam(sys_get_temp_dir(), 'contact-httpd-');
$siteArchive = tempnam(sys_get_temp_dir(), 'contact-site-');
try {
    $message = "First line\nSecond line | Mail to info: Success";
    $options = [
        'input' => ['name' => 'Example User', 'email' => 'visitor@example.invalid', 'phone' => '123',
            'message' => $message, 'g-recaptcha-response' => 'private-token'],
        'archivePaths' => ['httpd' => $httpdArchive, 'site' => $siteArchive],
        'primary' => false, 'backup' => false, 'test' => false,
    ];
    [$code] = scenario($options);
    $lines = file($siteArchive, FILE_IGNORE_NEW_LINES);
    check($code === 502 && count($lines) === 1, 'All mail sends failed without a recoverable archive record');
    check(file($httpdArchive, FILE_IGNORE_NEW_LINES) === $lines, 'The archives did not receive identical records');
    $record = json_decode($lines[0], true);
    check($record['name'] === 'Example User' && $record['email'] === 'visitor@example.invalid'
        && $record['phone'] === '123' && $record['message'] === $message, 'The archive lost contact details or message formatting');
    check($record['request_id'] === 'request-123' && $record['mail'] === ['primary' => 'failed', 'backup' => 'failed', 'test' => 'failed'],
        'The archive is missing the request ID or mail outcomes');
    check(strpos($lines[0], 'private-token') === false && strpos($lines[0], 'private-secret') === false, 'The archive includes verification secrets');

    $options['archivePaths']['httpd'] = $httpdArchive . '/unwritable.log';
    $options['primary'] = true;
    set_error_handler(function () { throw new ErrorException('Simulated endpoint warning handler'); });
    try {
        [$code, $body, $logs, $calls, $events] = scenario($options);
    } finally {
        restore_error_handler();
    }
    check($code === 200 && $body['success'], 'An archive write failure overrode mail acceptance');
    check(in_array(['contact_archive_write_failed', 'request-123', ['destination' => 'httpd']], $events, true), 'The failed archive destination was not logged');
    $updatedLines = file($siteArchive, FILE_IGNORE_NEW_LINES);
    check(count($updatedLines) === 2 && $updatedLines[0] === $lines[0], 'An archive failure prevented appending to the other file');
} finally {
    unlink($httpdArchive);
    unlink($siteArchive);
}

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
