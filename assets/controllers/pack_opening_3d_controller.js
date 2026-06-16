import { Controller } from '@hotwired/stimulus';
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { drawPackFront, PACK_FRONT_W, PACK_FRONT_H } from '../lib/pack_front.js';

/**
 * 3D pack opening — packs.com-style progressive enhancement over the 2D swipe.
 *
 * Loads a GLTF pack (rigid body + a foil flap carrying a pre-baked cloth-tear as
 * morph targets) and **scrubs the GLTF animation with the drag**: the tear
 * follows your finger forwards and back, snaps back below the threshold, commits
 * at the top. On commit it hands off to the existing 2D reveal
 * (`booster-opening` controller) via a bubbling `pack3d:opened` event.
 *
 * Rendering aims at the packs.com look (see refs-packs/RAPPORT-OUVERTURE-3D.md):
 *   - glossy plastic-film foil: MeshPhysicalMaterial (metalness + clearcoat),
 *     artwork kept colour-accurate via an emissive map, sheen from a procedural
 *     environment so a specular highlight sweeps the surface as the pack tilts;
 *   - a procedural normal map for the crimp/creases of the wrapper;
 *   - drifting, twinkling sparkles (THREE.Points) floating around the pack;
 *   - a continuous idle float + a damped pointer-driven parallax tilt.
 * Contact shadow + vignette live in opening.css (the canvas is alpha).
 *
 * Strictly additive: if WebGL is unavailable or the model fails to load, the
 * controller marks itself `is-unavailable` (CSS hides the dead canvas) and
 * `arm()` commits immediately — the reveal starts right after the server-side
 * draw, no peel required, so the booster is never debited without a reveal.
 * Under prefers-reduced-motion the reveal controller shows everything at once
 * on its own. Anything cosmetic (sparkles, normal map) is best-effort and
 * never aborts the pack.
 */

// A full tear is a share of the pack's on-screen WIDTH — the baked flap peels
// sideways (ΔX dominant), so we drag horizontally and scale the throw to width
// (narrower than the height, hence the larger ratio) for a consistent feel.
const DRAG_RATIO = 0.34;
const DRAG_MIN = 90; // floor so tiny viewports still need a deliberate pull
const DRAG_DISTANCE = 150; // fallback before the stage has been measured
const COMMIT_THRESHOLD = 0.6; // pull ~60% of the way and release → it tears open
const TILT = 0.2; // how far the pack leans toward the pointer

// idle "alive" float (packs.com homepage feel) — damped out as you grab/tear
const IDLE_BOB_AMP = 0.08; // vertical bob amplitude (fit units)
const IDLE_BOB_SPEED = 1.4;
const IDLE_ROCK_AMP = 0.1; // gentle yaw rock (rad)
const IDLE_ROCK_SPEED = 0.55;
const BASE_TILT = 0.1; // slight resting lean so the pack never reads flat

// Front-face UV rect baked into the GLTF (U 0.111..0.896, V 0.085..0.996),
// in pixels on the 1618×6672 sheet with flipY=false → pixelY = (1-V)·H.
const SHEET = { w: 1618, h: 6672 };
const FRONT = { x: 179, y: 26, w: 1270, h: 6076 };

const SPARKLE_COUNT = 140;

export default class extends Controller {
    static targets = ['canvas'];

    static values = {
        model: String,
        texture: String,
        bgUrl: String,
        fallbackUrl: String,
        logoUrl: String,
        extensionName: String,
        cardCount: Number,
        armed: Boolean,
    };

    connect() {
        this.committed = false;
        this.armed = this.hasArmedValue ? this.armedValue : false;
        this.progress = 0;
        this.targetProgress = 0;
        this.tilt = { x: 0, y: 0 };
        this.pointer = { x: 0, y: 0 };
        this.dragDistance = DRAG_DISTANCE;

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return; // the reveal controller shows everything at once on its own
        }

