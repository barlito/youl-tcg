import { Controller } from '@hotwired/stimulus';

/**
 * Border Configuration Preview Controller
 * Provides live preview of border configuration in admin forms
 */
export default class extends Controller {
    static targets = [
        'preview',
        'previewCard',
        'colorInput',
        'widthInput',
        'radiusInput',
        'angleInput',
        'glowEnabled',
        'glowIntensity',
        'glowColor',
        'glowPulse',
        'fadeEnabled',
        'fadeDirection',
        'fadeStart',
        'fadeLength',
        'jsonToggle',
        'jsonTextarea'
    ];

    connect() {
        console.log('Border preview controller connected');

        // Create preview container if it doesn't exist
        if (!this.hasPreviewTarget) {
            this.createPreviewContainer();
        }

        // Wrap form content in a grid layout
        this.setupGridLayout();

        // Initial render
        this.updatePreview();

        // Observe collection changes for dynamic color inputs
        this.observeColorCollection();
    }

    setupGridLayout() {
        // Find the preview and form fields
        const preview = this.element.querySelector('.border-preview-container');
        const formFields = this.element.querySelector('.form-widget');

        if (!preview || !formFields) return;

        // Create wrapper with CSS Grid
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'display: grid; grid-template-columns: 1fr 400px; gap: 20px; align-items: start;';

        // Move preview to wrapper
        formFields.parentNode.insertBefore(wrapper, formFields);
        wrapper.appendChild(formFields);
        wrapper.appendChild(preview);

        // Make preview sticky
        preview.style.cssText = 'position: sticky; top: 20px; padding: 20px; background: #f5f5f5; border-radius: 8px;';
    }

    createPreviewContainer() {
        const container = document.createElement('div');
        container.className = 'border-preview-container';
        container.dataset.borderPreviewTarget = 'preview';

        container.innerHTML = `
            <h3 style="margin-top: 0;">Preview de la bordure</h3>
            <div style="display: flex; justify-content: center; align-items: center; min-height: 300px; background: #1e293b; border-radius: 8px; padding: 20px;">
                <div data-border-preview-target="previewCard" style="position: relative; display: inline-block;">
                    <img src="/images/cards/default_card.png"
                         alt="Preview"
                         style="width: 250px; height: auto; display: block; border-radius: 12px;"
                         onerror="this.style.background='linear-gradient(135deg, #667eea 0%, #764ba2 100%)'; this.style.minHeight='350px'; this.alt='';">
                </div>
            </div>
        `;

        // Insert at the beginning of the form
        this.element.insertAdjacentElement('afterbegin', container);
    }

    // Called on any form input change
    updatePreview() {
        console.log('Updating preview...');
        const config = this.getCurrentConfig();
        console.log('Config:', config);
        this.applyBorderStyles(config);
    }

    getCurrentConfig() {
        // Get colors from all color inputs (including dynamically added ones)
        const colors = [];
        const allColorInputs = this.element.querySelectorAll('input[type="color"]');
        console.log('Found color inputs:', allColorInputs.length);

        allColorInputs.forEach(input => {
            if (input.value) {
                colors.push(input.value);
            }
        });

        // Get other fields with fallbacks
        const width = this.hasWidthInputTarget ? parseInt(this.widthInputTarget.value) : 4;
        const radius = this.hasRadiusInputTarget ? parseInt(this.radiusInputTarget.value) : 16;
        const angle = this.hasAngleInputTarget ? parseInt(this.angleInputTarget.value) : 135;

        // Glow config
        const glowEnabled = this.hasGlowEnabledTarget ? this.glowEnabledTarget.checked : false;
        const glowIntensity = this.hasGlowIntensityTarget ? parseInt(this.glowIntensityTarget.value) : 50;
        const glowColor = this.hasGlowColorTarget ? this.glowColorTarget.value : '#9333ea';
        const glowPulse = this.hasGlowPulseTarget ? this.glowPulseTarget.checked : false;

        // Fade config
        const fadeEnabled = this.hasFadeEnabledTarget ? this.fadeEnabledTarget.checked : false;
        const fadeDirection = this.hasFadeDirectionTarget ? parseInt(this.fadeDirectionTarget.value) : 135;
        const fadeStart = this.hasFadeStartTarget ? parseInt(this.fadeStartTarget.value) : 50;
        const fadeLength = this.hasFadeLengthTarget ? parseInt(this.fadeLengthTarget.value) : 50;

        return {
            colors: colors.length > 0 ? colors : ['#9333ea', '#ec4899'],
            width,
            radius,
            angle,
            glow: {
                enabled: glowEnabled,
                intensity: glowIntensity,
                color: glowColor,
                pulse: glowPulse
            },
            fade: {
                enabled: fadeEnabled,
                direction: fadeDirection,
                start: fadeStart,
                length: fadeLength
            }
        };
    }

    applyBorderStyles(config) {
        if (!this.hasPreviewCardTarget) return;

        const gradient = `linear-gradient(${config.angle}deg, ${config.colors.join(', ')})`;

        // Create/update dynamic styles
        let styleEl = document.getElementById('border-preview-styles');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'border-preview-styles';
            document.head.appendChild(styleEl);
        }

