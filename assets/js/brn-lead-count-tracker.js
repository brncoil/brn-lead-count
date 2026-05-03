(function () {
    'use strict';

    if (typeof brnLeadCountData === 'undefined' || !brnLeadCountData.ajaxUrl || !brnLeadCountData.nonce) {
        return;
    }

    var endpoint = brnLeadCountData.ajaxUrl;
    var nonce = brnLeadCountData.nonce;
    var recentLeadKeys = Object.create(null);
    var elementorHooksBound = false;

    function shouldSkipDuplicate(leadType, label) {
        var key = leadType + '|' + (label || '');
        var now = Date.now();
        var lastSeen = recentLeadKeys[key] || 0;

        if (now - lastSeen < 3000) {
            return true;
        }

        recentLeadKeys[key] = now;
        return false;
    }

    function trackGa4Event(leadType, label) {
        var eventName = 'brn_lead_' + leadType;
        var params = {
            lead_type: leadType,
            lead_label: (label || '').substring(0, 180),
            page_location: window.location.href
        };

        if (typeof window.gtag === 'function') {
            window.gtag('event', eventName, params);
            return;
        }

        if (Array.isArray(window.dataLayer)) {
            window.dataLayer.push({
                event: eventName,
                lead_type: params.lead_type,
                lead_label: params.lead_label,
                page_location: params.page_location
            });
        }
    }

    function sendLead(leadType, label) {
        if (shouldSkipDuplicate(leadType, label)) {
            return;
        }

        trackGa4Event(leadType, label);

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

    function buildFormLabel(form, prefix) {
        var action = (form.getAttribute('action') || '').trim();
        var id = (form.getAttribute('id') || '').trim();
        var name = (form.getAttribute('name') || '').trim();

        var labelParts = [];
        if (prefix) {
            labelParts.push(prefix);
        }
        if (id) {
            labelParts.push('id:' + id);
        }
        if (name) {
            labelParts.push('name:' + name);
        }
        if (action) {
            labelParts.push('action:' + action.substring(0, 120));
        }

        return labelParts.join(' | ');
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

        // Elementor forms: capture submit as fallback in case success hooks are unavailable.
        if (form.classList.contains('elementor-form')) {
            sendLead('form_submit', buildFormLabel(form, 'elementor:submit'));
            return;
        }

        sendLead('form_submit', buildFormLabel(form, 'form:submit'));
    }, true);

    function bindElementorHooks() {
        if (elementorHooksBound || typeof window.jQuery === 'undefined') {
            return;
        }

        elementorHooksBound = true;

        window.jQuery(document).on('submit_success', function (event, response) {
            var form = event && event.target ? event.target : null;
            var formId = '';
            var formName = '';

            if (response && response.data) {
                if (response.data.form_id) {
                    formId = String(response.data.form_id);
                }
                if (response.data.form_name) {
                    formName = String(response.data.form_name);
                }
            }

            if (form && form.getAttribute) {
                formId = formId || (form.getAttribute('id') || '').trim();
                formName = formName || (form.getAttribute('name') || '').trim();
            }

            var labelParts = ['elementor:success'];
            if (formName) {
                labelParts.push('name:' + formName);
            }
            if (formId) {
                labelParts.push('id:' + formId);
            }

            sendLead('form_submit', labelParts.join(' | '));
        });

        // Some Elementor setups emit response events via ajaxComplete; detect successful form responses.
        window.jQuery(document).ajaxComplete(function (event, xhr, settings) {
            if (!settings || !settings.url || settings.url.indexOf('admin-ajax.php') === -1) {
                return;
            }

            var data = settings.data || '';
            if (typeof data !== 'string' || data.indexOf('action=elementor_pro_forms_send_form') === -1) {
                return;
            }

            var responseText = xhr && typeof xhr.responseText === 'string' ? xhr.responseText : '';
            if (!responseText || responseText.indexOf('"success":true') === -1) {
                return;
            }

            sendLead('form_submit', 'elementor:ajax_success');
        });
    }

    bindElementorHooks();

    document.addEventListener('DOMContentLoaded', function () {
        bindElementorHooks();
    });
})();
