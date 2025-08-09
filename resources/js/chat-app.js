// resources/js/chat-app.js

export default function registerChatStore(Alpine) {
  Alpine.store('chat', {
    // ---------------------------
    // INCOLLA QUI dentro TUTTO il tuo oggetto store:
    // folders, projectsNoFolder, search, tabs, activeTabId, autoContext, model, composer, thinking, ecc.
    // + tutti i metodi: init, isOpen, toggleFolder, filteredNoFolder, filteredProjects,
    //   persistTabs, activeTab, activateTab, closeTab, openProjectTab, promptNewProject, createProject,
    //   promptNewFolder, createFolder, reloadTree, findProjectInTree, sendMessage
    // ---------------------------

    folders: [],
    projectsNoFolder: [],
    search: '',
    openFolderIds: {},
    tabs: [],
    activeTabId: null,
    autoContext: true,
    model: 'gpt-5',
    composer: { text: '' },
    thinking: false,

    init({ folders = [], projectsNoFolder = [] } = {}) {
        this.folders = folders;
        this.projectsNoFolder = projectsNoFolder;
        (this.folders || []).forEach(f => this.openFolderIds[f.id] = true);

        const saved = localStorage.getItem('chatTabsV4');
        if (saved) {
            try {
            const { tabs, active } = JSON.parse(saved);
            this.tabs = tabs || [];
            this.activeTabId = active || (this.tabs[0]?.id ?? null);
            } catch {}
        }
    },
    isCode(text = '') {
        const t = String(text).trimStart();
        return t.startsWith('```');
    },
    stripFences(text = '') {
        // rimuove le prime/ultime triple backtick (e l’eventuale "```lang")
        const t = String(text);
        if (!t.trimStart().startsWith('```')) return t;
        // togli prima riga di fence
        const withoutFirst = t.replace(/^```[^\n]*\n?/, '');
        // togli ultima fence se presente
        return withoutFirst.replace(/```[\s\r\n]*$/, '');
    },


    // ... (tutto il resto dei metodi che avevi prima, identici)
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
    persistTabs(){ localStorage.setItem('chatTabsV4', JSON.stringify({tabs:this.tabs, active:this.activeTabId})); },
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
        // se esiste già la tab → attivala e assicurati di aver caricato lo storico
        const existing = this.tabs.find(t => t.project_id === project.id);
        if (existing) {
            this.activateTab(existing.id);
            if (!existing._loaded) await this.loadMessages(project.id, existing);
            return;
        }

        // crea nuova tab vuota e attivala
        const id = crypto.randomUUID();
        const tab = { id, title: project.path.split('/').pop(), path: project.path, project_id: project.id, messages: [], _loaded: false };
        this.tabs.push(tab);
        this.activeTabId = id;
        this.persistTabs();

        // carica subito lo storico (senza aspettare invio messaggi)
        await this.loadMessages(project.id, tab);
        },

        async loadMessages(projectId, tabRef){
        try {
            const res = await fetch(`/api/messages?project_id=${projectId}`, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            const loaded = (data.messages || []).map(m => ({ role: m.role, content: m.content }));
            // 👉 assegnazione sostitutiva: forza l’update reattivo
            tabRef.messages = loaded;
            tabRef._loaded = true;
            this.persistTabs();
        } catch (e) {
            tabRef.messages = [{ role: 'assistant', content: 'Errore caricando messaggi: ' + e.message }];
        }
    },
    promptNewProject(){
      const path = prompt('Path progetto (es. Consorzio/ScuoleGuida oppure SoloNome):');
      if (!path) return;
      this.createProject(path);
    },
    async createProject(path){
      const res = await fetch(`/api/projects`, {
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
      await this.reloadTree();
      const proj = this.findProjectInTree(data.project?.path);
      if (proj) this.openProjectTab(proj);
    },
    promptNewFolder(){
      const path = prompt('Path cartella (es. Consorzio o Consorzio/Sub):');
      if (!path) return;
      this.createFolder(path);
    },
    async createFolder(path){
      const res = await fetch(`/api/folders`, {
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
      this.folders = data.tree.folders || [];
      this.projectsNoFolder = data.tree.projectsNoFolder || [];
      if (data.folder?.id) this.openFolderIds[data.folder.id] = true;
    },
    async reloadTree(){
      const treeRes = await fetch(`/api/projects`);
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
    async sendMessage(){
      const text = (this.composer.text || '').trim();
      if (!text) return;

      let tab = this.activeTab();
      if (!tab) {
        await this.createProject('Default');
        tab = this.activeTab();
        if (!tab) { alert('Impossibile aprire la tab Default.'); return; }
      }

      tab.messages.push({role:'user', content:text});
      this.composer.text = '';
      this.thinking = true;

      try{
        const res = await fetch(`/send`, {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams({
            project_path: tab.path,
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
  });
}
