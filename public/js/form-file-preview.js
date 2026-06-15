/**
 * Preview uploaded images/PDFs before form submission.
 */
(function () {
    'use strict';

    function isPreviewable(file) {
        return file && (file.type.startsWith('image/') || file.type === 'application/pdf');
    }

    function renderPreview(container, file) {
        if (!container || !file || !isPreviewable(file)) {
            if (container) container.classList.add('hidden');
            return;
        }

        container.classList.remove('hidden');
        container.innerHTML = '';

        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.className = 'max-h-48 rounded-lg border border-base-content/10 mx-auto';
            img.alt = 'Upload preview';
            const reader = new FileReader();
            reader.onload = function (e) {
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
            container.appendChild(img);
            return;
        }

        if (file.type === 'application/pdf') {
            const frame = document.createElement('iframe');
            frame.className = 'w-full h-56 rounded-lg border border-base-content/10';
            frame.title = 'PDF preview';
            const reader = new FileReader();
            reader.onload = function (e) {
                frame.src = e.target.result;
            };
            reader.readAsDataURL(file);
            container.appendChild(frame);
        }
    }

    function bindInput(input) {
        if (!input || input.dataset.previewBound === 'true') return;

        const previewEl = document.querySelector('[data-file-preview="' + input.id + '"]');
        if (!previewEl) return;

        input.addEventListener('change', function () {
            const files = input.files;
            if (!files || files.length === 0) {
                previewEl.classList.add('hidden');
                previewEl.innerHTML = '';
                return;
            }

            if (input.multiple) {
                previewEl.classList.remove('hidden');
                previewEl.innerHTML = '';
                Array.from(files).forEach(function (file, index) {
                    const wrap = document.createElement('div');
                    wrap.className = 'rounded-lg border border-base-content/10 p-2';
                    wrap.innerHTML = '<p class="text-xs text-base-content/60 mb-2">' + (index + 1) + '. ' + file.name + '</p>';
                    const inner = document.createElement('div');
                    wrap.appendChild(inner);
                    previewEl.appendChild(wrap);
                    renderPreview(inner, file);
                });
                return;
            }

            renderPreview(previewEl, files[0]);
        });

        input.dataset.previewBound = 'true';
    }

    function initAll(root) {
        (root || document).querySelectorAll('[data-file-input][data-enable-preview="true"]').forEach(bindInput);
    }

    window.FormFilePreview = { initAll: initAll };

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);
    });
})();
