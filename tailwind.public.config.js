/**
 * Public pages (landing, login/register, customer portal) used the Tailwind
 * Play CDN: default theme, no plugins. This config reproduces exactly that,
 * as a build-time stylesheet, so nothing third-party runs on the login page
 * and the pages do not wait for a CDN script before they can be styled.
 * (The app config adds @tailwindcss/forms, which would restyle these forms.)
 */
export default {
    content: [
        './resources/views/landing.blade.php',
        './resources/views/portal/**/*.blade.php',
        './resources/views/auth/**/*.blade.php',
        './resources/views/components/layouts/auth.blade.php',
    ],
    theme: { extend: {} },
    plugins: [],
};
