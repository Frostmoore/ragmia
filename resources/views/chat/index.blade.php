<x-app-layout>
    <x-slot name="header">
        <div x-data class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                {{-- Hamburger SOLO mobile --}}
                <button
                    class="lg:hidden inline-flex items-center justify-center p-2 rounded-md
                            text-gray-600 hover:text-gray-900 hover:bg-gray-100
                            dark:text-gray-300 dark:hover:text-gray-100 dark:hover:bg-gray-800"
                    x-on:click.stop.prevent="
                        console.log('[burger] click, was=', $store.ui.sidebarOpen);
                        $store.ui.sidebarOpen = true;
                        console.log('[burger] now =', $store.ui.sidebarOpen);
                    "
                    aria-label="Apri menu progetti">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" stroke="currentColor" fill="none" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                    Bolt Threads Chat
                </h2>
            </div>
        </div>
    </x-slot>

    {{-- ******* PURGE TABS PRIMA DI QUALSIASI INIT ******* --}}
    <script>
    (function hardResetTabs(){
        try {
            // Elimina TUTTE le chiavi che possono reimportare tab vecchie
            [
                'chatTabsV3',               // la tua chiave legacy
                'ragmia:tabs',
                'ragmia:active',
                'ragmia:autoContext',
                'ragmia:model',
                'ragmia:compress_model',
                'ragmia:useCompressor'
            ].forEach(k => localStorage.removeItem(k));
        } catch (e) { console.warn('Purge tabs storage fallita', e); }

        // Se lo store è già presente (hot reload ecc.), azzera anche lo stato runtime
        if (window.Alpine && Alpine.store && Alpine.store('chat')) {
            const chat = Alpine.store('chat');
            chat.tabs = [];
            chat.activeTabId = null;
            chat.active = null; // compat
        }
    })();
    </script>

    {{-- Inizializza store chat (SENZA ripristinare tab) --}}
    <div x-data
         x-init="$store.chat.init({ folders: @js($folders ?? []), projectsNoFolder: @js($projectsNoFolder ?? []) })"
         class="flex-1 min-h-0 bg-gray-50 dark:bg-gray-900">

        {{-- Overlay mobile --}}
        <div
            x-show="$store.ui.sidebarOpen"
            x-transition.opacity
            @click="$store.ui.sidebarOpen = false"
            @keydown.window.escape="$store.ui.sidebarOpen = false"
            class="fixed inset-0 bg-black/40 z-40 lg:hidden"
            aria-hidden="true">
        </div>

        <div class="h-full min-h-0 grid grid-cols-1 lg:grid-cols-[20rem,1fr]">
            {{-- DESKTOP: sidebar fissa nella colonna sinistra --}}
            <div class="hidden lg:block">
                <x-chat.sidebar class="h-full border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800" />
            </div>

            {{-- MAIN --}}
            <div class="h-full min-h-0 flex flex-col bg-white dark:bg-gray-900">
                <x-chat.tabs class="shrink-0 border-b border-gray-200 dark:border-gray-700" />
                <x-chat.messages class="flex-1 min-h-0" />
                <x-chat.composer class="shrink-0 border-t border-gray-200 dark:border-gray-700 dark:bg-gray-800" />
            </div>
        </div>

        {{-- MOBILE: drawer fisso sopra (fuori dal flow) --}}
        <div
            class="lg:hidden fixed z-50 inset-y-0 left-0 w-4/5 max-w-xs bg-white dark:bg-gray-800
                    transform transition-transform duration-200 ease-in-out"
            :style="$store.ui.sidebarOpen ? 'transform: translateX(0);' : 'transform: translateX(-100%);'">
            <x-chat.sidebar class="h-full border-r border-gray-200 dark:border-gray-700" />
        </div>
    </div>

    <script>
    (function () {
    // Attendi che Alpine e lo store 'chat' siano disponibili
    const until = (cond, { tries = 120, delay = 50 } = {}) =>
        new Promise((resolve, reject) => {
        const tick = () => {
            try {
            if (cond()) return resolve(true);
            if (tries-- <= 0) return reject(new Error('timeout'));
            setTimeout(tick, delay);
            } catch (e) { reject(e); }
        };
        tick();
        });

    const patch = (chat) => {
        if (chat.__messagesPatchApplied) return;
        chat.__messagesPatchApplied = true;

        // Forza la reattività rimpiazzando l’array tabs (e gli oggetti tab)
        chat.__forceRender = function () {
        this.tabs = this.tabs.map(t => ({ ...t })); // clone shallow di ogni tab
        };

        // Carica messaggi per una tab (id progetto) solo se non già caricati
        chat.loadTabMessages = async function (tab) {
        if (!tab || tab._loaded || tab._loading) return;
        tab._loading = true;
        try {
            const res = await fetch('/api/messages?project_id=' + encodeURIComponent(tab.project_id), {
            headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            const msgs = Array.isArray(data?.messages)
            ? data.messages.map(m => ({ role: String(m.role), content: String(m.content ?? '') }))
            : [];

            // ⚠️ NIENTE push: riassegna l’intero array e poi rimpiazza le tabs
            tab.messages = msgs;
            tab._loaded = true;
            tab._loading = false;

            this.__forceRender();
            this.persistTabs && this.persistTabs();
        } catch (e) {
            tab._loading = false;
            tab._loaded = true;
            tab.messages = [{ role: 'assistant', content: 'Errore caricando messaggi: ' + (e?.message || e) }];
            this.__forceRender();
        }
        };

        // Wrap: quando apri una tab, carica SUBITO lo storico
        const openOrig = typeof chat.openProjectTab === 'function' ? chat.openProjectTab.bind(chat) : null;
        chat.openProjectTab = async function (project) {
        let tab = this.tabs.find(t => t.project_id === project.id);
        if (!tab) {
            tab = {
            id: (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2)),
            title: (project.path || project.name || '').split('/').pop() || 'Senza nome',
            path: project.path || project.name || '',
            project_id: project.id,
            messages: [],
            _loaded: false,
            _loading: false
            };
            this.tabs = [...this.tabs, tab]; // riassegna per reattività
        }
        this.activeTabId = tab.id;
        this.persistTabs && this.persistTabs();

        // Carica immediatamente i messaggi
        this.loadTabMessages(tab);

        // Esegui eventuale logica originale (se c’era)
        if (openOrig) {
            try { await openOrig(project); } catch {}
        }
        };

        // Wrap: se attivi una tab non ancora caricata, caricala
        const activateOrig = typeof chat.activateTab === 'function' ? chat.activateTab.bind(chat) : null;
        chat.activateTab = function (id) {
        this.activeTabId = id;
        const tab = typeof this.activeTab === 'function' ? this.activeTab() : (this.tabs.find(t => t.id === id) || null);
        if (tab && !tab._loaded && !tab._loading) this.loadTabMessages(tab);
        this.persistTabs && this.persistTabs();
        if (activateOrig) try { activateOrig(id); } catch {}
        };

        // Primo paint: se c’è una tab attiva vuota, carica
        queueMicrotask(() => {
        try {
            const tab = typeof chat.activeTab === 'function' ? chat.activeTab() : null;
            if (tab && (!Array.isArray(tab.messages) || tab.messages.length === 0)) {
            chat.loadTabMessages(tab);
            }
        } catch {}
        });
    };

    // Avvia patch quando tutto è pronto
    until(() => window.Alpine && Alpine.store && Alpine.store('chat'))
        .then(() => patch(Alpine.store('chat')))
        .catch(() => { /* ignora */ });
    })();
    </script>

</x-app-layout>