        // Build mask for fade
        let maskStyles = '';
        if (config.fade.enabled) {
            const fadeStartPercent = config.fade.start;
            const fadeEndPercent = Math.min(100, fadeStartPercent + config.fade.length);
            const maskGradient = `linear-gradient(${config.fade.direction}deg, black 0%, black ${fadeStartPercent}%, transparent ${fadeEndPercent}%)`;

            maskStyles = `
                -webkit-mask-image: ${maskGradient};
                mask-image: ${maskGradient};
            `;
        }

        // Build glow styles
        let glowStyles = '';
        if (config.glow.enabled) {
            const glowSize = (config.glow.intensity / 100) * 60;
            const normalShadow = `0 0 ${glowSize * 0.5}px ${config.glow.color}, 0 0 ${glowSize}px ${config.glow.color}80`;

            if (config.glow.pulse) {
                const peakShadow = `0 0 ${glowSize * 0.75}px ${config.glow.color}, 0 0 ${glowSize * 1.5}px ${config.glow.color}cc`;

                let pulseStyleEl = document.getElementById('border-pulse-animation');
                if (!pulseStyleEl) {
                    pulseStyleEl = document.createElement('style');
                    pulseStyleEl.id = 'border-pulse-animation';
                    document.head.appendChild(pulseStyleEl);
                }

                pulseStyleEl.textContent = `
                    @keyframes border-preview-pulse {
                        0%, 100% { box-shadow: ${normalShadow}; }
                        50% { box-shadow: ${peakShadow}; }
                    }
                `;

                glowStyles = `
                    box-shadow: ${normalShadow};
                    animation: border-preview-pulse 2s ease-in-out infinite;
                `;
            } else {
                glowStyles = `box-shadow: ${normalShadow};`;
            }
        }

        // Apply styles
        const uniqueId = this.element.id || 'border-preview-card';
        styleEl.textContent = `
            [data-border-preview-target="previewCard"] {
                position: relative;
                display: inline-block;
            }

            [data-border-preview-target="previewCard"]::before {
                content: '';
                position: absolute;
                top: -${config.width}px;
                left: -${config.width}px;
                right: -${config.width}px;
                bottom: -${config.width}px;
                background: ${gradient};
                border-radius: ${config.radius}px;
                z-index: -1;
                ${maskStyles}
                ${glowStyles}
            }

            [data-border-preview-target="previewCard"] img {
                position: relative;
                z-index: 1;
                border-radius: ${Math.max(0, config.radius - config.width)}px;
            }
        `;
    }

    // Toggle JSON import/export
    toggleJson() {
        if (!this.hasJsonTextareaTarget) return;

        if (this.jsonTextareaTarget.style.display === 'none') {
            // Export current config to JSON
            const config = this.getCurrentConfig();
            this.jsonTextareaTarget.value = JSON.stringify(config, null, 2);
            this.jsonTextareaTarget.style.display = 'block';
        } else {
            this.jsonTextareaTarget.style.display = 'none';
        }
    }

    // Import JSON configuration
    importJson() {
        if (!this.hasJsonTextareaTarget) return;

        try {
            const config = JSON.parse(this.jsonTextareaTarget.value);
            this.applyConfigToForm(config);
            this.updatePreview();
        } catch (e) {
            console.error('Invalid JSON:', e);
            alert('Configuration JSON invalide');
        }
    }

    applyConfigToForm(config) {
        if (config.width !== undefined && this.hasWidthInputTarget) {
            this.widthInputTarget.value = config.width;
        }

        if (config.radius !== undefined && this.hasRadiusInputTarget) {
            this.radiusInputTarget.value = config.radius;
        }

        if (config.angle !== undefined && this.hasAngleInputTarget) {
            this.angleInputTarget.value = config.angle;
        }

        // Apply glow config
        if (config.glow) {
            if (this.hasGlowEnabledTarget) {
                this.glowEnabledTarget.checked = config.glow.enabled;
            }
            if (config.glow.intensity !== undefined && this.hasGlowIntensityTarget) {
                this.glowIntensityTarget.value = config.glow.intensity;
            }
            if (config.glow.color && this.hasGlowColorTarget) {
                this.glowColorTarget.value = config.glow.color;
            }
            if (this.hasGlowPulseTarget) {
                this.glowPulseTarget.checked = config.glow.pulse;
            }
        }

        // Apply fade config
        if (config.fade) {
            if (this.hasFadeEnabledTarget) {
                this.fadeEnabledTarget.checked = config.fade.enabled;
            }
            if (config.fade.direction !== undefined && this.hasFadeDirectionTarget) {
                this.fadeDirectionTarget.value = config.fade.direction;
            }
            if (config.fade.start !== undefined && this.hasFadeStartTarget) {
                this.fadeStartTarget.value = config.fade.start;
            }
            if (config.fade.length !== undefined && this.hasFadeLengthTarget) {
                this.fadeLengthTarget.value = config.fade.length;
            }
        }
    }

    observeColorCollection() {
        console.log('Setting up collection observer');

        // Watch the entire form for changes (EasyAdmin adds elements dynamically)
        const observer = new MutationObserver((mutations) => {
            console.log('DOM mutation detected', mutations.length);

            // Re-attach event listeners to all color inputs
            const allColorInputs = this.element.querySelectorAll('input[type="color"]');
            allColorInputs.forEach(input => {
                if (!input.dataset.listenerAttached) {
                    input.addEventListener('input', () => this.updatePreview());
                    input.dataset.listenerAttached = 'true';
                    console.log('Attached listener to new color input');
                }
            });

            this.updatePreview();
        });

        observer.observe(this.element, {
            childList: true,
            subtree: true
        });
    }
}
