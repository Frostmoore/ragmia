@props(['class' => ''])

@php
    // Utente "uncapped" (lista da .env LLM_UNCAPPED_USERS, fallback alla tua email)
    $allow = array_filter(array_map('trim', explode(',', env('LLM_UNCAPPED_USERS', 'r.ronconi@smp-digital.it'))));
    $isUncapped = auth()->check() && in_array(auth()->user()->email ?? '', $allow, true);
@endphp

<aside
    x-data="sidebarActions('{{ csrf_token() }}', {{ $isUncapped ? 'true' : 'false' }})"
    x-init="
        $nextTick(() => {
            if (!$store.chat) { console.error('Alpine $store.chat mancante'); return; }
            // esponi le funzioni ai figli (project-tree ecc.)
            $store.chat.deleteProject = (id, name) => $data.deleteProject(id, name);
            $store.chat.deleteFolder  = (id, name) => $data.deleteFolder(id, name);

            // evita undefined su prima render
            $store.chat.folders = $store.chat.folders || [];
            $store.chat.projectsNoFolder = $store.chat.projectsNoFolder || [];

            // init cap token + fetch patch
            initMaxTokens();
            patchFetchForMaxTokens();
        })
    "
    {{ $attributes->merge(['class' => "bg-white dark:bg-gray-800 h-full min-h-0 flex flex-col $class"]) }}>

    {{-- HEADER (fisso) --}}
    <div class="shrink-0 p-4 border-b border-gray-200 dark:border-gray-700">
        <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">Progetti</div>

        {{-- Impostazioni --}}
        <div class="mt-3 space-y-3">
            <label class="flex items-center justify-between text-sm text-gray-700 dark:text-gray-300">
                <span>Auto-contesto</span>
                <input type="checkbox"
                       x-model="$store.chat.autoContext"
                       x-on:change="$store.chat.persistTabs && $store.chat.persistTabs()"
                       class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
            </label>

            <div>
                <div class="text-xs mb-1 text-gray-500 dark:text-gray-400">Modello</div>
                <x-select
                    x-model="$store.chat.model"
                    x-on:change="$store.chat.persistTabs && $store.chat.persistTabs()"
                    class="w-full dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100">
                    <option value="openai:gpt-5">OpenAI — GPT-5</option>
                    <option value="anthropic:claude-3-5-haiku">Anthropic — Claude 3.5 Haiku</option>
                    <option value="google:gemini-1.5-flash">Google — Gemini 1.5 Flash</option>
                    <option value="openai:gpt-4o-mini">OpenAI — GPT-4o mini</option>
                    <option value="openai:o3">OpenAI — GPT-o3</option>
                    <option value="openai:o3-pro">OpenAI — o3-pro</option>
                </x-select>
            </div>

            <div>
                <div class="text-xs mb-1 text-gray-500 dark:text-gray-400">Compressore</div>
                <x-select
                    x-model="$store.chat.compress_model"
                    x-on:change="$store.chat.persistTabs && $store.chat.persistTabs()"
                    class="w-full dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100">
                    <option value="openai:gpt-4o-mini">OpenAI — GPT-4o mini</option>
                    <option value="anthropic:claude-3-5-haiku">Anthropic — Claude 3.5 Haiku</option>
                    <option value="google:gemini-1.5-flash">Google — Gemini 1.5 Flash</option>
                </x-select>
            </div>

            {{-- Limite token risposta (sotto "Compressore") --}}
            <div>
                <div class="text-xs mb-1 text-gray-500 dark:text-gray-400 flex items-center justify-between">
                    <span>Limite token risposta</span>
                    <span x-show="uncapped"
                          class="ml-2 px-1.5 py-0.5 text-[10px] rounded bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">
                        NO CAP
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        type="number"
                        inputmode="numeric"
                        min="1"
                        :max="uncapped ? 120000 : 4000"
                        x-model.number="$store.chat.maxTokensDesired"
                        @change="saveMaxTokens()"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100
                               focus:ring-0 focus:border-gray-400 dark:focus:border-gray-500 text-sm px-3 py-1.5">
                    <button
                        type="button"
                        @click="$store.chat.maxTokensDesired = uncapped ? 120000 : 4000; saveMaxTokens()"
                        class="px-2 py-1 text-xs rounded-md border border-gray-300 text-gray-700 hover:bg-gray-100
                               dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        Reset
                    </button>
                </div>
                <div class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                    <span x-show="!uncapped">Cap server: 4000</span>
                    <span x-show="uncapped">Cap server: illimitato (interno)</span>
                </div>
            </div>

            {{-- Usa compressore (se OFF invia il prompt raw) --}}
            <label class="mt-2 flex items-center justify-between text-sm text-gray-700 dark:text-gray-300">
                <span>Usa compressore</span>
                <input type="checkbox"
                       x-model="$store.chat.useCompressor"
                       x-on:change="$store.chat.persistTabs && $store.chat.persistTabs()"
                       class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
            </label>

            <div class="border-t border-gray-200 dark:border-gray-700"></div>
        </div>

        {{-- Search --}}
        <div class="mt-3 flex items-center gap-2">
            <input x-model="$store.chat.search"
                   type="text" placeholder="Cerca..."
                   class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100
                          focus:ring-0 focus:border-gray-400 dark:focus:border-gray-500 text-sm">
        </div>

        {{-- Toolbar --}}
        <div class="mt-3">
            <div class="grid grid-cols-2 gap-2 lg:hidden">
                <x-secondary-button type="button"
                    x-on:click="$store.chat.promptNewFolder()"
                    class="w-full justify-center dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600 hover:dark:bg-gray-700">
                    + Cartella
                </x-secondary-button>

                <x-primary-button type="button"
                    x-on:click="$store.chat.promptNewProject()"
                    class="w-full justify-center dark:bg-gray-700 dark:hover:bg-gray-600">
                    + Progetto
                </x-primary-button>
            </div>

            <div class="hidden lg:flex items-center gap-2">
                <x-secondary-button type="button"
                    x-on:click="$store.chat.promptNewFolder()"
                    class="dark:bg-gray-800 dark:text-gray-100 dark:border-gray-600 hover:dark:bg-gray-700">
                    + Cartella
                </x-secondary-button>

                <x-primary-button type="button"
                    x-on:click="$store.chat.promptNewProject()"
                    class="dark:bg-gray-700 dark:hover:bg-gray-600">
                    + Progetto
                </x-primary-button>
            </div>
        </div>
    </div>

    {{-- LISTA --}}
    <div id="sidebar-scroll"
         class="grow h-0 min-h-0 overflow-y-auto p-2 space-y-3 scrollbar-none"
         style="-webkit-overflow-scrolling: touch;">

        {{-- Progetti senza cartella --}}
        <template x-if="($store.chat.projectsNoFolder && $store.chat.projectsNoFolder.length)">
            <div>
                <div class="px-3 py-2 text-xs uppercase text-gray-400 dark:text-gray-500">Senza cartella</div>

                <template x-for="p in $store.chat.filteredNoFolder()" :key="p.id">
                    <div class="group flex items-center justify-between px-3 py-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                        <button
                            type="button"
                            @click="$store.chat.openProjectTab(p)"
                            class="flex-1 text-left text-sm text-gray-800 dark:text-gray-100 truncate pr-2">
                            <span x-text="p.path"></span>
                        </button>

                        {{-- X delete progetto --}}
                        <button
                            type="button"
                            @click.prevent.stop="$store.chat.deleteProject(p.id, p.path)"
                            class="opacity-100 md:opacity-0 md:group-hover:opacity-100 inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-red-600"
                            title="Elimina progetto"
                            aria-label="Elimina progetto">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 8.586l3.536-3.536 1.414 1.414L11.414 10l3.536 3.536-1.414 1.414L10 11.414l-3.536 3.536-1.414-1.414L8.586 10 5.05 6.464l1.414-1.414L10 8.586z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </div>
                </template>
            </div>
        </template>

        {{-- Albero cartelle/progetti --}}
        <x-chat.project-tree :nodes="$folders ?? []" />
    </div>
