import { Controller } from '@hotwired/stimulus';

/**
 * Pack opening reveal — `sealed → tearing → reveal → summary` state machine.
 *
 * The cards have already been drawn server-side (the live component holds the
 * opening); this controller only choreographs the presentation. It owns its DOM
 * subtree (`data-live-ignore`), so no anime.js: timing is plain `setTimeout`
 * timelines + CSS animations, and the swipe maps the pointer straight to a
 * `--tp` (tear progress) custom property.
 *
 * Reveal order is rarest-last (the server sorts it); per card we light a rarity
 * aura, slam its label, and play a bigger entrance for shiny cards.
 */

const RARITY_COLOR = {
    common: '#9aa3b2',
    uncommon: '#5be584',
    rare: '#54a8ff',
    epic: '#a435f0',
    legendary: '#ff8a2b',
};
const SHINY = new Set(['rare', 'epic', 'legendary']);
const DRAG_DISTANCE = 210; // px of horizontal drag for a full tear
const COMMIT_THRESHOLD = 0.6; // release past this commits the tear

export default class extends Controller {
    static targets = [
        'sealed', 'pack', 'tearing', 'whiteout', 'flash',
        'reveal', 'aura', 'label', 'card', 'dot', 'next', 'counter', 'summary',
    ];

    static values = {
        count: Number,
    };

    connect() {
        this.phase = 'sealed';
        this.revealIndex = -1;
        this.tearProgress = 0;
        this.dragging = false;
        this.timers = [];

        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced || this.countValue === 0) {
            this._toSummary();
        }
    }

    disconnect() {
        this.timers.forEach(clearTimeout);
        this.timers = [];
    }

    _after(ms, fn) {
        const timer = setTimeout(fn, ms);
        this.timers.push(timer);

        return timer;
    }

    // ----------------------------------------------------- swipe to slice
    dragStart(event) {
        if (this.phase !== 'sealed') {
            return;
        }
        this.dragging = true;
        this.startX = event.clientX;
        try {
            event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
            // pointer capture is best-effort
        }
    }

    dragMove(event) {
        if (!this.dragging) {
            return;
        }
        const progress = Math.max(0, Math.min(1, (event.clientX - this.startX) / DRAG_DISTANCE));
        this.tearProgress = progress;
        this.element.style.setProperty('--tp', `${progress}`);
        this.packTarget.classList.toggle('is-cutting', progress > 0.02);
    }

    dragEnd() {
        if (!this.dragging) {
            return;
        }
        this.dragging = false;
        if (this.tearProgress > COMMIT_THRESHOLD) {
            this.commitTear();
        } else {
            this.tearProgress = 0;
            this.element.style.setProperty('--tp', '0');
            this.packTarget.classList.remove('is-cutting');
        }
    }

    // fallback: click the pack / button to open in one go
    openAtOnce() {
        this.commitTear();
    }

    // ----------------------------------------------------- tear timeline
    commitTear() {
        if (this.phase !== 'sealed') {
            return;
        }
        this.phase = 'tearing';
        this.tearProgress = 1;
        this.element.style.setProperty('--tp', '1');
        this.element.dataset.phase = 'tearing';

        this.sealedTarget.hidden = true;
        this.tearingTarget.hidden = false;
        this.whiteoutTarget.classList.add('is-tearing');

        // CSS whiteout peaks at ~1.15s; hand off to the reveal exactly then.
        this._after(1150, () => this._startReveal());
    }

    _startReveal() {
        this.phase = 'reveal';
        this.element.dataset.phase = 'reveal';
        this.tearingTarget.hidden = true;
        this.revealTarget.hidden = false;

        // carry the whiteout seamlessly into the first card, then fade it out
        this.whiteoutTarget.classList.remove('is-tearing');
        this.whiteoutTarget.classList.add('is-bridge');
        this._after(600, () => this.whiteoutTarget.classList.remove('is-bridge'));

        this._showCard(0);
    }

    // ----------------------------------------------------- reveal cards
    _showCard(index) {
        this.revealIndex = index;
        const card = this.cardTargets[index];
        const { rarity } = card.dataset;
        const color = RARITY_COLOR[rarity] || '#ffffff';
        const shiny = SHINY.has(rarity);

        this.element.style.setProperty('--reveal-color', color);
        this.element.classList.toggle('is-shiny', shiny);

        this.cardTargets.forEach((node, i) => node.classList.toggle('is-current', i === index));
        this._retrigger(card, 'is-entering');
        this._retrigger(this.auraTarget, 'is-on');

        this.labelTarget.textContent = card.dataset.label;
        this._retrigger(this.labelTarget, 'is-on');

        if (rarity === 'epic' || rarity === 'legendary') {
            this._flash(0.42, 180);
            this._shake();
        } else if (rarity === 'rare') {
            this._flash(0.24, 140);
        }

        this.dotTargets.forEach((dot, i) => {
            dot.style.setProperty('--dot-color', RARITY_COLOR[this.cardTargets[i].dataset.rarity] || '#fff');
            dot.classList.toggle('is-done', i < index);
            dot.classList.toggle('is-current', i === index);
        });

        const last = index >= this.countValue - 1;
        this.nextTarget.textContent = last ? 'Voir le butin ▸' : 'Carte suivante ▸';
        if (this.hasCounterTarget) {
            this.counterTarget.textContent = `${index + 1} / ${this.countValue} · CLIQUE N'IMPORTE OÙ`;
        }
    }

    advance(event) {
        if (this.phase !== 'reveal') {
            return;
        }
        if (event) {
            event.stopPropagation();
        }
        if (this.revealIndex < this.countValue - 1) {
            this._showCard(this.revealIndex + 1);
        } else {
            this._toSummary();
        }
    }

    // ----------------------------------------------------- summary
    _toSummary() {
        this.phase = 'summary';
        this.element.dataset.phase = 'summary';
        if (this.hasSealedTarget) {
            this.sealedTarget.hidden = true;
        }
        if (this.hasTearingTarget) {
            this.tearingTarget.hidden = true;
        }
        if (this.hasRevealTarget) {
            this.revealTarget.hidden = true;
        }
        this.summaryTarget.hidden = false;
    }

    // ----------------------------------------------------- helpers
    _retrigger(node, className) {
        node.classList.remove(className);
        void node.offsetWidth; // force reflow so the animation restarts
        node.classList.add(className);
    }

    _flash(opacity, ms) {
        this.flashTarget.style.setProperty('--flash', `${opacity}`);
        this.flashTarget.classList.add('is-on');
        this._after(ms, () => this.flashTarget.classList.remove('is-on'));
    }

    _shake() {
        this.element.classList.add('is-shake');
        this._after(500, () => this.element.classList.remove('is-shake'));
    }
}
