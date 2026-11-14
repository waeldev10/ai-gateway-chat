
@php
    // Static defaults only: tokens are fixed, so each one is read explicitly.
    // Every value is validated as strict #RRGGBB before it may reach CSS;
    // anything else falls back to a safe constant (never raw config).
    $uiHex = static fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) === 1
        ? strtoupper(trim($v))
        : null;
    $uiLight = [
        'primary' => $uiHex(config('ui.colors.defaults.light.primary')) ?? '#4F46E5',
        'primary_text' => $uiHex(config('ui.colors.defaults.light.primary_text')) ?? '#FFFFFF',
        'accent' => $uiHex(config('ui.colors.defaults.light.accent')) ?? '#7C3AED',
        'link' => $uiHex(config('ui.colors.defaults.light.link')) ?? '#4338CA',
    ];
    $uiDark = [
        'primary' => $uiHex(config('ui.colors.defaults.dark.primary')) ?? '#818CF8',
        'primary_text' => $uiHex(config('ui.colors.defaults.dark.primary_text')) ?? '#0B1120',
        'accent' => $uiHex(config('ui.colors.defaults.dark.accent')) ?? '#A78BFA',
        'link' => $uiHex(config('ui.colors.defaults.dark.link')) ?? '#A5B4FC',
    ];
@endphp
<style id="ui-colors">
    :root{
        --ui-primary:{{ $uiLight['primary'] }};
        --ui-primary-foreground:{{ $uiLight['primary_text'] }};
        --ui-accent:{{ $uiLight['accent'] }};
        --ui-link:{{ $uiLight['link'] }};
    }
    .dark{
        --ui-primary:{{ $uiDark['primary'] }};
        --ui-primary-foreground:{{ $uiDark['primary_text'] }};
        --ui-accent:{{ $uiDark['accent'] }};
        --ui-link:{{ $uiDark['link'] }};
    }
</style>
<script>
    // First-paint UI colors: same principle as theme-init. Runs synchronously
    // in <head> so a saved palette applies before anything renders (no flash
    // of defaults). Only strict #RRGGBB values are honored; anything missing,
    // malformed, or corrupted is ignored and the stylesheet defaults above
    // stay in effect. Cosmetic only: never touches auth, DB, or app logic.
    window.__uiColors = (function () {
        const KEY = 'ui_colors';
        const TOKENS = ['primary', 'primary_text', 'accent', 'link'];
        const MAP = {
            primary: '--ui-primary',
            primary_text: '--ui-primary-foreground',
            accent: '--ui-accent',
            link: '--ui-link'
        };
        const HEX = /^#[0-9a-fA-F]{6}$/;
        const LIGHT = @js($uiLight);
        const DARK = @js($uiDark);

        function copy(palette) {
            return {
                primary: palette.primary,
                primary_text: palette.primary_text,
                accent: palette.accent,
                link: palette.link
            };
        }

        // The editor uses the defaults for the scheme that is currently active,
        // so opening settings in dark mode does not show light-only values.
        function defaults() {
            try {
                return copy(document.documentElement.classList.contains('dark') ? DARK : LIGHT);
            } catch (e) {
                return copy(LIGHT);
            }
        }

        function sanitize(value) {
            if (typeof value !== 'string') return null;
            value = value.trim();
            return HEX.test(value) ? value.toUpperCase() : null;
        }

        // Atomic palette integrity: a stored palette is valid only when ALL
        // four tokens are present and strictly valid — matching save().
        // Anything incomplete, malformed, or corrupted yields {} so the
        // stylesheet defaults apply instead of a partial palette.
        function read() {
            try {
                const raw = localStorage.getItem(KEY);
                if (!raw) return {};
                const data = JSON.parse(raw);
                if (!data || typeof data !== 'object' || Array.isArray(data)) return {};
                const out = {};
                TOKENS.forEach(function (token) {
                    const clean = sanitize(data[token]);
                    if (clean) out[token] = clean;
                });
                if (Object.keys(out).length !== TOKENS.length) return {};
                return out;
            } catch (e) {
                return {};
            }
        }

        // Only validated values ever reach CSS: each input is sanitized
        // first, so the value passed to setProperty is always the checked
        // one (strict #RRGGBB, normalized). Property writes only.
        function apply(colors) {
            try {
                const root = document.documentElement;
                if (!root || !colors || typeof colors !== 'object') return;
                TOKENS.forEach(function (token) {
                    const clean = sanitize(colors[token]);
                    if (clean) root.style.setProperty(MAP[token], clean);
                });
            } catch (e) {}
        }

        function applyStored() {
            // Clear first so a removed palette (e.g. reset in another tab)
            // can never leave stale inline overrides behind.
            try {
                const root = document.documentElement;
                TOKENS.forEach(function (token) {
                    root.style.removeProperty(MAP[token]);
                });
            } catch (e) {}
            apply(read());
        }

        // Persist a full 4-token palette (strict #RRGGBB each). Returns the
        // normalized palette, or null when invalid (nothing is stored).
        function save(colors) {
            try {
                const out = {};
                TOKENS.forEach(function (token) {
                    const clean = colors ? sanitize(colors[token]) : null;
                    if (clean) out[token] = clean;
                });
                if (Object.keys(out).length !== TOKENS.length) return null;
                localStorage.setItem(KEY, JSON.stringify(out));
                apply(out);
                return out;
            } catch (e) {
                return null;
            }
        }

        // Forget the palette and drop inline overrides so the per-scheme
        // stylesheet defaults (:root / .dark) take over again.
        function clear() {
            try {
                localStorage.removeItem(KEY);
            } catch (e) {}
            try {
                const root = document.documentElement;
                TOKENS.forEach(function (token) {
                    root.style.removeProperty(MAP[token]);
                });
            } catch (e) {}
        }

        return {
            KEY: KEY,
            TOKENS: TOKENS,
            sanitize: sanitize,
            read: read,
            apply: apply,
            applyStored: applyStored,
            defaults: defaults,
            save: save,
            clear: clear
        };
    })();

    try {
        window.__uiColors.applyStored();
    } catch (e) {}
</script>
