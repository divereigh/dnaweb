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
                // Single typeface family for clarity. JetBrains Mono for tabular data.
                display: ['Inter', 'system-ui', 'sans-serif'],
                serif: ['Inter', 'system-ui', 'sans-serif'],
                sans: ['Inter', 'system-ui', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
            },
            colors: {
                // Surface scale (whites and warm grays). Names retained from earlier
                // palette so existing class usage keeps working.
                paper: {
                    50:  '#ffffff', // primary surface
                    100: '#fafafa', // hover surface
                    200: '#f5f5f5',
                    300: '#e5e5e5', // hairline border
                    400: '#d4d4d4',
                    500: '#a3a3a3',
                },
                // Text scale — neutral grays from secondary up to pure black.
                ink: {
                    50:  '#a3a3a3',
                    100: '#737373',
                    200: '#525252',
                    300: '#404040',
                    400: '#262626',
                    500: '#171717', // primary text
                    600: '#0a0a0a', // headlines
                },
                sepia: {
                    300: '#d4d4d4',
                    400: '#a3a3a3',
                    500: '#737373', // muted/secondary text
                    600: '#525252',
                },
                // Single interactive accent.
                wine: {
                    400: '#3b82f6',
                    500: '#2563eb', // links / hover
                    600: '#1d4ed8',
                },
                // Secondary accent — used sparingly for status (e.g. "Managed").
                marine: {
                    500: '#0d9488',
                    600: '#0f766e',
                },
            },
            boxShadow: {
                'paper': '0 0 0 1px #e5e5e5',
                'leaf':  '0 0 0 1px #e5e5e5',
                'card':  '0 0 0 1px #e5e5e5',
            },
            letterSpacing: {
                eyebrow: '0.04em',
            },
        },
    },

    plugins: [forms],
};
