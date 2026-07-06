/**
 * CardTilt — framework-agnostic 3D card tilt + holo driver.
 *
 * Vanilla ES module, ZERO dependencies (no Stimulus, no build step needed). It is
 * the reusable core of the card effect: copy this file + the card CSS
 * (assets/styles/cards/base.css + holo.css) + the card markup and it works in any
 * app. The Stimulus controller in this project is just a thin wrapper around it.
 *
 * Public contract (the "API" the CSS reads):
 *   Required markup (any rarity via data-rarity on the root):
 *     <div class="card interactive" data-rarity="legendary">
 *       <div class="card__translater"><div class="card__rotator">
 *         <div class="card__front">
 *           <img src="…">
 *           <div class="card__shine"></div>
 *           <div class="card__glare"></div>
 *         </div>
 *       </div></div>
 *     </div>
 *   Per-frame CSS custom properties written on the root element:
 *     --pointer-x / --pointer-y          pointer position over the card (%)
 *     --pointer-from-center              0 at center → 1 at the edges
 *     --pointer-from-top / --from-left   normalised pointer (0–1)
 *     --background-x / --background-y    damped gradient position (%)
 *     --card-opacity                     0 (idle) → 1 (active), drives the layers
 *   and `transform: rotateX/Y` on `.card__rotator`; the `interacting` class is
 *   toggled on the root while the spring loop runs (CSS hides the layers at rest).
 *
 * Tuning the look is pure CSS (holo.css): --holo-intensity / --holo-saturation /
 * --holo-glitter, set per rarity and overridable inline per card.
 */

const SPRING_STIFFNESS = 160;
const SPRING_DAMPING = 22;
// Softer spring for the click-to-zoom popover (scale / translate): slower, more
// damped so the card glides to centre rather than snapping.
const POPOVER_STIFFNESS = 90;
const POPOVER_DAMPING = 18;
// Largest zoom factor when a card is activated (pokeholo uses 1.75).
const MAX_POPOVER_SCALE = 1.75;
// Full turn the card makes around Y while gliding to the viewport centre —
// the click-to-zoom reads as "the card flips out of the grid into your hand".
const POPOVER_FLIP_DEG = 360;
// Clamp dt so a backgrounded tab doesn't make the integration explode.
const MAX_DT = 0.032;
const SETTLE_THRESHOLD = 0.01;

const clamp = (value, min = 0, max = 100) => Math.min(Math.max(value, min), max);
const round = (value, precision = 3) => parseFloat(value.toFixed(precision));
const adjust = (value, fromMin, fromMax, toMin, toMax) =>
    round(toMin + (toMax - toMin) * (value - fromMin) / (fromMax - fromMin));

// Only one card may be zoomed in at a time across the whole page.
let activeInstance = null;

export class CardTilt {
    constructor(element) {
        this.element = element;
        this.rotator = element.querySelector('.card__rotator');
        // The zoom translate/scale lives on the translater, NOT on the root:
        // every pointer/centre computation must measure the translater's rect,
        // or a zoomed card tracks the mouse against its empty grid slot.
        this.translater = element.querySelector('.card__translater') || element;
        this.springs = {
            rotateX: this._spring(0),
            rotateY: this._spring(0),
            glareX: this._spring(50),
            glareY: this._spring(50),
            opacity: this._spring(0),
            // popover springs (click-to-zoom): centre translation + scale + flip
            scale: this._spring(1),
            translateX: this._spring(0),
            translateY: this._spring(0),
            flip: this._spring(0),
        };
        this.pointerInside = false;
        this.active = false;
        this.frame = null;
        this.lastTime = 0;

        // Click-to-zoom is disabled inside the booster opening (it owns its own
        // click handling for the flip), but enabled everywhere else.
        this.zoomEnabled = !element.closest('[data-controller~="booster-opening"]');

        this._onPointerMove = (event) => this._handlePointerMove(event);
        this._onPointerLeave = () => this._handlePointerLeave();
        element.addEventListener('pointermove', this._onPointerMove);
        element.addEventListener('pointerleave', this._onPointerLeave);

        if (this.zoomEnabled) {
            this._onClick = (event) => this._handleClick(event);
            this._onKeyDown = (event) => { if (event.key === 'Escape') this.deactivate(); };
            this._onReposition = () => { if (this.active) this._setCenter(); };
            element.addEventListener('click', this._onClick);
        }
    }

