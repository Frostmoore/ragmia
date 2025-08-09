@props(['class' => ''])

<section {{ $attributes->merge(['class' => "flex-1 min-h-0 $class"]) }}>
    <div class="h-full min-h-0 overflow-y-auto lg:scrollbar-thin lg:scrollbar-thumb-gray-400 lg:scrollbar-track-gray-200 scrollbar-hide lg:scrollbar-default">

        <div class="max-w-4xl mx-auto p-4 space-y-4">

            <template x-if="!$store.chat.activeTab() || $store.chat.activeTab().messages.length === 0">
                <div class="text-center text-gray-500 dark:text-gray-400 text-sm mt-10">
                    Seleziona un progetto a sinistra o creane uno nuovo.
                </div>
            </template>

            <template x-for="(m, i) in $store.chat.activeTab()?.messages || []" :key="i">
                <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">

                    {{-- BOLLA PER TESTO NORMALE (wrap) --}}
                    <template x-if="!$store.chat.isCode(m.content)">
                        <div :class="[
                                'max-w-[80%] px-4 py-2 rounded-2xl shadow text-sm break-words whitespace-pre-wrap',
                                m.role === 'user'
                                    ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                                    : 'bg-white border border-gray-200 text-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100'
                            ]"
                            x-text="m.content">
                        </div>
                    </template>

                    {{-- BOLLA PER BLOCCO DI CODICE (scroll X dentro) --}}
                    <template x-if="$store.chat.isCode(m.content)">
                        <div :class="[
                                'max-w-[80%] rounded-2xl shadow text-sm',
                                m.role === 'user'
                                    ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                                    : 'bg-white border border-gray-200 text-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100'
                            ]"
                            class="overflow-x-auto">
                            <pre class="px-4 py-2 font-mono whitespace-pre min-w-max"
                                 x-text="$store.chat.stripFences(m.content)"></pre>
                        </div>
                    </template>

                </div>
            </template>

            <div x-show="$store.chat.thinking" class="flex justify-start">
                <div class="max-w-[80%] px-4 py-2 rounded-2xl shadow bg-white border border-gray-200 text-sm
                            text-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100">
                    <span>Sto ragionando</span><span class="inline-block animate-pulse">...</span>
                </div>
            </div>

        </div>
    </div>
</section>
