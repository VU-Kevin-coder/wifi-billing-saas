const storageKey = 'wifi-billing-theme';
const themes = ['auto', 'light', 'dark'];
const media = window.matchMedia('(prefers-color-scheme: dark)');

function getTheme() {
    try {
        const theme = localStorage.getItem(storageKey);
        return themes.includes(theme) ? theme : 'auto';
    } catch (_) {
        return 'auto';
    }
}

function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'auto') {
        delete root.dataset.appTheme;
    } else {
        root.dataset.appTheme = theme;
    }
    root.classList.toggle('dark', theme === 'dark' || (theme === 'auto' && media.matches));
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
        button.textContent = `Theme: ${theme}`;
        button.setAttribute('aria-label', `Change color theme. Current theme: ${theme}`);
    });
}

let theme = getTheme();
applyTheme(theme);

document.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-theme-toggle]') : null;
    if (!button) return;
    theme = themes[(themes.indexOf(theme) + 1) % themes.length];
    try {
        localStorage.setItem(storageKey, theme);
    } catch (_) {
        // Keep the theme usable when browser storage is unavailable.
    }
    applyTheme(theme);
});

window.addEventListener('storage', event => {
    if (event.key !== storageKey) return;
    theme = getTheme();
    applyTheme(theme);
});

media.addEventListener('change', () => {
    if (theme === 'auto') applyTheme(theme);
});
