@props(['class' => ''])

<nav
    x-data="tabsNs({{ auth()->id() ?? 'null' }})"
    x-init="boot($store.chat)"
    {{ $attributes->merge(['class' => 'shrink-0 h-11 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-2 flex items-center overflow-x-auto gap-2']) }}
>
    <template x-for="(tab, idx) in $store.chat.tabs" :key="tab.id">
        <div
            @click="$store.chat.activateTab && $store.chat.activateTab(tab.id)"
            :class="[
                'group flex items-center gap-2 px-3 py-1.5 rounded-full cursor-pointer whitespace-nowrap border transition-colors',
                $store.chat.activeTabId === tab.id
                    ? 'bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100'
                    : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 border-transparent text-gray-700 dark:text-gray-300'
            ]"
        >
            <span class="text-sm" x-text="tab.title"></span>
            <button
                type="button"
                @click.stop="$store.chat.closeTab && ($store.chat.closeTab(tab.id), $store.chat.persistTabs && $store.chat.persistTabs())"
                class="hidden group-hover:inline-block text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100"
                aria-label="Chiudi"
            >&times;</button>
        </div>
    </template>

    <!-- Watchers: persisti quando cambia roba rilevante -->
    <div class="hidden" x-effect="$store.chat?.persistTabs && $store.chat.persistTabs()"></div>
    <div class="hidden" x-effect="$store.chat?.persistTabs && (JSON.stringify($store.chat.tabs), $store.chat.persistTabs())"></div>
    <div class="hidden" x-effect="$store.chat?.persistTabs && ($store.chat.activeTabId, $store.chat.persistTabs())"></div>
    <div class="hidden" x-effect="$store.chat?.persistTabs && ($store.chat.model, $store.chat.persistTabs())"></div>
    <div class="hidden" x-effect="$store.chat?.persistTabs && ($store.chat.compress_model, $store.chat.persistTabs())"></div>
    <div class="hidden" x-effect="$store.chat?.persistTabs && ($store.chat.autoContext, $store.chat.persistTabs())"></div>
    <div class="hidden" x-effect="$store.chat?.persistTabs && ($store.chat.useCompressor, $store.chat.persistTabs())"></div>
</nav>

<script>
function tabsNs(userId) {
    return {
        ns: 'ragmia:u:' + (userId === null ? 'guest' : userId) + ':',

        boot(chat) {
            if (!chat) { console.error('Alpine $store.chat mancante'); return; }

            // Forza vuoto al primo mount (sticazzi™)
            chat.tabs = Array.isArray(chat.tabs) ? chat.tabs : [];
            if (chat.tabs.length) {
                chat.tabs = [];
                chat.activeTabId = null;
                chat.active = null;
            }

            // Namespace e persistenza per-utente (non reimportiamo nulla)
            chat._ns = this.ns;
            chat.persistTabs = function () {
                try {
                    localStorage.setItem(this._ns + 'tabs', JSON.stringify(this.tabs || []));
                    const active = (this.activeTabId ?? this.active ?? null);
                    localStorage.setItem(this._ns + 'activeTabId', JSON.stringify(active));
                    localStorage.setItem(this._ns + 'active', JSON.stringify(active));
                    localStorage.setItem(this._ns + 'autoContext', this.autoContext ? '1' : '0');
                    localStorage.setItem(this._ns + 'model', this.model || '');
                    localStorage.setItem(this._ns + 'compress_model', this.compress_model || '');
                    localStorage.setItem(this._ns + 'useCompressor', this.useCompressor ? '1' : '0');
                } catch (e) {}
            };
        }
    };
}
</script>
