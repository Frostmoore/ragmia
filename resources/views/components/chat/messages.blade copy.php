@props(['class' => ''])

<section {{ $attributes->merge(['class' => "flex-1 min-h-0 $class"]) }}>
  <div
    class="h-full min-h-0 overflow-y-auto overflow-x-hidden
           lg:scrollbar-thin lg:scrollbar-thumb-gray-400 lg:scrollbar-track-gray-200
           scrollbar-hide lg:scrollbar-default"
    x-data="{
      prevTab: null,
      prevLen: 0,
      toBottom() {
        // scrolla questo stesso elemento (il container scrollabile)
        const el = this.$el;
        el.scrollTop = el.scrollHeight;
      }
    }"
    x-init="
      // primo render → giù in fondo
      prevTab = Alpine.store('chat').activeTabId;
      prevLen = Alpine.store('chat').activeTab()?.messages?.length || 0;
      $nextTick(() => requestAnimationFrame(() => toBottom()));
    "
    x-effect="
      // se cambia tab o cambia la lunghezza dei messaggi → giù in fondo
      (() => {
        const t = Alpine.store('chat').activeTabId;
        const len = Alpine.store('chat').activeTab()?.messages?.length || 0;
        if (t !== prevTab || len !== prevLen) {
          prevTab = t; prevLen = len;
          $nextTick(() => requestAnimationFrame(() => toBottom()));
        }
      })()
    "
  >
    <div id="chat-messages" class="max-w-4xl mx-auto p-4 space-y-4">
      <template x-if="!$store.chat.activeTab() || $store.chat.activeTab().messages.length === 0">
        <div class="text-center text-gray-500 dark:text-gray-400 text-sm mt-10">
          Seleziona un progetto a sinistra o creane uno nuovo.
        </div>
      </template>

      <template x-for="(m, i) in $store.chat.activeTab()?.messages || []" :key="i">
        <div :class="m.role === 'user' ? 'flex justify-end' 
                    : m.role === 'debug' ? 'flex justify-center'
                    : 'flex justify-start'">
            <div class="max-w-[80%] flex flex-col gap-1.5">

            <!-- DEBUG BUBBLE -->
            <template x-if="m.role === 'debug'">
                <div class="px-3 py-2 rounded-lg text-xs whitespace-pre-wrap break-words bg-yellow-100 text-yellow-900 border border-yellow-300 relative">
                <button type="button" 
                        class="absolute top-1 right-1 text-[10px] px-1 py-0.5 rounded bg-yellow-200 hover:bg-yellow-300"
                        @click="$store.chat.removeMessage(i)">
                    ✕
                </button>
                <strong x-text="m.debugTitle"></strong>
                <div class="mt-1" x-text="m.content"></div>
                </div>
            </template>

            <!-- NORMAL SEGMENTS -->
            <!-- NORMAL SEGMENTS -->
            <template x-if="m.role !== 'debug'">
                <template x-for="(seg, sidx) in $store.chat.parseSegments(m.content)" :key="i+'-'+sidx">
                    <div class="w-full">

                    <!-- TEXT -->
                    <div
                        x-show="seg.type !== 'code'"
                        class="px-3 py-2 rounded-2xl text-sm whitespace-pre-wrap break-words"
                        :class="m.role === 'user'
                        ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                        : 'bg-white border border-gray-200 text-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100'"
                        x-text="seg.content">
                    </div>

                    <!-- CANVAS CODE (```canvas[:lang]) -->
                    <template x-if="seg.type === 'code' && (seg.lang || '').startsWith('canvas')">
                    <div
                        class="relative rounded-2xl overflow-hidden ring-1 ring-gray-200 dark:ring-gray-700
                            bg-gray-50/70 dark:bg-gray-900/70 backdrop-blur">
                        <!-- Header -->
                        <div class="flex items-center justify-between px-3 py-2 text-xs
                                    bg-gray-100/80 dark:bg-gray-800/70 border-b border-gray-200 dark:border-gray-700">
                        <div class="font-mono tracking-wide text-gray-700 dark:text-gray-200">
                            <span class="opacity-70">Canvas</span>
                            <span class="mx-1">·</span>
                            <span x-text="((seg.lang||'').split(':')[1] || 'plaintext').toUpperCase()"></span>
                        </div>
                        <div class="flex gap-2">
                            <button type="button"
                                    class="px-2 py-0.5 text-[11px] rounded-md
                                        bg-gray-200 text-gray-800 hover:bg-gray-300
                                        dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600"
                                    @click="$store.chat.copyToClipboard(seg.content)">
                            Copia
                            </button>
                            <button type="button"
                                    class="px-2 py-0.5 text-[11px] rounded-md
                                        bg-indigo-600 text-white hover:bg-indigo-700"
                                    @click="$store.chat.openCanvas ? $store.chat.openCanvas(seg.content, (seg.lang||'').split(':')[1] || 'plaintext') : $store.chat.copyToClipboard(seg.content)">
                            Apri in canvas
                            </button>
                        </div>
                        </div>
                        <!-- Body -->
                        <pre class="m-0 p-3 overflow-x-auto">
                        <code :class="'hljs block font-mono text-[13px] leading-snug whitespace-pre language-'+(((seg.lang||'').split(':')[1]) || 'plaintext')"
                                x-text="seg.content"></code>
                        </pre>
                    </div>
                    </template>

                    <!-- NORMAL CODE (non-canvas) -->
                    <template x-if="seg.type === 'code' && !((seg.lang || '').startsWith('canvas'))">
                    <div
                        class="relative rounded-2xl text-sm overflow-hidden p-3"
                        :class="m.role === 'user'
                        ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                        : 'bg-white border border-gray-200 text-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100'">
                        <button type="button"
                                class="absolute top-2 right-2 px-2 py-0.5 text-[11px] rounded-md
                                    bg-gray-200 text-gray-800 hover:bg-gray-300
                                    dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600"
                                @click="$store.chat.copyToClipboard(seg.content)">
                        Copia
                        </button>
                        <pre class="m-0 p-0 overflow-x-auto">
                        <code :class="'hljs block font-mono text-[13px] leading-snug whitespace-pre language-'+(seg.lang || 'plaintext')"
                                x-text="seg.content"></code>
                        </pre>
                    </div>
                    </template>


                    <!-- NORMAL CODE -->
                    <template x-if="seg.type === 'code' && ( (seg.lang||'').startsWith('canvas') || (seg.meta && seg.meta.canvas) )">

                        <div
                        class="relative rounded-2xl text-sm overflow-hidden p-3"
                        :class="m.role === 'user'
                            ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                            : 'bg-white border border-gray-200 text-gray-900 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100'">
                        <button type="button"
                            class="absolute top-2 right-2 px-2 py-0.5 text-[11px] rounded-md
                                    bg-gray-200 text-gray-800 hover:bg-gray-300
                                    dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600"
                            @click="$store.chat.copyToClipboard(seg.content)">
                            Copia
                        </button>
                            <pre class="m-0 p-0 overflow-x-auto">
                                <code :class="'hljs block font-mono text-[13px] leading-snug whitespace-pre language-'+(seg.lang || 'plaintext')"
                                    x-text="seg.content"></code>
                            </pre>
                        </div>
                    </template>

                    </div>
                </template>
            </template>

            </div>
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
