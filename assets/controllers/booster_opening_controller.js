import { Controller } from '@hotwired/stimulus';

/**
 * Booster opening reveal — drives the dedicated packs.com-style page.
 *
 * The cards are already drawn server-side (the BoosterOpening live component
 * holds the opening); this controller only choreographs the presentation. It
 * lives on the persistent `.opening` page root, alongside the 3D pack (which
 * sits in a `data-live-ignore` subtree and bubbles a `pack3d:opened` event when
 * peeled).
 *
 * Flow: once the pack has been opened server-side this controller arms the pack
 * (a `pack3d:arm` window event) so it can be peeled. Peeling fires
 * `pack3d:opened` → `startReveal()`, which presents the drawn cards one at a time
 * in the centre showcase, each face-DOWN with the remaining cards stacked behind
 * it (packs.com style). Clicking the card flips it (3D rotateY) to reveal the
 * face — per-card state machine 'back' → 'flipping' → 'face' — which lights up the
 * matching card in the right-hand set list. Cards run common → rarest (climax
 * last); when the last one is up it surfaces the "open another / back" footer.
 *
 * No anime.js: plain `setTimeout` timelines + CSS animations.
 */

const RARITY_COLOR = {
    common: '#9aa3b2',
    uncommon: '#5be584',
    rare: '#54a8ff',
    epic: '#a435f0',
    legendary: '#ff8a2b',
};
const RARITY_LABEL = {
    common: 'Commune',
    uncommon: 'Peu commune',
    rare: 'Rare',
    epic: 'Épique',
    legendary: 'Légendaire',
};
const RARITY_RANK = { common: 0, uncommon: 1, rare: 2, epic: 3, legendary: 4 };
const SHINY = new Set(['rare', 'epic', 'legendary']);

// must match the .opening__flip CSS transition duration
const FLIP_MS = 560;

export default class extends Controller {
    static targets = [
        'showcase', 'aura', 'label', 'card', 'flip', 'stack', 'next', 'counter',
        'slot', 'best', 'revealedCount', 'flash', 'foot', 'cta',
    ];

    static values = {
        count: Number,
    };

    connect() {
        this._ensureInit();
    }

    disconnect() {
        this.timers.forEach(clearTimeout);
        this.timers = [];
    }