    /** Detach listeners and stop the loop. Call when removing the card. */
    destroy() {
        this.element.removeEventListener('pointermove', this._onPointerMove);
        this.element.removeEventListener('pointerleave', this._onPointerLeave);
        if (this.zoomEnabled) {
            this.element.removeEventListener('click', this._onClick);
            this._unbindActiveListeners();
        }
        if (activeInstance === this) {
            activeInstance = null;
        }
        this._restoreAncestors();
        this._stopLoop();
    }

    _spring(value) {
        return { value, velocity: 0, target: value };
    }

    /** Toggle the zoomed/centred popover state for this card. */
    _handleClick() {
        if (this.active) {
            this.deactivate();
        } else {
            this.activate();
        }
    }

    /** Zoom this card in, centred in the viewport (deactivates any other). */
    activate() {
        if (activeInstance && activeInstance !== this) {
            activeInstance.deactivate();
        }
        activeInstance = this;
        this.active = true;
        this.element.classList.add('active');

        const rect = this.translater.getBoundingClientRect();
        const scaleW = (window.innerWidth / rect.width) * 0.9;
        const scaleH = (window.innerHeight / rect.height) * 0.9;
        this.springs.scale.target = Math.min(scaleW, scaleH, MAX_POPOVER_SCALE);
        this.springs.flip.target = POPOVER_FLIP_DEG;
        this._boostAncestors();
        this._setCenter();

        this._bindActiveListeners();
        this._startLoop();
    }

    /** Return this card to its place in the grid. */
    deactivate() {
        if (!this.active) {
            return;
        }
        this.active = false;
        this.element.classList.remove('active');
        this.springs.scale.target = 1;
        this.springs.translateX.target = 0;
        this.springs.translateY.target = 0;
        this.springs.flip.target = 0; // spins back the other way on the way home
        if (activeInstance === this) {
            activeInstance = null;
        }
        this._unbindActiveListeners();
        this._startLoop();
    }

    /** Aim the translate springs so the card sits at the viewport centre. */
    _setCenter() {
        const rect = this.translater.getBoundingClientRect();
        // The translater's rect includes the current translate, so add it back
        // to get the delta from the card's resting position to the centre.
        this.springs.translateX.target = round(
            window.innerWidth / 2 - rect.left - rect.width / 2 + this.springs.translateX.value,
        );
        this.springs.translateY.target = round(
            window.innerHeight / 2 - rect.top - rect.height / 2 + this.springs.translateY.value,
        );
    }

