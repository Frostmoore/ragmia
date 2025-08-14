@props(['nodes' => []])

<template x-for="f in ($store.chat.folders || [])" :key="f.id">
    <div class="mb-2">
        {{-- Cartella principale --}}
        <div class="group flex items-center justify-between px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-100">
            <button
                type="button"
                @click="$store.chat.toggleFolder(f.id)"
                class="flex items-center gap-2 min-w-0"
            >
                <svg :class="{'rotate-90': $store.chat.isOpen(f.id)}"
                     class="h-4 w-4 transition-transform text-gray-500 dark:text-gray-400"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
                </svg>
                <span class="font-medium truncate" x-text="f.name"></span>
            </button>

            {{-- X elimina cartella --}}
            <button
                type="button"
                @click.prevent.stop="$store.chat.deleteFolder(f.id, f.name)"
                class="opacity-100 md:opacity-0 md:group-hover:opacity-100 inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-red-600"
                title="Elimina cartella"
                aria-label="Elimina cartella"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 8.586l3.536-3.536 1.414 1.414L11.414 10l3.536 3.536-1.414 1.414L10 11.414l-3.536 3.536-1.414-1.414L8.586 10 5.05 6.464l1.414-1.414L10 8.586z" clip-rule="evenodd"/>
                </svg>
            </button>
        </div>

        <div x-show="$store.chat.isOpen(f.id)" x-collapse>
            {{-- Progetti diretti nella cartella --}}
            <template x-for="p in $store.chat.filteredProjects(f.projects)" :key="p.id">
                <div class="group flex items-center justify-between px-5 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <button
                        type="button"
                        @click="$store.chat.openProjectTab(p)"
                        class="flex-1 text-left text-sm text-gray-800 dark:text-gray-100 truncate pr-2"
                    >
                        <span x-text="p.path"></span>
                    </button>

                    {{-- X elimina progetto --}}
                    <button
                        type="button"
                        @click.prevent.stop="$store.chat.deleteProject(p.id, p.path)"
                        class="opacity-100 md:opacity-0 md:group-hover:opacity-100 inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-red-600"
                        title="Elimina progetto"
                        aria-label="Elimina progetto"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 8.586l3.536-3.536 1.414 1.414L11.414 10l3.536 3.536-1.414 1.414L10 11.414l-3.536 3.536-1.414-1.414L8.586 10 5.05 6.464l1.414-1.414L10 8.586z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </div>
            </template>

            {{-- Figli --}}
            <div class="ml-3 mt-1 border-l border-gray-100 dark:border-gray-700 pl-2">
                <template x-for="c in f.children" :key="c.id">
                    <div class="mb-2">
                        <div class="group flex items-center justify-between px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-100">
                            <button
                                type="button"
                                @click="$store.chat.toggleFolder(c.id)"
                                class="flex items-center gap-2 min-w-0"
                            >
                                <svg :class="{'rotate-90': $store.chat.isOpen(c.id)}"
                                     class="h-4 w-4 transition-transform text-gray-500 dark:text-gray-400"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
                                </svg>
                                <span class="font-medium truncate" x-text="c.name"></span>
                            </button>

                            {{-- X elimina cartella (figlia) --}}
                            <button
                                type="button"
                                @click.prevent.stop="$store.chat.deleteFolder(c.id, c.name)"
                                class="opacity-100 md:opacity-0 md:group-hover:opacity-100 inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-red-600"
                                title="Elimina cartella"
                                aria-label="Elimina cartella"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 8.586l3.536-3.536 1.414 1.414L11.414 10l3.536 3.536-1.414 1.414L10 11.414l-3.536 3.536-1.414-1.414L8.586 10 5.05 6.464l1.414-1.414L10 8.586z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        </div>

                        <div x-show="$store.chat.isOpen(c.id)" x-collapse>
                            {{-- Progetti nel figlio --}}
                            <template x-for="p in $store.chat.filteredProjects(c.projects)" :key="p.id">
                                <div class="group flex items-center justify-between px-5 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <button
                                        type="button"
                                        @click="$store.chat.openProjectTab(p)"
                                        class="flex-1 text-left text-sm text-gray-800 dark:text-gray-100 truncate pr-2"
                                    >
                                        <span x-text="p.path"></span>
                                    </button>

                                    {{-- X elimina progetto --}}
                                    <button
                                        type="button"
                                        @click.prevent.stop="$store.chat.deleteProject(p.id, p.path)"
                                        class="opacity-100 md:opacity-0 md:group-hover:opacity-100 inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-red-600"
                                        title="Elimina progetto"
                                        aria-label="Elimina progetto"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M10 8.586l3.536-3.536 1.414 1.414L11.414 10l3.536 3.536-1.414 1.414L10 11.414l-3.536 3.536-1.414-1.414L8.586 10 5.05 6.464l1.414-1.414L10 8.586z" clip-rule="evenodd"/>
                                        </svg>
                                    </button>
                                </div>
                            </template>

                            {{-- Nipoti --}}
                            <div class="ml-3 mt-1 border-l border-gray-100 dark:border-gray-700 pl-2">
                                <template x-for="gc in c.children" :key="gc.id">
                                    <div class="mb-2">
                                        <div class="group flex items-center justify-between px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-100">
                                            <button
                                                type="button"
                                                @click="$store.chat.toggleFolder(gc.id)"
                                                class="flex items-center gap-2 min-w-0"
                                            >
                                                <svg :class="{'rotate-90': $store.chat.isOpen(gc.id)}"
                                                     class="h-4 w-4 transition-transform text-gray-500 dark:text-gray-400"
                                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
                                                </svg>
                                                <span class="font-medium truncate" x-text="gc.name"></span>
                                            </button>

                                            {{-- X elimina cartella (nipote) --}}
                                            <button
                                                type="button"
                                                @click.prevent.stop="$store.chat.deleteFolder(gc.id, gc.name)"
                                                class="opacity-100 md:opacity-0 md:group-hover:opacity-100 inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-red-600"
                                                title="Elimina cartella"
                                                aria-label="Elimina cartella"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M10 8.586l3.536-3.536 1.414 1.414L11.414 10l3.536 3.536-1.414 1.414L10 11.414l-3.536 3.536-1.414-1.414L8.586 10 5.05 6.464l1.414-1.414L10 8.586z" clip-rule="evenodd"/>
                                                </svg>
                                            </button>
                                        </div>

                                        <div x-show="$store.chat.isOpen(gc.id)" x-collapse>
                                            <template x-for="p in $store.chat.filteredProjects(gc.projects)" :key="p.id">
                                                <div class="group flex items-center justify-between px-5 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                                    <button
                                                        type="button"
                                                        @click="$store.chat.openProjectTab(p)"
                                                        class="flex-1 text-left text-sm text-gray-800 dark:text-gray-100 truncate pr-2"
                                                    >
                                                        <span x-text="p.path"></span>
                                                    </button>

                                                    {{-- X elimina progetto --}}
                                                    <button
                                                        type="button"
                                                        @click.prevent.stop="$store.chat.deleteProject(p.id, p.path)"
                                                        class="opacity-100 md:opacity-0 md:group-hover:opacity-100 inline-flex items-center justify-center w-5 h-5 rounded text-gray-400 hover:text-red-600"
                                                        title="Elimina progetto"
                                                        aria-label="Elimina progetto"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fill-rule="evenodd" d="M10 8.586l3.536-3.536 1.414 1.414L11.414 10l3.536 3.536-1.414 1.414L10 11.414l-3.536 3.536-1.414-1.414L8.586 10 5.05 6.464l1.414-1.414L10 8.586z" clip-rule="evenodd"/>
                                                        </svg>
                                                    </button>
                                                </div>
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
