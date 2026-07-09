<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Inline snippet shared by the Card and Extension CRUDs: displays the live
 * value next to the foilSize range slider ("Auto" / "45 %" / "Cover"). The
 * 10/100 bounds mirror VisualConfig::FOIL_SIZE_MIN/MAX (below MIN = the Auto
 * sentinel position).
 */
final class FoilSizeSliderScript
{
    public const string HTML = <<<'HTML'
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('input[type="range"][data-foil-size]').forEach((slider) => {
                const output = document.createElement('output');
                output.style.cssText = 'margin-left: 12px; font-weight: 600; min-width: 90px; display: inline-block;';
                slider.style.verticalAlign = 'middle';
                slider.after(output);
                const render = () => {
                    const value = parseInt(slider.value, 10);
                    output.textContent = value < 10 ? 'Auto (preset)' : (value >= 100 ? 'Cover (100 %)' : value + ' % (tilé)');
                };
                slider.addEventListener('input', render);
                render();
            });
        });
        </script>
        HTML;
}
