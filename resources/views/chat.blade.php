<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>RAG Chat • Progetti & Tabs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    *::-webkit-scrollbar{height:8px;width:8px}
    *::-webkit-scrollbar-thumb{background:#c7c7d1;border-radius:6px}
    *{scrollbar-width:thin;scrollbar-color:#c7c7d1 transparent}
    @keyframes blink { 0%,80%,100%{opacity:0.2} 40%{opacity:1} }
  </style>
</head>
<body class="bg-slate-50 text-slate-800">
<div x-data="chatApp()" x-init="init()" class="h-screen w-screen flex overflow-hidden">

  <!-- Sidebar -->
  <aside class="w-80 shrink-0 bg-white border-r border-slate-200 flex flex-col">
    <div class="p-4 border-b border-slate-200">
      <div class="text-lg font-semibold">Progetti</div>
      <div class="mt-3 flex gap-2">
        <input x-model="search" type="text" placeholder="Cerca..."
               class="flex-1 rounded-lg border-slate-300 focus:ring-0 focus:border-slate-400 text-sm">
        <div class="flex gap-2">
          <button @click="promptNewFolder"
                  class="px-3 py-1.5 text-sm rounded-lg bg-slate-100 hover:bg-slate-200">+ Cartella</button>
          <button @click="promptNewProject"
                  class="px-3 py-1.5 text-sm rounded-lg bg-slate-900 text-white hover:bg-slate-800">+ Progetto</button>
        </div>
      </div>
    </div>

    <div class="flex-1 overflow-auto p-2">
      <!-- Progetti senza cartella -->
      <template x-if="projectsNoFolder.length">
        <div class="mb-3">
          <div class="px-3 py-2 text-xs uppercase text-slate-400">Senza cartella</div>
          <template x-for="p in filteredNoFolder()" :key="p.id">
            <button @click="openProjectTab(p)"
                    class="w-full text-left px-5 py-2 rounded-lg hover:bg-slate-100 text-sm">
              <span x-text="p.path"></span>
            </button>
          </template>
        </div>
      </template>

      <!-- Cartelle (nidificate) -->
      <template x-for="f in folders" :key="f.id">
        <div class="mb-2">
          <button @click="toggleFolder(f.id)"
                  class="w-full flex items-center justify-between px-3 py-2 rounded-lg hover:bg-slate-100">
            <span class="font-medium" x-text="f.name"></span>
            <svg :class="{'rotate-90': isOpen(f.id)}" class="h-4 w-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
            </svg>
          </button>
          <div x-show="isOpen(f.id)" x-collapse>
            <template x-for="p in filteredProjects(f.projects)" :key="p.id">
              <button @click="openProjectTab(p)"
                      class="w-full text-left px-5 py-2 rounded-lg hover:bg-slate-100 text-sm">
                <span x-text="p.path"></span>
              </button>
            </template>
            <template x-for="c in f.children" :key="c.id">
              <div class="ml-3 mt-1 border-l border-slate-100 pl-2">
                <button @click="toggleFolder(c.id)"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg hover:bg-slate-100">
                  <span class="font-medium" x-text="c.name"></span>
                  <svg :class="{'rotate-90': isOpen(c.id)}" class="h-4 w-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
                  </svg>
                </button>
                <div x-show="isOpen(c.id)" x-collapse>
                  <template x-for="p in filteredProjects(c.projects)" :key="p.id">
                    <button @click="openProjectTab(p)"
                            class="w-full text-left px-5 py-2 rounded-lg hover:bg-slate-100 text-sm">
                      <span x-text="p.path"></span>
                    </button>
                  </template>
                  <template x-for="gc in c.children" :key="gc.id">
                    <div class="ml-3 mt-1 border-l border-slate-100 pl-2">
                      <button @click="toggleFolder(gc.id)"
                              class="w-full flex items-center justify-between px-3 py-2 rounded-lg hover:bg-slate-100">
                        <span class="font-medium" x-text="gc.name"></span>
                        <svg :class="{'rotate-90': isOpen(gc.id)}" class="h-4 w-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
                        </svg>
                      </button>
                      <div x-show="isOpen(gc.id)" x-collapse>
                        <template x-for="p in filteredProjects(gc.projects)" :key="p.id">
                          <button @click="openProjectTab(p)"
                                  class="w-full text-left px-5 py-2 rounded-lg hover:bg-slate-100 text-sm">
                            <span x-text="p.path"></span>
                          </button>
                        </template>
                      </div>
                    </div>
                  </template>
                </div>
              </div>
            </template>
          </div>
        </div>
      </template>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 flex flex-col">
    <!-- Topbar -->
    <header class="h-14 border-b border-slate-200 bg-white px-4 flex items-center justify-between">
      <div class="font-semibold">RAG Chatbot</div>
      <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" x-model="autoContext" class="rounded border-slate-300">
          Auto-contesto
        </label>
        <select x-model="model" class="text-sm rounded-lg border-slate-300 focus:ring-0 focus:border-slate-400">
          <option value="gpt-5">GPT-5</option>
        </select>
      </div>
    </header>

    <!-- Tabs -->
    <nav class="h-11 bg-slate-100 border-b border-slate-200 px-2 flex items-center overflow-x-auto gap-2">
      <template x-for="(tab, idx) in tabs" :key="tab.id">
        <div @click="activateTab(tab.id)"
             :class="['group flex items-center gap-2 px-3 py-1.5 rounded-full cursor-pointer whitespace-nowrap',
                      activeTabId === tab.id ? 'bg-white border border-slate-300' : 'bg-slate-200 hover:bg-slate-300']">
          <span class="text-sm" x-text="tab.title"></span>
          <button @click.stop="closeTab(tab.id)"
                  class="hidden group-hover:inline-block text-slate-500 hover:text-slate-900">&times;</button>
        </div>
      </template>
    </nav>

    <!-- Chat area -->
    <section class="flex-1 overflow-auto">
      <div class="max-w-4xl mx-auto p-4 space-y-4" id="messages">
        <template x-if="!activeTab() || activeTab().messages.length === 0">
          <div class="text-center text-slate-500 text-sm mt-10">
            Seleziona un progetto a sinistra o creane uno nuovo.
          </div>
        </template>

        <template x-for="(m, i) in activeTab()?.messages || []" :key="i">
          <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
            <div :class="['max-w-[80%] px-4 py-2 rounded-2xl shadow',
                          m.role === 'user' ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200']">
              <pre class="whitespace-pre-wrap text-sm" x-text="m.content"></pre>
            </div>
          </div>
        </template>

        <!-- Typing inline -->
        <div x-show="thinking" class="flex justify-start">
          <div class="max-w-[80%] px-4 py-2 rounded-2xl shadow bg-white border border-slate-200 text-sm">
            <span>Sto ragionando</span>
            <span class="inline-block animate-[blink_1.4s_infinite_.0s]">.</span>
            <span class="inline-block animate-[blink_1.4s_infinite_.2s]">.</span>
            <span class="inline-block animate-[blink_1.4s_infinite_.4s]">.</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Composer -->
    <footer class="border-t border-slate-200 bg-white">
      <div class="max-w-4xl mx-auto p-3">
        <form @submit.prevent="sendMessage" class="space-y-2">
          <textarea x-model="composer.text" rows="4"
                    placeholder="Scrivi qui (Invio = invia, Shift+Invio = nuova riga)"
                    @keydown.enter.prevent="if(!$event.shiftKey){ sendMessage(); }"
                    class="w-full rounded-lg border-slate-300 focus:ring-0 focus:border-slate-400 text-sm"></textarea>
          <div class="flex items-center justify-between">
            <div class="text-xs text-slate-500">
              Tab attiva: <span class="font-semibold" x-text="activeTab()?.path || '—'"></span>
            </div>
            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800">
              Invia
            </button>
          </div>
        </form>
      </div>
    </footer>

  </main>
</div>

<script>
function chatApp(){
  return {
    // server data
    folders: @json($folders ?? []),
    projectsNoFolder: @json($projectsNoFolder ?? []),

    // ui state
    search: '',
    openFolderIds: {},
    tabs: [],
    activeTabId: null,
    autoContext: true,
    model: 'gpt-5',
    composer: { text: '' },
    thinking: false,

    async init(){
      (this.folders || []).forEach(f => this.openFolderIds[f.id] = true);
      const saved = localStorage.getItem('chatTabsV3');
      if (saved) {
        try {
          const {tabs, active} = JSON.parse(saved);
          this.tabs = tabs || [];
          this.activeTabId = active || (this.tabs[0]?.id ?? null);
        } catch {}
      }
    },

    // sidebar helpers
    isOpen(id){ return !!this.openFolderIds[id]; },
    toggleFolder(id){ this.openFolderIds[id] = !this.openFolderIds[id]; },

    filteredNoFolder(){
      const q = this.search.trim().toLowerCase();
      if (!q) return this.projectsNoFolder;
      return this.projectsNoFolder.filter(p => p.path.toLowerCase().includes(q));
    },
    filteredProjects(arr){
      const q = this.search.trim().toLowerCase();
      if (!q) return arr;
      return arr.filter(p => p.path.toLowerCase().includes(q));
    },

    // tabs
    persistTabs(){ localStorage.setItem('chatTabsV3', JSON.stringify({tabs:this.tabs, active:this.activeTabId})); },
    activeTab(){ return this.tabs.find(t => t.id === this.activeTabId) || null; },
    activateTab(id){ this.activeTabId = id; this.persistTabs(); },
    closeTab(id){
      const i = this.tabs.findIndex(t => t.id === id);
      if (i === -1) return;
      const was = this.activeTabId === id;
      this.tabs.splice(i,1);
      if (was) this.activeTabId = this.tabs[0]?.id ?? null;
      this.persistTabs();
    },

    async openProjectTab(project){
      const existing = this.tabs.find(t => t.project_id === project.id);
      if (existing) { this.activateTab(existing.id); return; }

      const id = crypto.randomUUID();
      const tab = { id, title: project.path.split('/').pop(), path: project.path, project_id: project.id, messages: [] };
      this.tabs.push(tab);
      this.activeTabId = id;
      this.persistTabs();

      // carica storico messaggi
      try {
        const res = await fetch(`{{ route('messages.list') }}?project_id=${project.id}`);
        const data = await res.json();
        (data.messages || []).forEach(m => tab.messages.push({role:m.role, content:m.content}));
        this.persistTabs();
      } catch (e) {
        tab.messages.push({role:'assistant', content:'Errore caricando messaggi: '+e.message});
      }
    },

    // crea cose
    promptNewProject(){
      const path = prompt('Path progetto (es. Consorzio/ScuoleGuida oppure SoloNome):');
      if (!path) return;
      this.createProject(path);
    },
    async createProject(path){
      const res = await fetch(`{{ route('projects.create') }}`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({ path })
      });
      const data = await res.json();
      if (!res.ok) { alert('Errore creazione progetto: ' + (data.error || res.status)); return; }

      // reload albero e apri tab
      await this.reloadTree();
      // trova il progetto appena creato
      const proj = this.findProjectInTree(data.project?.path);
      if (proj) this.openProjectTab(proj);
    },

    promptNewFolder(){
      const path = prompt('Path cartella (es. Consorzio o Consorzio/Sub):');
      if (!path) return;
      this.createFolder(path);
    },
    async createFolder(path){
      const res = await fetch(`{{ route('folders.create') }}`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({ path })
      });
      const data = await res.json();
      if (!res.ok) { alert('Errore creazione cartella: ' + (data.error || res.status)); return; }
      // ricarica struttura
      this.folders = data.tree.folders || [];
      this.projectsNoFolder = data.tree.projectsNoFolder || [];
      // apri automaticamente la cartella appena creata
      if (data.folder?.id) this.openFolderIds[data.folder.id] = true;
    },

    async reloadTree(){
      const treeRes = await fetch(`{{ route('projects.list') }}`);
      const tree = await treeRes.json();
      this.folders = tree.folders || [];
      this.projectsNoFolder = tree.projectsNoFolder || [];
    },
    findProjectInTree(path){
      for (const p of (this.projectsNoFolder||[])) if (p.path === path) return p;
      const stack = [...(this.folders||[])];
      while(stack.length){
        const f = stack.shift();
        for (const p of (f.projects||[])) if (p.path === path) return p;
        (f.children||[]).forEach(ch => stack.push(ch));
      }
      return null;
    },

    // invio messaggio: usa SEMPRE la tab attiva
    async sendMessage(){
      const text = (this.composer.text || '').trim();
      if (!text) return;

      // deve esserci una tab attiva; se non c'è, crea progetto Default e apri
      let tab = this.activeTab();
      if (!tab) {
        await this.createProject('Default');
        tab = this.activeTab();
        if (!tab) { alert('Impossibile aprire la tab Default.'); return; }
      }

      // push messaggio utente
      tab.messages.push({role:'user', content:text});
      this.composer.text = '';
      this.thinking = true;

      try{
        const res = await fetch(`{{ route('chat.send') }}`, {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams({
            project_path: tab.path, // SEMPRE la tab attiva
            prompt: text,
            auto: this.autoContext ? '1' : '0'
          })
        });
        const data = await res.json();
        const ans = data.answer || data.error || 'Errore sconosciuto.';
        tab.messages.push({role:'assistant', content: String(ans)});
      }catch(e){
        tab.messages.push({role:'assistant', content: 'Errore: '+e.message});
      }finally{
        this.thinking = false;
        this.persistTabs();
      }
    }
  }
}
</script>
</body>
</html>