</aside>

{{-- Helper JS per Alpine (niente backtick) --}}
<script>
window.sidebarActions = window.sidebarActions || function (csrfToken, uncapped) {
    return {
        uncapped: !!uncapped,

        // ---- MAX TOKENS (UI + storage + fetch header) ----
        storageKey: function(name){
            try {
                var ns = (this.$store && this.$store.chat && this.$store.chat._ns) ? this.$store.chat._ns : 'ragmia:';
                return ns + name;
            } catch(e) { return 'ragmia:' + name; }
        },
        maxCap: function() {
            return this.uncapped ? 120000 : 4000;
        },
        initMaxTokens: function(){
            var key = this.storageKey('max_tokens');
            var v = localStorage.getItem(key);
            var cap = this.maxCap();
            var num = v ? parseInt(v, 10) : cap;
            if (!num || num < 1) num = cap;
            if (num > cap) num = cap;
            if (!this.$store.chat) return;
            this.$store.chat.maxTokensDesired = num;
            if (this.$store.chat.persistTabs) this.$store.chat.persistTabs();
        },
        saveMaxTokens: function(){
            if (!this.$store.chat) return;
            var cap = this.maxCap();
            var v = parseInt(this.$store.chat.maxTokensDesired || 0, 10);
            if (!v || v < 1) v = 1;
            if (v > cap) v = cap;
            this.$store.chat.maxTokensDesired = v;
            localStorage.setItem(this.storageKey('max_tokens'), String(v));
            if (this.$store.chat.persistTabs) this.$store.chat.persistTabs();
        },
        patchFetchForMaxTokens: function(){
            try {
                if (window.__ragmia_fetch_patched) return;
                var orig = window.fetch;
                var self = this;
                window.fetch = function(input, init) {
                    try {
                        var url = (typeof input === 'string') ? input : (input && input.url) ? input.url : '';
                        var isSend = url.indexOf('/send') !== -1; // Route::post('/send'...)
                        if (isSend) {
                            init = init || {};
                            init.headers = init.headers || {};
                            // valore attuale (già clampato)
                            var v = (self.$store && self.$store.chat && self.$store.chat.maxTokensDesired) ? self.$store.chat.maxTokensDesired : self.maxCap();
                            // imposta header X-Max-Tokens: sarà letto lato server
                            if (init.headers instanceof Headers) {
                                init.headers.set('X-Max-Tokens', String(v));
                            } else {
                                init.headers['X-Max-Tokens'] = String(v);
                            }
                        }
                    } catch(e) {}
                    return orig.apply(this, arguments);
                };
                window.__ragmia_fetch_patched = true;
            } catch(e) {}
        },

        // ---- TREE helpers ----
        async refreshTree() {
            try {
                const res = await fetch('/api/projects', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                const json = await res.json();
                const tree = (json && json.tree) ? json.tree : json;
                if (tree && tree.folders && tree.projectsNoFolder) {
                    this.$store.chat.folders = tree.folders;
                    this.$store.chat.projectsNoFolder = tree.projectsNoFolder;
                    if (this.$store.chat && this.$store.chat.persistTabs) this.$store.chat.persistTabs();
                } else {
                    console.warn('Formato tree inatteso', json);
                }
            } catch (e) {
                console.error('refreshTree error', e);
            }
        },

        async deleteProject(id, name) {
            if (!confirm('Eliminare il progetto "' + name + '"? Verranno rimossi anche i messaggi.')) return;
            try {
                const res = await fetch('/api/projects/' + id, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                var json = null; try { json = await res.json(); } catch(e) {}
                if (!res.ok || !(json && json.ok)) throw new Error((json && json.error) ? json.error : ('Errore ' + res.status + ' ' + res.statusText));
                await this.refreshTree();
            } catch (e) {
                alert((e && e.message) ? e.message : 'Errore eliminazione progetto');
            }
        },

        async deleteFolder(id, name) {
            if (!confirm('Eliminare la cartella "' + name + '" con TUTTO il contenuto?')) return;
            try {
                const res = await fetch('/api/folders/' + id, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                var json = null; try { json = await res.json(); } catch(e) {}
                if (!res.ok || !(json && json.ok)) throw new Error((json && json.error) ? json.error : ('Errore ' + res.status + ' ' + res.statusText));
                await this.refreshTree();
            } catch (e) {
                alert((e && e.message) ? e.message : 'Errore eliminazione cartella');
            }
        }
    };
};
</script>
