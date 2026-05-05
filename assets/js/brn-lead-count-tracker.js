(function () {
    'use strict';

    if (typeof brnLeadCountData === 'undefined' || !brnLeadCountData.ajaxUrl || !brnLeadCountData.nonce) {
        return;
    }

    var endpoint = brnLeadCountData.ajaxUrl;
    var nonce = brnLeadCountData.nonce;
    var recentLeadKeys = {};
    var pendingImages = [];

    function shouldSkipDuplicate(leadType, label) {
        var key = leadType + '|' + (label || '');
        var now = Date.now();

        if (recentLeadKeys[key] && now - recentLeadKeys[key] < 1500) {
            return true;
        }

        recentLeadKeys[key] = now;
        return false;
    }

    function releasePendingImage(image) {
        var index = pendingImages.indexOf(image);
        if (index !== -1) {
            pendingImages.splice(index, 1);
        }
    }

    function getEventTarget(event) {
        var target = event.target;

        if (target && target.nodeType === 3) {
            target = target.parentNode;
        }

        if (!target || typeof target.closest !== 'function') {
            return null;
        }

        return target;
    }

    function sendLead(leadType, label, options) {
        options = options || {};

        if (shouldSkipDuplicate(leadType, label)) {
            return;
        }

        var data = new URLSearchParams();
        data.append('action', 'brn_lead_count_track');
        data.append('nonce', nonce);
        data.append('lead_type', leadType);
        data.append('label', label || '');
        data.append('url', window.location.href);
        data.append('page_title', document.title || '');

        if (options.allowGetFallback) {
            var img = new Image();
            pendingImages.push(img);
            img.onload = function () {
                releasePendingImage(img);
            };
            img.onerror = function () {
                releasePendingImage(img);
            };
            img.src = endpoint + '?' + data.toString() + '&transport=get&_=' + Date.now();
        }

        if (navigator.sendBeacon && options.useBeacon !== false) {
            var blob = new Blob([data.toString()], {
                type: 'application/x-www-form-urlencoded; charset=UTF-8'
            });
            if (navigator.sendBeacon(endpoint, blob)) {
                return;
            }
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

    function handleTrackedLink(event) {
        var target = getEventTarget(event);
        if (!target) {
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

        var normalized = href.toLowerCase();

        if (normalized.indexOf('tel:') === 0) {
            sendLead('phone', getTextContent(link), { allowGetFallback: true });
            return;
        }

        if (normalized.indexOf('mailto:') === 0) {
            sendLead('email', getTextContent(link), { allowGetFallback: true });
            return;
        }

        var isWhatsapp = normalized.indexOf('whatsapp://') === 0 ||
            normalized.indexOf('https://wa.me/') === 0 ||
            normalized.indexOf('http://wa.me/') === 0 ||
            normalized.indexOf('https://api.whatsapp.com/') === 0 ||
            normalized.indexOf('http://api.whatsapp.com/') === 0 ||
            normalized.indexOf('whatsapp.com/') > -1;

        if (isWhatsapp) {
            sendLead('whatsapp', getTextContent(link), { allowGetFallback: true });
        }
    }

    document.addEventListener('pointerdown', handleTrackedLink, true);
    document.addEventListener('touchstart', handleTrackedLink, true);
    document.addEventListener('click', handleTrackedLink, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }

        // Skip Elementor forms — they submit via AJAX and are handled separately below.
        if (form.classList.contains('elementor-form')) {
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

    // Elementor Pro forms: submitted and validated via AJAX.
    // Elementor fires 'submit_success' on the form element after a successful server response.
    document.addEventListener('submit_success', function (event) {
        var form = event.target;
        if (!form) {
            return;
        }

        var id = (form.getAttribute('id') || '').trim();
        var formName = '';
        var nameEl = form.querySelector('[name="form_name"]');
        if (nameEl) {
            formName = (nameEl.value || '').trim();
        }

        var labelParts = ['elementor'];
        if (formName) {
            labelParts.push(formName);
        }
        if (id) {
            labelParts.push('id:' + id);
        }

        sendLead('form_submit', labelParts.join(' | '));
    }, true);

    // Elementor also triggers a jQuery event on window: elementor/forms/submit_success
    if (typeof window.jQuery !== 'undefined') {
        window.jQuery(document).on('submit_success.elementor-forms', function (event, response) {
            var formName = '';
            try {
                if (response && response.data && response.data.form_name) {
                    formName = String(response.data.form_name);
                }
            } catch (e) {}

            var labelParts = ['elementor'];
            if (formName) {
                labelParts.push(formName);
            }

            sendLead('form_submit', labelParts.join(' | '));
        });
    }
})();
