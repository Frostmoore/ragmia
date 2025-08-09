@props(['nodes' => []])

<template x-for="f in $store.chat.folders" :key="f.id">
    <div class="mb-2">
        {{-- Cartella principale --}}
        <button @click="$store.chat.toggleFolder(f.id)"
                class="w-full flex items-center justify-between px-3 py-2 rounded-lg
                       hover:bg-gray-100 dark:hover:bg-gray-700
                       text-gray-800 dark:text-gray-100">
            <span class="font-medium" x-text="f.name"></span>
            <svg :class="{'rotate-90': $store.chat.isOpen(f.id)}"
                 class="h-4 w-4 transition-transform text-gray-500 dark:text-gray-400"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
            </svg>
        </button>

        <div x-show="$store.chat.isOpen(f.id)" x-collapse>
            {{-- Progetti diretti nella cartella --}}
            <template x-for="p in $store.chat.filteredProjects(f.projects)" :key="p.id">
                <button @click="$store.chat.openProjectTab(p)"
                        class="w-full text-left px-5 py-2 rounded-lg
                               hover:bg-gray-100 dark:hover:bg-gray-700
                               text-sm text-gray-800 dark:text-gray-100">
                    <span x-text="p.path"></span>
                </button>
            </template>

            {{-- Figli --}}
            <div class="ml-3 mt-1 border-l border-gray-100 dark:border-gray-700 pl-2">
                <template x-for="c in f.children" :key="c.id">
                    <div class="mb-2">
                        <button @click="$store.chat.toggleFolder(c.id)"
                                class="w-full flex items-center justify-between px-3 py-2 rounded-lg
                                       hover:bg-gray-100 dark:hover:bg-gray-700
                                       text-gray-800 dark:text-gray-100">
                            <span class="font-medium" x-text="c.name"></span>
                            <svg :class="{'rotate-90': $store.chat.isOpen(c.id)}"
                                 class="h-4 w-4 transition-transform text-gray-500 dark:text-gray-400"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
                            </svg>
                        </button>

                        <div x-show="$store.chat.isOpen(c.id)" x-collapse>
                            {{-- Progetti nel figlio --}}
                            <template x-for="p in $store.chat.filteredProjects(c.projects)" :key="p.id">
                                <button @click="$store.chat.openProjectTab(p)"
                                        class="w-full text-left px-5 py-2 rounded-lg
                                               hover:bg-gray-100 dark:hover:bg-gray-700
                                               text-sm text-gray-800 dark:text-gray-100">
                                    <span x-text="p.path"></span>
                                </button>
                            </template>

                            {{-- Nipoti --}}
                            <div class="ml-3 mt-1 border-l border-gray-100 dark:border-gray-700 pl-2">
                                <template x-for="gc in c.children" :key="gc.id">
                                    <div>
                                        <button @click="$store.chat.toggleFolder(gc.id)"
                                                class="w-full flex items-center justify-between px-3 py-2 rounded-lg
                                                       hover:bg-gray-100 dark:hover:bg-gray-700
                                                       text-gray-800 dark:text-gray-100">
                                            <span class="font-medium" x-text="gc.name"></span>
                                            <svg :class="{'rotate-90': $store.chat.isOpen(gc.id)}"
                                                 class="h-4 w-4 transition-transform text-gray-500 dark:text-gray-400"
                                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
                                            </svg>
                                        </button>

                                        <div x-show="$store.chat.isOpen(gc.id)" x-collapse>
                                            <template x-for="p in $store.chat.filteredProjects(gc.projects)" :key="p.id">
                                                <button @click="$store.chat.openProjectTab(p)"
                                                        class="w-full text-left px-5 py-2 rounded-lg
                                                               hover:bg-gray-100 dark:hover:bg-gray-700
                                                               text-sm text-gray-800 dark:text-gray-100">
                                                    <span x-text="p.path"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
