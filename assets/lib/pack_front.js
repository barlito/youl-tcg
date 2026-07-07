/**
 * Paints a YOUL pack "front" (artwork + branding) onto a 2D canvas context at a
 * fixed 840×1300 resolution. Shared by the 3D opening pack (blitted into the
 * GLTF UV rect) and the boosters grid (drawn straight into a tile canvas) so the
 * two stay visually identical. Pass already-loaded images (hero/logo) or null.
 */

export const PACK_FRONT_W = 840;
export const PACK_FRONT_H = 1300;

export function drawPackFront(c, { hero = null, logo = null, name = 'YOUL', count = 5, fallback = false }) {
    const fw = PACK_FRONT_W;
    const fh = PACK_FRONT_H;

    c.fillStyle = '#180a33';
    c.fillRect(0, 0, fw, fh);
    if (hero) {
        // centred cover-fit — fills the whole front incl. the bottom
        const s = Math.max(fw / hero.width, fh / hero.height);
        const w = hero.width * s;
        const h = hero.height * s;
        c.drawImage(hero, (fw - w) / 2, (fh - h) / 2, w, h);
    }

    // gentle bottom gradient: keeps the wordmark legible, light enough that the
    // artwork still reads all the way down
    const vignette = c.createLinearGradient(0, fh * 0.5, 0, fh);
    vignette.addColorStop(0, 'rgba(11,7,18,0)');
    vignette.addColorStop(1, 'rgba(11,7,18,0.6)');
    c.fillStyle = vignette;
    c.fillRect(0, 0, fw, fh);

    // subtle holographic sheen
    c.save();
    c.globalCompositeOperation = 'screen';
    c.globalAlpha = 0.1;
    const sheen = c.createLinearGradient(0, 0, fw, fh);
    sheen.addColorStop(0, '#ff3db0');
    sheen.addColorStop(0.5, '#5be4ff');
    sheen.addColorStop(1, '#a435f0');
    c.fillStyle = sheen;
    for (let i = -fh; i < fw; i += 120) {
        c.fillRect(i, 0, 46, fh);
    }
    c.restore();

    // brand logo on the fallback front (no extension artwork)
    if (fallback && logo) {
        const lw = fw * 0.74;
        const lh = lw * (logo.height / logo.width);
        c.drawImage(logo, (fw - lw) / 2, fh * 0.3, lw, lh);
    }

    const label = (name || 'YOUL').toUpperCase();
    c.textAlign = 'center';
    c.fillStyle = '#fff';
    c.font = `800 ${label.length > 10 ? 64 : 88}px "Arial Black", system-ui, sans-serif`;
    c.fillText(label, fw / 2, fh * 0.8);
    c.fillStyle = '#c9a0ff';
    c.font = '700 26px monospace';
    c.fillText('TRADING CARD GAME', fw / 2, fh * 0.845);
    c.fillStyle = 'rgba(255,255,255,.85)';
    c.font = '600 20px monospace';
    c.fillText(`CONTIENT ${count} CARTE${count > 1 ? 'S' : ''}`, fw / 2, fh * 0.93);

    // "N CARTES" badge, top-right
    c.fillStyle = '#0b0712';
    roundRect(c, fw - 230, 40, 190, 96, 16);
    c.fill();
    c.fillStyle = '#fff';
    c.textAlign = 'right';
    c.font = '800 56px "Arial Black", sans-serif';
    c.fillText(`${count}`, fw - 150, 108);
    c.font = '700 20px monospace';
    c.fillText('CARTES', fw - 55, 100);
    c.textAlign = 'center';

    // chrome border
    c.strokeStyle = 'rgba(201,160,255,.55)';
    c.lineWidth = 10;
    roundRect(c, 5, 5, fw - 10, fh - 10, 28);
    c.stroke();
}

function roundRect(c, x, y, w, h, r) {
    c.beginPath();
    c.moveTo(x + r, y);
    c.arcTo(x + w, y, x + w, y + h, r);
    c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r);
    c.arcTo(x, y, x + w, y, r);
    c.closePath();
}
