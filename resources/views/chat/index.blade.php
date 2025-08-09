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
                    RAG Chatbot
                </h2>
            </div>

            {{-- Controlli (SOLO desktop) --}}
            {{-- <div class="hidden lg:flex items-center gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox"
                           x-bind:checked="$store.chat.autoContext"
                           x-on:change="$store.chat.setAutoContext($event.target.checked)"
                           class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                    Auto-contesto
                </label>

                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Modello</span>
                    <x-select
                        x-bind:value="$store.chat.model"
                        x-on:change="$store.chat.setModel($event.target.value)"
                        class="!py-1 !text-sm w-56 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-100">
                        <option value="openai:gpt-5">OpenAI — GPT-5</option>
                        <option value="anthropic:claude-3-5-haiku">Anthropic — Claude 3.5 Haiku</option>
                        <option value="google:gemini-1.5-flash">Google — Gemini 1.5 Flash</option>
                        <option value="openai:gpt-4o-mini">OpenAI — GPT-4o mini</option>
                    </x-select>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Compressore</span>
                    <x-select
                        x-bind:value="$store.chat.compress_model"
                        x-on:change="$store.chat.setCompressModel($event.target.value)"
                        class="!py-1 !text-sm w-60 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-100">
                        <option value="openai:gpt-4o-mini">OpenAI — GPT-4o mini</option>
                        <option value="anthropic:claude-3-5-haiku">Anthropic — Claude 3.5 Haiku</option>
                        <option value="google:gemini-1.5-flash">Google — Gemini 1.5 Flash</option>
                    </x-select>
                </div>
            </div> --}}
        </div>
    </x-slot>

    {{-- Inizializza store chat; sidebar nello store $store.ui --}}
    <div x-data
         x-init="$store.chat.init({ folders: @js($folders ?? []), projectsNoFolder: @js($projectsNoFolder ?? []) })"
         class="flex-1 min-h-0 bg-gray-50 dark:bg-gray-900">

        {{-- Overlay mobile --}}
        {{-- Overlay mobile (solo quando aperta) --}}
        {{-- Overlay mobile --}}
        <div
            x-show="$store.ui.sidebarOpen"
            x-transition.opacity
            @click="
                console.log('[overlay] click -> close');
                $store.ui.sidebarOpen = false
            "
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

            {{-- MOBILE: drawer fisso sopra (fuori dal flow), con log --}}
            <div
            class="lg:hidden fixed z-50 inset-y-0 left-0 w-4/5 max-w-xs bg-white dark:bg-gray-800
                    transform transition-transform duration-200 ease-in-out"
            x-init="console.log('[sidebar-mobile] mounted')"
            x-effect="console.log('[sidebar-mobile] open =', $store.ui.sidebarOpen)"
            :style="$store.ui.sidebarOpen ? 'transform: translateX(0);' : 'transform: translateX(-100%);'">
            <x-chat.sidebar class="h-full border-r border-gray-200 dark:border-gray-700" />
        </div>


    </div>

</x-app-layout>
