(function () {
    'use strict';

    if (typeof brnLeadCountData === 'undefined' || !brnLeadCountData.restUrl || !brnLeadCountData.nonce) {
        return;
    }

    var endpoint = brnLeadCountData.restUrl;
    var nonce    = brnLeadCountData.nonce;

    // Deduplicate: ignore a second event for the same lead within 2 seconds.
    var recentLeadKeys = {};

    function shouldSkipDuplicate(leadType, label) {
        var key = leadType + '|' + (label || '');
        var now = Date.now();

        if (recentLeadKeys[key] && now - recentLeadKeys[key] < 2000) {
            return true;
        }

        recentLeadKeys[key] = now;
        return false;
    }

    // Build URL-encoded body without relying on URLSearchParams (broadest compatibility).
    function buildBody(leadType, label) {
        var fields = {
            nonce:      nonce,
            lead_type:  leadType,
            label:      label || '',
            url:        window.location.href,
            page_title: document.title || ''
        };

        var parts = [];
        for (var k in fields) {
            if (Object.prototype.hasOwnProperty.call(fields, k)) {
                parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]));
            }
        }

        return parts.join('&');
    }

    function sendLead(leadType, label) {
        if (shouldSkipDuplicate(leadType, label)) {
            return;
        }

        var body = buildBody(leadType, label);

        // 1. sendBeacon: designed to survive page navigation (dialer open, WhatsApp handoff).
        if (typeof navigator.sendBeacon === 'function') {
            try {
                var blob = new Blob([body], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
                if (navigator.sendBeacon(endpoint, blob)) {
                    return;
                }
            } catch (e) {}
        }

        // 2. fetch with keepalive: works on modern browsers, survives short unloads.
        if (typeof fetch === 'function') {
            try {
                fetch(endpoint, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body:    body,
                    keepalive: true
                }).catch(function () {});
                return;
            } catch (e) {}
        }

        // 3. XHR: universal fallback.
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', endpoint, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.send(body);
        } catch (e) {}
    }

    // -----------------------------------------------------------------------
    // Link clicks: tel:, mailto:, WhatsApp
    // Only 'click' is used — pointerdown/touchstart also fire during scrolling,
    // which causes false leads. 'click' fires only on a confirmed tap.
    // -----------------------------------------------------------------------

    function getTextContent(el) {
        var text = (el.getAttribute('aria-label') || el.textContent || '').trim();
        if (!text) {
            text = el.getAttribute('href') || '';
        }
        return text.substring(0, 180);
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target) {
            return;
        }

        // Walk up from text nodes.
        if (target.nodeType === 3) {
            target = target.parentNode;
        }

        if (!target || typeof target.closest !== 'function') {
            return;
        }

        var link = target.closest('a[href]');
        if (!link) {
            return;
        }

        var href = (link.getAttribute('href') || '').trim();
        if (!href) {
            return;
        }

        var lower = href.toLowerCase();

        if (lower.indexOf('tel:') === 0) {
            sendLead('phone', getTextContent(link));
            return;
        }

        if (lower.indexOf('mailto:') === 0) {
            sendLead('email', getTextContent(link));
            return;
        }

        var isWhatsapp =
            lower.indexOf('whatsapp://') === 0 ||
            lower.indexOf('https://wa.me/') === 0 ||
            lower.indexOf('http://wa.me/') === 0 ||
            lower.indexOf('https://api.whatsapp.com/') === 0 ||
            lower.indexOf('http://api.whatsapp.com/') === 0 ||
            lower.indexOf('whatsapp.com/') > -1;

        if (isWhatsapp) {
            sendLead('whatsapp', getTextContent(link));
        }
    }, true);

    // -----------------------------------------------------------------------
    // Native form submissions (not Elementor).
    // -----------------------------------------------------------------------

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }

        // Skip Elementor forms — handled separately below.
        if (form.classList && form.classList.contains('elementor-form')) {
            return;
        }

        var parts  = [];
        var id     = (form.getAttribute('id') || '').trim();
        var name   = (form.getAttribute('name') || '').trim();
        var action = (form.getAttribute('action') || '').trim();

        if (id)     { parts.push('id:' + id); }
        if (name)   { parts.push('name:' + name); }
        if (action) { parts.push('action:' + action.substring(0, 80)); }

        sendLead('form_submit', parts.join(' | '));
    }, true);

    // -----------------------------------------------------------------------
    // Elementor Pro forms — submit via AJAX, no native submit event fires.
    // Elementor dispatches a custom 'submit_success' event on the form element.
    // -----------------------------------------------------------------------

    document.addEventListener('submit_success', function (event) {
        var form = event.target;
        if (!form) {
            return;
        }

        var parts    = ['elementor'];
        var nameEl   = form.querySelector('[name="form_name"]');
        var formName = nameEl ? (nameEl.value || '').trim() : '';
        var id       = (form.getAttribute('id') || '').trim();

        if (formName) { parts.push(formName); }
        if (id)       { parts.push('id:' + id); }

        sendLead('form_submit', parts.join(' | '));
    }, true);

    // jQuery trigger fallback (older Elementor versions).
    if (typeof window.jQuery !== 'undefined') {
        window.jQuery(document).on('submit_success.elementor-forms', function (event, response) {
            var formName = '';
            try {
                if (response && response.data && response.data.form_name) {
                    formName = String(response.data.form_name);
                }
            } catch (e) {}

            var parts = ['elementor'];
            if (formName) { parts.push(formName); }
            sendLead('form_submit', parts.join(' | '));
        });
    }

})();
