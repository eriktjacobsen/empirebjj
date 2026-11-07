(function () {
    'use strict';
    var nav = document.querySelector('.navbar-fixed-top');
    var toggle = document.querySelector('.navbar-toggle');
    var menu = document.querySelector('.navbar-collapse');
    if (!nav || !toggle || !menu) return;

    function closeMenu() {
        menu.classList.remove('in');
        toggle.setAttribute('aria-expanded', 'false');
    }
    toggle.addEventListener('click', function () {
        var expanded = menu.classList.toggle('in');
        toggle.setAttribute('aria-expanded', String(expanded));
    });
    menu.addEventListener('click', function (event) {
        if (event.target.closest('a')) closeMenu();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menu.classList.contains('in')) {
            closeMenu();
            toggle.focus();
        }
    });

    var links = Array.from(menu.querySelectorAll('a.page-scroll'));
    var sections = links.map(function (link) {
        return document.querySelector(link.getAttribute('href'));
    });
    var scheduled = false;
    function updateNavigation() {
        scheduled = false;
        nav.classList.toggle('navbar-shrink', window.scrollY >= 100);
        var current = -1;
        sections.forEach(function (section, index) {
            if (section && section.getBoundingClientRect().top <= 120) current = index;
        });
        links.forEach(function (link, index) {
            link.parentElement.classList.toggle('active', index === current);
            if (index === current) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }
    function scheduleUpdate() {
        if (!scheduled) {
            scheduled = true;
            window.requestAnimationFrame(updateNavigation);
        }
    }
    window.addEventListener('scroll', scheduleUpdate, { passive: true });
    window.addEventListener('resize', scheduleUpdate);
    updateNavigation();
}());
