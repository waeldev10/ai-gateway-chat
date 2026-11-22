
@php

    $uiHex = static fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) === 1
        ? strtoupper(trim($v))
        : null;
    $uiLight = [
        'primary' => $uiHex(config('ui.colors.defaults.light.primary')) ?? '#0C5548',
        'primary_text' => $uiHex(config('ui.colors.defaults.light.primary_text')) ?? '#FFFFFF',
        'accent' => $uiHex(config('ui.colors.defaults.light.accent')) ?? '#23796A',
        'link' => $uiHex(config('ui.colors.defaults.light.link')) ?? '#0C5548',
    ];
    $uiDark = [
        'primary' => $uiHex(config('ui.colors.defaults.dark.primary')) ?? '#63B4A5',
        'primary_text' => $uiHex(config('ui.colors.defaults.dark.primary_text')) ?? '#06231D',
        'accent' => $uiHex(config('ui.colors.defaults.dark.accent')) ?? '#80C8BA',
        'link' => $uiHex(config('ui.colors.defaults.dark.link')) ?? '#8FDCCC',
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
   
            try {
                const root = document.documentElement;
                TOKENS.forEach(function (token) {
                    root.style.removeProperty(MAP[token]);
                });
            } catch (e) {}
            apply(read());
        }

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
