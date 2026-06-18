/**
 * Document Template Builder — free-position canvas editor for certificate/document layouts.
 */
(function () {
    'use strict';

    const BLOCK_DEFAULTS = {
        header_banner: { content: 'Document Title', subtitle: 'Subtitle', style: { textAlign: 'center', backgroundColor: '#1e3a5f', color: '#ffffff', fontSize: 20, padding: 20, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 } },
        heading: { content: 'Section Heading', style: { fontSize: 16, fontWeight: 'bold', color: '#1e3a5f', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 } },
        text: { content: 'Enter your text here...', style: { fontSize: 12, padding: 0, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 } },
        placeholder: { placeholder: 'noa.number' },
        field_label: { label: 'Label', style: { fontSize: 11, fontWeight: 'bold', color: '#374151', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0 } },
        field_value: { placeholder: 'noa.number', style: { fontSize: 11, color: '#1e3a5f', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0 } },
        info_row: { label: 'Label', placeholder: 'noa.number' },
        divider: { dividerStyle: 'solid', height: 24, slashWeight: 'medium', slashCount: 5, style: { marginTop: 12, marginBottom: 12, padding: 0, marginLeft: 0, marginRight: 0, color: '#374151', borderColor: '#6366f1', borderWidth: 1 } },
        spacer: { height: 24 },
        table: { placeholder: 'containers.table', columns: ['Column 1', 'Column 2', 'Column 3'] },
        signature: { label: 'Authorized Signature', style: { textAlign: 'center', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 } },
        qr_code: { placeholder: 'document.verification_url', size: 80, position: { pinY: true } },
        border_box: { content: 'Certificate content area', style: { borderColor: '#1e3a5f', borderWidth: 2, backgroundColor: '#ffffff', color: '#1e3a5f', padding: 24, textAlign: 'center', marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 } },
        two_column: { leftContent: 'Left column', rightContent: 'Right column' },
        footer: { content: 'Footer text', style: { textAlign: 'center', fontSize: 10, color: '#666666', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 0, marginLeft: 0 }, position: { pinY: false } },
        logo: { config: { src: '', alt: 'Logo', width: 120 }, style: { textAlign: 'center', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 } },
        image: { config: { src: '', alt: 'Image', width: 120 }, style: { textAlign: 'center', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 } },
    };

    const BLOCK_LABELS = {
        header_banner: 'Header Banner', heading: 'Heading', text: 'Text Block',
        field_label: 'Field Label', field_value: 'Field Value', placeholder: 'Dynamic Field',
        info_row: 'Label + Value (combined)', divider: 'Divider', spacer: 'Spacer', table: 'Data Table',
        signature: 'Signature', qr_code: 'QR Code', border_box: 'Border Box', two_column: 'Two Columns',
        footer: 'Footer', logo: 'Logo', image: 'Image',
    };

    const ELEMENT_HEIGHTS = {
        header_banner: 64, heading: 36, text: 44, placeholder: 28, field_label: 24, field_value: 24, info_row: 28,
        divider: 20, spacer: 24, table: 130, signature: 64, qr_code: 96,
        border_box: 88, two_column: 64, footer: 36, logo: 56, image: 56,
    };

    let layout = { canvas: {}, elements: [] };
    let selectedId = null;
    let saveUrl = '';
    let uploadUrl = '';
    let previewUrl = '';
    let dragState = null;
    let resizeState = null;

    const SNAP_THRESHOLD = 10;
    const GUIDE_COLOR_IDLE = 'rgba(124, 58, 237, 0.7)';
    const GUIDE_COLOR_DRAG = 'rgba(124, 58, 237, 0.9)';
    const GUIDE_COLOR_SNAP = '#d946ef';

    const PAPER_SIZES = {
        A4: { width: 794, height: 1123, label: 'A4' },
        letter: { width: 816, height: 1056, label: 'Letter' },
        legal: { width: 816, height: 1344, label: 'Legal' },
    };

    function generateId() {
        return 'el_' + Math.random().toString(16).slice(2, 10);
    }

    function normalizeTableColumnWidths(el) {
        const cols = el.columns || [];
        const count = cols.length;
        if (count === 0) {
            el.columnWidths = [];
            return;
        }

        const raw = Array.isArray(el.columnWidths)
            ? el.columnWidths.map(w => parseFloat(w)).filter(w => Number.isFinite(w) && w > 0)
            : [];

        if (raw.length === count) {
            const sum = raw.reduce((total, width) => total + width, 0);
            if (sum > 0) {
                el.columnWidths = raw.map(width => Math.round((width / sum) * 1000) / 10);
                const remainder = Math.round((100 - el.columnWidths.reduce((total, width) => total + width, 0)) * 10) / 10;
                el.columnWidths[count - 1] = Math.round((el.columnWidths[count - 1] + remainder) * 10) / 10;
                return;
            }
        }

        const base = Math.floor((100 / count) * 10) / 10;
        el.columnWidths = cols.map((_, index) => (
            index === count - 1
                ? Math.round((100 - base * (count - 1)) * 10) / 10
                : base
        ));
    }

    function buildTableColgroupMarkup(el) {
        normalizeTableColumnWidths(el);
        const cols = el.columns || [];
        return cols.map((_, index) => `<col style="width:${el.columnWidths[index]}%;">`).join('');
    }

    function buildTableCellWidthStyle(el, index) {
        normalizeTableColumnWidths(el);
        const width = el.columnWidths[index] ?? (100 / Math.max((el.columns || []).length, 1));
        return `width:${width}%;max-width:${width}%;`;
    }

    function renderTableColumnResizerMarkup(el) {
        normalizeTableColumnWidths(el);
        const cols = el.columns || [];
        if (cols.length < 2) return '';

        let cumulative = 0;
        const handles = [];
        for (let index = 0; index < cols.length - 1; index++) {
            cumulative += el.columnWidths[index];
            handles.push(`<span class="dt-table-col-resize" data-col-index="${index}" style="left:${cumulative}%;" title="Drag to resize columns"></span>`);
        }

        return `<div class="dt-table-col-resizers" aria-hidden="true">${handles.join('')}</div>`;
    }

    function bindTableColumnResizers(item, el) {
        const wrap = item.querySelector('.dt-table-wrap');
        if (!wrap) return;

        wrap.querySelectorAll('.dt-table-col-resize').forEach(handle => {
            handle.addEventListener('mousedown', (event) => {
                event.stopPropagation();
                event.preventDefault();

                const colIndex = parseInt(handle.dataset.colIndex, 10);
                if (!Number.isFinite(colIndex)) return;

                const table = wrap.querySelector('table');
                if (!table) return;

                const tableWidth = table.getBoundingClientRect().width || 1;
                const startX = event.clientX;
                const startLeft = el.columnWidths[colIndex];
                const startRight = el.columnWidths[colIndex + 1];
                handle.classList.add('is-active');

                const onMove = (moveEvent) => {
                    const deltaPct = ((moveEvent.clientX - startX) / tableWidth) * 100;
                    let newLeft = startLeft + deltaPct;
                    let newRight = startRight - deltaPct;
                    const minWidth = 3;

                    if (newLeft < minWidth) {
                        newRight -= minWidth - newLeft;
                        newLeft = minWidth;
                    }
                    if (newRight < minWidth) {
                        newLeft -= minWidth - newRight;
                        newRight = minWidth;
                    }

                    el.columnWidths[colIndex] = Math.round(newLeft * 10) / 10;
                    el.columnWidths[colIndex + 1] = Math.round(newRight * 10) / 10;
                    updateElement(el);
                    renderProperties(el);
                };

                const onUp = () => {
                    handle.classList.remove('is-active');
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                };

                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });
    }

    function init() {
        const root = document.getElementById('doc-template-builder');
        if (!root) return;

        saveUrl = root.dataset.saveUrl;
        uploadUrl = root.dataset.uploadUrl;
        previewUrl = root.dataset.previewUrl;

        try {
            layout = JSON.parse(root.dataset.layout || '{}');
        } catch (e) {
            layout = { canvas: defaultCanvas(), elements: [] };
        }

        if (!layout.canvas) layout.canvas = defaultCanvas();
        if (!layout.elements) layout.elements = [];
        layout.elements.forEach(el => {
            if (el.type === 'table') normalizeTableColumnWidths(el);
        });

        if (!layout.canvas.paperSize) {
            layout.canvas.paperSize = root.dataset.paperSize || 'A4';
        }
        if (!layout.canvas.orientation) {
            layout.canvas.orientation = root.dataset.orientation || 'portrait';
        }
        if (!layout.canvas.margin) {
            layout.canvas.margin = { top: 48, right: 48, bottom: 48, left: 48 };
        }
        if (layout.canvas.showMarginGuides === undefined) {
            layout.canvas.showMarginGuides = true;
        }

        applyPaperDimensions();
        layout.elements.forEach(ensureElementPosition);
        ensureFreeLayout();
        applyCanvasSettings();
        resetGuideLines();
        renderCanvas();
        bindEvents();
        bindDocumentDrag();
    }

    function defaultCanvas() {
        return {
            width: 794, height: 1123, paperSize: 'A4', orientation: 'portrait',
            layoutMode: 'free',
            backgroundColor: '#ffffff',
            margin: { top: 48, right: 48, bottom: 48, left: 48 },
            borderColor: '#1e3a5f', showPageBorder: true, showMarginGuides: true,
        };
    }

    function getPaperDimensions(paperSize, orientation) {
        const base = PAPER_SIZES[paperSize] || PAPER_SIZES.A4;
        if (orientation === 'landscape') {
            return { width: base.height, height: base.width, label: base.label };
        }
        return { width: base.width, height: base.height, label: base.label };
    }

    function applyPaperDimensions() {
        const dims = getPaperDimensions(
            layout.canvas.paperSize || 'A4',
            layout.canvas.orientation || 'portrait'
        );
        layout.canvas.width = dims.width;
        layout.canvas.height = dims.height;

        const canvas = document.getElementById('dt-canvas');
        if (canvas) {
            canvas.style.maxWidth = dims.width + 'px';
            canvas.style.aspectRatio = dims.width + ' / ' + dims.height;
        }
    }

    function renderPaperGuides() {
        const guides = document.getElementById('dt-paper-guides');
        if (!guides) return;

        const show = layout.canvas.showMarginGuides !== false;
        guides.style.display = show ? 'block' : 'none';
        if (!show) return;

        const m = canvasMargins();
        const cw = canvasWidth();
        const ch = canvasHeight();

        const setZone = (id, styles) => {
            const el = document.getElementById(id);
            if (!el) return;
            Object.assign(el.style, styles);
        };

        setZone('dt-margin-zone-top', {
            left: '0', top: '0', width: '100%',
            height: (m.top / ch * 100) + '%',
        });
        setZone('dt-margin-zone-bottom', {
            left: '0', bottom: '0', width: '100%',
            height: (m.bottom / ch * 100) + '%',
        });
        setZone('dt-margin-zone-left', {
            left: '0', top: (m.top / ch * 100) + '%',
            width: (m.left / cw * 100) + '%',
            height: ((ch - m.top - m.bottom) / ch * 100) + '%',
        });
        setZone('dt-margin-zone-right', {
            right: '0', top: (m.top / ch * 100) + '%',
            width: (m.right / cw * 100) + '%',
            height: ((ch - m.top - m.bottom) / ch * 100) + '%',
        });

        const frame = document.getElementById('dt-margin-frame');
        if (frame) {
            frame.style.left = (m.left / cw * 100) + '%';
            frame.style.top = (m.top / ch * 100) + '%';
            frame.style.width = ((cw - m.left - m.right) / cw * 100) + '%';
            frame.style.height = ((ch - m.top - m.bottom) / ch * 100) + '%';
        }

        const label = document.getElementById('dt-paper-label');
        if (label) {
            const ps = layout.canvas.paperSize || 'A4';
            const ori = layout.canvas.orientation || 'portrait';
            const paperLabel = (PAPER_SIZES[ps] || PAPER_SIZES.A4).label;
            label.innerHTML = `${paperLabel} · ${ori}<br><span style="font-weight:500;opacity:.85">${cw}×${ch}px</span>`;
        }
    }

    function clampElementsToCanvas() {
        const cw = canvasWidth();
        const ch = canvasHeight();

        layout.elements.forEach(el => {
            ensureElementPosition(el);
            const w = el.position.width || contentWidth();
            el.position.width = Math.min(w, cw);
            el.position.x = Math.max(0, Math.min(el.position.x, cw - el.position.width));
            el.position.y = Math.max(0, Math.min(el.position.y, ch - 16));
        });
    }

    function syncCanvasSettingsFromLayout() {
        const paperSize = document.getElementById('dt-paper-size');
        const orientation = document.getElementById('dt-orientation');
        const marginTop = document.getElementById('dt-margin-top');
        const marginRight = document.getElementById('dt-margin-right');
        const marginBottom = document.getElementById('dt-margin-bottom');
        const marginLeft = document.getElementById('dt-margin-left');
        const showMarginGuides = document.getElementById('dt-show-margin-guides');
        const m = canvasMargins();

        if (paperSize) paperSize.value = layout.canvas.paperSize || 'A4';
        if (orientation) orientation.value = layout.canvas.orientation || 'portrait';
        if (marginTop) marginTop.value = m.top ?? 48;
        if (marginRight) marginRight.value = m.right ?? 48;
        if (marginBottom) marginBottom.value = m.bottom ?? 48;
        if (marginLeft) marginLeft.value = m.left ?? 48;
        if (showMarginGuides) showMarginGuides.checked = layout.canvas.showMarginGuides !== false;
    }

    function updateCanvasMarginsFromInputs() {
        if (!layout.canvas.margin) layout.canvas.margin = {};
        const read = (id) => parseInt(document.getElementById(id)?.value, 10);

        layout.canvas.margin.top = read('dt-margin-top') ?? 48;
        layout.canvas.margin.right = read('dt-margin-right') ?? 48;
        layout.canvas.margin.bottom = read('dt-margin-bottom') ?? 48;
        layout.canvas.margin.left = read('dt-margin-left') ?? 48;
    }

    function canvasWidth() {
        return layout.canvas.width || 794;
    }

    function canvasHeight() {
        return layout.canvas.height || 1123;
    }

    function contentWidth() {
        const m = layout.canvas.margin || { left: 48, right: 48 };
        return canvasWidth() - (m.left || 0) - (m.right || 0);
    }

    function canvasMargins() {
        return layout.canvas.margin || { top: 48, right: 48, bottom: 48, left: 48 };
    }

    function ensureFreeLayout() {
        layout.canvas.layoutMode = 'free';
        const sorted = [...layout.elements].sort((a, b) => (a.order || 0) - (b.order || 0));

        if (sorted.some(el => el.position)) {
            sorted.forEach(ensureElementPosition);
            layout.elements = sorted;
            return;
        }

        const m = canvasMargins();
        let y = m.top || 0;

        sorted.forEach(el => {
            ensureElementStyle(el);
            const height = estimateElementHeight(el);
            el.position = {
                x: m.left || 0,
                y: y,
                width: contentWidth(),
            };
            y += height + (parseInt(el.style?.marginBottom, 10) || 8) + (parseInt(el.style?.marginTop, 10) || 0);
        });

        layout.elements = sorted;
    }

    function ensureElementPosition(el) {
        if (!el.position) {
            const m = canvasMargins();
            el.position = { x: m.left || 0, y: m.top || 0, width: contentWidth() };
        } else {
            const m = canvasMargins();
            if (el.position.x === undefined) el.position.x = m.left || 0;
            if (el.position.y === undefined) el.position.y = m.top || 0;
            if (!el.position.width) el.position.width = contentWidth();
        }

        if (el.position.pinY === undefined) {
            el.position.pinY = el.type !== 'footer';
        }
    }

    function ensureElementStyle(el) {
        if (!el.style) {
            el.style = { padding: 0, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 };
        } else {
            ['padding', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft'].forEach(key => {
                if (el.style[key] === undefined) {
                    el.style[key] = key === 'marginBottom' ? 8 : 0;
                }
            });
        }
    }

    function estimateElementHeight(el) {
        if (el.type === 'spacer') return el.height || 24;
        if (el.type === 'divider' && ['outline', 'slash'].includes(el.dividerStyle)) {
            return el.height || (el.dividerStyle === 'slash' ? 24 : 14);
        }
        if (el.type === 'table') {
            const rowCount = Math.max((el.columns || []).length > 0 ? 3 : 1, 1);
            return 40 + rowCount * 30 + 12;
        }
        return ELEMENT_HEIGHTS[el.type] || 40;
    }

    function verticalGap() {
        return 4;
    }

    function estimatedBlockHeight(el) {
        const s = el.style || {};
        const padding = (parseInt(s.padding, 10) || 0) * 2;
        return estimateElementHeight(el) + padding + (parseInt(s.marginTop, 10) || 0) + (parseInt(s.marginBottom, 10) || 0) + verticalGap();
    }

    function applyVerticalStack() {
        const sorted = [...layout.elements].sort((a, b) => (a.order || 0) - (b.order || 0));
        let y = canvasMargins().top || 0;

        sorted.forEach(el => {
            ensureElementPosition(el);
            ensureElementStyle(el);
            el.position.y = Math.round(y);
            y += estimatedBlockHeight(el);
        });
    }

    function applyVerticalStackFromDom() {
        const sorted = [...layout.elements].sort((a, b) => (a.order || 0) - (b.order || 0));
        let y = canvasMargins().top || 0;

        sorted.forEach(el => {
            ensureElementPosition(el);
            const item = document.querySelector(`[data-element-id="${el.id}"]`);
            if (item) applyElementDomPosition(item, el);
            const height = measureElementContentHeight(el, item);
            el.position.y = Math.round(y);
            y += height + verticalGap();
        });
    }

    function measureElementContentHeight(el, itemEl) {
        const inner = document.getElementById('dt-canvas-inner');
        if (!inner) return estimatedBlockHeight(el);

        const item = itemEl || document.querySelector(`[data-element-id="${el.id}"]`);
        if (!item) return estimatedBlockHeight(el);

        const preview = item.querySelector('.dt-element-preview');
        if (!preview) return estimatedBlockHeight(el);

        const previewRect = preview.getBoundingClientRect();
        const innerRect = inner.getBoundingClientRect();
        const scaleY = canvasHeight() / innerRect.height;
        const s = el.style || {};
        const paddingY = (parseInt(s.padding, 10) || 0) * 2;

        return Math.max(16, previewRect.height * scaleY + paddingY);
    }

    function measureElementHeight(el) {
        return measureElementContentHeight(el);
    }

    function getNextElementY() {
        const m = canvasMargins();
        if (!layout.elements.length) return m.top || 0;

        return layout.elements.reduce((max, el) => {
            const y = el.position?.y || 0;
            const bottom = y + estimateElementHeight(el) + (parseInt(el.style?.marginBottom, 10) || 0);
            return Math.max(max, bottom);
        }, m.top || 0) + 12;
    }

    function bindEvents() {
        document.querySelectorAll('.block-palette-btn').forEach(btn => {
            btn.addEventListener('click', () => addElement(btn.dataset.blockType));
        });

        document.getElementById('dt-save-btn')?.addEventListener('click', saveLayout);
        document.getElementById('dt-preview-btn')?.addEventListener('click', openLivePdfPreview);

        document.getElementById('dt-show-border')?.addEventListener('change', function () {
            layout.canvas.showPageBorder = this.checked;
            applyCanvasSettings();
        });

        document.getElementById('dt-border-color')?.addEventListener('input', function () {
            layout.canvas.borderColor = this.value;
            applyCanvasSettings();
        });

        document.getElementById('dt-paper-size')?.addEventListener('change', function () {
            layout.canvas.paperSize = this.value;
            applyPaperDimensions();
            clampElementsToCanvas();
            applyCanvasSettings();
            renderCanvas();
        });

        document.getElementById('dt-orientation')?.addEventListener('change', function () {
            layout.canvas.orientation = this.value;
            applyPaperDimensions();
            clampElementsToCanvas();
            applyCanvasSettings();
            renderCanvas();
        });

        ['dt-margin-top', 'dt-margin-right', 'dt-margin-bottom', 'dt-margin-left'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', function () {
                updateCanvasMarginsFromInputs();
                renderPaperGuides();
            });
        });

        document.getElementById('dt-show-margin-guides')?.addEventListener('change', function () {
            layout.canvas.showMarginGuides = this.checked;
            renderPaperGuides();
        });

        document.querySelectorAll('.placeholder-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                navigator.clipboard?.writeText(chip.dataset.placeholder);
                showToast('Copied: ' + chip.dataset.placeholder);
            });
        });
    }

    function bindDocumentDrag() {
        document.addEventListener('mousemove', onDocumentMouseMove);
        document.addEventListener('mouseup', onDocumentMouseUp);
    }

    function applyCanvasSettings() {
        const canvas = document.getElementById('dt-canvas');
        const borderCheck = document.getElementById('dt-show-border');
        const borderColor = document.getElementById('dt-border-color');

        syncCanvasSettingsFromLayout();
        if (borderCheck) borderCheck.checked = layout.canvas.showPageBorder !== false;
        if (borderColor) borderColor.value = layout.canvas.borderColor || '#1e3a5f';

        if (canvas) {
            canvas.style.border = layout.canvas.showPageBorder !== false
                ? '2px solid ' + (layout.canvas.borderColor || '#1e3a5f')
                : '1px solid #e2e8f0';
        }

        renderPaperGuides();
    }

    function addElement(type) {
        const defaults = JSON.parse(JSON.stringify(BLOCK_DEFAULTS[type] || {}));
        const defaultPosition = defaults.position || {};
        delete defaults.position;

        const maxOrder = layout.elements.reduce((m, e) => Math.max(m, e.order || 0), 0);
        const m = canvasMargins();
        const element = {
            id: generateId(),
            type,
            order: maxOrder + 1,
            content: '',
            placeholder: '',
            label: '',
            style: { fontSize: 12, fontWeight: 'normal', textAlign: 'left', color: '#000000', padding: 0, marginTop: 0, marginRight: 0, marginBottom: 8, marginLeft: 0 },
            config: {},
            ...defaults,
            position: {
                x: defaultPosition.x ?? m.left ?? 0,
                y: defaultPosition.y ?? getNextElementY(),
                width: defaultPosition.width ?? (type === 'field_label' ? Math.round(contentWidth() * 0.36) : type === 'field_value' ? Math.round(contentWidth() * 0.6) : contentWidth()),
                pinY: defaultPosition.pinY ?? (type !== 'footer'),
            },
        };
        if (type === 'field_value' && defaultPosition.x === undefined) {
            element.position.x = (m.left || 0) + Math.round(contentWidth() * 0.38) + 12;
        }
        ensureElementStyle(element);
        ensureElementPosition(element);
        if (type === 'table') {
            normalizeTableColumnWidths(element);
        }
        layout.elements.push(element);
        selectedId = element.id;
        renderCanvas();
        renderProperties(element);
    }

    function removeElement(id) {
        layout.elements = layout.elements.filter(e => e.id !== id);
        if (selectedId === id) selectedId = null;
        renderCanvas();
        renderProperties(null);
    }

    function splitInfoRow(el) {
        const pos = el.position || {};
        const m = canvasMargins();
        const x = pos.x ?? m.left ?? 0;
        const y = pos.y ?? getNextElementY();
        const totalWidth = pos.width ?? contentWidth();
        const labelWidth = Math.round(totalWidth * 0.36);
        const gap = 12;
        const valueWidth = Math.max(120, totalWidth - labelWidth - gap);
        const valueX = x + labelWidth + gap;
        const maxOrder = layout.elements.reduce((max, item) => Math.max(max, item.order || 0), 0);
        const baseStyle = { ...(el.style || {}), marginBottom: 0 };

        const labelEl = {
            id: generateId(),
            type: 'field_label',
            order: maxOrder + 1,
            label: el.label || 'Label',
            content: '',
            placeholder: '',
            style: { ...baseStyle, fontWeight: 'bold', color: baseStyle.color || '#374151' },
            config: {},
            position: {
                x,
                y,
                width: labelWidth,
                pinY: pos.pinY !== false,
            },
        };

        const valueEl = {
            id: generateId(),
            type: 'field_value',
            order: maxOrder + 2,
            label: '',
            content: '',
            placeholder: el.placeholder || 'noa.number',
            style: { ...baseStyle, fontWeight: 'normal', color: '#1e3a5f' },
            config: {},
            position: {
                x: valueX,
                y,
                width: valueWidth,
                pinY: pos.pinY !== false,
            },
        };

        layout.elements = layout.elements.filter(item => item.id !== el.id);
        layout.elements.push(labelEl, valueEl);
        selectedId = labelEl.id;
        renderCanvas();
        renderProperties(labelEl);
        showToast('Split into separate Field Label and Field Value blocks', 'success');
    }

    function canvasCenter() {
        return { x: canvasWidth() / 2, y: canvasHeight() / 2 };
    }

    function getElementBlockHeight(el) {
        if (el.type === 'spacer') {
            return parseInt(el.height, 10) || 24;
        }
        if (el.type === 'qr_code') {
            return parseInt(el.size, 10) || 72;
        }
        if (el.position?.measuredHeight) {
            return parseInt(el.position.measuredHeight, 10);
        }

        return null;
    }

    function setElementBlockHeight(el, height) {
        const rounded = Math.round(height);
        if (el.type === 'spacer') {
            el.height = Math.max(4, rounded);
            return;
        }
        if (el.type === 'qr_code') {
            el.size = Math.max(40, Math.min(200, rounded));
            return;
        }
        if (!el.position) {
            el.position = {};
        }
        el.position.measuredHeight = Math.max(20, rounded);
    }

    function getResizeHandleMarkup() {
        return `<div class="dt-resize-handles" aria-hidden="true">
            <span class="dt-resize-handle dt-resize-w" data-dir="w" title="Resize width"></span>
            <span class="dt-resize-handle dt-resize-e" data-dir="e" title="Resize width"></span>
            <span class="dt-resize-handle dt-resize-s" data-dir="s" title="Resize height"></span>
            <span class="dt-resize-handle dt-resize-sw" data-dir="sw" title="Resize"></span>
            <span class="dt-resize-handle dt-resize-se" data-dir="se" title="Resize"></span>
        </div>`;
    }

    function bindResizeHandles(item, el) {
        item.querySelectorAll('.dt-resize-handle').forEach((handle) => {
            handle.addEventListener('mousedown', (e) => startResize(e, el, item, handle.dataset.dir || 'se'));
        });
    }

    function startResize(e, el, itemEl, direction) {
        if (e.button !== 0) return;

        const inner = document.getElementById('dt-canvas-inner');
        if (!inner) return;

        ensureElementPosition(el);
        const rect = inner.getBoundingClientRect();
        const cw = canvasWidth();
        const ch = canvasHeight();
        const size = measureElementSize(itemEl);
        const blockHeight = getElementBlockHeight(el) || size.height;

        resizeState = {
            el,
            itemEl,
            direction,
            scaleX: cw / rect.width,
            scaleY: ch / rect.height,
            startX: e.clientX,
            startY: e.clientY,
            origWidth: el.position.width || contentWidth(),
            origHeight: blockHeight,
            origX: el.position.x || 0,
            origY: el.position.y || 0,
            origConfigWidth: el.config?.width || el.position.width || contentWidth(),
            moved: false,
        };

        selectedId = el.id;
        itemEl.classList.add('ring-2', 'ring-primary', 'is-resizing');
        e.preventDefault();
        e.stopPropagation();
    }

    function applyResize(dx, dy) {
        if (!resizeState) return;

        const { el, direction } = resizeState;
        const cw = canvasWidth();
        const ch = canvasHeight();
        ensureElementPosition(el);

        if (direction.includes('e')) {
            const maxWidth = cw - (el.position.x || 0);
            el.position.width = Math.round(Math.max(40, Math.min(maxWidth, resizeState.origWidth + dx)));
        }

        if (direction.includes('w')) {
            let newWidth = resizeState.origWidth - dx;
            let newX = resizeState.origX + dx;
            if (newX < 0) {
                newWidth += newX;
                newX = 0;
            }
            newWidth = Math.max(40, Math.min(cw - newX, newWidth));
            el.position.width = Math.round(newWidth);
            el.position.x = Math.round(newX);
        }

        if (direction.includes('s')) {
            const maxHeight = Math.max(20, ch - (el.position.y || 0) - 8);
            setElementBlockHeight(el, Math.min(maxHeight, resizeState.origHeight + dy));
        }

        if (['logo', 'image'].includes(el.type) && (direction.includes('e') || direction.includes('w') || direction.includes('se') || direction.includes('sw'))) {
            if (!el.config) {
                el.config = { src: '', alt: el.type === 'logo' ? 'Logo' : 'Image', width: 120 };
            }
            const ratio = (el.position.width || resizeState.origWidth) / resizeState.origWidth;
            el.config.width = Math.max(40, Math.min(el.position.width || cw, Math.round(resizeState.origConfigWidth * ratio)));
        }

        applyElementDomPosition(resizeState.itemEl, el);
        updateElementInLayout(el);

        const preview = resizeState.itemEl.querySelector('.dt-element-preview');
        if (preview) {
            preview.innerHTML = renderElementPreview(el);
        }
    }

    function measureElementSize(itemEl) {
        const inner = document.getElementById('dt-canvas-inner');
        if (!inner || !itemEl) {
            return { width: 120, height: 40 };
        }

        const rect = inner.getBoundingClientRect();
        const cw = canvasWidth();
        const ch = canvasHeight();
        const scaleX = cw / rect.width;
        const scaleY = ch / rect.height;

        return {
            width: itemEl.offsetWidth * scaleX,
            height: itemEl.offsetHeight * scaleY,
        };
    }

    function updateGuideLines(snapV, snapH, dragging) {
        const vGuide = document.getElementById('dt-guide-v-center');
        const hGuide = document.getElementById('dt-guide-h-center');
        const dot = document.getElementById('dt-guide-center-dot');
        if (!vGuide || !hGuide || !dot) return;

        const vColor = snapV ? GUIDE_COLOR_SNAP : (dragging ? GUIDE_COLOR_DRAG : GUIDE_COLOR_IDLE);
        const hColor = snapH ? GUIDE_COLOR_SNAP : (dragging ? GUIDE_COLOR_DRAG : GUIDE_COLOR_IDLE);
        const vWeight = snapV ? '2px' : '1px';
        const hWeight = snapH ? '2px' : '1px';

        vGuide.style.cssText = [
            'position:absolute', 'top:0', 'bottom:0', 'left:50%', 'width:0',
            'transform:translateX(-50%)', 'pointer-events:none',
            `border-left:${vWeight} dashed ${vColor}`,
            snapV ? 'box-shadow:0 0 10px rgba(217,70,239,0.55)' : '',
            'transition:all 0.15s ease',
        ].join(';');

        hGuide.style.cssText = [
            'position:absolute', 'left:0', 'right:0', 'top:50%', 'height:0',
            'transform:translateY(-50%)', 'pointer-events:none',
            `border-top:${hWeight} dashed ${hColor}`,
            snapH ? 'box-shadow:0 0 10px rgba(217,70,239,0.55)' : '',
            'transition:all 0.15s ease',
        ].join(';');

        const dotSize = snapV && snapH ? 10 : 8;
        const dotColor = snapV || snapH ? GUIDE_COLOR_SNAP : GUIDE_COLOR_IDLE;
        dot.style.cssText = [
            'position:absolute', 'left:50%', 'top:50%',
            `width:${dotSize}px`, `height:${dotSize}px`,
            `margin:${-dotSize / 2}px 0 0 ${-dotSize / 2}px`,
            'border-radius:50%', `background:${dotColor}`,
            `border:2px solid ${snapV || snapH ? '#f0abfc' : 'rgba(124,58,237,0.85)'}`,
            'pointer-events:none', 'transition:all 0.15s ease',
            snapV && snapH ? 'box-shadow:0 0 8px rgba(217,70,239,0.6)' : '',
        ].join(';');
    }

    function resetGuideLines() {
        updateGuideLines(false, false, false);
    }

    function getSnapDimensions(el, itemEl, size) {
        const blockWidth = el.position?.width ? Math.min(el.position.width, size.width) : size.width;
        const blockHeight = size.height;

        if (['logo', 'image'].includes(el.type) && el.config?.width) {
            const contentWidth = parseInt(el.config.width, 10) || blockWidth;
            const align = el.style?.textAlign || 'center';
            let offsetX = 0;

            if (align === 'center') {
                offsetX = (blockWidth - contentWidth) / 2;
            } else if (align === 'right') {
                offsetX = blockWidth - contentWidth;
            }

            return {
                width: contentWidth,
                height: blockHeight,
                offsetX,
                blockWidth,
            };
        }

        return {
            width: blockWidth,
            height: blockHeight,
            offsetX: 0,
            blockWidth,
        };
    }

    function applyCenterSnap(el, itemEl) {
        const center = canvasCenter();
        const size = measureElementSize(itemEl);
        const snapSize = getSnapDimensions(el, itemEl, size);

        let newX = el.position.x;
        let newY = el.position.y;
        let snapV = false;
        let snapH = false;

        const centerX = newX + snapSize.offsetX + snapSize.width / 2;
        const centerY = newY + snapSize.height / 2;

        if (Math.abs(centerX - center.x) <= SNAP_THRESHOLD) {
            newX = center.x - snapSize.offsetX - snapSize.width / 2;
            snapV = true;
        }

        if (Math.abs(centerY - center.y) <= SNAP_THRESHOLD) {
            newY = center.y - snapSize.height / 2;
            snapH = true;
        }

        const cw = canvasWidth();
        const ch = canvasHeight();
        const maxX = Math.max(0, cw - (snapSize.blockWidth || snapSize.width));
        el.position.x = Math.round(Math.max(0, Math.min(maxX, newX)));
        el.position.y = Math.round(Math.max(0, Math.min(ch - 16, newY)));

        updateGuideLines(snapV, snapH, true);

        return { snapV, snapH };
    }

    function applyElementDomPosition(item, el) {
        const pos = el.position || { x: 0, y: 0, width: contentWidth() };
        const s = el.style || {};
        const cw = canvasWidth();
        const ch = canvasHeight();
        item.style.left = (pos.x / cw * 100) + '%';
        item.style.top = (pos.y / ch * 100) + '%';
        item.style.width = pos.width ? (pos.width / cw * 100) + '%' : 'auto';
        item.style.zIndex = String(el.id === selectedId ? 15 : (el.order || 1));
        item.style.padding = (s.padding ?? 0) + 'px';
        item.style.margin = `${s.marginTop ?? 0}px ${s.marginRight ?? 0}px ${s.marginBottom ?? 0}px ${s.marginLeft ?? 0}px`;

        const blockHeight = getElementBlockHeight(el);
        if (blockHeight) {
            item.style.minHeight = (blockHeight / ch * 100) + '%';
        } else {
            item.style.minHeight = '';
        }
    }

    function getElementSummary(el) {
        const truncate = (text, max = 42) => {
            const value = String(text || '').trim();
            if (!value) return '—';
            return value.length > max ? value.slice(0, max) + '…' : value;
        };

        switch (el.type) {
            case 'heading':
            case 'text':
            case 'border_box':
                return truncate(el.content);
            case 'header_banner':
                return truncate(el.content || el.subtitle);
            case 'footer':
                return truncate(el.content);
            case 'field_label':
            case 'info_row':
            case 'signature':
                return truncate(el.label);
            case 'field_value':
            case 'placeholder':
            case 'qr_code':
                return el.placeholder ? `{{ ${el.placeholder} }}` : '—';
            case 'table':
                return el.placeholder
                    ? `{{ ${el.placeholder} }}`
                    : truncate((el.columns || []).join(', '), 48);
            case 'two_column':
                return truncate(el.leftContent) + ' | ' + truncate(el.rightContent);
            case 'logo':
            case 'image':
                return truncate(el.config?.alt || (el.type === 'logo' ? 'Logo' : 'Image'));
            case 'spacer':
                return `${el.height || 24}px height`;
            case 'divider':
                if (el.dividerStyle === 'slash') {
                    return `Slashes × ${el.slashCount || 5}`;
                }
                return dividerStyleLabel(el.dividerStyle);
            default:
                return truncate(el.content || el.label || el.placeholder);
        }
    }

    function selectElement(el) {
        selectedId = el.id;
        renderCanvas();
        renderProperties(el);

        const canvasItem = document.querySelector(`[data-element-id="${el.id}"]`);
        canvasItem?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    }

    function renderUsedElementsList() {
        const list = document.getElementById('dt-used-elements-list');
        const empty = document.getElementById('dt-used-elements-empty');
        const count = document.getElementById('dt-used-elements-count');
        if (!list) return;

        const sorted = [...layout.elements].sort((a, b) => (a.order || 0) - (b.order || 0));
        list.innerHTML = '';

        sorted.forEach((el, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dt-used-element-item' + (selectedId === el.id ? ' is-selected' : '');
            button.dataset.elementId = el.id;
            button.innerHTML = `
                <span class="badge badge-ghost badge-xs mt-0.5 flex-shrink-0">${index + 1}</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-xs font-semibold text-base-content">${esc(BLOCK_LABELS[el.type] || el.type)}</span>
                    <span class="block text-[11px] text-base-content/60 truncate">${esc(getElementSummary(el))}</span>
                </span>`;

            button.addEventListener('click', () => selectElement(el));
            list.appendChild(button);
        });

        if (empty) empty.classList.toggle('hidden', sorted.length > 0);
        if (count) count.textContent = String(sorted.length);
    }

    function renderCanvas() {
        const list = document.getElementById('dt-elements-list');
        const empty = document.getElementById('dt-canvas-empty');
        const count = document.getElementById('dt-element-count');
        if (!list) return;

        const sorted = [...layout.elements].sort((a, b) => (a.order || 0) - (b.order || 0));
        list.innerHTML = '';

        sorted.forEach(el => {
            ensureElementPosition(el);
            ensureElementStyle(el);

            const item = document.createElement('div');
            item.className = 'dt-element-item group absolute rounded border-2 transition-shadow cursor-move ' +
                (selectedId === el.id ? 'border-primary bg-primary/5 shadow-md' : 'border-transparent');
            item.dataset.elementId = el.id;
            applyElementDomPosition(item, el);

            item.innerHTML = `
                <div class="dt-element-body">
                    <div class="dt-element-preview pointer-events-none${el.type === 'table' && selectedId === el.id ? ' dt-table-preview--interactive' : ''}">${renderElementPreview(el)}</div>
                </div>
                ${selectedId === el.id ? getResizeHandleMarkup() : ''}`;

            item.addEventListener('mousedown', (e) => {
                if (e.target.closest('.dt-resize-handle') || e.target.closest('.dt-table-col-resize')) return;
                startDrag(e, el, item);
            });

            if (selectedId === el.id) {
                bindResizeHandles(item, el);
                if (el.type === 'table') {
                    bindTableColumnResizers(item, el);
                }
            }

            item.addEventListener('click', (e) => {
                if (dragState?.moved) return;
                selectedId = el.id;
                renderCanvas();
                renderProperties(el);
            });

            list.appendChild(item);
        });

        if (empty) empty.classList.toggle('hidden', sorted.length > 0);
        if (count) count.textContent = sorted.length + ' elements';
        renderUsedElementsList();
    }

    function startDrag(e, el, itemEl) {
        if (e.button !== 0 || e.target.closest('.dt-resize-handle') || e.target.closest('.dt-table-col-resize')) return;

        const inner = document.getElementById('dt-canvas-inner');
        if (!inner) return;

        const rect = inner.getBoundingClientRect();
        const cw = canvasWidth();
        const ch = canvasHeight();

        dragState = {
            el,
            itemEl,
            scaleX: cw / rect.width,
            scaleY: ch / rect.height,
            startX: e.clientX,
            startY: e.clientY,
            origX: el.position?.x || 0,
            origY: el.position?.y || 0,
            moved: false,
        };

        selectedId = el.id;
        itemEl.classList.add('ring-2', 'ring-primary');
        updateGuideLines(false, false, true);
        e.preventDefault();
    }

    function onDocumentMouseMove(e) {
        if (resizeState) {
            const dx = (e.clientX - resizeState.startX) * resizeState.scaleX;
            const dy = (e.clientY - resizeState.startY) * resizeState.scaleY;

            if (Math.abs(dx) > 2 || Math.abs(dy) > 2) {
                resizeState.moved = true;
            }

            applyResize(dx, dy);
            return;
        }

        if (!dragState) return;

        const dx = (e.clientX - dragState.startX) * dragState.scaleX;
        const dy = (e.clientY - dragState.startY) * dragState.scaleY;

        if (Math.abs(dx) > 2 || Math.abs(dy) > 2) {
            dragState.moved = true;
        }

        const size = measureElementSize(dragState.itemEl);
        const snapSize = getSnapDimensions(dragState.el, dragState.itemEl, size);
        let newX = dragState.origX + dx;
        let newY = dragState.origY + dy;

        const center = canvasCenter();
        const centerX = newX + snapSize.offsetX + snapSize.width / 2;
        const centerY = newY + snapSize.height / 2;
        let snapV = false;
        let snapH = false;

        if (Math.abs(centerX - center.x) <= SNAP_THRESHOLD) {
            newX = center.x - snapSize.offsetX - snapSize.width / 2;
            snapV = true;
        }

        if (Math.abs(centerY - center.y) <= SNAP_THRESHOLD) {
            newY = center.y - snapSize.height / 2;
            snapH = true;
        }

        const cw = canvasWidth();
        const ch = canvasHeight();
        const maxX = Math.max(0, cw - (snapSize.blockWidth || snapSize.width));
        const maxY = Math.max(0, ch - snapSize.height);

        dragState.el.position.x = Math.round(Math.max(0, Math.min(maxX, newX)));
        dragState.el.position.y = Math.round(Math.max(0, Math.min(maxY, newY)));

        updateGuideLines(snapV, snapH, true);
        applyElementDomPosition(dragState.itemEl, dragState.el);
        updateElementInLayout(dragState.el);
    }

    function onDocumentMouseUp() {
        if (resizeState) {
            resizeState.itemEl.classList.remove('ring-2', 'ring-primary', 'is-resizing');

            if (resizeState.moved) {
                const preview = resizeState.itemEl.querySelector('.dt-element-preview');
                if (preview && !['spacer', 'qr_code'].includes(resizeState.el.type)) {
                    const measured = measureElementContentHeight(resizeState.el, resizeState.itemEl);
                    if (!resizeState.direction.includes('s') && !resizeState.direction.includes('sw') && !resizeState.direction.includes('se')) {
                        resizeState.el.position.measuredHeight = Math.round(Math.max(measured, resizeState.el.position.measuredHeight || 0));
                    }
                }
                refreshCanvasElement(resizeState.el);
                renderProperties(resizeState.el);
            }

            resizeState = null;
            return;
        }

        if (!dragState) return;

        dragState.itemEl.classList.remove('ring-2', 'ring-primary', 'ring-fuchsia-400');
        resetGuideLines();

        if (dragState.moved) {
            renderProperties(dragState.el);
        }

        dragState = null;
    }

    function updateElementInLayout(el) {
        const idx = layout.elements.findIndex(e => e.id === el.id);
        if (idx >= 0) layout.elements[idx] = el;
    }

    function defaultTextColor(type) {
        switch (type) {
            case 'heading': return '#1e3a5f';
            case 'field_label': return '#374151';
            case 'field_value': return '#1e3a5f';
            case 'footer': return '#666666';
            case 'text': return '#333333';
            case 'header_banner': return '#ffffff';
            default: return '#000000';
        }
    }

    function buildPreviewStyle(el, defaults = {}) {
        const s = { ...defaults, ...(el.style || {}) };
        const rules = [];

        if (s.fontSize !== undefined && s.fontSize !== null && s.fontSize !== '') {
            rules.push(`font-size:${Number(s.fontSize)}pt`);
        }
        if (s.fontWeight) {
            rules.push(`font-weight:${s.fontWeight}`);
        }
        if (s.color) {
            rules.push(`color:${s.color}`);
        }
        if (s.textAlign) {
            rules.push(`text-align:${s.textAlign}`);
        }
        if (s.backgroundColor) {
            rules.push(`background-color:${s.backgroundColor}`);
        }

        return rules.join(';');
    }

    function refreshCanvasElement(el) {
        const item = document.querySelector(`[data-element-id="${el.id}"]`);
        if (!item) {
            renderCanvas();
            return;
        }

        item.className = 'dt-element-item group absolute rounded border-2 transition-shadow cursor-move ' +
            (selectedId === el.id ? 'border-primary bg-primary/5 shadow-md' : 'border-transparent');

        applyElementDomPosition(item, el);

        const preview = item.querySelector('.dt-element-preview');
        if (preview) {
            preview.className = 'dt-element-preview pointer-events-none' +
                (el.type === 'table' && selectedId === el.id ? ' dt-table-preview--interactive' : '');
            preview.innerHTML = renderElementPreview(el);
        }

        if (selectedId === el.id) {
            bindResizeHandles(item, el);
            if (el.type === 'table') {
                bindTableColumnResizers(item, el);
            }
        }

        renderUsedElementsList();
    }

    function renderElementPreview(el) {
        const s = el.style || {};

        switch (el.type) {
            case 'header_banner': {
                const bannerStyle = buildPreviewStyle(el, {
                    backgroundColor: '#1e3a5f',
                    color: '#ffffff',
                    textAlign: 'center',
                    fontSize: 18,
                });
                const titleStyle = buildPreviewStyle(el, { fontWeight: 'bold', color: s.color || '#ffffff', fontSize: s.fontSize || 18 });
                return `<div style="${bannerStyle};padding:${s.padding ?? 12}px;border-radius:4px;">
                    <div style="${titleStyle};line-height:1.3;">${esc(el.content || 'Title')}</div>
                    ${el.subtitle ? `<div style="font-size:${Math.max(8, (Number(s.fontSize) || 18) - 8)}pt;opacity:.85;margin-top:4px;">${esc(el.subtitle)}</div>` : ''}
                </div>`;
            }
            case 'heading':
                return `<div style="${buildPreviewStyle(el, { fontWeight: 'bold', color: '#1e3a5f', fontSize: 13 })};margin:0;padding-bottom:4px;border-bottom:1px solid #d1d5db;word-wrap:break-word;">${esc(el.content || 'Heading')}</div>`;
            case 'text':
                return `<div style="${buildPreviewStyle(el, { color: '#333333', fontSize: 11 })};margin:0;word-wrap:break-word;">${esc(el.content || 'Text')}</div>`;
            case 'placeholder':
                if (el.label) {
                    return `<table style="width:100%;border-collapse:collapse;table-layout:fixed;margin:0;"><tr>
                        <td style="width:38%;${buildPreviewStyle(el, { fontWeight: 'bold', color: '#374151', fontSize: 11 })};padding:5px 10px 5px 0;vertical-align:top;">${esc(el.label)}</td>
                        <td style="width:62%;${buildPreviewStyle(el, { color: '#1e3a5f', fontWeight: 'normal', fontSize: 11 })};padding:5px 0;vertical-align:top;word-wrap:break-word;">{{ ${esc(el.placeholder || 'key')} }}</td>
                    </tr></table>`;
                }
                return `<div style="${buildPreviewStyle(el, { color: '#1e3a5f', fontSize: 11 })};word-wrap:break-word;">{{ ${esc(el.placeholder || 'key')} }}</div>`;
            case 'field_label':
                return `<div style="${buildPreviewStyle(el, { fontWeight: 'bold', color: '#374151', fontSize: 11 })};word-wrap:break-word;">${esc(el.label || 'Label')}</div>`;
            case 'field_value':
                return `<div style="${buildPreviewStyle(el, { color: '#1e3a5f', fontSize: 11 })};word-wrap:break-word;">{{ ${esc(el.placeholder || 'key')} }}</div>`;
            case 'info_row':
                return `<table style="width:100%;border-collapse:collapse;table-layout:fixed;margin:0;font-size:${s.fontSize || 11}pt;"><tr>
                    <td style="width:38%;${buildPreviewStyle(el, { fontWeight: 'bold', color: '#374151', fontSize: s.fontSize || 11 })};padding:5px 10px 5px 0;vertical-align:top;">${esc(el.label || 'Label')}</td>
                    <td style="width:62%;${buildPreviewStyle(el, { color: '#1e3a5f', fontWeight: 'normal', fontSize: s.fontSize || 11 })};padding:5px 0;vertical-align:top;word-wrap:break-word;">{{ ${esc(el.placeholder || 'key')} }}</td>
                </tr></table>`;
            case 'divider':
                return renderDividerHtml(el);
            case 'spacer':
                return `<div style="height:${el.height || 16}px;background:repeating-linear-gradient(45deg,#f0f0f0,#f0f0f0 2px,#fff 2px,#fff 4px);border-radius:2px;"></div>`;
            case 'table': {
                normalizeTableColumnWidths(el);
                const cols = el.columns || ['Column 1', 'Column 2'];
                const tableFont = s.fontSize || 10;
                const colgroup = buildTableColgroupMarkup(el);
                const header = cols.map((c, index) => `<th style="${buildTableCellWidthStyle(el, index)}padding:8px 6px;text-align:left;border:1px solid #1e3a5f;font-weight:bold;background:#1e3a5f;color:#fff;font-size:${tableFont}pt;overflow:hidden;word-wrap:break-word;vertical-align:top;">${esc(c)}</th>`).join('');
                const sample = cols.map((_, index) => `<td style="${buildTableCellWidthStyle(el, index)}padding:7px 6px;border:1px solid #e2e8f0;color:#9ca3af;font-size:${tableFont}pt;overflow:hidden;word-wrap:break-word;vertical-align:top;">…</td>`).join('');
                const resizers = selectedId === el.id ? renderTableColumnResizerMarkup(el) : '';
                return `<div class="dt-table-wrap" style="position:relative;">
                    <table style="width:100%;border-collapse:collapse;table-layout:fixed;margin:0;font-size:${tableFont}pt;">
                        <colgroup>${colgroup}</colgroup>
                        <thead><tr>${header}</tr></thead>
                        <tbody><tr style="background:#f8fafc;">${sample}</tr></tbody>
                    </table>
                    ${resizers}
                </div>`;
            }
            case 'signature':
                return `<div style="${buildPreviewStyle(el, { textAlign: 'center' })};padding:12px 0;">
                    <div style="border-top:1px solid #333;width:120px;margin:0 auto 4px;"></div>
                    <span style="font-size:${s.fontSize || 10}pt;color:${s.color || '#666'};">${esc(el.label || 'Signature')}</span>
                </div>`;
            case 'qr_code': {
                const size = el.size || 72;
                const previewSize = Math.max(32, Math.min(96, Math.round(size * 0.6)));
                return `<div style="text-align:${s.textAlign || 'center'};"><div style="display:inline-block;width:${previewSize}px;height:${previewSize}px;border:2px dashed #ccc;line-height:${previewSize}px;font-size:9px;color:#999;">QR</div></div>`;
            }
            case 'border_box': {
                const borderWidth = s.borderWidth ?? 2;
                const borderColor = s.borderColor || s.backgroundColor || '#1e3a5f';
                const boxStyle = buildPreviewStyle(el, { fontSize: 12, fontWeight: s.fontWeight || 'normal' });
                return `<div style="border:${borderWidth}px solid ${borderColor};padding:${s.padding ?? 16}px;${boxStyle};word-wrap:break-word;">${esc(el.content || 'Content')}</div>`;
            }
            case 'two_column':
                return `<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;${buildPreviewStyle(el, { fontSize: 10 })};"><div style="word-wrap:break-word;">${esc(el.leftContent || 'Left')}</div><div style="word-wrap:break-word;">${esc(el.rightContent || 'Right')}</div></div>`;
            case 'footer':
                return `<div style="${buildPreviewStyle(el, { fontSize: 8, color: '#6b7280', textAlign: 'center' })};border-top:1px solid #e5e7eb;padding-top:10px;margin:0;line-height:1.4;word-wrap:break-word;">${esc(el.content || 'Footer')}</div>`;
            case 'logo':
            case 'image': {
                const cfg = el.config || {};
                const align = el.style?.textAlign || 'center';
                if (cfg.src) {
                    return `<div style="text-align:${align};"><img src="${esc(cfg.src)}" alt="${esc(cfg.alt||'Logo')}" style="max-width:${cfg.width||120}px;height:auto;display:inline-block;"></div>`;
                }
                return `<div style="text-align:${align};"><div style="display:inline-block;width:80px;height:40px;border:2px dashed #ccc;line-height:40px;font-size:9px;color:#999;">${esc(cfg.alt||'Logo')}</div></div>`;
            }
            default:
                return `<span class="text-xs text-base-content/50">${BLOCK_LABELS[el.type] || el.type}</span>`;
        }
    }

    const SLASH_WEIGHTS = {
        thin: { fontSize: 10, fontWeight: 'normal' },
        medium: { fontSize: 14, fontWeight: 'bold' },
        bold: { fontSize: 18, fontWeight: 'bold' },
    };

    function dividerStyleLabel(style) {
        return ({ solid: 'Solid line', dots: 'Dots', outline: 'Outline', slash: 'Slashes' })[style || 'solid'] || 'Solid line';
    }

    function getSlashWeightConfig(el) {
        return SLASH_WEIGHTS[el.slashWeight || 'medium'] || SLASH_WEIGHTS.medium;
    }

    function getSlashCount(el) {
        const count = parseInt(el.slashCount, 10);
        return Number.isFinite(count) ? Math.max(1, Math.min(200, count)) : 5;
    }

    function renderSlashDividerHtml(el) {
        const s = el.style || {};
        const color = s.color || '#374151';
        const barHeight = el.height || 24;
        const slashType = getSlashWeightConfig(el);
        const count = getSlashCount(el);
        const cells = Array.from({ length: count }, () =>
            `<td align="center" valign="middle" style="border:none;padding:0;font-family:Inter,DejaVu Sans,Arial,sans-serif;font-size:${slashType.fontSize}pt;font-weight:${slashType.fontWeight};color:${esc(color)};line-height:1;">/</td>`
        ).join('');

        return `<table width="100%" cellpadding="0" cellspacing="0" style="border:none;border-collapse:collapse;height:${barHeight}px;">
            <tr>${cells}</tr>
        </table>`;
    }

    function renderDividerHtml(el) {
        const s = el.style || {};
        const style = el.dividerStyle || 'solid';
        const color = s.color || '#d1d5db';
        const mt = s.marginTop ?? 0;
        const mb = s.marginBottom ?? 0;

        if (style === 'dots') {
            return `<hr style="border:none;border-top:2px dotted ${esc(color)};margin:${mt}px 0 ${mb}px 0;">`;
        }

        if (style === 'outline') {
            const h = el.height || 14;
            const borderColor = s.borderColor || color;
            const borderWidth = s.borderWidth || 1;

            return `<div style="height:${h}px;border:${borderWidth}px solid ${esc(borderColor)};margin:${mt}px 0 ${mb}px 0;box-sizing:border-box;background:transparent;"></div>`;
        }

        if (style === 'slash') {
            return `<div style="margin:${mt}px 0 ${mb}px 0;">${renderSlashDividerHtml(el)}</div>`;
        }

        return `<hr style="border:none;border-top:1px solid ${esc(color)};margin:${mt}px 0 ${mb}px 0;">`;
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function renderPositionAndSpacingControls(el) {
        ensureElementPosition(el);
        ensureElementStyle(el);
        const pos = el.position;

        return `
            <div class="divider my-1"></div>
            <p class="text-xs font-semibold uppercase tracking-wide text-base-content/50">Position</p>
            <div class="grid grid-cols-3 gap-2">
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">X</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-position" data-pos="x" value="${pos.x}" min="0">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Y</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-position" data-pos="y" value="${pos.y}" min="0">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Width</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-position" data-pos="width" value="${pos.width || contentWidth()}" min="40">
                </div>
            </div>
            <label class="flex items-center gap-2 cursor-pointer mt-2">
                <input type="checkbox" class="checkbox checkbox-xs checkbox-primary dt-prop-pin-y" ${pos.pinY !== false ? 'checked' : ''}>
                <span class="text-xs">Lock vertical position in PDF</span>
            </label>
            <p class="text-xs text-base-content/50 mt-2">Drag the block to move it. Use the blue handles on the selected block to resize width and height.</p>
            ${el.type === 'footer' ? '<p class="text-xs text-base-content/50 mt-1">The footer automatically sits below the container table in the generated PDF.</p>' : ''}
            ${el.type === 'table' ? '<p class="text-xs text-base-content/50 mt-1">Row count expands in PDF; drag column header edges to resize widths.</p>' : ''}
            <p class="text-xs font-semibold uppercase tracking-wide text-base-content/50 mt-2">Spacing</p>
            <div class="grid grid-cols-2 gap-2">
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Padding</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-style" data-style="padding" value="${el.style.padding ?? 0}" min="0" max="120">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Margin Top</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-style" data-style="marginTop" value="${el.style.marginTop ?? 0}" min="0" max="120">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Margin Bottom</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-style" data-style="marginBottom" value="${el.style.marginBottom ?? 0}" min="0" max="120">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Margin Left</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-style" data-style="marginLeft" value="${el.style.marginLeft ?? 0}" min="0" max="120">
                </div>
                <div class="form-control col-span-2">
                    <label class="label py-0"><span class="label-text text-xs">Margin Right</span></label>
                    <input type="number" class="input input-bordered input-sm dt-prop-style" data-style="marginRight" value="${el.style.marginRight ?? 0}" min="0" max="120">
                </div>
            </div>`;
    }

    function renderProperties(el) {
        const panel = document.getElementById('dt-properties-panel');
        if (!panel) return;

        if (!el) {
            panel.innerHTML = '<p class="text-sm text-base-content/50">Select an element on the canvas to edit its properties</p>';
            return;
        }

        let html = `<div class="space-y-3">
            <div class="flex items-center justify-between">
                <span class="badge badge-ghost badge-sm">${BLOCK_LABELS[el.type] || el.type}</span>
                <button type="button" class="btn btn-xs btn-error btn-ghost dt-prop-delete">Remove</button>
            </div>`;

        const textFields = ['content', 'subtitle', 'label', 'leftContent', 'rightContent'];
        textFields.forEach(field => {
            if (el[field] !== undefined || ['content','label'].includes(field)) {
                const val = el[field] ?? '';
                const show = field === 'content' || field === 'label' || field === 'subtitle' ||
                    (field === 'leftContent' && el.type === 'two_column') ||
                    (field === 'rightContent' && el.type === 'two_column') ||
                    (field === 'content' && ['text','heading','footer','border_box','header_banner'].includes(el.type));
                if (!show && field !== 'label') return;
                if (field === 'subtitle' && el.type !== 'header_banner') return;
                if (field === 'label' && !['placeholder','info_row','field_label','signature'].includes(el.type)) return;
                if (field === 'content' && !['text','heading','footer','border_box','header_banner'].includes(el.type)) return;
                if (field === 'leftContent' && el.type !== 'two_column') return;
                if (field === 'rightContent' && el.type !== 'two_column') return;

                const contentLabel = field === 'content' && el.type === 'heading' ? 'Heading text' : field.replace(/([A-Z])/g,' $1');
                html += `<div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs capitalize">${contentLabel}</span></label>
                    <input type="text" class="input input-bordered input-sm w-full dt-prop-input" data-field="${field}" value="${esc(val)}">
                </div>`;
            }
        });

        if (['placeholder','info_row','field_value','table','qr_code'].includes(el.type)) {
            html += `<div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Placeholder Key</span></label>
                <input type="text" class="input input-bordered input-sm w-full font-mono dt-prop-input" data-field="placeholder" value="${esc(el.placeholder||'')}">
            </div>`;
        }

        if (el.type === 'info_row') {
            html += `<button type="button" class="btn btn-xs btn-outline btn-primary w-full dt-split-info-row">Split into Label + Value</button>
                <p class="text-[11px] text-base-content/50">Creates two independent blocks so you can position the label and value separately.</p>`;
        }

        if (el.type === 'spacer') {
            html += `<div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Height (px)</span></label>
                <input type="number" class="input input-bordered input-sm w-full dt-prop-input" data-field="height" value="${el.height||24}" min="4" max="200">
            </div>`;
        }

        if (el.type === 'table') {
            normalizeTableColumnWidths(el);
            const cols = el.columns || [];
            html += `<div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Columns (comma-separated)</span></label>
                <input type="text" class="input input-bordered input-sm w-full dt-prop-columns" value="${(cols).join(', ')}">
            </div>
            <div class="form-control">
                <div class="flex items-center justify-between gap-2">
                    <label class="label py-0"><span class="label-text text-xs">Column widths (%)</span></label>
                    <button type="button" class="btn btn-xs btn-ghost dt-equalize-columns">Equalize</button>
                </div>
                <div class="dt-col-widths-grid mt-1">
                    ${cols.map((column, index) => `
                        <div>
                            <label class="label py-0"><span class="label-text text-[10px] truncate" title="${esc(column)}">${esc(column)}</span></label>
                            <input type="number" class="input input-bordered input-xs w-full dt-col-width-input" data-col-index="${index}" value="${el.columnWidths[index]}" min="3" max="97" step="0.5">
                        </div>
                    `).join('')}
                </div>
                <p class="text-[11px] text-base-content/50 mt-1">Total: <span class="dt-col-width-total font-mono">${el.columnWidths.reduce((sum, width) => sum + width, 0).toFixed(1)}%</span>. Drag the blue handles on column headers to resize.</p>
            </div>`;
        }

        if (['logo', 'image'].includes(el.type)) {
            if (!el.config) el.config = { src: '', alt: el.type === 'logo' ? 'Logo' : 'Image', width: 120 };
            if (!el.style) el.style = { textAlign: 'center' };

            const src = el.config.src || '';
            html += `<div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Image (PNG or JPG, max 2 MB)</span></label>
                <div class="rounded-lg border-2 border-dashed border-base-300 p-3">
                    ${src
                        ? `<img src="${esc(src)}" alt="Preview" class="mx-auto max-h-24 w-auto mb-2 rounded border border-base-300 dt-image-preview">`
                        : '<p class="text-xs text-base-content/50 text-center mb-2">No image uploaded yet</p>'}
                    <input type="file" accept="image/png,image/jpeg,.png,.jpg,.jpeg" class="file-input file-input-bordered file-input-sm w-full dt-image-upload">
                    ${src ? '<button type="button" class="btn btn-xs btn-ghost btn-block mt-2 dt-image-remove">Remove image</button>' : ''}
                </div>
            </div>
            <div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Alt Text</span></label>
                <input type="text" class="input input-bordered input-sm w-full dt-prop-config" data-config="alt" value="${esc(el.config.alt || (el.type === 'logo' ? 'Logo' : 'Image'))}">
            </div>
            <div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Image Width (px)</span></label>
                <input type="number" class="input input-bordered input-sm w-full dt-prop-config" data-config="width" value="${el.config.width || 120}" min="40" max="400">
            </div>
            <div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Alignment</span></label>
                <select class="select select-bordered select-sm w-full dt-prop-style" data-style="textAlign">
                    <option value="left" ${el.style.textAlign === 'left' ? 'selected' : ''}>Left</option>
                    <option value="center" ${el.style.textAlign === 'center' ? 'selected' : ''}>Center</option>
                    <option value="right" ${el.style.textAlign === 'right' ? 'selected' : ''}>Right</option>
                </select>
            </div>`;
        }

        if (el.type === 'divider') {
            if (!el.style) el.style = {};
            const dividerStyle = el.dividerStyle || 'solid';
            html += `<div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Divider Style</span></label>
                <select class="select select-bordered select-sm w-full dt-prop-divider-style">
                    <option value="solid" ${dividerStyle === 'solid' ? 'selected' : ''}>Solid line</option>
                    <option value="dots" ${dividerStyle === 'dots' ? 'selected' : ''}>Dots</option>
                    <option value="outline" ${dividerStyle === 'outline' ? 'selected' : ''}>Outline</option>
                    <option value="slash" ${dividerStyle === 'slash' ? 'selected' : ''}>Slashes</option>
                </select>
            </div>
            <div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Line color</span></label>
                <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="color" value="${el.style.color || (dividerStyle === 'slash' ? '#374151' : '#d1d5db')}">
            </div>`;

            if (dividerStyle === 'outline') {
                html += `<div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Bar height (px)</span></label>
                    <input type="number" class="input input-bordered input-sm w-full dt-prop-input" data-field="height" value="${el.height || 14}" min="4" max="48">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Border color</span></label>
                    <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="borderColor" value="${el.style.borderColor || el.style.color || '#6366f1'}">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Border width (px)</span></label>
                    <input type="number" class="input input-bordered input-sm w-full dt-prop-style" data-style="borderWidth" value="${el.style.borderWidth ?? 1}" min="1" max="6">
                </div>`;
            }

            if (dividerStyle === 'slash') {
                const slashWeight = el.slashWeight || 'medium';
                html += `<div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Slash weight</span></label>
                    <select class="select select-bordered select-sm w-full dt-prop-slash-weight">
                        <option value="thin" ${slashWeight === 'thin' ? 'selected' : ''}>Thin</option>
                        <option value="medium" ${slashWeight === 'medium' ? 'selected' : ''}>Medium (rounded)</option>
                        <option value="bold" ${slashWeight === 'bold' ? 'selected' : ''}>Bold (rounded)</option>
                    </select>
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Number of slashes</span></label>
                    <input type="number" class="input input-bordered input-sm w-full dt-prop-input" data-field="slashCount" value="${el.slashCount || 5}" min="1" max="200">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Bar height (px)</span></label>
                    <input type="number" class="input input-bordered input-sm w-full dt-prop-input" data-field="height" value="${el.height || 24}" min="12" max="48">
                </div>`;
            }
        }

        if (el.style && !['logo', 'image', 'divider', 'spacer'].includes(el.type)) {
            html += `<div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Text Align</span></label>
                <select class="select select-bordered select-sm w-full dt-prop-style" data-style="textAlign">
                    <option value="left" ${el.style.textAlign==='left'?'selected':''}>Left</option>
                    <option value="center" ${el.style.textAlign==='center'?'selected':''}>Center</option>
                    <option value="right" ${el.style.textAlign==='right'?'selected':''}>Right</option>
                </select>
            </div>
            <div class="form-control">
                <label class="label py-0"><span class="label-text text-xs">Font Size</span></label>
                <input type="number" class="input input-bordered input-sm w-full dt-prop-style" data-style="fontSize" value="${el.style.fontSize||12}" min="8" max="48">
            </div>`;

            if (el.type === 'header_banner') {
                html += `<div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Background Color</span></label>
                    <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="backgroundColor" value="${el.style.backgroundColor||'#1e3a5f'}">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Text Color</span></label>
                    <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="color" value="${el.style.color||'#ffffff'}">
                </div>`;
            } else if (el.type === 'border_box') {
                html += `<div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Box Color</span></label>
                    <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="backgroundColor" value="${el.style.backgroundColor||'#ffffff'}">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Border Color</span></label>
                    <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="borderColor" value="${el.style.borderColor || el.style.backgroundColor || '#1e3a5f'}">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Border Width (px)</span></label>
                    <input type="number" class="input input-bordered input-sm w-full dt-prop-style" data-style="borderWidth" value="${el.style.borderWidth ?? 2}" min="0" max="12">
                </div>
                <div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Text Color</span></label>
                    <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="color" value="${el.style.color||'#1e3a5f'}">
                </div>`;
            } else {
                html += `<div class="form-control">
                    <label class="label py-0"><span class="label-text text-xs">Text Color</span></label>
                    <input type="color" class="input input-bordered input-sm w-full h-8 dt-prop-style" data-style="color" value="${el.style.color||defaultTextColor(el.type)}">
                </div>`;
            }
        }

        html += renderPositionAndSpacingControls(el);
        html += '</div>';
        panel.innerHTML = html;

        panel.querySelector('.dt-prop-divider-style')?.addEventListener('change', function () {
            el.dividerStyle = this.value;
            if (this.value === 'outline' && !el.height) {
                el.height = 14;
            }
            if (this.value === 'slash') {
                if (!el.height) el.height = 24;
                if (!el.slashWeight) el.slashWeight = 'medium';
                if (!el.slashCount) el.slashCount = 5;
            }
            updateElement(el);
            renderProperties(el);
        });

        panel.querySelector('.dt-prop-slash-weight')?.addEventListener('change', function () {
            el.slashWeight = this.value;
            updateElement(el);
        });

        panel.querySelector('.dt-prop-delete')?.addEventListener('click', () => removeElement(el.id));
        panel.querySelector('.dt-split-info-row')?.addEventListener('click', () => splitInfoRow(el));

        panel.querySelectorAll('.dt-prop-input').forEach(input => {
            input.addEventListener('input', () => {
                const field = input.dataset.field;
                if (field === 'height') el.height = parseInt(input.value, 10) || 16;
                else if (field === 'slashCount') el.slashCount = Math.max(1, Math.min(200, parseInt(input.value, 10) || 5));
                else el[field] = input.value;
                updateElement(el);
            });
        });

        panel.querySelectorAll('.dt-prop-style').forEach(input => {
            const handler = () => {
                if (!el.style) el.style = {};
                const key = input.dataset.style;
                el.style[key] = input.type === 'number' ? parseInt(input.value, 10) || 0 : input.value;
                updateElement(el);
            };
            input.addEventListener('input', handler);
            input.addEventListener('change', handler);
        });

        panel.querySelectorAll('.dt-prop-position').forEach(input => {
            input.addEventListener('input', () => {
                if (!el.position) el.position = { x: 0, y: 0, width: contentWidth() };
                el.position[input.dataset.pos] = parseInt(input.value, 10) || 0;
                updateElement(el);
            });
        });

        panel.querySelector('.dt-prop-pin-y')?.addEventListener('change', function () {
            if (!el.position) el.position = { x: 0, y: 0, width: contentWidth() };
            el.position.pinY = this.checked;
            updateElement(el);
        });

        panel.querySelector('.dt-prop-columns')?.addEventListener('input', function () {
            el.columns = this.value.split(',').map(s => s.trim()).filter(Boolean);
            el.columnWidths = null;
            normalizeTableColumnWidths(el);
            updateElement(el);
            renderProperties(el);
        });

        panel.querySelector('.dt-equalize-columns')?.addEventListener('click', () => {
            el.columnWidths = null;
            normalizeTableColumnWidths(el);
            updateElement(el);
            renderProperties(el);
        });

        panel.querySelectorAll('.dt-col-width-input').forEach(input => {
            input.addEventListener('input', () => {
                const index = parseInt(input.dataset.colIndex, 10);
                if (!Number.isFinite(index)) return;

                normalizeTableColumnWidths(el);
                const nextWidth = Math.max(3, Math.min(97, parseFloat(input.value) || 0));
                const current = el.columnWidths[index];
                const delta = nextWidth - current;
                el.columnWidths[index] = nextWidth;

                const adjustIndex = index < el.columnWidths.length - 1 ? index + 1 : index - 1;
                if (adjustIndex >= 0) {
                    el.columnWidths[adjustIndex] = Math.max(3, Math.round((el.columnWidths[adjustIndex] - delta) * 10) / 10);
                }

                normalizeTableColumnWidths(el);
                panel.querySelectorAll('.dt-col-width-input').forEach(widthInput => {
                    const widthIndex = parseInt(widthInput.dataset.colIndex, 10);
                    if (Number.isFinite(widthIndex)) {
                        widthInput.value = el.columnWidths[widthIndex];
                    }
                });
                const totalEl = panel.querySelector('.dt-col-width-total');
                if (totalEl) {
                    totalEl.textContent = `${el.columnWidths.reduce((sum, width) => sum + width, 0).toFixed(1)}%`;
                }
                updateElement(el);
            });
        });

        panel.querySelectorAll('.dt-prop-config').forEach(input => {
            input.addEventListener('input', () => {
                if (!el.config) el.config = {};
                const key = input.dataset.config;
                el.config[key] = key === 'width' ? parseInt(input.value, 10) || 120 : input.value;
                updateElement(el);
            });
        });

        panel.querySelector('.dt-image-upload')?.addEventListener('change', function () {
            const file = this.files?.[0];
            if (!file) return;
            uploadElementImage(el, file, panel);
        });

        panel.querySelector('.dt-image-remove')?.addEventListener('click', () => {
            if (!el.config) el.config = {};
            el.config.src = '';
            updateElement(el);
            renderProperties(el);
        });
    }

    function uploadElementImage(el, file, panel) {
        const allowedTypes = ['image/png', 'image/jpeg'];
        if (!allowedTypes.includes(file.type)) {
            showToast('Only PNG and JPG images are allowed', 'error');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            showToast('Image must be 2 MB or smaller', 'error');
            return;
        }
        if (!uploadUrl) {
            showToast('Upload is not configured', 'error');
            return;
        }

        const input = panel.querySelector('.dt-image-upload');
        if (input) input.disabled = true;

        const formData = new FormData();
        formData.append('image', file);

        fetch(uploadUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        })
            .then(r => r.json())
            .then(result => {
                if (!result.success) {
                    throw new Error(result.message || 'Upload failed');
                }
                if (!el.config) el.config = {};
                el.config.src = result.url;
                updateElement(el);
                renderProperties(el);
                showToast('Image uploaded', 'success');
            })
            .catch(err => showToast(err.message || 'Upload failed', 'error'))
            .finally(() => {
                if (input) {
                    input.disabled = false;
                    input.value = '';
                }
            });
    }

    function updateElement(el) {
        updateElementInLayout(el);
        refreshCanvasElement(el);
    }

    function captureLayoutFromDom() {
        applyPaperDimensions();
        updateCanvasMarginsFromInputs();

        layout.elements.forEach(el => {
            if (el.type === 'table') {
                normalizeTableColumnWidths(el);
            }
            const item = document.querySelector(`[data-element-id="${el.id}"]`);
            if (item) {
                el.position.measuredHeight = Math.round(measureElementContentHeight(el, item));
            }
            ensureElementPosition(el);
        });

        layout.canvas.layoutMode = 'free';
    }

    function openLivePdfPreview() {
        if (!previewUrl) {
            showToast('Preview is not configured', 'error');
            return;
        }

        captureLayoutFromDom();

        fetch(previewUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ layout }),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Preview failed');
                }
                return response.blob();
            })
            .then((blob) => {
                const url = URL.createObjectURL(blob);
                window.open(url, '_blank', 'noopener,noreferrer');
                setTimeout(() => URL.revokeObjectURL(url), 60000);
            })
            .catch(() => showToast('Preview failed — save your layout and try again', 'error'));
    }

    function saveLayout() {
        const btn = document.getElementById('dt-save-btn');
        const spinner = document.getElementById('dt-save-spinner');
        if (btn) btn.disabled = true;
        if (spinner) spinner.classList.remove('hidden');

        captureLayoutFromDom();

        fetch(saveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ layout }),
        })
        .then(r => r.json())
        .then(result => {
            showToast(result.success ? 'Layout saved' : (result.message || 'Save failed'), result.success ? 'success' : 'error');
        })
        .catch(() => showToast('Save failed', 'error'))
        .finally(() => {
            if (btn) btn.disabled = false;
            if (spinner) spinner.classList.add('hidden');
        });
    }

    function showToast(message, type) {
        if (typeof dtToast === 'function') {
            dtToast(message, type);
            return;
        }
        const container = document.getElementById('dtToastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-error') + ' shadow-lg pointer-events-auto';
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    document.addEventListener('DOMContentLoaded', init);
})();
