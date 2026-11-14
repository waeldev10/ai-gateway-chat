
<script>
    try {
        const stored = localStorage.getItem('theme');
        const t = stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
        const m = matchMedia('(prefers-color-scheme: dark)').matches;
        const d = t === 'dark' || (t === 'system' && m);
        document.documentElement.classList.toggle('dark', d);
        document.documentElement.style.colorScheme = d ? 'dark' : 'light';
    } catch (e) {}
</script>