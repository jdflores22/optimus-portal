(function () {
    const STEP_DEFINITIONS = {
        submit_accreditation: {
            targetId: 'guide-submit-accreditation',
            stepKey: 'submit_accreditation',
            title: 'Submit Accreditation',
            text: 'Get verified with your shipping lines to unlock all portal features. Tap this card to begin.',
        },
        link_brokers: {
            targetId: 'guide-link-brokers',
            stepKey: 'link_brokers',
            title: 'Link Brokers',
            text: 'Use referral codes to connect with brokers. Click Got it to continue to referral codes.',
            redirectConfigKey: 'referralCodesUrl',
        },
        generate_new_code: {
            targetId: 'guide-generate-new-code',
            stepKey: 'generate_new_code',
            title: 'Generate New Code',
            text: 'Click here to create a referral code. You will need one code for each broker you want to link.',
        },
        generate_referral_code_modal: {
            targetId: 'guide-generate-referral-modal',
            stepKey: 'generate_referral_code_modal',
            title: 'Generate Referral Code',
            text: 'Give this referral code to your broker so they can register and connect with your account. Each code works for one broker only.',
            openGenerateModal: true,
            redirectConfigKey: 'manifestListUrl',
        },
        view_manifest_list: {
            targetId: 'guide-manifest-list',
            stepKey: 'view_manifest_list',
            title: 'My Manifests',
            text: 'Track all manifests declared with your company. Search, filter, view details, and complete payments as your broker uploads shipments.',
            redirectConfigKey: 'dashboardUrl',
        },
    };

    function ConsigneeOnboardingGuide(config) {
        this.config = config;
        this.queue = Array.isArray(config.steps) ? config.steps.slice() : [];
        this.currentIndex = 0;
        this.overlay = document.getElementById('consigneeGuideOverlay');
        this.panels = {
            top: document.getElementById('consigneeGuidePanelTop'),
            left: document.getElementById('consigneeGuidePanelLeft'),
            right: document.getElementById('consigneeGuidePanelRight'),
            bottom: document.getElementById('consigneeGuidePanelBottom'),
        };
        this.highlight = document.getElementById('consigneeGuideHighlight');
        this.tooltip = document.getElementById('consigneeGuideTooltip');
        this.titleEl = document.getElementById('consigneeGuideTooltipTitle');
        this.textEl = document.getElementById('consigneeGuideTooltipText');
        this.stepLabelEl = document.getElementById('consigneeGuideStepLabel');
        this.activeTarget = null;
        this.activeStep = null;
        this.isOpen = false;
        this.overlayHomeParent = null;

        this.onResize = this.onResize.bind(this);
        this.onScroll = this.onScroll.bind(this);

        document.getElementById('consigneeGuideGotItBtn')?.addEventListener('click', () => this.advance());
        document.getElementById('consigneeGuideSkipBtn')?.addEventListener('click', () => this.skipAll());

        if (this.overlay && !this.overlayHomeParent) {
            this.overlayHomeParent = this.overlay.parentNode;
        }
    }

    ConsigneeOnboardingGuide.prototype.getViewportBounds = function () {
        return {
            top: 0,
            left: 0,
            right: window.innerWidth,
            bottom: window.innerHeight,
            width: window.innerWidth,
            height: window.innerHeight,
        };
    };

    ConsigneeOnboardingGuide.prototype.getHighlightBounds = function () {
        const padding = 8;
        const rect = this.activeTarget.getBoundingClientRect();

        return {
            top: rect.top - padding,
            left: rect.left - padding,
            right: rect.right + padding,
            bottom: rect.bottom + padding,
            width: rect.width + padding * 2,
            height: rect.height + padding * 2,
        };
    };

    ConsigneeOnboardingGuide.prototype.mountOverlay = function () {
        if (!this.overlay) {
            return;
        }

        if (this.overlay.parentNode !== document.body) {
            document.body.appendChild(this.overlay);
        }
    };

    ConsigneeOnboardingGuide.prototype.restoreOverlayHome = function () {
        if (!this.overlay || !this.overlayHomeParent) {
            return;
        }

        if (this.overlay.parentNode !== this.overlayHomeParent) {
            this.overlayHomeParent.appendChild(this.overlay);
        }
    };

    ConsigneeOnboardingGuide.prototype.openGenerateModalIfAvailable = function () {
        if (typeof window.openGenerateModal === 'function') {
            window.openGenerateModal();
        }
    };

    ConsigneeOnboardingGuide.prototype.closeGenerateModalIfOpen = function () {
        const modal = document.getElementById('generateModal');
        if (modal && !modal.classList.contains('hidden') && typeof window.closeGenerateModal === 'function') {
            window.closeGenerateModal();
        }
    };

    ConsigneeOnboardingGuide.prototype.setGuideModalBackdrop = function (hidden) {
        const modal = document.getElementById('generateModal');
        if (!modal) {
            return;
        }

        modal.classList.toggle('guide-backdrop-hidden', hidden);
    };

    ConsigneeOnboardingGuide.prototype.setGenerateModalLayer = function (active) {
        const modal = document.getElementById('generateModal');
        if (!modal) {
            return;
        }

        modal.classList.toggle('consignee-guide-modal-layer', active);
    };

    ConsigneeOnboardingGuide.prototype.prepareStep = function (stepName) {
        this.setGuideModalBackdrop(false);

        if (stepName === 'generate_referral_code_modal') {
            this.openGenerateModalIfAvailable();
            this.setGuideModalBackdrop(true);
        }
    };

    ConsigneeOnboardingGuide.prototype.start = function () {
        if (!this.config.enabled || this.isOpen || this.queue.length === 0) {
            return false;
        }

        return this.showStepAt(this.currentIndex);
    };

    ConsigneeOnboardingGuide.prototype.showStepAt = function (index) {
        if (index >= this.queue.length) {
            this.close(false);
            return false;
        }

        const stepName = this.queue[index];
        const step = STEP_DEFINITIONS[stepName];
        if (!step) {
            this.currentIndex = index + 1;
            return this.showStepAt(this.currentIndex);
        }

        this.prepareStep(stepName);

        const target = document.getElementById(step.targetId);
        if (!target || !this.overlay) {
            this.currentIndex = index + 1;
            return this.showStepAt(this.currentIndex);
        }

        this.clearHighlight();
        this.mountOverlay();

        this.currentIndex = index;
        this.activeStep = step;
        this.activeTarget = target;
        this.isOpen = true;

        target.classList.add('relative', 'z-[1000001]');
        this.setGenerateModalLayer(stepName === 'generate_referral_code_modal');
        this.overlay.classList.remove('hidden');
        this.overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        try {
            target.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'instant' });
        } catch (error) {
            target.scrollIntoView(true);
        }

        if (this.titleEl) {
            this.titleEl.textContent = step.title;
        }
        if (this.textEl) {
            this.textEl.textContent = step.text;
        }
        if (this.stepLabelEl) {
            this.stepLabelEl.textContent = 'Step ' + (index + 1) + ' of ' + this.queue.length;
        }

        window.setTimeout(() => this.position(), step.openGenerateModal ? 150 : 0);

        if (index === 0) {
            window.addEventListener('resize', this.onResize);
            window.addEventListener('scroll', this.onScroll, true);
        }

        return true;
    };

    ConsigneeOnboardingGuide.prototype.position = function () {
        if (!this.activeTarget || !this.overlay) {
            return;
        }

        const viewport = this.getViewportBounds();
        const hole = this.getHighlightBounds();

        const vTop = viewport.top;
        const vLeft = viewport.left;
        const vRight = viewport.right;
        const vBottom = viewport.bottom;

        const topHeight = Math.max(0, Math.min(hole.top, vBottom) - vTop);
        this.panels.top.style.top = vTop + 'px';
        this.panels.top.style.left = vLeft + 'px';
        this.panels.top.style.width = viewport.width + 'px';
        this.panels.top.style.height = topHeight + 'px';

        const bottomTop = Math.max(hole.bottom, vTop);
        const bottomHeight = Math.max(0, vBottom - bottomTop);
        this.panels.bottom.style.top = bottomTop + 'px';
        this.panels.bottom.style.left = vLeft + 'px';
        this.panels.bottom.style.width = viewport.width + 'px';
        this.panels.bottom.style.height = bottomHeight + 'px';

        const midTop = Math.max(hole.top, vTop);
        const midBottom = Math.min(hole.bottom, vBottom);
        const midHeight = Math.max(0, midBottom - midTop);

        const leftWidth = Math.max(0, Math.min(hole.left, vRight) - vLeft);
        this.panels.left.style.top = midTop + 'px';
        this.panels.left.style.left = vLeft + 'px';
        this.panels.left.style.width = leftWidth + 'px';
        this.panels.left.style.height = midHeight + 'px';

        const rightLeft = Math.max(hole.right, vLeft);
        const rightWidth = Math.max(0, vRight - rightLeft);
        this.panels.right.style.top = midTop + 'px';
        this.panels.right.style.left = rightLeft + 'px';
        this.panels.right.style.width = rightWidth + 'px';
        this.panels.right.style.height = midHeight + 'px';

        this.highlight.style.top = hole.top + 'px';
        this.highlight.style.left = hole.left + 'px';
        this.highlight.style.width = hole.width + 'px';
        this.highlight.style.height = hole.height + 'px';

        const tooltipWidth = this.tooltip.offsetWidth || 320;
        const tooltipHeight = this.tooltip.offsetHeight || 180;
        let tooltipTop = hole.bottom + 16;
        let tooltipLeft = hole.left;

        if (tooltipTop + tooltipHeight > window.innerHeight - 16) {
            tooltipTop = Math.max(16, hole.top - tooltipHeight - 16);
        }

        if (tooltipLeft + tooltipWidth > window.innerWidth - 16) {
            tooltipLeft = Math.max(16, window.innerWidth - tooltipWidth - 16);
        }

        this.tooltip.style.top = tooltipTop + 'px';
        this.tooltip.style.left = tooltipLeft + 'px';
    };

    ConsigneeOnboardingGuide.prototype.onResize = function () {
        this.position();
    };

    ConsigneeOnboardingGuide.prototype.onScroll = function () {
        this.position();
    };

    ConsigneeOnboardingGuide.prototype.persistStep = function (stepKey) {
        return fetch(this.config.completeStepUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: new URLSearchParams({ step: stepKey }),
        }).catch(function () {
            return null;
        });
    };

    ConsigneeOnboardingGuide.prototype.advance = function () {
        if (!this.isOpen || !this.activeStep) {
            return;
        }

        const completedStepKey = this.activeStep.stepKey;

        const redirectUrl = this.activeStep.redirectConfigKey
            ? this.config[this.activeStep.redirectConfigKey]
            : null;
        if (redirectUrl) {
            const shouldCloseGenerateModal = completedStepKey === 'generate_referral_code_modal';
            this.persistStep(completedStepKey).finally(() => {
                this.close(shouldCloseGenerateModal);
                window.location.href = redirectUrl;
            });
            return;
        }

        this.persistStep(completedStepKey);

        const nextIndex = this.currentIndex + 1;
        if (nextIndex < this.queue.length) {
            if (completedStepKey === 'generate_new_code') {
                this.openGenerateModalIfAvailable();
            }

            window.setTimeout(() => {
                this.showStepAt(nextIndex);
            }, completedStepKey === 'generate_new_code' ? 300 : 200);
            return;
        }

        this.close(true);
    };

    ConsigneeOnboardingGuide.prototype.skipAll = function () {
        if (!this.isOpen) {
            return;
        }

        const remaining = this.queue.slice(this.currentIndex);
        remaining.forEach((stepName) => {
            const step = STEP_DEFINITIONS[stepName];
            if (step) {
                this.persistStep(step.stepKey);
            }
        });

        this.close(true);
    };

    ConsigneeOnboardingGuide.prototype.clearHighlight = function () {
        if (this.activeTarget) {
            this.activeTarget.classList.remove('relative', 'z-[1000001]');
        }
    };

    ConsigneeOnboardingGuide.prototype.close = function (closeGenerateModal) {
        this.clearHighlight();
        this.setGuideModalBackdrop(false);
        this.setGenerateModalLayer(false);

        if (this.overlay) {
            this.overlay.classList.add('hidden');
            this.overlay.setAttribute('aria-hidden', 'true');
        }

        document.body.style.overflow = '';
        window.removeEventListener('resize', this.onResize);
        window.removeEventListener('scroll', this.onScroll, true);

        this.restoreOverlayHome();

        if (closeGenerateModal) {
            this.closeGenerateModalIfOpen();
        }

        this.isOpen = false;
        this.activeTarget = null;
        this.activeStep = null;
    };

    window.ConsigneeOnboardingGuide = ConsigneeOnboardingGuide;
    window.ConsigneeDashboardGuide = ConsigneeOnboardingGuide;

    window.initConsigneeOnboardingGuide = function (config) {
        const guide = new ConsigneeOnboardingGuide(config);
        window.consigneeOnboardingGuide = guide;
        window.consigneeDashboardGuide = guide;
        return guide;
    };

    window.initConsigneeDashboardGuide = window.initConsigneeOnboardingGuide;
})();
