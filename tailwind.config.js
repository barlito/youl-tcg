/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./vendor/tales-from-a-dev/flowbite-bundle/templates/**/*.html.twig",
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  theme: {
    extend: {
      colors: {
        bg: '#0b0712',
        surface: '#160e22',
        primary: { DEFAULT: '#a435f0', on: '#000' },
        magenta: '#ff3db0',
        ink: { DEFAULT: '#f0e8f7', strong: '#fff', muted: '#b9a8cf', dim: '#6a5a8a' },
        hairline: 'rgba(164, 53, 240, 0.2)',
        live: '#5be584',
        soon: '#ffd24a',
        rarity: {
          common: '#9aa3b2',
          uncommon: '#5be584',
          rare: '#54a8ff',
          epic: '#a435f0',
          legendary: '#ff8a2b',
        },
      },
      fontFamily: {
        display: ['"Space Grotesk"', 'sans-serif'],
        sans: ['Inter', 'ui-sans-serif', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'monospace'],
      },
    },
  },
  plugins: [],
}
