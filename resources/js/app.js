// import './stream-client.js';
// import './chat-stream.js';
// import './message-actions.js';
// import './chat-history.js';

function currentTheme() {
    try {
        const stored = localStorage.getItem('theme');
        return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
    } catch (e) {
        return 'system';
    }
}

function applyTheme() {
    const t = currentTheme();
    let dark = false;
    try {
        dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    } catch (e) {
        dark = false;
    }
    document.documentElement.classList.toggle('dark', dark);
    try {
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    } catch (e) {}
    return t;
}

window.__applyTheme = applyTheme;
window.__currentTheme = currentTheme;

window.__setTheme = function (t) {
    if (t !== 'light' && t !== 'dark' && t !== 'system') {
        return currentTheme();
    }
    try {
        localStorage.setItem('theme', t);
    } catch (e) {}
    return applyTheme();
};

applyTheme();

function expectedDark() {
    try {
        const t = currentTheme();
        if (t === 'dark') return true;
        if (t === 'light') return false;
        return matchMedia('(prefers-color-scheme: dark)').matches;
    } catch (e) {
        return false;
    }
}

try {
    if (typeof MutationObserver !== 'undefined' && document.documentElement) {
        new MutationObserver(() => {
            let has = false;
            try {
                has = document.documentElement.classList.contains('dark');
            } catch (e) { return; }
            if (has !== expectedDark()) applyTheme();
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    }
} catch (e) {}

function applyStoredUiColors() {
    try {
        if (window.__uiColors) window.__uiColors.applyStored();
    } catch (e) {}
}

applyStoredUiColors();

let navLoadingTimer = null;
function disarmNavLoadingGate() {
    if (navLoadingTimer) {
        clearTimeout(navLoadingTimer);
        navLoadingTimer = null;
    }
    try {
        document.documentElement.classList.remove('nav-loading-pending');
    } catch (e) {}
}
document.addEventListener('livewire:navigate', () => {
    try {
        document.documentElement.classList.add('nav-loading-pending');
    } catch (e) {}
    if (navLoadingTimer) clearTimeout(navLoadingTimer);
    navLoadingTimer = setTimeout(disarmNavLoadingGate, 250);
});

document.addEventListener('livewire:navigated', () => {
    disarmNavLoadingGate();
    applyTheme();
    applyStoredUiColors();
});

try {
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (currentTheme() === 'system') {
            applyTheme();
        }
    });
} catch (e) {}

window.addEventListener('storage', (e) => {
    if (e && e.key === 'theme') {
        applyTheme();
    }
    if (e && e.key === 'ui_colors') {
        applyStoredUiColors();
    }
});
