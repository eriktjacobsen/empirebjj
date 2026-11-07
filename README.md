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

The endpoint returns JSON with `success`, `message`, and a random `request_id`; the ID also appears in `X-Request-ID`. HTTP statuses are 200 for primary email acceptance, 422 for invalid input/CAPTCHA, 405 for an unsupported method, 502 for primary mail rejection, and 503 for verification/configuration/unexpected service failures. Responses are not cached.

Failed requests retain the visitor's text, reset CAPTCHA, and allow a retry. A network interruption displays that acceptance could not be confirmed, because the server may have sent the email before the response was lost. Multiple simultaneous clicks start only one browser request.

Structured JSON events go to the host's configured PHP `error_log`, tagged with `component: contact_form` and a request ID. Relevant events are `invalid_input`, `invalid_method`, `captcha_configuration_failed`, `captcha_rejected`, `captcha_service_failed`, `primary_mail_failed`, `primary_mail_accepted`, `autoresponse_failed`, `subscription_configuration_failed`, `subscription_failed`, and `handler_failed`. Mailchimp failures include numeric HTTP/transport codes. Messages, names, addresses, phone numbers, CAPTCHA tokens, credentials, exception text, and external response bodies are not logged. Keep PHP logs outside the public document root, rotate them, and route failure events into the hosting platform's existing monitoring.

Primary email acceptance determines success. Autoresponse and Mailchimp failures are logged but do not tell a visitor to resend an already accepted enquiry. `mail()` acceptance does **not** establish final delivery: delivery/bounce failures must also be monitored in the existing mail transport/provider logs. Verify the PHP error log destination and permissions on deployment. No production log access is needed for the local tests below.

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
