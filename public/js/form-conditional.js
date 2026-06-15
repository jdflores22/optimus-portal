/**
 * Show/hide form fields based on showWhen rules from form builder.
 */
(function () {
    'use strict';

    function getFieldValue(form, fieldId) {
        const block = form.querySelector('[data-field-id="' + fieldId + '"]');
        if (!block) return '';

        const type = block.dataset.fieldType;
        if (type === 'checkbox' || type === 'toggle' || type === 'terms') {
            const cb = block.querySelector('input[type="checkbox"]');
            return cb && cb.checked ? (cb.value || '1') : '';
        }
        if (type === 'multi_select') {
            const select = block.querySelector('select[multiple]');
            if (!select) return '';
            return Array.from(select.selectedOptions).map(function (o) { return o.value; }).join(',');
        }
        if (type === 'radio') {
            const checked = block.querySelector('input[type="radio"]:checked');
            return checked ? checked.value : '';
        }
        if (type === 'address') {
            const region = block.querySelector('.address-region')?.value || '';
            const province = block.querySelector('.address-province')?.value || '';
            const city = block.querySelector('.address-city')?.value || '';
            const barangay = block.querySelector('.address-barangay')?.value || '';
            return region + '|' + province + '|' + city + '|' + barangay;
        }

        const input = block.querySelector('#field_' + fieldId) || block.querySelector('[name="' + fieldId + '"]');
        return input ? (input.value || '').trim() : '';
    }

    function evaluateRule(form, rule) {
        if (!rule || !rule.field) return true;
        const current = getFieldValue(form, rule.field);
        const expected = rule.value ?? '';
        const operator = rule.operator || 'equals';

        if (operator === 'equals') {
            return current === expected;
        }
        if (operator === 'not_equals') {
            return current !== expected;
        }
        if (operator === 'contains') {
            return current.indexOf(expected) !== -1;
        }
        return true;
    }

    function applyRules(form) {
        form.querySelectorAll('[data-field-block]').forEach(function (block) {
            let showWhen = null;
            try {
                showWhen = block.dataset.showWhen ? JSON.parse(block.dataset.showWhen) : null;
            } catch (e) {
                showWhen = null;
            }

            const visible = evaluateRule(form, showWhen);
            block.classList.toggle('hidden', !visible);
            block.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (!visible) {
                    el.dataset.conditionallyDisabled = 'true';
                    el.disabled = true;
                } else if (el.dataset.conditionallyDisabled === 'true') {
                    el.disabled = false;
                    delete el.dataset.conditionallyDisabled;
                }
            });
        });
        form.dispatchEvent(new CustomEvent('conditionalchange', { bubbles: true }));
    }

    function initForm(form) {
        if (!form || form.dataset.conditionalInitialized === 'true') return;
        const refresh = function () { applyRules(form); };
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        refresh();
        form.dataset.conditionalInitialized = 'true';
    }

    function initAll(root) {
        (root || document).querySelectorAll('form[data-dynamic-form="true"]').forEach(initForm);
    }

    window.FormConditional = { initAll: initAll, initForm: initForm };

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);
    });
})();
