import { Controller } from '@hotwired/stimulus';
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';

/**
 * 3D pack opening — packs.com-style progressive enhancement over the 2D swipe.
 *
 * Loads a GLTF pack (rigid body + a foil flap carrying a pre-baked cloth-tear as
 * morph targets) with an unlit material, and **scrubs the GLTF animation with
 * the drag**: the tear follows your finger forwards and back, snaps back below
 * the threshold, commits at the top. On commit it hands off to the existing 2D
 * reveal (`booster-opening` controller) via a bubbling `pack3d:opened` event.
 *
 * Strictly additive: if WebGL is unavailable, the model fails to load, or the
 * user prefers reduced motion, this controller does nothing and the 2D swipe
 * pack stays in place (CSS only hides it once `is-ready` is set here).
 */

const DRAG_DISTANCE = 130; // px of drag for a full tear (packs.com threshold)
const COMMIT_THRESHOLD = 0.98;
const TEAR_START = 0.4; // the first 40% of the clip settles the body; the tear scrubs 0.4 → 1
const TILT = 0.18; // how far the pack leans toward the pointer

export default class extends Controller {
    static targets = ['canvas'];

    static values = {
        model: String,
        texture: String,
    };

    connect() {
        this.committed = false;
        this.progress = 0;
        this.targetProgress = 0;
        this.tilt = { x: 0, y: 0 };
        this.pointer = { x: 0, y: 0 };

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !this._webglAvailable()) {
            return;
        }

        this._init().catch(() => this._teardown());
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
        const height = this.element.clientHeight || 460;

        this.renderer = new THREE.WebGLRenderer({ canvas: this.canvasTarget, alpha: true, antialias: true });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.setSize(width, height, false);

        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(35, width / height, 0.1, 100);
        this.camera.position.set(0, 0, 6);

        // unlit material → swap the texture freely, colours stay pixel-perfect
        if (this.hasTextureValue && this.textureValue) {
            const texture = await new THREE.TextureLoader().loadAsync(this.textureValue);
            texture.flipY = false; // glTF UV convention
            texture.colorSpace = THREE.SRGBColorSpace;
            gltf.scene.traverse((node) => {
                if (node.isMesh && node.material) {
                    node.material.map = texture;
                    node.material.needsUpdate = true;
                }
            });
        }

        // centre + frame the pack
        this.pack = gltf.scene;
        const box = new THREE.Box3().setFromObject(this.pack);
        const center = box.getCenter(new THREE.Vector3());
        const size = box.getSize(new THREE.Vector3());
        this.pack.position.sub(center);
        const fit = 3.4 / Math.max(size.x, size.y, size.z);
        this.pack.scale.setScalar(fit);
        this.pivot = new THREE.Group();
        this.pivot.add(this.pack);
        this.scene.add(this.pivot);

        // pause every clip and drive time by hand
        this.mixer = new THREE.AnimationMixer(gltf.scene);
        this.duration = 0;
        for (const clip of gltf.animations) {
            const action = this.mixer.clipAction(clip);
            action.play();
            action.paused = true;
            this.duration = Math.max(this.duration, clip.duration);
        }
        this._applyProgress(0);

        this.element.classList.add('is-ready'); // CSS hides the 2D fallback pack
        this._onResize = () => this._resize();
        window.addEventListener('resize', this._onResize);
        this._loop();
    }

    // ------------------------------------------------------------- drag
    dragStart(event) {
        if (this.committed || !this.renderer) {
            return;
        }
        this.dragging = true;
        this.startX = event.clientX;
        try {
            event.currentTarget.setPointerCapture(event.pointerId);
        } catch {
            // best effort
        }
    }

    dragMove(event) {
        const rect = this.element.getBoundingClientRect();
        this.pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        this.pointer.y = ((event.clientY - rect.top) / rect.height) * 2 - 1;

        if (!this.dragging || this.committed) {
            return;
        }
        this.targetProgress = Math.max(0, Math.min(1, Math.abs(event.clientX - this.startX) / DRAG_DISTANCE));
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

    // fallback: click to open in one go
    openAtOnce() {
        if (this.renderer && !this.committed) {
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

        // lean toward the pointer
        this.tilt.x += (this.pointer.y * TILT - this.tilt.x) * 0.1;
        this.tilt.y += (this.pointer.x * TILT - this.tilt.y) * 0.1;
        if (this.pivot) {
            this.pivot.rotation.x = this.tilt.x;
            this.pivot.rotation.y = this.tilt.y;
        }

        this.renderer.render(this.scene, this.camera);
    }

    _applyProgress(progress) {
        if (!this.mixer) {
            return;
        }
        this.mixer.setTime((TEAR_START + (1 - TEAR_START) * progress) * this.duration);
    }

    _resize() {
        if (!this.renderer) {
            return;
        }
        const width = this.element.clientWidth || 360;
        const height = this.element.clientHeight || 460;
        this.renderer.setSize(width, height, false);
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
    }

    _teardown() {
        this.committed = null;
        if (this.frame) {
            cancelAnimationFrame(this.frame);
        }
        if (this._onResize) {
            window.removeEventListener('resize', this._onResize);
        }
        this.renderer?.dispose();
    }
}
