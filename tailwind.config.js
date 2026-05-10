import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Display — characterful serif for headings, page titles, person names
                display: [
                    '"EB Garamond"',
                    'Cardo',
                    'Georgia',
                    'serif',
                ],
                // Body — modern serif kept for prose, subtitles, labels
                serif: [
                    '"Source Serif 4"',
                    '"Source Serif Pro"',
                    'Georgia',
                    'serif',
                ],
                // Sans for tight UI labels, eyebrow caps, small UI affordances
                sans: [
                    '"Inter Tight"',
                    'system-ui',
                    'sans-serif',
                ],
                // Mono for centimorgan numbers, identifiers, dates — strict tabular feel
                mono: [
                    '"JetBrains Mono"',
                    'ui-monospace',
                    'monospace',
                ],
            },
            colors: {
                // Parchment / paper base — warm, faintly aged
                paper: {
                    50:  '#FBF7EC',
                    100: '#F5EFDF',
                    200: '#EDE3CC',
                    300: '#DFD0AE',
                    400: '#C7B58D',
                    500: '#A89870',
                },
                // Ink — deep brown-black, warmer than gray-900
                ink: {
                    50:  '#7C7060',
                    100: '#665A4A',
                    200: '#4F4636',
                    300: '#3B3325',
                    400: '#2A2317',
                    500: '#1A140B',
                    600: '#100B05',
                },
                // Sepia muted text
                sepia: {
                    300: '#A48F6E',
                    400: '#8C7A5C',
                    500: '#73613F',
                    600: '#5B4B2D',
                },
                // Wine — primary accent for hover/active/important
                wine: {
                    400: '#9A4242',
                    500: '#7A2D2D',
                    600: '#5F2222',
                },
                // Indigo ink — link/secondary accent
                marine: {
                    500: '#2D3D7A',
                    600: '#1F2C5A',
                },
            },
            boxShadow: {
                'paper': '0 1px 0 rgba(60, 47, 23, 0.04), 0 0 0 1px rgba(60, 47, 23, 0.06)',
                'leaf':  '0 1px 2px rgba(60, 47, 23, 0.06), 0 0 0 1px rgba(60, 47, 23, 0.08)',
                'card':  '0 1px 1px rgba(60, 47, 23, 0.04), 0 6px 20px -10px rgba(60, 47, 23, 0.18), 0 0 0 1px rgba(60, 47, 23, 0.08)',
            },
            letterSpacing: {
                eyebrow: '0.18em',
            },
        },
    },

    plugins: [forms],
};
