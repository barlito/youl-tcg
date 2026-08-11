/* stimulusFetch: 'lazy' */
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
 * face — per-card state machine 'back' → 'flipping' → 'face' — which flips the
 * matching tile in the right-hand reveal tracker (zone 1 of the aside) from its
 * back to the real card. Cards run common → rarest (climax last); when the last
 * one is up it surfaces the "open another / back" footer. The lower aside zone
 * (set contents: search/sort) is a separate `set-contents` controller.
 *
 * No anime.js: plain `setTimeout` timelines + CSS animations.
 */

const RARITY_COLOR = {
    common: '#9aa3b2',
    uncommon: '#5be584',
    rare: '#54a8ff',
    legendary: '#ff8a2b',
};
const RARITY_LABEL = {
    common: 'Commune',
    uncommon: 'Peu commune',
    rare: 'Rare',
    legendary: 'Légendaire',
};
const RARITY_RANK = { common: 0, uncommon: 1, rare: 2, legendary: 3 };
const SHINY = new Set(['rare', 'legendary']);

// must match the .opening__flip CSS transition duration
const FLIP_MS = 560;

export default class extends Controller {
    static targets = [
        'showcase', 'aura', 'label', 'card', 'flip', 'stack', 'next', 'counter',
        'track', 'best', 'revealedCount', 'flash', 'foot', 'cta',
    ];

    static values = {
        count: Number,
        openingId: String,
    };

    connect() {
        this._ensureInit();
    }

    disconnect() {
        this.timers.forEach(clearTimeout);
        this.timers = [];
    }

    // The `.opening` root is stable across the live re-render of `open()`, so
    // Stimulus does NOT re-run connect() — it fires these value-changed
    // callbacks instead. Note they can run before connect(), hence the explicit
    // init guard.
    countValueChanged(current, previous) {
        this._ensureInit();

        if (current > 0) {
            this._armDraw();
        } else if (previous > 0) {
            this._sealPack();
        }
    }

    // Opening one pack right after another keeps the same card count, so the
    // count alone can't tell a new draw from the previous one: the opening id
    // is what changes. Without it, "open another" had to go through the sealed
    // state first — one extra click per pack.
    openingIdValueChanged(current, previous) {
        this._ensureInit();

        if (current) {
            // a pack left torn by the previous draw has to be re-sealed first
            this._armDraw(Boolean(previous));
        } else if (previous) {
            this._sealPack();
        }
    }

    // Arms the (data-live-ignore) 3D pack for the draw on the table. Guarded by
    // the opening id: count and id both land on the same re-render, whichever
    // callback runs first wins and the other is a no-op.
    _armDraw(reseal = false) {
        if (this._armedOpeningId && this._armedOpeningId === this.openingIdValue) {
            return;
        }
        this._armedOpeningId = this.openingIdValue;

        if (reseal) {
            window.dispatchEvent(new CustomEvent('pack3d:reset'));
        }

        this._resetReveal();
        this.lastIndex = this.countValue - 1;
        window.dispatchEvent(new CustomEvent('pack3d:arm'));

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this._after(0, () => this._revealAllAtOnce());
        }
    }

    _sealPack() {
        this._armedOpeningId = null;
        window.dispatchEvent(new CustomEvent('pack3d:reset'));
        this._resetReveal();
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
        // reveal tracker back to all-mystery
        this.trackTargets?.forEach((tile) => {
            tile.classList.remove('is-revealed', 'is-popping');
            const name = tile.querySelector('.opening__track-name');
            if (name) {
                name.textContent = 'Non révélée';
            }
        });
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = '';
            this.labelTarget.classList.remove('is-on');
        }
        this._updateStack(0);
        if (this.hasFootTarget) {
            this.footTarget.hidden = true;
        }
        // The "carte suivante" button and counter keep their slot in the flow at all
        // times — we only fade them (is-shown / is-hidden), never display:none them,
        // so the centred showcase height stays constant and the card never jumps.
        if (this.hasNextTarget) {
            this.nextTarget.classList.remove('is-shown');
        }
        if (this.hasCounterTarget) {
            this.counterTarget.classList.remove('is-hidden');
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
            this.nextTarget.classList.remove('is-shown');
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
        const { rarity } = card.dataset;
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

        // Burst on EVERY legendary, whatever its slot — not just the last card.
        // Legendary gets the strongest hit; the climax still pops if it happens
        // to be a lesser rarity. The burst is tinted by --reveal-color.
        if (rarity === 'legendary') {
            this._flash(0.62, 240);
        } else if (isLast) {
            this._flash(0.5, 200);
        } else if (rarity === 'rare') {
            this._flash(0.24, 140);
        }

        this._revealTrack(index);
        this._unmaskSetTile(card.dataset.cardId);
        this._bumpBest(rarity);
        this._updateRevealedCount(index + 1);
        this._updateStack(this.lastIndex - index);

        if (isLast) {
            // rarest card is last: it stays on screen and the loot/actions surface
            this._end();
        } else if (this.hasNextTarget) {
            this.nextTarget.classList.add('is-shown');
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
            this.nextTarget.classList.remove('is-shown');
        }
        if (this.hasCounterTarget) {
            this.counterTarget.classList.add('is-hidden');
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
        this.cardTargets.forEach((card, index) => {
            this._revealTrack(index);
            this._unmaskSetTile(card.dataset.cardId);
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

    // -------------------------------------------- reveal tracker (zone 1 aside)
    // flip the matching tile in the top "Révélé x/N" grid from its back to the
    // real card, in sync with the centre flip
    _revealTrack(index) {
        const tile = this.trackTargets[index];
        if (!tile) {
            return;
        }
        tile.classList.add('is-revealed');
        const nameEl = tile.querySelector('.opening__track-name');
        if (nameEl && nameEl.dataset.name) {
            nameEl.textContent = nameEl.dataset.name;
        }
        this._retrigger(tile, 'is-popping');
    }

    // -------------------------------------------- set contents (zone 2 aside)
    // A card FIRST won by this opening is server-rendered masked (`is-pending`,
    // full data carried in data-* attributes) so the set list cannot spoil the
    // showcase; flip by flip we promote its tile to the regular owned rendering.
    _unmaskSetTile(cardId) {
        if (!cardId) {
            return;
        }
        this.element.querySelectorAll(`.opening__card-tile.is-pending[data-card-id="${cardId}"]`).forEach((tile) => {
            tile.classList.remove('is-masked', 'is-pending');
            tile.dataset.name = tile.dataset.pendingName || '';
            tile.dataset.rarity = tile.dataset.pendingRarity || '';
            if (tile.dataset.rarity) {
                tile.style.setProperty('--tile-rar', `var(--rarity-${tile.dataset.rarity})`);
            }
            tile.querySelector('.opening__card-back')?.remove();
            // the rendered card, not an <img>: the component carries both faces
            const render = tile.querySelector('.opening__card-render');
            if (render) {
                render.hidden = false;
            }
            const name = tile.querySelector('.opening__card-name');
            if (name && name.dataset.revealName) {
                name.textContent = name.dataset.revealName;
            }
            const tier = tile.querySelector('.opening__card-tier');
            if (tier && tier.dataset.revealTier) {
                tier.textContent = tier.dataset.revealTier;
                tier.hidden = false;
            }
            this._retrigger(tile, 'is-popping');
        });
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
