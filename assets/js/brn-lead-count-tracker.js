(function () {
    'use strict';

    if (typeof brnLeadCountData === 'undefined' || !brnLeadCountData.ajaxUrl || !brnLeadCountData.nonce) {
        return;
    }

    var endpoint = brnLeadCountData.ajaxUrl;
    var nonce = brnLeadCountData.nonce;

    function sendLead(leadType, label) {
        var data = new URLSearchParams();
        data.append('action', 'brn_lead_count_track');
        data.append('nonce', nonce);
        data.append('lead_type', leadType);
        data.append('label', label || '');
        data.append('url', window.location.href);

        if (navigator.sendBeacon) {
            var blob = new Blob([data.toString()], {
                type: 'application/x-www-form-urlencoded; charset=UTF-8'
            });
            navigator.sendBeacon(endpoint, blob);
            return;
        }

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: data.toString(),
            keepalive: true
        }).catch(function () {
            // Ignore network failures to avoid blocking user actions.
        });
    }

    function getTextContent(el) {
        if (!el) {
            return '';
        }

        var text = (el.getAttribute('aria-label') || el.textContent || '').trim();
        if (!text) {
            text = el.getAttribute('href') || '';
        }

        return text.substring(0, 180);
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link) {
            return;
        }

        var href = (link.getAttribute('href') || '').trim();
        if (!href) {
            return;
        }

        var normalized = href.toLowerCase();

        if (normalized.indexOf('tel:') === 0) {
            sendLead('phone', getTextContent(link));
            return;
        }

        if (normalized.indexOf('mailto:') === 0) {
            sendLead('email', getTextContent(link));
            return;
        }

        var isWhatsapp = normalized.indexOf('whatsapp://') === 0 ||
            normalized.indexOf('https://wa.me/') === 0 ||
            normalized.indexOf('http://wa.me/') === 0 ||
            normalized.indexOf('https://api.whatsapp.com/') === 0 ||
            normalized.indexOf('http://api.whatsapp.com/') === 0 ||
            normalized.indexOf('whatsapp.com/') > -1;

        if (isWhatsapp) {
            sendLead('whatsapp', getTextContent(link));
        }
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }

        var action = (form.getAttribute('action') || '').trim();
        var id = (form.getAttribute('id') || '').trim();
        var name = (form.getAttribute('name') || '').trim();

        var labelParts = [];
        if (id) {
            labelParts.push('id:' + id);
        }
        if (name) {
            labelParts.push('name:' + name);
        }
        if (action) {
            labelParts.push('action:' + action.substring(0, 120));
        }

        sendLead('form_submit', labelParts.join(' | '));
    }, true);
})();
