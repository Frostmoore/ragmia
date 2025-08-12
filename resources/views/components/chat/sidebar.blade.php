@props(['class' => ''])

<aside {{ $attributes->merge(['class' => "bg-white dark:bg-gray-800 h-full min-h-0 flex flex-col $class"]) }}>
    {{-- HEADER (fisso) --}}
    <div class="shrink-0 p-4 border-b border-gray-200 dark:border-gray-700">
        <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">Progetti</div>

        {{-- Impostazioni SOLO mobile --}}
        <div class="mt-3 space-y-3">
            <label class="flex items-center justify-between text-sm text-gray-700 dark:text-gray-300">
                <span>Auto-contesto</span>
                <input type="checkbox"
                       x-model="$store.chat.autoContext"
                       x-on:change="$store.chat.persistTabs()"
                       class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
            </label>

            <div>
                <div class="text-xs mb-1 text-gray-500 dark:text-gray-400">Modello</div>
                <x-select
                    x-model="$store.chat.model"
                    x-on:change="$store.chat.persistTabs()"
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
                    x-on:change="$store.chat.persistTabs()"
                    class="w-full dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100">
                    <option value="openai:gpt-4o-mini">OpenAI — GPT-4o mini</option>
                    <option value="anthropic:claude-3-5-haiku">Anthropic — Claude 3.5 Haiku</option>
                    <option value="google:gemini-1.5-flash">Google — Gemini 1.5 Flash</option>
                </x-select>
            </div>

            {{-- Usa compressore (se OFF invia il prompt raw) --}}
            <label class="mt-2 flex items-center justify-between text-sm text-gray-700 dark:text-gray-300">
                <span>Usa compressore</span>
                <input type="checkbox"
                       x-model="$store.chat.useCompressor"
                       x-on:change="$store.chat.persistTabs()"
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

    {{-- LISTA (scrollabile con scrollbar nascosta) --}}
    <div id="sidebar-scroll"
         class="grow h-0 min-h-0 overflow-y-auto p-2 space-y-3 scrollbar-none"
         style="-webkit-overflow-scrolling: touch;">
        <template x-if="$store.chat.projectsNoFolder.length">
            <div>
                <div class="px-3 py-2 text-xs uppercase text-gray-400 dark:text-gray-500">Senza cartella</div>
                <template x-for="p in $store.chat.filteredNoFolder()" :key="p.id">
                    <button @click="$store.chat.openProjectTab(p)"
                            class="w-full text-left px-5 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700
                                   text-sm text-gray-800 dark:text-gray-100">
                        <span x-text="p.path"></span>
                    </button>
                </template>
            </div>
        </template>

        <x-chat.project-tree :nodes="$folders ?? []" />
    </div>
</aside>