        if (this._webglAvailable()) {
            this._init().catch(() => {
                this._teardown();
                this._markUnavailable();
            });
        } else {
            this._markUnavailable();
        }

        // We live in a data-live-ignore subtree, so a bubbling event is our only
        // channel to the reveal controller — and a live re-render can re-instantiate
        // us *after* its one-shot `pack3d:arm` already fired, leaving us sealed
        // (connect() resets `armed` to false). Announce readiness so the reveal
        // controller re-arms us whenever a draw is already on the table.
        this.dispatch('ready', { prefix: 'pack3d', bubbles: true });
    }

    disconnect() {
        this._teardown();
    }

    // ------------------------------------------------------------- setup
    _webglAvailable() {
        try {
            const canvas = document.createElement('canvas');

            return !!(window.WebGLRenderingContext && (canvas.getContext('webgl2') || canvas.getContext('webgl')));
        } catch {
            return false;
        }
    }

    async _init() {
        const gltf = await new GLTFLoader().loadAsync(this.modelValue);
        if (this.committed === null) {
            return; // disconnected while loading
        }

        const width = this.element.clientWidth || 360;
        const height = this.element.clientHeight || 480;

        this.renderer = new THREE.WebGLRenderer({ canvas: this.canvasTarget, alpha: true, antialias: true });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.setSize(width, height, false);
        // No tone mapping: the artwork rides the emissive channel and is already in
        // sRGB, so ACES only desaturated/flattened it ("délavé"). Render it 1:1 for
        // punchy, accurate colours; the foil specular still pops via env + clearcoat.
        this.renderer.toneMapping = THREE.NoToneMapping;

        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(35, width / height, 0.1, 100);
        this.camera.position.set(0, 0, 6);

        // glossy plastic-film reflections + a key/rim rig. The env hotspot lives
        // top-left, so tilting the pack sweeps a specular highlight across it.
        this.scene.environment = this._buildEnvironment();
        const key = new THREE.DirectionalLight(0xffffff, 2.2);
        key.position.set(-2.5, 3.5, 4);
        this.scene.add(key);
        const rim = new THREE.DirectionalLight(0xc9a0ff, 1.3);
        rim.position.set(3, 1.5, -2);
        this.scene.add(rim);
        this.scene.add(new THREE.AmbientLight(0xffffff, 0.3));

        const texture = await this._loadArtwork();
        const normalMap = this._buildNormalMap();

        // Swap the export's flat "test" material for a foil PBR material. The
        // artwork rides the emissive channel so colours stay pixel-accurate
        // (unlit look), while metalness + clearcoat + the env catch a moving
        // highlight. Morph-target tear keeps working — it's geometry-driven.
        this.materials = [];
        gltf.scene.traverse((node) => {
            if (!node.isMesh) {
                return;
            }
            const material = new THREE.MeshPhysicalMaterial({
                color: 0x080808,
                map: texture,
                emissive: 0xffffff,
                emissiveMap: texture,
                emissiveIntensity: 1,
                metalness: 0.6,
                roughness: 0.28,
                clearcoat: 1,
                clearcoatRoughness: 0.18,
                envMapIntensity: 1.4,
                side: THREE.DoubleSide,
            });
            if (normalMap) {
                material.normalMap = normalMap;
                material.normalScale = new THREE.Vector2(0.15, 0.15);
            }
            node.material = material;
            this.materials.push(material);
        });

        // centre + frame the pack
        this.pack = gltf.scene;
        const box = new THREE.Box3().setFromObject(this.pack);
        const center = box.getCenter(new THREE.Vector3());
        const size = box.getSize(new THREE.Vector3());
        this.pack.position.sub(center);
        // packs.com framing: zoom in and drop the pack so its top stays in frame
        // while the (less pretty) folded bottom seal falls off the bottom edge
        const fit = 6 / Math.max(size.x, size.y, size.z);
        this.pack.scale.setScalar(fit);
        this.pack.position.y -= 1.2; // sink it so the top fills the frame and the bottom seal runs off-canvas
        this.pivot = new THREE.Group();
        this.pivot.add(this.pack);
        this.scene.add(this.pivot);

        this._buildSparkles(); // best-effort; never aborts the pack

        // pause every clip and drive time by hand
        this.mixer = new THREE.AnimationMixer(gltf.scene);
        this.actions = [];
        this.duration = 0;
        for (const clip of gltf.animations) {
            const action = this.mixer.clipAction(clip);
            action.play();
            action.paused = true;
            this.actions.push(action);
            this.duration = Math.max(this.duration, clip.duration);
        }
        this.clock = new THREE.Clock();
        this._applyProgress(0); // sealed at rest (clip time 0)

        this.element.classList.add('is-ready'); // styling hook: the 3D pack is live
        this._updateDragDistance();
        this._onResize = () => this._resize();
        window.addEventListener('resize', this._onResize);
        // track the cursor across the whole scene so the pack leans toward it even
        // when the pointer is not directly over the (small) pack element
        this._onPointerMove = (event) => this._trackPointer(event);
        window.addEventListener('pointermove', this._onPointerMove);
        this._loop();
    }

    // Artwork: either an explicit texture URL, or a composed pack front built on
    // a canvas (packs.com's runtime drawImage trick — the shipped filmstrip
    // texture maps wrong onto the UV rect, so we paint a real front instead).
    async _loadArtwork() {
        const value = this.hasTextureValue ? this.textureValue : '';
        if (value && value !== 'composite') {
            const texture = await new THREE.TextureLoader().loadAsync(value);
            texture.flipY = false; // glTF UV convention
            texture.colorSpace = THREE.SRGBColorSpace;
            texture.anisotropy = this.renderer.capabilities.getMaxAnisotropy();

            return texture;
        }

        return this._buildCompositeTexture();
    }

    // Procedural studio environment: a bright top-left hotspot fading through
    // violet to dark, pushed through PMREM. Cheaper than an HDR and gives the
    // moving specular sweep without an addon (no importmap change needed).
    _buildEnvironment() {
        const canvas = document.createElement('canvas');
        canvas.width = 512;
        canvas.height = 256;
        const ctx = canvas.getContext('2d');

        const grad = ctx.createLinearGradient(0, 0, 0, canvas.height);
        grad.addColorStop(0, '#fdfbff');
        grad.addColorStop(0.32, '#9d7bd6');
        grad.addColorStop(0.62, '#3a1f63');
        grad.addColorStop(1, '#0a0614');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // bright specular hotspot, upper-left
        const hot = ctx.createRadialGradient(150, 50, 0, 150, 50, 150);
        hot.addColorStop(0, 'rgba(255,255,255,0.95)');
        hot.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = hot;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const equirect = new THREE.CanvasTexture(canvas);
        equirect.mapping = THREE.EquirectangularReflectionMapping;

        const pmrem = new THREE.PMREMGenerator(this.renderer);
        const env = pmrem.fromEquirectangular(equirect).texture;
        pmrem.dispose();
        equirect.dispose();

        return env;
    }

    // Subtle normal map: faint vertical micro-creases (cylindrical film curve)
    // plus a crimped top edge. Best-effort — returns null on any failure.
    _buildNormalMap() {
        try {
            const w = 256;
            const h = 512;
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            const image = ctx.createImageData(w, h);
            const data = image.data;

            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    // x-derivative of gentle vertical creases → R channel
                    const crease = Math.sin((x / w) * Math.PI * 26) * 32;
                    // Faint crimp ridges fading in toward the very top edge. Kept
                    // subtle and feathered (no hard cutoff) so the tear band blends
                    // with the body instead of catching a bright specular rim — the
                    // old amplitude (70) + sharp y<24 cutoff lit a pale line along it.
                    const crimpFalloff = Math.max(0, 1 - y / 28); // 1 at the top → 0 by ~28px
                    const crimp = Math.sin((x / w) * Math.PI * 80) * 16 * crimpFalloff;
                    const i = (y * w + x) * 4;
                    data[i] = Math.max(0, Math.min(255, 128 + crease + crimp));
                    data[i + 1] = 128; // flat in Y
                    data[i + 2] = 255; // pointing out
                    data[i + 3] = 255;
                }
            }
            ctx.putImageData(image, 0, 0);

            const texture = new THREE.CanvasTexture(canvas);
            texture.wrapS = THREE.RepeatWrapping;
            texture.wrapT = THREE.RepeatWrapping;

            return texture;
        } catch {
            return null;
        }
    }

    // Drifting, twinkling sparkles around the pack (packs.com sparticles feel),
    // as a single additive THREE.Points field. Best-effort: a shader failure
    // leaves the pack untouched.
    _buildSparkles() {
        try {
            const positions = new Float32Array(SPARKLE_COUNT * 3);
            const scales = new Float32Array(SPARKLE_COUNT);
            const phases = new Float32Array(SPARKLE_COUNT);
            for (let i = 0; i < SPARKLE_COUNT; i++) {
                positions[i * 3] = (Math.random() - 0.5) * 7;
                positions[i * 3 + 1] = (Math.random() - 0.5) * 8;
                positions[i * 3 + 2] = (Math.random() - 0.5) * 3 - 1; // mostly behind the pack
                scales[i] = 0.5 + Math.random() * 1.8;
                phases[i] = Math.random() * Math.PI * 2;
            }

            const geometry = new THREE.BufferGeometry();
            geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
            geometry.setAttribute('aScale', new THREE.BufferAttribute(scales, 1));
            geometry.setAttribute('aPhase', new THREE.BufferAttribute(phases, 1));

            const material = new THREE.ShaderMaterial({
                transparent: true,
                depthWrite: false,
                blending: THREE.AdditiveBlending,
                uniforms: {
                    uTime: { value: 0 },
                    uSize: { value: 26 * this.renderer.getPixelRatio() },
                    uColor: { value: new THREE.Color(0xe7d4ff) },
                },
                vertexShader: `
                    attribute float aScale;
                    attribute float aPhase;
                    uniform float uTime;
                    uniform float uSize;
                    varying float vTw;
                    void main() {
                        vec4 mv = modelViewMatrix * vec4(position, 1.0);
                        float tw = 0.5 + 0.5 * sin(uTime * 2.0 + aPhase);
                        vTw = tw;
                        gl_PointSize = uSize * aScale * (0.35 + tw) * (1.0 / -mv.z);
                        gl_Position = projectionMatrix * mv;
                    }
                `,
                fragmentShader: `
                    varying float vTw;
                    uniform vec3 uColor;
                    void main() {
                        float d = length(gl_PointCoord - 0.5);
                        float a = smoothstep(0.5, 0.0, d);
                        gl_FragColor = vec4(uColor, a * (0.2 + 0.8 * vTw));
                    }
                `,
            });

            this.sparkles = new THREE.Points(geometry, material);
            this.sparkles.renderOrder = -1;
            this.scene.add(this.sparkles);
        } catch {
            this.sparkles = null;
        }
    }

    // Compose a real pack front on a canvas and blit it into the GLTF's UV rect.
    // Artwork + wordmark + card count come from the opened extension (dynamic).
    // The front itself is drawn by the shared drawPackFront() so the grid tiles
    // and this 3D pack look identical.
    async _buildCompositeTexture() {
        let hero = this.hasBgUrlValue && this.bgUrlValue ? await this._loadImage(this.bgUrlValue) : null;
        // no extension art → fall back to the branded YOUL front
        const fallback = !hero;
        if (fallback && this.hasFallbackUrlValue && this.fallbackUrlValue) {
            hero = await this._loadImage(this.fallbackUrlValue);
        }
        const logo = fallback && this.hasLogoUrlValue && this.logoUrlValue
            ? await this._loadImage(this.logoUrlValue)
            : null;

        // render the front at 3× — it gets stretched into a tall UV rect, so the
        // extra resolution keeps the wordmark/logo/art from looking pixelated
        const scale = 3;
        const front = document.createElement('canvas');
        front.width = PACK_FRONT_W * scale;
        front.height = PACK_FRONT_H * scale;
        drawPackFront(front.getContext('2d'), {
            hero,
            logo,
            name: this.hasExtensionNameValue ? this.extensionNameValue : '',
            count: this.hasCardCountValue && this.cardCountValue ? this.cardCountValue : 5,
            fallback,
            scale,
        });

        const sheet = document.createElement('canvas');
        sheet.width = SHEET.w;
        sheet.height = SHEET.h;
        const g = sheet.getContext('2d');
        let bg = g.createLinearGradient(0, 0, 0, SHEET.h);
        bg.addColorStop(0, '#2a1655');
        bg.addColorStop(1, '#0b0712');
        g.fillStyle = bg;
        g.fillRect(0, 0, SHEET.w, SHEET.h);
        // Cover the WHOLE sheet with the artwork first: some pack faces (the bottom
        // seal, the sides) have UVs outside the front rect, so without this they'd
        // show flat violet — the "texture stops before the bottom" look. The crisp
        // front then goes on top into its exact UV rect.
        if (hero) {
            const s = Math.max(SHEET.w / hero.width, SHEET.h / hero.height);
            const hw = hero.width * s;
            const hh = hero.height * s;
            g.drawImage(hero, (SHEET.w - hw) / 2, (SHEET.h - hh) / 2, hw, hh);
        }
        g.drawImage(front, FRONT.x, FRONT.y, FRONT.w, FRONT.h);

        const texture = new THREE.CanvasTexture(sheet);
        texture.flipY = false;
        texture.colorSpace = THREE.SRGBColorSpace;
        // the pack is always tilted in 3D — without anisotropy the angled surface
        // samples the texture poorly and looks pixelated/aliased even at high res
        texture.anisotropy = this.renderer.capabilities.getMaxAnisotropy();

        return texture;
    }

    _loadImage(src) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = src;
        });
    }

    // No 3D pack to peel (WebGL missing or model failed to load): the draw is
    // already persisted server-side, so hand off to the reveal immediately —
    // never leave a debited booster stuck behind a dead canvas.
    _markUnavailable() {
        this.unavailable = true;
        this.element.classList.add('is-unavailable');
        if (this.armed && !this.committed) {
            this._commit();
        }
    }

    // Enable peeling — fired (via a window event) once the booster has been drawn
    // server-side, so the wrapper can't be torn before there are cards to reveal.
    arm() {
        this.armed = true;

        if (this.unavailable && !this.committed) {
            this._commit();
        }
    }

    // Re-seal for another draw — this pack lives in a data-live-ignore subtree and
    // persists between openings, so "open another" must reset it to a sealed state.
    reseal() {
        this.committed = false;
        this.armed = false;
        this.dragging = false;
        this.targetProgress = 0;
    }

    // ------------------------------------------------------------- drag
    dragStart(event) {
        if (this.committed || !this.armed || !this.renderer) {
            return;
        }
        this.dragging = true;
        this.startX = event.clientX; // peel the top ribbon sideways (packs.com)
        try {
            event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
            // best effort
        }
    }

    dragMove(event) {
        if (!this.dragging || this.committed) {
            return;
        }
        this.targetProgress = Math.max(0, Math.min(1, Math.abs(event.clientX - this.startX) / this.dragDistance));
    }

    // lean toward the cursor anywhere on screen, normalised against the pack centre
    _trackPointer(event) {
        const zone = this._zoneEl || (this._zoneEl = this.element.closest('.opening__pack-col') || this.element);
        const rect = zone.getBoundingClientRect();
        // only react to the cursor inside the opening zone (left column) — hovering
        // the set list on the right must not tilt the pack
        if (
            event.clientX < rect.left || event.clientX > rect.right
            || event.clientY < rect.top || event.clientY > rect.bottom
        ) {
            this.pointer.x = 0;
            this.pointer.y = 0;

            return;
        }
        const centerX = rect.left + rect.width / 2;
        const centerY = rect.top + rect.height / 2;
        this.pointer.x = Math.max(-1, Math.min(1, (event.clientX - centerX) / (rect.width / 2)));
        this.pointer.y = Math.max(-1, Math.min(1, (event.clientY - centerY) / (rect.height / 2)));
    }

    dragEnd() {
        if (!this.dragging) {
            return;
        }
        this.dragging = false;
        if (this.targetProgress >= COMMIT_THRESHOLD) {
            this._commit();
        } else {
            this.targetProgress = 0; // snap back
        }
    }

    // fallback: click to open in one go — must work without a renderer too
    openAtOnce() {
        if (this.armed && !this.committed) {
            this._commit();
        }
    }

    _commit() {
        this.committed = true;
        this.targetProgress = 1;
        this.dispatch('opened', { prefix: 'pack3d', bubbles: true });
    }

    // ------------------------------------------------------------- render
    _loop() {
        this.frame = requestAnimationFrame(() => this._loop());

        // ease the tear toward its target so snap-back and commit feel springy
        this.progress += (this.targetProgress - this.progress) * 0.18;
        this._applyProgress(this.progress);

        // lean toward the pointer — held still mid-tear so the drag reads as a peel
        // and is never "swallowed" by the pack rotating to follow the cursor
        if (!this.dragging) {
            this.tilt.x += (this.pointer.y * TILT - this.tilt.x) * 0.1;
            this.tilt.y += (this.pointer.x * TILT - this.tilt.y) * 0.1;
        }

        // idle float — full while sealed & untouched, fades out as you grab or tear
        const t = this.clock.getElapsedTime();
        const calm = this.committed ? 0 : (this.dragging ? 0.15 : 1) * (1 - this.progress);
        const bob = Math.sin(t * IDLE_BOB_SPEED) * IDLE_BOB_AMP * calm;
        const rock = Math.sin(t * IDLE_ROCK_SPEED) * IDLE_ROCK_AMP * calm;

        if (this.pivot) {
            this.pivot.rotation.x = BASE_TILT + this.tilt.x;
            this.pivot.rotation.y = this.tilt.y + rock;
            this.pivot.position.y = bob;
        }

        if (this.sparkles) {
            this.sparkles.material.uniforms.uTime.value = t;
            this.sparkles.rotation.z = t * 0.02;
            this.sparkles.position.y = Math.sin(t * 0.3) * 0.15;
        }

        this.renderer.render(this.scene, this.camera);
    }

    _applyProgress(progress) {
        if (!this.mixer) {
            return;
        }
        // Scrub by driving each (paused) action's time directly — mixer.setTime()
        // does NOT advance paused actions, so the tear would never move with it.
        const time = progress * this.duration;
        for (const action of this.actions) {
            action.time = Math.min(time, action.getClip().duration);
        }
        this.mixer.update(0);
    }

    _resize() {
        if (!this.renderer) {
            return;
        }
        const width = this.element.clientWidth || 360;
        const height = this.element.clientHeight || 480;
        this.renderer.setSize(width, height, false);
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this._updateDragDistance();
    }

    // full-tear distance as a share of the pack's rendered width (scale-robust)
    _updateDragDistance() {
        this.dragDistance = Math.max(DRAG_MIN, (this.element.clientWidth || 420) * DRAG_RATIO);
    }

    _teardown() {
        this.committed = null;
        if (this.frame) {
            cancelAnimationFrame(this.frame);
        }
        if (this._onResize) {
            window.removeEventListener('resize', this._onResize);
        }
        if (this._onPointerMove) {
            window.removeEventListener('pointermove', this._onPointerMove);
        }
        this.sparkles?.geometry.dispose();
        this.sparkles?.material.dispose();
        this.materials?.forEach((material) => material.dispose());
        this.renderer?.dispose();
    }
}
