<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between" x-data="{}">
            <div class="flex items-center gap-3">
                {{-- Hamburger SOLO mobile --}}
                <button
                    class="lg:hidden inline-flex items-center justify-center p-2 rounded-md
                        text-gray-600 hover:text-gray-900 hover:bg-gray-100
                        dark:text-gray-300 dark:hover:text-gray-100 dark:hover:bg-gray-800"
                    @click="$store.ui.sidebarOpen = true"
                    aria-label="Apri menu progetti">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" stroke="currentColor" fill="none">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
                    RAG Chatbot
                </h2>
            </div>

            {{-- Controlli visibili SOLO desktop --}}
            <div class="hidden lg:flex items-center gap-3">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" x-model="$store.chat.autoContext"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                    Auto-contesto
                </label>
                <x-select x-model="$store.chat.model"
                        class="!py-1 !text-sm w-36 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-100">
                    <option value="gpt-5">GPT-5</option>
                </x-select>
            </div>
        </div>
    </x-slot>


    {{-- x-data contiene anche lo stato della sidebar --}}
    <div x-data="{ sidebarOpen: false }"
         x-init="$store.chat.init({ folders: @js($folders ?? []), projectsNoFolder: @js($projectsNoFolder ?? []) })"
         class="flex-1 min-h-0 bg-gray-50 dark:bg-gray-900">

        <div class="h-full min-h-0 grid grid-cols-1 lg:grid-cols-[20rem,1fr]">
            {{-- ===== Sidebar ===== --}}

            {{-- Overlay mobile (click per chiudere) --}}
            <div x-show="$store.ui.sidebarOpen" x-transition.opacity
                @click="$store.ui.sidebarOpen = false"
                @keydown.window.escape="$store.ui.sidebarOpen = false"
                class="fixed inset-0 bg-black/40 z-40 lg:hidden" aria-hidden="true">
            </div>



            {{-- Drawer mobile + sidebar desktop --}}
            <div class="fixed z-50 inset-y-0 left-0 w-4/5 max-w-xs transform transition-transform duration-200 ease-in-out
            lg:static lg:translate-x-0 lg:w-auto lg:max-w-none"
            :class="$store.ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
                <x-chat.sidebar class="h-full border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800" />
            </div>



            {{-- ===== Main ===== --}}
            <div class="h-full min-h-0 flex flex-col bg-white dark:bg-gray-900">
                <x-chat.tabs class="shrink-0 border-b border-gray-200 dark:border-gray-700" />
                <x-chat.messages class="flex-1 min-h-0" />
                <x-chat.composer class="shrink-0 border-t border-gray-200 dark:border-gray-700 dark:bg-gray-800" />
            </div>
        </div>
    </div>

    {{-- Se NON già incluso nel layout --}}
    <script type="module" src="{{ asset('resources/js/app.js') }}"></script>
</x-app-layout>
