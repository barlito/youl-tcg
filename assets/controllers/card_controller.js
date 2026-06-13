import { Controller } from '@hotwired/stimulus';
import { clamp, adjust } from '../scripts/card/mathHelper.js';

/**
 * 3D card controller — mouse-tracking rotation, shine and glare effects.
 *
 * Auto-attached to any `.card.interactive` element via `data-controller="card"`.
 * The motion is smoothed by hand-rolled springs (semi-implicit Euler) in a
 * single rAF loop: one style write per frame, and the loop fully stops once
 * the card has settled — the effect layers are then hidden (see holo.css),
 * which keeps a grid of dozens of cards cheap.
 */

const SPRING_STIFFNESS = 160;
const SPRING_DAMPING = 22;
// Clamp dt so a backgrounded tab doesn't make the integration explode.
const MAX_DT = 0.032;
const SETTLE_THRESHOLD = 0.01;

export default class extends Controller {
    connect() {
        this.rotator = this.element.querySelector('.card__rotator');
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
        this.element.addEventListener('pointermove', this._onPointerMove);
        this.element.addEventListener('pointerleave', this._onPointerLeave);
    }

    disconnect() {
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
