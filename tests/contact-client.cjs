const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../js/contact_me.js'), 'utf8');

function setup(fetcher, ready = true) {
    const handlers = {};
    const scripts = [];
    const button = {disabled: false};
    const status = {children: [], replaceChildren(...children) { this.children = children; }};
    let resets = 0, captchaResets = 0, requests = 0, observer;
    const form = {
        action: 'https://example.invalid/mail/contact_me.php',
        querySelector(selector) { return selector.startsWith('button') ? button : {dataset: {sitekey: 'public-key', theme: 'dark'}}; },
        addEventListener(name, callback) { handlers[name] = callback; },
        reportValidity() { return true; },
        setAttribute() {}, removeAttribute() {},
        reset() { resets++; }
    };
    const document = {
        getElementById(id) { return id === 'contactForm' ? form : status; },
        createElement() {
            return {set innerHTML(value) { throw new Error('HTML sink used'); }};
        },
        head: {appendChild(script) { scripts.push(script); }}
    };
    const window = {
        IntersectionObserver: true,
        grecaptcha: {render() { return 0; }, getResponse() { return 'local-token'; }, reset() { captchaResets++; }}
    };
    vm.runInNewContext(source, {document, window, URLSearchParams,
        FormData: class extends Map { constructor() { super([['name', '<img/src=x/onerror=alert(1)> Test'], ['message', 'Retain this message']]); } },
        IntersectionObserver: class {
            constructor(callback) { observer = callback; }
            observe() {} disconnect() {}
        },
        fetch: async (...args) => { requests++; return fetcher(...args); }
    });
    if (ready) {
        observer([{isIntersecting: true}]);
        window.empireRecaptchaReady();
    }
    return {submit: () => handlers.submit({preventDefault() {}}),
        focus: () => handlers.focusin(), scripts, button,
        text: () => status.children[0]?.textContent || '',
        resets: () => resets, captchaResets: () => captchaResets, requests: () => requests};
}

test('CAPTCHA is deferred and submission waits for the widget to render', async () => {
    const page = setup(() => { throw new Error('Must not send'); }, false);
    assert.equal(page.scripts.length, 0);
    await page.submit();
    assert.equal(page.scripts.length, 1);
    assert.equal(page.requests(), 0);
    assert.match(page.text(), /complete the verification/);
    page.focus();
    assert.equal(page.scripts.length, 1);
});

test('only explicit JSON success with a successful HTTP status clears the form', async () => {
    const page = setup(async (url, options) => {
        assert.equal(options.method, 'POST');
        assert.equal(options.body.get('g-recaptcha-response'), 'local-token');
        return {ok: true, json: async () => ({success: true})};
    });
    await page.submit();
    assert.equal(page.resets(), 1);
    assert.match(page.text(), /submitted/);
    assert.equal(page.captchaResets(), 1);
    assert.equal(page.button.disabled, false);
});

for (const [name, fetcher] of [
    ['HTTP 200 rejection', async () => ({ok: true, json: async () => ({success: false, message: 'Verification failed'})})],
    ['failed primary mail', async () => ({ok: false, json: async () => ({success: false, message: 'Mail was not accepted'})})],
    ['HTTP failure despite success field', async () => ({ok: false, json: async () => ({success: true})})],
    ['legacy text response', async () => ({ok: true, json: async () => { throw new SyntaxError('Not JSON'); }})],
    ['network failure', async () => { throw new Error('Offline'); }],
    ['HTML-shaped error text', async () => ({ok: false, json: async () => ({message: '<img src=x onerror=alert(1)>'})})]
]) {
    test(`${name} retains text, resets CAPTCHA, and re-enables submission`, async () => {
        const page = setup(fetcher);
        await page.submit();
        assert.equal(page.resets(), 0);
        assert.equal(page.captchaResets(), 1);
        assert.equal(page.button.disabled, false);
        assert(page.text().length > 0);
        if (name === 'HTML-shaped error text') assert.equal(page.text(), '<img src=x onerror=alert(1)>');
        if (name === 'network failure') assert.match(page.text(), /could not confirm/);
    });
}

test('double submission starts one request until the first completes', async () => {
    let resolve;
    const page = setup(() => new Promise(done => { resolve = done; }));
    const first = page.submit();
    await page.submit();
    assert.equal(page.requests(), 1);
    assert.equal(page.button.disabled, true);
    resolve({ok: true, json: async () => ({success: true})});
    await first;
    assert.equal(page.resets(), 1);
});
