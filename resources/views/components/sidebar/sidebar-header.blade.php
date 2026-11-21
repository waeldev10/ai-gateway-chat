
<div class="h-14 flex items-center gap-2 px-3 shrink-0" :class="sidebarOpen ? '' : 'lg:hidden'">

    <button type="button" @click="if (drawer) { drawer = false; userMenu = false; } else { toggleSidebar(); }" :aria-label="drawer ? 'إغلاق القائمة' : 'تبديل الشريط'" :aria-expanded="drawer ? 'true' : (sidebarOpen ? 'true' : 'false')" aria-controls="chat-sidebar"
        class="w-9 h-9 grid place-items-center rounded-xl 
          hover:bg-foreground/5 shrink-0 cursor-pointer
        transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
    </button>

    <span class="font-bold tracking-tight truncate text-[15px]">بوابة الذكاء الاصطناعي</span>

</div>

<div class="hidden h-14 shrink-0 items-center justify-center px-2" :class="sidebarOpen ? '' : 'lg:flex'"
    x-data="{ tip: false, tipTop: 0 }"
    @mouseenter="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
    @mouseleave="tip = false"
    @focusin="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
    @focusout="tip = false">
    <button type="button" @click="toggleSidebar()" aria-label="توسيع الشريط" :aria-expanded="sidebarOpen ? 'true' : 'false'" aria-controls="chat-sidebar"
        class="w-9 h-9 grid place-items-center rounded-xl  hover:bg-foreground/5 shrink-0 transition-colors focus-visible:outline-2 
        focus-visible:outline-offset-2 focus-visible:outline-current cursor-pointer">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
    </button>

    <x-sidebar.collapsed-tip label="توسيع الشريط" />

</div>
