(function () {
    'use strict';

    var form = document.getElementById('contactForm');
    if (!form) return;
    var status = document.getElementById('success');
    var button = form.querySelector('button[type="submit"]');
    var widget = form.querySelector('.g-recaptcha');
    var pending = false;
    var captchaLoading = false;
    var captchaWidgetId = null;

    function showStatus(message, success) {
        var alert = document.createElement('div');
        alert.className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
        alert.textContent = message;
        status.replaceChildren(alert);
    }

    function loadCaptcha() {
        if (captchaLoading) return;
        captchaLoading = true;
        window.empireRecaptchaReady = function () {
            captchaWidgetId = window.grecaptcha.render(widget, {
                sitekey: widget.dataset.sitekey,
                theme: widget.dataset.theme
            });
        };
        var script = document.createElement('script');
        script.src = 'https://www.google.com/recaptcha/api.js?onload=empireRecaptchaReady&render=explicit';
        script.async = true;
        script.onerror = function () {
            captchaLoading = false;
            showStatus('Verification could not load. Please reload the page or contact us by phone.', false);
        };
        document.head.appendChild(script);
    }

    form.addEventListener('focusin', loadCaptcha, { once: true });
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            if (entries.some(function (entry) { return entry.isIntersecting; })) {
                loadCaptcha();
                observer.disconnect();
            }
        }, { rootMargin: '600px' });
        observer.observe(form);
    } else {
        loadCaptcha();
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (pending || !form.reportValidity()) return;
        if (captchaWidgetId === null || !window.grecaptcha || !window.grecaptcha.getResponse(captchaWidgetId)) {
            loadCaptcha();
            showStatus('Please complete the verification before sending your message.', false);
            return;
        }

        pending = true;
        button.disabled = true;
        button.textContent = 'Sending…';
        form.setAttribute('aria-busy', 'true');
        status.replaceChildren();
        try {
            var data = new URLSearchParams(new FormData(form));
            data.set('g-recaptcha-response', window.grecaptcha.getResponse(captchaWidgetId));
            var response = await fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: data
            });
            var result = await response.json();
            if (!response.ok || result.success !== true) {
                showStatus(result.message || 'Your message could not be sent. Please try again or contact us by phone.', false);
                return;
            }
            showStatus('Thank you! Your message has been submitted.', true);
            form.reset();
        } catch (error) {
            // A lost response does not establish whether the server sent the email.
            showStatus('We could not confirm your message was sent. Your text is still here. Please contact us by phone before trying again.', false);
        } finally {
            pending = false;
            button.disabled = false;
            button.textContent = 'Send Message';
            form.removeAttribute('aria-busy');
            if (captchaWidgetId !== null && window.grecaptcha && window.grecaptcha.reset) window.grecaptcha.reset(captchaWidgetId);
        }
    });
}());
