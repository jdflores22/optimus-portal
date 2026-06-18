(function () {
    const FILTER_REGEX = {
        numeric: /[^0-9]/g,
        alpha: /[^A-Za-z]/g,
        alphanumeric: /[^A-Za-z0-9]/g,
    };

    function readMaxLength(input) {
        if (input.maxLength > 0) {
            return input.maxLength;
        }

        const block = input.closest('[data-field-block]');
        if (block?.dataset?.maxLength) {
            const parsed = parseInt(block.dataset.maxLength, 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
        }

        return null;
    }

    function sanitizeValue(value, restriction, maxLength) {
        let next = value;

        if (restriction && FILTER_REGEX[restriction]) {
            next = next.replace(FILTER_REGEX[restriction], '');
        }

        if (maxLength !== null && next.length > maxLength) {
            next = next.slice(0, maxLength);
        }

        return next;
    }

    function applyValue(input, restriction, maxLength) {
        const sanitized = sanitizeValue(input.value, restriction, maxLength);
        if (sanitized !== input.value) {
            input.value = sanitized;
        }
    }

    function bindInput(input) {
        if (!input || input.dataset.restrictionsBound === '1') {
            return;
        }

        const restriction = input.dataset.inputRestriction || '';
        const maxLength = readMaxLength(input);

        if (!restriction && maxLength === null) {
            return;
        }

        input.dataset.restrictionsBound = '1';

        input.addEventListener('input', function () {
            applyValue(input, restriction, maxLength);
        });

        input.addEventListener('paste', function (event) {
            event.preventDefault();
            const pasted = (event.clipboardData || window.clipboardData).getData('text') || '';
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? input.value.length;
            const merged = input.value.slice(0, start) + pasted + input.value.slice(end);
            input.value = sanitizeValue(merged, restriction, maxLength);
            const cursor = Math.min(sanitizeValue(merged, restriction, maxLength).length, start + pasted.length);
            if (typeof input.setSelectionRange === 'function') {
                input.setSelectionRange(cursor, cursor);
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        input.addEventListener('drop', function (event) {
            event.preventDefault();
        });
    }

    function init(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('input[type="text"], input[type="tel"], input[type="email"], input[type="url"], textarea').forEach(function (input) {
            const block = input.closest('[data-field-block]');
            if (!input.dataset.inputRestriction && block?.dataset?.inputRestriction) {
                input.dataset.inputRestriction = block.dataset.inputRestriction;
            }
            bindInput(input);
        });
    }

    window.FormFieldRestrictions = {
        init: init,
    };

    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });
})();
