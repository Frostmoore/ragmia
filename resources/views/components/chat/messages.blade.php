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
        <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
          <div class="max-w-[80%] flex flex-col gap-1.5">
            <!-- Segmenti -->
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

                <!-- CODE -->
                <div
                  x-show="seg.type === 'code'"
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
              </div>
            </template>
            <!-- /Segmenti -->
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
