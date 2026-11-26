<button type="button" class="victory-theme-button" x-data="{ dark: document.documentElement.classList.contains('dark') }" x-on:theme-changed.window="dark = $event.detail === 'dark' || ($event.detail === 'system' &amp;&amp; window.matchMedia('(prefers-color-scheme: dark)').matches)" x-on:click="$dispatch('theme-changed', dark ? 'light' : 'dark')" x-bind:aria-pressed="dark.toString()" x-bind:aria-label="dark ? 'Gunakan mode terang' : 'Gunakan mode gelap'" aria-label="Gunakan mode gelap">
    <span x-show="!dark" aria-hidden="true">@svg('heroicon-o-moon')</span>
    <span x-show="dark" x-cloak aria-hidden="true">@svg('heroicon-o-sun')</span>
</button>
