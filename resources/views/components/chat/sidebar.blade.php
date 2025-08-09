@props(['class' => ''])
<aside {{ $attributes->merge(['class' => "bg-white dark:bg-gray-800 $class"]) }}>
    <div class="h-full flex flex-col">
        <div class="shrink-0 p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">Progetti</div>

            {{-- Impostazioni SOLO mobile --}}
            <div class="lg:hidden mt-3 space-y-3">
                <label class="flex items-center justify-between text-sm text-gray-700 dark:text-gray-300">
                    <span>Auto-contesto</span>
                    <input type="checkbox" x-model="$store.chat.autoContext"
                        class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                </label>

                <div>
                    <div class="text-xs mb-1 text-gray-500 dark:text-gray-400">Modello</div>
                    <x-select x-model="$store.chat.model"
                            class="w-full dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100">
                        <option value="gpt-5">GPT-5</option>
                    </x-select>
                </div>

                <div class="border-t border-gray-200 dark:border-gray-700"></div>
            </div>

            {{-- Search --}}
            <div class="mt-3 flex items-center gap-2">
                <input x-model="$store.chat.search"
                    type="text" placeholder="Cerca..."
                    class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100
                            focus:ring-0 focus:border-gray-400 dark:focus:border-gray-500 text-sm">
            </div>

            {{-- Toolbar: su mobile griglia 2 colonne, su desktop inline --}}
            <div class="mt-3">
                {{-- Mobile / tablet: griglia, niente overflow --}}
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

                {{-- Desktop: affiancati con gap, no wrap inutile --}}
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


        <div class="flex-1 min-h-0 overflow-y-auto p-2 space-y-3">
            {{-- Senza cartella --}}
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

            {{-- Albero cartelle/progetti --}}
            <x-chat.project-tree :nodes="$folders ?? []" />
        </div>
    </div>
</aside>
