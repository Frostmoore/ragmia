@props(['class' => ''])
<nav {{ $attributes->merge(['class' => "shrink-0 h-11 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-2 flex items-center overflow-x-auto gap-2"]) }}>
    <template x-for="(tab, idx) in $store.chat.tabs" :key="tab.id">
        <div @click="$store.chat.activateTab(tab.id)"
             :class="[
                'group flex items-center gap-2 px-3 py-1.5 rounded-full cursor-pointer whitespace-nowrap border transition-colors',
                $store.chat.activeTabId === tab.id
                    ? 'bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100'
                    : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 border-transparent text-gray-700 dark:text-gray-300'
             ]">
            <span class="text-sm" x-text="tab.title"></span>
            <button @click.stop="$store.chat.closeTab(tab.id)"
                    class="hidden group-hover:inline-block text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">&times;</button>
        </div>
    </template>
</nav>
