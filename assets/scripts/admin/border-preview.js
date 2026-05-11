/**
 * Border Configuration Preview for EasyAdmin
 * Provides live preview of border configuration in admin forms
 */

document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on a page with border configuration
    const borderForm = document.querySelector('.border-colors-collection');
    console.log(borderForm);
    if (!borderForm) {
        return;
    }

    // Create preview container if it doesn't exist
    let previewContainer = document.querySelector('.border-preview-container');
    if (!previewContainer) {
        previewContainer = createPreviewContainer();
        // Insert after the form or at the top
        const formContainer = document.querySelector('form');
        if (formContainer) {
            formContainer.insertAdjacentElement('afterbegin', previewContainer);
        }
    }

    // Initialize preview
    updatePreview();

    // Listen to all form changes
    document.querySelectorAll('.border-preview-control').forEach(input => {
        input.addEventListener('input', updatePreview);
        input.addEventListener('change', updatePreview);
    });

    // Handle color collection changes (add/remove)
    observeCollectionChanges();

    // Handle JSON import/export toggle
    const jsonToggle = document.querySelector('.border-json-toggle');
    const jsonTextarea = document.querySelector('.border-json-textarea');

    if (jsonToggle && jsonTextarea) {
        jsonToggle.addEventListener('click', function() {
            if (jsonTextarea.style.display === 'none') {
                // Export current config to JSON
                const config = getCurrentConfig();
                jsonTextarea.value = JSON.stringify(config, null, 2);
                jsonTextarea.style.display = 'block';
            } else {
                jsonTextarea.style.display = 'none';
            }
        });

        // Import JSON when textarea changes
        jsonTextarea.addEventListener('blur', function() {
            try {
                const config = JSON.parse(this.value);
                applyConfigToForm(config);
                updatePreview();
            } catch (e) {
                console.error('Invalid JSON:', e);
            }
        });
    }
});

function createPreviewContainer() {
    const container = document.createElement('div');
    container.className = 'border-preview-container';
    container.style.cssText = 'margin-bottom: 20px; padding: 20px; background: #f5f5f5; border-radius: 8px;';

    container.innerHTML = `
        <h3 style="margin-top: 0;">Preview de la bordure</h3>
        <div style="display: flex; justify-content: center; align-items: center; min-height: 300px; background: #1e293b; border-radius: 8px; padding: 20px;">
            <div id="border-preview-card" style="position: relative; display: inline-block;">
                <img src="/images/cards/placeholder.png"
                     alt="Preview"
                     style="width: 300px; height: auto; display: block; border-radius: 12px;"
                     onerror="this.style.background='linear-gradient(135deg, #667eea 0%, #764ba2 100%)'; this.style.minHeight='400px';">
            </div>
        </div>
    `;

    return container;
}

function updatePreview() {
    const config = getCurrentConfig();
    applyBorderStyles(config);
}

function getCurrentConfig() {
    // Get colors from collection
    const colors = [];
    document.querySelectorAll('.border-color-input').forEach(input => {
        if (input.value) {
            colors.push(input.value);
        }
    });

    // Get other fields
    const width = parseInt(document.querySelector('input[name*="[width]"]')?.value || 4);
    const radius = parseInt(document.querySelector('input[name*="[radius]"]')?.value || 16);
    const angle = parseInt(document.querySelector('input[name*="[angle]"]')?.value || 135);

    // Get glow config
    const glowEnabled = document.querySelector('input[name*="[glow][enabled]"]')?.checked || false;
    const glowIntensity = parseInt(document.querySelector('input[name*="[glow][intensity]"]')?.value || 50);
    const glowColor = document.querySelector('input[name*="[glow][color]"]')?.value || '#9333ea';
    const glowPulse = document.querySelector('input[name*="[glow][pulse]"]')?.checked || false;

    // Get fade config
    const fadeEnabled = document.querySelector('input[name*="[fade][enabled]"]')?.checked || false;
    const fadeDirection = parseInt(document.querySelector('input[name*="[fade][direction]"]')?.value || 135);
    const fadeStart = parseInt(document.querySelector('input[name*="[fade][start]"]')?.value || 50);
    const fadeLength = parseInt(document.querySelector('input[name*="[fade][length]"]')?.value || 50);

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

function applyBorderStyles(config) {
    const previewCard = document.getElementById('border-preview-card');
    if (!previewCard) return;

    // Build gradient
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

            // Create pulse animation
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
    styleEl.textContent = `
        #border-preview-card {
            position: relative;
            display: inline-block;
        }

        #border-preview-card::before {
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

        #border-preview-card img {
            position: relative;
            z-index: 1;
            border-radius: ${Math.max(0, config.radius - config.width)}px;
        }
    `;
}

function applyConfigToForm(config) {
    // Apply colors (this is complex, we'd need to manipulate the collection)
    // For now, just update simple fields

    if (config.width !== undefined) {
        const widthInput = document.querySelector('input[name*="[width]"]');
        if (widthInput) widthInput.value = config.width;
    }

    if (config.radius !== undefined) {
        const radiusInput = document.querySelector('input[name*="[radius]"]');
        if (radiusInput) radiusInput.value = config.radius;
    }

    if (config.angle !== undefined) {
        const angleInput = document.querySelector('input[name*="[angle]"]');
        if (angleInput) angleInput.value = config.angle;
    }

    // Apply glow config
    if (config.glow) {
        const glowEnabledInput = document.querySelector('input[name*="[glow][enabled]"]');
        if (glowEnabledInput) glowEnabledInput.checked = config.glow.enabled;

        if (config.glow.intensity !== undefined) {
            const glowIntensityInput = document.querySelector('input[name*="[glow][intensity]"]');
            if (glowIntensityInput) glowIntensityInput.value = config.glow.intensity;
        }

        if (config.glow.color) {
            const glowColorInput = document.querySelector('input[name*="[glow][color]"]');
            if (glowColorInput) glowColorInput.value = config.glow.color;
        }

        const glowPulseInput = document.querySelector('input[name*="[glow][pulse]"]');
        if (glowPulseInput) glowPulseInput.checked = config.glow.pulse;
    }

    // Apply fade config
    if (config.fade) {
        const fadeEnabledInput = document.querySelector('input[name*="[fade][enabled]"]');
        if (fadeEnabledInput) fadeEnabledInput.checked = config.fade.enabled;

        if (config.fade.direction !== undefined) {
            const fadeDirectionInput = document.querySelector('input[name*="[fade][direction]"]');
            if (fadeDirectionInput) fadeDirectionInput.value = config.fade.direction;
        }

        if (config.fade.start !== undefined) {
            const fadeStartInput = document.querySelector('input[name*="[fade][start]"]');
            if (fadeStartInput) fadeStartInput.value = config.fade.start;
        }

        if (config.fade.length !== undefined) {
            const fadeLengthInput = document.querySelector('input[name*="[fade][length]"]');
            if (fadeLengthInput) fadeLengthInput.value = config.fade.length;
        }
    }
}

function observeCollectionChanges() {
    // Watch for changes in the colors collection
    const collectionContainer = document.querySelector('.border-colors-collection');
    if (!collectionContainer) return;

    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length || mutation.removedNodes.length) {
                // Re-attach listeners to new inputs
                document.querySelectorAll('.border-color-input').forEach(input => {
                    input.removeEventListener('input', updatePreview);
                    input.addEventListener('input', updatePreview);
                });
                updatePreview();
            }
        });
    });

    observer.observe(collectionContainer, {
        childList: true,
        subtree: true
    });
}
