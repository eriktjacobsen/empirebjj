<?php
// The handler receives its external services so failure paths can be tested
// without sending email or adding subscribers.
function contact_response($status, $success, $message, $requestId)
{
    return [$status, ['success' => $success, 'message' => $message, 'request_id' => $requestId]];
}

function contact_log($event, $requestId, array $details = [])
{
    // Only fixed event names, request IDs, and non-sensitive diagnostics belong
    // here. Submitted fields are written only to the contact archives.
    error_log(json_encode(array_merge([
        'component' => 'contact_form', 'event' => $event, 'request_id' => $requestId,
    ], $details), JSON_UNESCAPED_SLASHES));
}

function contact_archive(array $paths, array $record, callable $log)
{
    $line = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    foreach ($paths as $destination => $path) {
        try {
            $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $error) {
            $written = false;
        }
        if ($written !== strlen($line)) {
            $log('contact_archive_write_failed', ['destination' => $destination]);
        }
    }
}

function contact_handle($method, array $input, array $config, array $services, $requestId)
{
    $log = function ($event, array $details = []) use ($services, $requestId) {
        $services['log']($event, $requestId, $details);
    };
    if ($method !== 'POST') {
        $log('invalid_method');
        return contact_response(405, false, 'Please submit the contact form using the Send Message button.', $requestId);
    }

    $limits = ['name' => 200, 'email' => 254, 'phone' => 80, 'message' => 10000, 'g-recaptcha-response' => 4096];
    foreach ($limits as $field => $limit) {
        if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '' || strlen($input[$field]) > $limit) {
            $log('invalid_input');
            return contact_response(422, false, 'Please complete all fields and the verification, then try again.', $requestId);
        }
        $input[$field] = trim($input[$field]);
    }
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $input['name'])) {
        $log('invalid_input');
        return contact_response(422, false, 'Please enter a valid name and email address.', $requestId);
    }
    if (empty($config['secret'])) {
        $log('captcha_configuration_failed');
        return contact_response(503, false, 'The contact form is temporarily unavailable. Please contact us by phone.', $requestId);
    }

    try {
        $verification = $services['verify']($input['g-recaptcha-response'], $config['secret']);
    } catch (Throwable $error) {
        $verification = ['success' => false, 'unavailable' => true];
    }
    if (empty($verification['success'])) {
        $unavailable = !empty($verification['unavailable']);
        $log($unavailable ? 'captcha_service_failed' : 'captcha_rejected');
        return contact_response($unavailable ? 503 : 422, false,
            $unavailable ? 'Verification is temporarily unavailable. Please try again later.' : 'Verification did not pass. Please complete a new verification and try again.', $requestId);
    }

    $to = 'info@empirejiujitsu.com';
    $recipients = [
        'primary' => $to,
        'backup' => 'scovelbjj@gmail.com',
        'test' => 'eriktjacobsen@gmail.com',
    ];
    $subject = 'New message to EmpireBJJ[' . $input['name'] . ']';
    $body = "Here are the details:\n\nName: {$input['name']}\n\nEmail: {$input['email']}\n\nPhone: {$input['phone']}\n\nMessage:\n{$input['message']}";
    $headers = "From: noreply@empirejiujitsu.com\r\nReply-To: " . $input['email'];
    $gymAccepted = false;
    $mailResults = [];
    foreach ($recipients as $role => $recipient) {
        try {
            $accepted = $services['send']($recipient, $subject, $body, $headers);
        } catch (Throwable $error) {
            $accepted = false;
        }
        $mailResults[$role] = $accepted ? 'accepted' : 'failed';
        $log($role . '_mail_' . $mailResults[$role]);
        if ($accepted && $role !== 'test') {
            $gymAccepted = true;
        }
    }
    contact_archive($config['archivePaths'] ?? [], [
        'timestamp' => date(DATE_ATOM),
        'request_id' => $requestId,
        'name' => $input['name'],
        'email' => $input['email'],
        'phone' => $input['phone'],
        'message' => $input['message'],
        'mail' => $mailResults,
    ], $log);
    if (!$gymAccepted) {
        return contact_response(502, false, 'Your message could not be sent. Please try again or contact us by phone.', $requestId);
    }

    // A send accepted for either gym recipient is enough to avoid asking the
    // visitor to send the same enquiry again.
    try {
        $responded = $services['send']($input['email'], 'Thank you for contacting Empire Jiu Jitsu',
            "Thank you for your loyalty and support.\n\nWe are open for classes and registration, information at https://www.empirebjj.com/\n\n- Kurt, Jake, and Empire family",
            "From: <{$to}>\r\nReply-To: {$to}");
        if (!$responded) $log('autoresponse_failed');
    } catch (Throwable $error) {
        $log('autoresponse_failed');
    }

    if (!empty($config['apiKey']) || !empty($config['listID'])) {
        if (empty($config['apiKey']) || empty($config['listID'])) {
            $log('subscription_configuration_failed');
        } else {
            try {
                $result = $services['subscribe']($input['email'], $config['apiKey'], $config['listID']);
                if ($result['error_code'] !== 0 || $result['http_status'] < 200 || $result['http_status'] >= 300) {
                    $log('subscription_failed', ['http_status' => (int) $result['http_status'], 'transport_code' => (int) $result['error_code']]);
                }
            } catch (Throwable $error) {
                $log('subscription_failed');
            }
        }
    }
    return contact_response(200, true, 'Thank you! Your message has been submitted.', $requestId);
}

function contact_http($url, array $options)
{
    $handle = curl_init($url);
    curl_setopt_array($handle, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($handle);
    $result = ['body' => $body, 'http_status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'error_code' => curl_errno($handle)];
    // Releasing the handle closes it, including on PHP versions where the
    // legacy curl_close() no-op is deprecated.
    $handle = null;
    return $result;
}

function contact_verify($token, $secret)
{
    $result = contact_http('https://www.google.com/recaptcha/api/siteverify', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $data = is_string($result['body']) ? json_decode($result['body'], true) : null;
    $unavailable = $result['error_code'] !== 0 || $result['http_status'] !== 200 || !is_array($data) || !isset($data['success']);
    if (!$unavailable && array_intersect($data['error-codes'] ?? [], ['invalid-input-secret', 'missing-input-secret'])) {
        $unavailable = true;
    }
    return ['success' => !$unavailable && $data['success'] === true, 'unavailable' => $unavailable];
}

function contact_subscribe($email, $apiKey, $listID)
{
    // Restrict the hostname derived from server-side configuration.
    $dataCenter = substr(strrchr($apiKey, '-') ?: '', 1);
    if (!preg_match('/^us[0-9]+$/', $dataCenter)) throw new RuntimeException('Invalid Mailchimp configuration');
    $url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0/lists/' . rawurlencode($listID) . '/members/' . md5(strtolower($email));
    return contact_http($url, [
        CURLOPT_USERPWD => 'user:' . $apiKey,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_TIMEOUT => 5,
        CURLOPT_POSTFIELDS => json_encode(['email_address' => $email, 'status' => 'subscribed']),
    ]);
}
