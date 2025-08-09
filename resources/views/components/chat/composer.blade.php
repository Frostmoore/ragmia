<footer {{ $attributes->merge(['class' => "shrink-0 border-t border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"]) }}>
    <div class="max-w-4xl mx-auto p-3">
        <form @submit.prevent="$store.chat.sendMessage()" class="space-y-2">
            <textarea
                x-model="$store.chat.composer.text"
                rows="4"
                placeholder="Scrivi qui (Invio = invia, Shift+Invio = nuova riga)"
                @keydown.enter.prevent="if(!$event.shiftKey){ $store.chat.sendMessage(); }"
                class="w-full rounded-lg border-gray-300 focus:ring-0 focus:border-gray-400 text-sm
                       bg-white text-gray-900 dark:bg-gray-900 dark:text-gray-100 dark:border-gray-700
                       placeholder-gray-400 dark:placeholder-gray-500"
            ></textarea>
            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <div>
                    Token usati: <span class="font-semibold" x-text="$store.chat.monthTokens || 0"></span> |
                    Spesa: $<span class="font-semibold" x-text="($store.chat.monthCost || 0).toFixed(2)"></span>
                </div>
                <x-primary-button type="submit">
                    Invia
                </x-primary-button>
            </div>

        </form>
    </div>
</footer>
