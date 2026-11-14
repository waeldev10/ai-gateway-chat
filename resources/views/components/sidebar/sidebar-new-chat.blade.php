{{-- Sidebar "New Chat" action. Presentation only: links to the existing
     conversation-creation route. Uses the parent Alpine scope (`sidebarOpen`):
     no new state is defined here apart from the local hover-tooltip state. --}}
<div class="px-3 pb-2 shrink-0" :class="sidebarOpen ? '' : 'lg:px-2 lg:grid lg:place-items-center'">
    <a wire:navigate href="{{ route('conversations.create') }}" aria-label="محادثة جديدة"
        :class="sidebarOpen ? '' : 'lg:w-10 lg:h-10 lg:mx-auto lg:px-0 lg:py-0'"
        x-data="{ tip: false, tipTop: 0 }"
        @mouseenter="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
        @mouseleave="tip = false"
        @focusin="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
        @focusout="tip = false"
        class="flex items-center justify-center gap-2 ui-primary-btn rounded-full py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2">
      <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24">
  <path d="M0 0h24v24H0z" fill="none" />
  <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v-3m0 0V9m0 3H9m3 0h3m-3 9a8.96 8.96 0 0 1-4.49-1.198a1.3 1.3 0 0 0-.257-.13a.5.5 0 0 0-.167-.017a1 1 0 0 0-.258.07l-2.31.769h-.002c-.487.163-.731.245-.893.187a.5.5 0 0 1-.304-.304c-.057-.162.024-.405.186-.892v-.003l.77-2.306l.002-.005c.042-.129.064-.194.068-.256a.5.5 0 0 0-.017-.168a1.2 1.2 0 0 0-.127-.252l-.003-.005A9 9 0 1 1 12 21" />
</svg>
        <span :class="sidebarOpen ? '' : 'lg:hidden'">محادثة جديدة</span>
        <x-sidebar.collapsed-tip label="محادثة جديدة" /></a>
</div>
