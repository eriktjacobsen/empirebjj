# EmpireBJJ

Jekyll site with a PHP contact endpoint. Build with a supported Ruby (3.3 or newer recommended), Bundler 2.5+, and `bundle install`, then `bundle exec jekyll build`. Local preview: `bundle exec jekyll serve`. Jekyll's development server does not execute PHP; use a PHP-capable host to exercise the contact endpoint.

Deploy the contents of `_site`, including its generated `.htaccess`, to the existing PHP-capable hosting setup. The custom Liquid plugins must run during the build. The Apache rules require `mod_headers` and `mod_deflate`; configure equivalent caching/compression rules if another server or CDN serves the site. HTML revalidates using ETag/Last-Modified; CSS, scripts and images use content-versioned URLs. The original Bootstrap **CSS** remains for the existing design; the old Bootstrap and jQuery JavaScript and their plugins have been removed in favor of native navigation and form handling.

## Production credentials

Secrets are intentionally absent from this public repository. Preserve the existing private production credential assignments when deploying the updated `mail/contact_me.php`. The endpoint also accepts these optional environment variables, which take precedence over private assignments:

- `RECAPTCHA_SECRET`
- `MAILCHIMP_API_KEY`
- `MAILCHIMP_LIST_ID`

No real values belong in Git, the generated static files, browser JavaScript, or logs. If Mailchimp credentials are both absent, only the newsletter integration is skipped. Partial Mailchimp configuration is logged. This update retains the existing autoresponse and subscription behavior.

Use a supported PHP release (8.3+ recommended) with cURL, its HTTPS support, a current CA certificate bundle, and the existing working `mail()` transport. TLS peer and hostname verification remain enabled. reCAPTCHA has a 3-second connection and 8-second total timeout; Mailchimp has a 3-second connection and 5-second total timeout. Field validation runs before external requests.

## Contact failures and logs

The endpoint returns JSON with `success`, `message`, and a random `request_id`. The ID also appears in `X-Request-ID`. HTTP statuses are 200 when the primary or backup mail is accepted, 422 for invalid input/CAPTCHA, 405 for an unsupported method, 502 when neither gym send is accepted, and 503 for verification/configuration/unexpected service failures. Responses are not cached.

Failed requests retain the visitor's text, reset CAPTCHA, and allow a retry. A network interruption displays that acceptance could not be confirmed, because the server may have sent the email before the response was lost. Multiple simultaneous clicks start only one browser request.

Structured JSON events go to the host's configured PHP `error_log`, tagged with `component: contact_form` and a request ID. Recipient events are `primary_mail_accepted`, `primary_mail_failed`, `backup_mail_accepted`, `backup_mail_failed`, `test_mail_accepted`, and `test_mail_failed`. Other events include `invalid_input`, `invalid_method`, `captcha_configuration_failed`, `captcha_rejected`, `captcha_service_failed`, `contact_archive_write_failed`, `autoresponse_failed`, `subscription_configuration_failed`, `subscription_failed`, and `handler_failed`. Mailchimp failures include numeric HTTP/transport codes. PHP error-log events omit contact details, CAPTCHA tokens, credentials, exception text, and external response bodies.

Verified submissions are also appended to `/var/log/httpd/empire_contact.log` and `/var/www/empirebjj/raw_contacts.log`, even if all three mail sends fail. Each new line is a JSON record containing the timestamp, request ID, name, email, phone, full message, and the three send outcomes. Existing log entries stay in their original format. Keep the files writable by the PHP service account with restricted read permissions. The site copy lives beside `_site`, and Jekyll excludes it from the build. A failed append logs `contact_archive_write_failed` with the destination label, and the other archive still gets a write attempt.

The endpoint attempts all three sends independently. Acceptance by the mail transport for either gym recipient determines success. If only the test copy is accepted, the endpoint returns an error and skips the autoresponse and subscription. After gym acceptance, autoresponse and Mailchimp failures are logged without asking the visitor to resend. Check the mail transport or provider logs for final delivery and bounces. Verify the PHP error log destination and permissions on deployment. The local tests below use fake transports.

Deploy the new JavaScript and PHP endpoint together: the browser now requires the JSON success response. During the first rollout, an already cached old page may still use the previous client until its cache expires; invalidate the old script cache if the hosting platform supports it.

## Verification

Run:

```text
node --test tests/contact-client.cjs
php tests/contact-handler.php
bundle exec jekyll build
bundle exec bundler-audit check --update
```

The contact tests fake all CAPTCHA, email and subscription services. They verify successful acceptance, validation rejection, transport errors, secondary-service failures, duplicate-click suppression, retained inputs, safe text rendering, and privacy of logs without contacting external services. `tests/` is excluded from the generated site.

The production reCAPTCHA site key is intentionally restricted to its supported domains, so localhost cannot complete a real challenge with it. The existing ZenPlanner calendar refused to display in the local browser preview. Its provider URL has been retained; verify embedding on the real domain during rollout.
