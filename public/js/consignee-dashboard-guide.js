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
            text: 'Use referral codes to connect with brokers who can manage manifests and documentation on your behalf.',
        },
    };

    function ConsigneeDashboardGuide(config) {
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

        this.onResize = this.onResize.bind(this);
        this.onScroll = this.onScroll.bind(this);

        document.getElementById('consigneeGuideGotItBtn')?.addEventListener('click', () => this.advance());
        document.getElementById('consigneeGuideSkipBtn')?.addEventListener('click', () => this.skipAll());
    }

    ConsigneeDashboardGuide.prototype.start = function () {
        if (!this.config.enabled || this.isOpen || this.queue.length === 0) {
            return false;
        }

        return this.showStepAt(this.currentIndex);
    };

    ConsigneeDashboardGuide.prototype.showStepAt = function (index) {
        if (index >= this.queue.length) {
            this.close();
            return false;
        }

        const stepName = this.queue[index];
        const step = STEP_DEFINITIONS[stepName];
        if (!step) {
            this.currentIndex = index + 1;
            return this.showStepAt(this.currentIndex);
        }

        const target = document.getElementById(step.targetId);
        if (!target || !this.overlay) {
            this.currentIndex = index + 1;
            return this.showStepAt(this.currentIndex);
        }

        this.clearHighlight();

        this.currentIndex = index;
        this.activeStep = step;
        this.activeTarget = target;
        this.isOpen = true;

        target.classList.add('relative', 'z-[10007]');
        this.overlay.classList.remove('hidden');
        this.overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        if (this.titleEl) {
            this.titleEl.textContent = step.title;
        }
        if (this.textEl) {
            this.textEl.textContent = step.text;
        }
        if (this.stepLabelEl) {
            this.stepLabelEl.textContent = 'Step ' + (index + 1) + ' of ' + this.queue.length;
        }

        this.position();

        if (index === 0) {
            window.addEventListener('resize', this.onResize);
            window.addEventListener('scroll', this.onScroll, true);
        }

        return true;
    };

    ConsigneeDashboardGuide.prototype.position = function () {
        if (!this.activeTarget || !this.overlay) {
            return;
        }

        const rect = this.activeTarget.getBoundingClientRect();
        const padding = 8;
        const top = Math.max(0, rect.top - padding);
        const left = Math.max(0, rect.left - padding);
        const width = rect.width + padding * 2;
        const height = rect.height + padding * 2;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        this.panels.top.style.height = top + 'px';
        this.panels.left.style.top = top + 'px';
        this.panels.left.style.width = left + 'px';
        this.panels.left.style.height = height + 'px';
        this.panels.right.style.top = top + 'px';
        this.panels.right.style.left = left + width + 'px';
        this.panels.right.style.width = Math.max(0, viewportWidth - left - width) + 'px';
        this.panels.right.style.height = height + 'px';
        this.panels.bottom.style.top = top + height + 'px';
        this.panels.bottom.style.height = Math.max(0, viewportHeight - top - height) + 'px';

        this.highlight.style.top = top + 'px';
        this.highlight.style.left = left + 'px';
        this.highlight.style.width = width + 'px';
        this.highlight.style.height = height + 'px';

        const tooltipWidth = this.tooltip.offsetWidth || 320;
        const tooltipHeight = this.tooltip.offsetHeight || 180;
        let tooltipTop = top + height + 16;
        let tooltipLeft = left;

        if (tooltipTop + tooltipHeight > viewportHeight - 16) {
            tooltipTop = Math.max(16, top - tooltipHeight - 16);
        }

        if (tooltipLeft + tooltipWidth > viewportWidth - 16) {
            tooltipLeft = Math.max(16, viewportWidth - tooltipWidth - 16);
        }

        this.tooltip.style.top = tooltipTop + 'px';
        this.tooltip.style.left = tooltipLeft + 'px';
    };

    ConsigneeDashboardGuide.prototype.onResize = function () {
        this.position();
    };

    ConsigneeDashboardGuide.prototype.onScroll = function () {
        this.position();
    };

    ConsigneeDashboardGuide.prototype.persistStep = function (stepKey) {
        return fetch(this.config.completeStepUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: new URLSearchParams({ step: stepKey }),
        }).catch(function () {
            return null;
        });
    };

    ConsigneeDashboardGuide.prototype.advance = function () {
        if (!this.isOpen || !this.activeStep) {
            return;
        }

        const completedStep = this.activeStep.stepKey;
        this.persistStep(completedStep);

        const nextIndex = this.currentIndex + 1;
        if (nextIndex < this.queue.length) {
            window.setTimeout(() => {
                this.showStepAt(nextIndex);
            }, 200);
            return;
        }

        this.close();
    };

    ConsigneeDashboardGuide.prototype.skipAll = function () {
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

        this.close();
    };

    ConsigneeDashboardGuide.prototype.clearHighlight = function () {
        if (this.activeTarget) {
            this.activeTarget.classList.remove('z-[10007]');
        }
    };

    ConsigneeDashboardGuide.prototype.close = function () {
        this.clearHighlight();

        if (this.overlay) {
            this.overlay.classList.add('hidden');
            this.overlay.setAttribute('aria-hidden', 'true');
        }

        document.body.style.overflow = '';
        window.removeEventListener('resize', this.onResize);
        window.removeEventListener('scroll', this.onScroll, true);

        this.isOpen = false;
        this.activeTarget = null;
        this.activeStep = null;
    };

    window.ConsigneeDashboardGuide = ConsigneeDashboardGuide;

    window.initConsigneeDashboardGuide = function (config) {
        const guide = new ConsigneeDashboardGuide(config);
        window.consigneeDashboardGuide = guide;
        return guide;
    };
})();
