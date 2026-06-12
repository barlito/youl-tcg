import { Controller } from '@hotwired/stimulus';
import anime from '../scripts/card/lib/anime.es.js';
import { round, clamp, adjust } from '../scripts/card/mathHelper.js';

/**
 * 3D card controller — mouse-tracking rotation, shine and glare effects.
 *
 * Auto-attached to any `.card.interactive` element via `data-controller="card"`.
 * Replaces the legacy assets/scripts/card/card.js which only ran once at page
 * load (and therefore missed cards rendered later by Live Components).
 */
export default class extends Controller {
    connect() {
        this._onPointerMove = (event) => this._handlePointerMove(event);
        this._onMouseOut = (event) => this._handleMouseOut(event);
        this.element.addEventListener('pointermove', this._onPointerMove);
        this.element.addEventListener('mouseout', this._onMouseOut);
    }

    disconnect() {
        this.element.removeEventListener('pointermove', this._onPointerMove);
        this.element.removeEventListener('mouseout', this._onMouseOut);
        if (this._resetAnimation) {
            this._resetAnimation.pause();
            this._resetAnimation = null;
        }
    }

    _handlePointerMove(event) {
        const { pointerX, pointerY } = this._eventPosition(event);
        this._computePositions(pointerX, pointerY);
    }

    _handleMouseOut(event) {
        const { pointerX, pointerY } = this._eventPosition(event);
        this._resetCard(pointerX, pointerY);
    }

    _eventPosition(event) {
        let pointerX = event.clientX;
        let pointerY = event.clientY;

        if (event.type === 'touchmove') {
            pointerX = event.touches[0].clientX;
            pointerY = event.touches[0].clientY;
        }

        return { pointerX, pointerY };
    }

    _positions(pointerX, pointerY) {
        const rect = this.element.getBoundingClientRect();
        const absolute = {
            x: pointerX - rect.left,
            y: pointerY - rect.top,
        };
        const percent = {
            x: clamp(round((100 / rect.width) * absolute.x)),
            y: clamp(round((100 / rect.height) * absolute.y)),
        };
        const center = {
            x: percent.x - 50,
            y: percent.y - 50,
        };

        return { absolute, percent, center };
    }

    _computePositions(pointerX, pointerY) {
        const { percent, center } = this._positions(pointerX, pointerY);

        const background = {
            x: adjust(percent.x, 0, 100, 37, 63),
            y: adjust(percent.y, 0, 100, 33, 67),
        };
        const rotate = {
            x: round(-(center.x / 3.5)) / 2,
            y: round(center.y / 2) / 2,
        };
        const glare = {
            x: round(percent.x),
            y: round(percent.y),
            o: 1,
        };

        this._updateCard(background, rotate, glare, false);
    }

    _resetCard(pointerX, pointerY) {
        this._resetAnimation = anime({
            targets: this.element.querySelector('.card__rotator'),
            rotateX: {
                value: 0,
                easing: 'easeOutBack',
                delay: 100,
                endDelay: 100,
                duration: 800,
            },
            rotateY: {
                value: 0,
                easing: 'easeOutBack',
                delay: 100,
                endDelay: 100,
                duration: 800,
            },
            update: (anim) => {
                const progress = anim.progress;
                const { percent, center } = this._positions(pointerX, pointerY);

                const diffX = 50 - percent.x;
                const diffY = 50 - percent.y;
                const newX = percent.x + (diffX * progress / 100);
                const newY = percent.y + (diffY * progress / 100);

                this._updateCard(
                    {
                        x: adjust(newX, 0, 100, 37, 63),
                        y: adjust(newY, 0, 100, 33, 67),
                    },
                    {
                        x: round(-(center.x / 3.5)),
                        y: round(center.y / 2),
                    },
                    {
                        x: round(newX),
                        y: round(newY),
                        o: 1 - (progress / 50),
                    },
                    true,
                );
            },
        });
    }

    _updateCard(background, rotate, glare, isReset) {
        if (this._resetAnimation && !isReset) {
            this._resetAnimation.pause();
        }

        const pointerFromCenter = clamp(
            Math.sqrt((glare.y - 50) ** 2 + (glare.x - 50) ** 2) / 50,
            0,
            1,
        );

        const el = this.element;
        el.style.setProperty('--pointer-x', glare.x + '%');
        el.style.setProperty('--pointer-y', glare.y + '%');
        el.style.setProperty('--pointer-from-center', pointerFromCenter);
        el.style.setProperty('--pointer-from-top', glare.y / 100);
        el.style.setProperty('--pointer-from-left', glare.x / 100);
        el.style.setProperty('--card-opacity', glare.o);
        el.style.setProperty('--background-x', background.x + '%');
        el.style.setProperty('--background-y', background.y + '%');
        el.style.setProperty('--card-scale', '1');
        el.style.setProperty('--translate-x', '0px');
        el.style.setProperty('--translate-y', '0px');

        if (!isReset) {
            el.querySelector('.card__rotator').style.setProperty(
                'transform',
                `rotateX(${rotate.y}deg) rotateY(${rotate.x}deg)`,
            );
        }
    }
}