    _bindActiveListeners() {
        document.addEventListener('keydown', this._onKeyDown);
        window.addEventListener('resize', this._onReposition);
        window.addEventListener('scroll', this._onReposition, true);
        // a click anywhere outside this card closes the popover
        this._onOutsideClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.deactivate();
            }
        };
        // defer so the activating click doesn't immediately close it
        setTimeout(() => document.addEventListener('click', this._onOutsideClick), 0);
    }

    _unbindActiveListeners() {
        document.removeEventListener('keydown', this._onKeyDown);
        window.removeEventListener('resize', this._onReposition);
        window.removeEventListener('scroll', this._onReposition, true);
        if (this._onOutsideClick) {
            document.removeEventListener('click', this._onOutsideClick);
            this._onOutsideClick = null;
        }
    }

    /**
     * A zoomed card must paint above everything, but any ancestor that creates
     * a stacking context (transform/filter/z-index/opacity wrappers — the
     * homepage hero does exactly that) traps its z-index. While active, lift
     * every such ancestor; restored once the card has settled back home.
     */
    _boostAncestors() {
        if (this._boosted) {
            return;
        }
        this._boosted = [];
        let node = this.element.parentElement;
        while (node && node !== document.body) {
            const style = getComputedStyle(node);
            const createsContext = style.zIndex !== 'auto'
                || style.transform !== 'none'
                || style.filter !== 'none'
                || (style.backdropFilter && style.backdropFilter !== 'none')
                || parseFloat(style.opacity) < 1
                || style.isolation === 'isolate'
                || style.willChange.includes('transform')
                || style.willChange.includes('opacity');
            if (createsContext) {
                this._boosted.push([node, node.style.position, node.style.zIndex]);
                if (style.position === 'static') {
                    node.style.position = 'relative'; // z-index needs a positioned box
                }
                node.style.zIndex = '500';
            }
            node = node.parentElement;
        }
    }

    _restoreAncestors() {
        if (!this._boosted) {
            return;
        }
        for (const [node, position, zIndex] of this._boosted) {
            node.style.position = position;
            node.style.zIndex = zIndex;
        }
        this._boosted = null;
    }

    _handlePointerMove(event) {
        const rect = this.translater.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        const percentX = clamp((100 / rect.width) * (event.clientX - rect.left));
        const percentY = clamp((100 / rect.height) * (event.clientY - rect.top));

        this.pointerInside = true;
        this.springs.rotateX.target = (percentY - 50) / 4;
        this.springs.rotateY.target = -(percentX - 50) / 7;
        this.springs.glareX.target = percentX;
        this.springs.glareY.target = percentY;
        this.springs.opacity.target = 1;

        this._startLoop();
    }

    _handlePointerLeave() {
        this.pointerInside = false;
        this.springs.rotateX.target = 0;
        this.springs.rotateY.target = 0;
        this.springs.glareX.target = 50;
        this.springs.glareY.target = 50;
        this.springs.opacity.target = 0;

        this._startLoop();
    }

    _startLoop() {
        if (this.frame !== null) {
            return;
        }
        this.element.classList.add('interacting');
        this.lastTime = performance.now();
        this.frame = requestAnimationFrame((now) => this._tick(now));
    }

    _stopLoop() {
        if (this.frame !== null) {
            cancelAnimationFrame(this.frame);
            this.frame = null;
        }
    }

    _tick(now) {
        const dt = Math.min((now - this.lastTime) / 1000, MAX_DT);
        this.lastTime = now;

        // The popover springs (scale / translate / flip) use a softer, slower
        // spring than the tilt springs so the zoom glides instead of snapping.
        const popover = new Set(['scale', 'translateX', 'translateY', 'flip']);

        let energy = 0;
        for (const [name, spring] of Object.entries(this.springs)) {
            const stiffness = popover.has(name) ? POPOVER_STIFFNESS : SPRING_STIFFNESS;
            const damping = popover.has(name) ? POPOVER_DAMPING : SPRING_DAMPING;
            const acceleration = -stiffness * (spring.value - spring.target)
                - damping * spring.velocity;
            spring.velocity += acceleration * dt;
            spring.value += spring.velocity * dt;
            energy += Math.abs(spring.velocity) + Math.abs(spring.value - spring.target);
        }

        this._render();

        // Stop once everything has settled (a zoomed-in card keeps its scale via
        // the persisted CSS vars + the .active class; the .card.active CSS rule
        // holds its z-index above the grid).
        if (!this.pointerInside && energy < SETTLE_THRESHOLD) {
            this._stopLoop();
            this.element.classList.remove('interacting');
            if (!this.active) {
                this._restoreAncestors(); // back in the grid: drop the lift
            }

            return;
        }

        this.frame = requestAnimationFrame((nextNow) => this._tick(nextNow));
    }

    _render() {
        const { rotateX, rotateY, glareX, glareY, opacity, scale, translateX, translateY, flip } = this.springs;
        const pointerFromCenter = clamp(
            Math.sqrt((glareY.value - 50) ** 2 + (glareX.value - 50) ** 2) / 50,
            0,
            1,
        );

        const style = this.element.style;
        style.setProperty('--pointer-x', `${glareX.value}%`);
        style.setProperty('--pointer-y', `${glareY.value}%`);
        style.setProperty('--pointer-from-center', `${pointerFromCenter}`);
        style.setProperty('--pointer-from-top', `${glareY.value / 100}`);
        style.setProperty('--pointer-from-left', `${glareX.value / 100}`);
        style.setProperty('--card-opacity', `${clamp(opacity.value, 0, 1)}`);
        style.setProperty('--background-x', `${adjust(glareX.value, 0, 100, 37, 63)}%`);
        style.setProperty('--background-y', `${adjust(glareY.value, 0, 100, 33, 67)}%`);
        style.setProperty('--card-scale', `${scale.value}`);
        style.setProperty('--translate-x', `${translateX.value}px`);
        style.setProperty('--translate-y', `${translateY.value}px`);

        // Snap sub-degree flip residue so the settled card is perfectly flat.
        const flipDeg = Math.abs(flip.value - flip.target) < 0.5 ? flip.target : flip.value;
        this.rotator.style.transform = `rotateX(${rotateX.value}deg) rotateY(${rotateY.value + flipDeg}deg)`;
    }
}

/**
 * Convenience for non-Stimulus apps: wire every `.card.interactive` under `root`
 * and return a teardown that destroys them all.
 *
 * @param {ParentNode} root
 * @returns {() => void}
 */
export function initCardTilts(root = document) {
    const instances = [...root.querySelectorAll('.card.interactive')].map((el) => new CardTilt(el));

    return () => instances.forEach((instance) => instance.destroy());
}
