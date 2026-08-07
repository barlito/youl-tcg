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
 * Tuning the look is pure CSS: the holo presets live in holo-presets.css,
 * the rarity glow in holo.css.
 */

const SPRING_STIFFNESS = 160;
const SPRING_DAMPING = 22;
// Softer spring for the click-to-zoom popover (scale / translate): slower, more
// damped so the card glides to centre rather than snapping.
const POPOVER_STIFFNESS = 90;
const POPOVER_DAMPING = 18;
// Largest zoom factor when a card is activated. High cap on purpose: the real
// limit is the 90% viewport fit computed in activate(), so the card gets as
// big as the screen allows whatever its size in the grid.
const MAX_POPOVER_SCALE = 3;
// Full turn the card makes around Y while gliding to the viewport centre —
// the click-to-zoom reads as "the card flips out of the grid into your hand".
const POPOVER_FLIP_DEG = 360;
// Clamp dt so a backgrounded tab doesn't make the integration explode.
const MAX_DT = 0.032;
// Per-spring rest tolerance. A quarter of a degree/pixel is invisible, and
// waiting for less keeps the loop (and the pixelated GPU layer) alive for
// seconds while the 360° flip creeps to zero. Unit-scaled springs (opacity
// 0..1, scale ~1..3) need a much finer tolerance or the final snap would jump.
const SETTLE_EPSILONS = { opacity: 0.01, scale: 0.005 };
const SETTLE_EPSILON = 0.25;

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
        // ~3/4 of the viewport: the poke-holo presence — big, not wall-to-wall.
        const scaleW = (window.innerWidth / rect.width) * 0.75;
        const scaleH = (window.innerHeight / rect.height) * 0.75;
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

    /**
     * Linear part of the transform chain above the card. The springs drive a
     * transform expressed in the card's own coordinates, so a delta measured on
     * screen has to be pushed back through this matrix: the homepage hero nests
     * the cards in a scaled stage AND in rotated wrappers, which both stretch
     * and turn any translation applied inside them.
     */
    _ancestorMatrix() {
        const chain = [];
        let node = this.element;

        while (node && node !== document.body) {
            const transform = getComputedStyle(node).transform;

            if (transform && transform !== 'none') {
                chain.push(new DOMMatrixReadOnly(transform));
            }
            node = node.parentElement;
        }

        // outermost first: that is the order the browser applies them
        let matrix = new DOMMatrix();
        for (let i = chain.length - 1; i >= 0; i -= 1) {
            matrix = matrix.multiply(chain[i]);
        }

        return matrix;
    }

    /** Aim the translate springs so the card sits at the viewport centre. */
    _setCenter() {
        const rect = this.translater.getBoundingClientRect();
        const screenX = window.innerWidth / 2 - rect.left - rect.width / 2;
        const screenY = window.innerHeight / 2 - rect.top - rect.height / 2;

        const { a, b, c, d } = this._ancestorMatrix();
        const determinant = a * d - b * c;
        // no usable matrix (degenerate): fall back to screen pixels
        const localX = determinant ? (d * screenX - c * screenY) / determinant : screenX;
        const localY = determinant ? (a * screenY - b * screenX) / determinant : screenY;

        this.springs.translateX.target = round(localX + this.springs.translateX.value);
        this.springs.translateY.target = round(localY + this.springs.translateY.value);
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
        // back on a compositor layer + 3D context while the springs animate
        this.translater.style.willChange = '';
        this.rotator.style.willChange = '';
        this.element.classList.remove('is-flat');
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
        // spring than the tilt springs so the zoom (and its spin) glides at the
        // poke-holo pace instead of snapping.
        const popover = new Set(['scale', 'translateX', 'translateY', 'flip']);

        let settled = true;
        for (const [name, spring] of Object.entries(this.springs)) {
            const stiffness = popover.has(name) ? POPOVER_STIFFNESS : SPRING_STIFFNESS;
            const damping = popover.has(name) ? POPOVER_DAMPING : SPRING_DAMPING;
            const acceleration = -stiffness * (spring.value - spring.target)
                - damping * spring.velocity;
            spring.velocity += acceleration * dt;
            spring.value += spring.velocity * dt;
            const epsilon = SETTLE_EPSILONS[name] ?? SETTLE_EPSILON;
            if (Math.abs(spring.value - spring.target) > epsilon || Math.abs(spring.velocity) > epsilon) {
                settled = false;
            }
        }

        // Stop as soon as every spring is within tolerance — snapped exactly on
        // target so the final frame is pixel-perfect. The next pointermove or
        // click restarts the loop, so idling here only burns frames. The hover
        // state (interacting class + holo layers) is only dropped once the
        // pointer has actually left.
        if (settled) {
            for (const spring of Object.values(this.springs)) {
                spring.value = spring.target;
                spring.velocity = 0;
            }
            this._render();
            this._stopLoop();
            if (!this.pointerInside) {
                this.element.classList.remove('interacting');
                if (!this.active) {
                    this._restoreAncestors(); // back in the grid: drop the lift
                }
            }
            // Crispness at rest: inside a 3D rendering context (perspective +
            // preserve-3d) the GPU pins the raster at LAYOUT size and merely
            // stretches it — visibly pixelated once zoomed, dpr 1 worst. When
            // the card has settled flat (identity rotation), drop out of 3D
            // entirely (is-flat + transform none) and release will-change: the
            // browser re-rasterises at the real on-screen scale. _startLoop
            // restores the 3D context the moment anything moves again.
            this.translater.style.willChange = 'auto';
            this.rotator.style.willChange = 'auto';
            const flat = Math.abs(this.springs.rotateX.value) < 0.1
                && Math.abs(this.springs.rotateY.value) < 0.1
                && Math.abs(this.springs.flip.value % 360) < 0.5;
            if (flat) {
                this.rotator.style.transform = 'none';
                this.element.classList.add('is-flat');
            }

            return;
        }

        this._render();
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
