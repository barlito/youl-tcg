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
 *     <div class="card interactive" data-rarity="epic">
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
// Clamp dt so a backgrounded tab doesn't make the integration explode.
const MAX_DT = 0.032;
const SETTLE_THRESHOLD = 0.01;

const clamp = (value, min = 0, max = 100) => Math.min(Math.max(value, min), max);
const round = (value, precision = 3) => parseFloat(value.toFixed(precision));
const adjust = (value, fromMin, fromMax, toMin, toMax) =>
    round(toMin + (toMax - toMin) * (value - fromMin) / (fromMax - fromMin));

export class CardTilt {
    constructor(element) {
        this.element = element;
        this.rotator = element.querySelector('.card__rotator');
        this.springs = {
            rotateX: this._spring(0),
            rotateY: this._spring(0),
            glareX: this._spring(50),
            glareY: this._spring(50),
            opacity: this._spring(0),
        };
        this.pointerInside = false;
        this.frame = null;
        this.lastTime = 0;

        this._onPointerMove = (event) => this._handlePointerMove(event);
        this._onPointerLeave = () => this._handlePointerLeave();
        element.addEventListener('pointermove', this._onPointerMove);
        element.addEventListener('pointerleave', this._onPointerLeave);
    }

    /** Detach listeners and stop the loop. Call when removing the card. */
    destroy() {
        this.element.removeEventListener('pointermove', this._onPointerMove);
        this.element.removeEventListener('pointerleave', this._onPointerLeave);
        this._stopLoop();
    }

    _spring(value) {
        return { value, velocity: 0, target: value };
    }

    _handlePointerMove(event) {
        const rect = this.element.getBoundingClientRect();
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

        let energy = 0;
        for (const spring of Object.values(this.springs)) {
            const acceleration = -SPRING_STIFFNESS * (spring.value - spring.target)
                - SPRING_DAMPING * spring.velocity;
            spring.velocity += acceleration * dt;
            spring.value += spring.velocity * dt;
            energy += Math.abs(spring.velocity) + Math.abs(spring.value - spring.target);
        }

        this._render();

        if (!this.pointerInside && energy < SETTLE_THRESHOLD) {
            this._stopLoop();
            this.element.classList.remove('interacting');

            return;
        }

        this.frame = requestAnimationFrame((nextNow) => this._tick(nextNow));
    }

    _render() {
        const { rotateX, rotateY, glareX, glareY, opacity } = this.springs;
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
        style.setProperty('--card-scale', '1');
        style.setProperty('--translate-x', '0px');
        style.setProperty('--translate-y', '0px');

        this.rotator.style.transform = `rotateX(${rotateX.value}deg) rotateY(${rotateY.value}deg)`;
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