    // The `.opening` root is stable across the live re-render of `open()`, so
    // Stimulus does NOT re-run connect() — it fires this value-changed callback
    // when the draw lands (count 0 → N) or is cleared on reset (N → 0). Note it
    // can run before connect(), hence the explicit init guard.
    countValueChanged(current, previous) {
        this._ensureInit();

        if (current > 0) {
            // a fresh opening — reset the reveal state and let the pack be peeled
            this._resetReveal();
            this.lastIndex = current - 1;
            window.dispatchEvent(new CustomEvent('pack3d:arm'));

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                this._after(0, () => this._revealAllAtOnce());
            }
        } else if (previous > 0) {
            // "open another": re-seal the (data-live-ignore) pack for the next draw
            window.dispatchEvent(new CustomEvent('pack3d:reset'));
            this._resetReveal();
        }
    }

    _ensureInit() {
        if (this._inited) {
            return;
        }
        this._inited = true;
        this.timers = [];
        this._resetReveal();
    }

    _resetReveal() {
        this.timers?.forEach(clearTimeout);
        this.timers = [];
        this.phase = 'idle';
        this.revealIndex = -1;
        this.cardState = 'back'; // back → flipping → face, per current card
        this.bestRank = -1;
        if (this.hasShowcaseTarget) {
            this.showcaseTarget.hidden = true;
            this.showcaseTarget.style.pointerEvents = '';
        }
        // every card face-down again, nothing current
        this.cardTargets?.forEach((node) => node.classList.remove('is-current', 'is-pop'));
        this.flipTargets?.forEach((flip) => flip.classList.remove('is-flipped'));
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = '';
            this.labelTarget.classList.remove('is-on');
        }
        this._updateStack(0);
        if (this.hasFootTarget) {
            this.footTarget.hidden = true;
        }
        // the "carte suivante" button only surfaces once a card has been flipped
        if (this.hasNextTarget) {
            this.nextTarget.hidden = true;
        }
        if (this.hasCounterTarget) {
            this.counterTarget.hidden = false;
            this.counterTarget.textContent = '';
        }
        // restore the tear CTA that _end() hides
        if (this.hasCtaTarget) {
            this.ctaTarget.hidden = false;
        }
    }

    _after(ms, fn) {
        const timer = setTimeout(fn, ms);
        this.timers.push(timer);

        return timer;
    }

    // ask the (data-live-ignore) 3D pack to tear itself open in one go
    forceOpen() {
        window.dispatchEvent(new CustomEvent('pack3d:commit'));
    }

    // The 3D pack lives in a data-live-ignore subtree and can be re-instantiated by
    // a live re-render *after* our one-shot `pack3d:arm` (from countValueChanged)
    // has already fired, leaving it sealed. Whenever it (re)announces readiness,
    // re-arm it if a draw is already on the table — closes that ordering race.
    syncPack() {
        if (this.hasCountValue && this.countValue > 0) {
            window.dispatchEvent(new CustomEvent('pack3d:arm'));
        }
    }

    // ----------------------------------------------------- reveal sequence
    // packs.com flow: each card is presented face-DOWN; the player clicks the card
    // to flip it (3D rotateY) and reveal the face, then advances to the next one.
    // Per-card state machine: 'back' → 'flipping' → 'face'.
    startReveal() {
        if (this.phase !== 'idle' || !this.hasCountValue || this.countValue === 0) {
            return;
        }
        this.phase = 'reveal';
        this.showcaseTarget.hidden = false;
        // the pack is open now — drop the tear instruction / "ouvrir d'un coup" CTA
        if (this.hasCtaTarget) {
            this.ctaTarget.hidden = true;
        }

        this._present(0);
    }

    // single entry point for clicks on the showcase: flip the current card, or
    // (once it is face-up) advance to the next one.
    tap() {
        if (this.phase !== 'reveal') {
            return;
        }
        if (this.cardState === 'back') {
            this._flip();
        } else if (this.cardState === 'face') {
            this.advance();
        }
    }

    // "Carte suivante" button (accessibility alternative to clicking the card);
    // only meaningful once the current card has been flipped face-up.
    advance(event) {
        if (this.phase !== 'reveal' || this.cardState !== 'face') {
            return;
        }
        if (event) {
            event.stopPropagation(); // don't let the button click bubble to tap()
        }

        const next = this.revealIndex + 1;
        if (next <= this.lastIndex) {
            this._present(next);
        }
    }

    // show card `index` face-down, with the remaining cards stacked behind it
    _present(index) {
        this.revealIndex = index;
        this.cardState = 'back';

        this.cardTargets.forEach((node, i) => node.classList.toggle('is-current', i === index));
        const card = this.cardTargets[index];
        const flip = this.flipTargets[index];
        card.classList.remove('is-pop');
        flip.classList.remove('is-flipped'); // back toward the player
        this._retrigger(card, 'is-entering');

        // neutral centre while it sits face-down
        this.labelTarget.classList.remove('is-on');
        this.labelTarget.textContent = '';
        this.element.classList.remove('is-shiny');

        if (this.hasNextTarget) {
            this.nextTarget.hidden = true;
        }
        // cards still waiting behind this one
        this._updateStack(this.lastIndex - index);

        if (this.hasCounterTarget) {
            this.counterTarget.textContent = `${index + 1} / ${this.countValue} · CLIQUE POUR RÉVÉLER`;
        }
    }

    // flip the current card face-up, then run the reveal payoff
    _flip() {
        if (this.cardState !== 'back') {
            return;
        }
        this.cardState = 'flipping';
        this.flipTargets[this.revealIndex].classList.add('is-flipped');
        this._after(FLIP_MS, () => this._onRevealed());
    }

    // the card has landed face-up: light it up, sync the set list, surface "next"
    _onRevealed() {
        this.cardState = 'face';
        const index = this.revealIndex;
        const isLast = index === this.lastIndex;
        const card = this.cardTargets[index];
        const { rarity, cardId } = card.dataset;
        const color = RARITY_COLOR[rarity] || '#ffffff';
        const shiny = SHINY.has(rarity);

        this.element.style.setProperty('--reveal-color', color);
        this.element.classList.toggle('is-shiny', shiny);

        // a punchier landing for shinies and the rarest-last climax
        if (shiny || isLast) {
            card.classList.remove('is-entering'); // free the animation slot for the pop
            this._retrigger(card, 'is-pop');
        }
        this._retrigger(this.auraTarget, 'is-on');
        this.labelTarget.textContent = card.dataset.label;
        this._retrigger(this.labelTarget, 'is-on');

        if (isLast || rarity === 'epic' || rarity === 'legendary') {
            this._flash(isLast ? 0.6 : 0.42, isLast ? 220 : 180);
        } else if (rarity === 'rare') {
            this._flash(0.24, 140);
        }

        this._lightSlot(cardId);
        this._bumpBest(rarity);
        this._updateRevealedCount(index + 1);
        this._updateStack(this.lastIndex - index);

        if (isLast) {
            // rarest card is last: it stays on screen and the loot/actions surface
            this._end();
        } else if (this.hasNextTarget) {
            this.nextTarget.hidden = false;
            if (this.hasCounterTarget) {
                this.counterTarget.textContent = `${index + 1} / ${this.countValue} · CLIQUE POUR CONTINUER`;
            }
        }
    }

    // a stacked deck of card backs peeking behind the current card (up to 3 layers)
    _updateStack(remaining) {
        if (!this.hasStackTarget) {
            return;
        }
        const layers = Array.from(this.stackTarget.children);
        layers.forEach((layer, i) => { layer.hidden = i >= remaining; });
        this.stackTarget.hidden = remaining <= 0;
    }

    _end() {
        this.phase = 'done';
        // keep the last card on screen; retire the in-showcase prompt + tear CTA
        // and surface the back / open-another actions under the booster
        if (this.hasNextTarget) {
            this.nextTarget.hidden = true;
        }
        if (this.hasCounterTarget) {
            this.counterTarget.hidden = true;
        }
        if (this.hasCtaTarget) {
            this.ctaTarget.hidden = true;
        }
        // the card stays visible, but the overlay must stop eating clicks meant for
        // the actions sitting under the booster
        if (this.hasShowcaseTarget) {
            this.showcaseTarget.style.pointerEvents = 'none';
        }
        if (this.hasFootTarget) {
            this.footTarget.hidden = false;
        }
    }

    // reduced motion / fallback: everything revealed instantly, no peel/flip needed
    _revealAllAtOnce() {
        this.phase = 'done';
        this.cardState = 'face';
        this.flipTargets.forEach((flip) => flip.classList.add('is-flipped'));
        this._updateStack(0);
        this.cardTargets.forEach((card) => {
            this._lightSlot(card.dataset.cardId);
            this._bumpBest(card.dataset.rarity);
        });
        this._updateRevealedCount(this.countValue);
        // keep the showcase visible (the footer lives inside it now), just let
        // clicks through to the actions
        if (this.hasShowcaseTarget) {
            this.showcaseTarget.hidden = false;
            this.showcaseTarget.style.pointerEvents = 'none';
        }
        if (this.hasCtaTarget) {
            this.ctaTarget.hidden = true;
        }
        if (this.hasFootTarget) {
            this.footTarget.hidden = false;
        }
    }

    // ----------------------------------------------------- set list (aside)
    _lightSlot(cardId) {
        const slot = this.slotTargets.find((node) => node.dataset.cardId === cardId);
        if (!slot) {
            return;
        }
        slot.classList.add('is-revealed');
        // reveal the real name (masked as "???" until owned / revealed)
        const nameEl = slot.querySelector('.opening__slot-name');
        if (nameEl && slot.dataset.cardName) {
            nameEl.textContent = slot.dataset.cardName;
        }
        this._retrigger(slot, 'is-popping');
    }

    _bumpBest(rarity) {
        const rank = RARITY_RANK[rarity] ?? -1;
        if (rank <= this.bestRank || !this.hasBestTarget) {
            return;
        }
        this.bestRank = rank;
        this.bestTarget.textContent = RARITY_LABEL[rarity] || rarity;
        this.bestTarget.style.color = RARITY_COLOR[rarity] || '#fff';
    }

    _updateRevealedCount(count) {
        if (this.hasRevealedCountTarget) {
            this.revealedCountTarget.textContent = `${count}`;
        }
    }

    // ----------------------------------------------------- helpers
    _retrigger(node, className) {
        node.classList.remove(className);
        void node.offsetWidth; // force reflow so the animation restarts
        node.classList.add(className);
    }

    _flash(opacity, ms) {
        if (!this.hasFlashTarget) {
            return;
        }
        this.flashTarget.style.setProperty('--flash', `${opacity}`);
        this.flashTarget.classList.add('is-on');
        this._after(ms, () => this.flashTarget.classList.remove('is-on'));
    }
}
