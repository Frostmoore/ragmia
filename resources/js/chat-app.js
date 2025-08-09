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
    model: 'openai:gpt-5',
    compress_model: 'openai:gpt-4o-mini',
    composer: { text: '' },
    thinking: false,
    monthTokens: 0,
    monthCost: 0,

    init({ folders = [], projectsNoFolder = [] } = {}) {
      this.folders = folders;
      this.projectsNoFolder = projectsNoFolder;
      (this.folders || []).forEach(f => this.openFolderIds[f.id] = true);

      const saved = localStorage.getItem('chatTabsV4');
      if (saved) {
        try {
          const { tabs, active, model, compress_model } = JSON.parse(saved);
          this.tabs = tabs || [];
          this.activeTabId = active || (this.tabs[0]?.id ?? null);
          if (model) this.model = model;
          if (compress_model) this.compress_model = compress_model;
        } catch {}
      }

      // normalizza comunque se vuoti
      if (!this.model) this.model = 'openai:gpt-5';
      if (!this.compress_model) this.compress_model = 'openai:gpt-4o-mini';
      queueMicrotask(() => this.ensureLoaded(this.activeTab()));

      fetch('/chat/stats')
        .then(r => r.json())
        .then(data => {
          this.monthTokens = data.tokens;
          this.monthCost = data.cost;
        });
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
    persistTabs(){
        localStorage.setItem('chatTabsV4', JSON.stringify({
            tabs: this.tabs,
            active: this.activeTabId,
            model: this.model,
            compress_model: this.compress_model
        }));
    },
    activeTab(){ return this.tabs.find(t => t.id === this.activeTabId) || null; },
    activateTab(id){ this.activeTabId = id; this.persistTabs(); this.ensureLoaded(this.activeTab()); },
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
            await this.ensureLoaded(existing);
            return;
        }

        // crea nuova tab vuota e attivala
        const id = crypto.randomUUID();
        const tab = { id, title: project.path.split('/').pop(), path: project.path, project_id: project.id, messages: [], _loaded: false };
        this.tabs.push(tab);
        this.activeTabId = id;
        this.persistTabs();

        // carica subito lo storico (senza aspettare invio messaggi)
        await this.ensureLoaded(tab);
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
            this.highlightCodes();
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
    // async sendMessage(){
    //   const text = (this.composer.text || '').trim();
    //   if (!text) return;

    //   let tab = this.activeTab();
    //   if (!tab) {
    //     await this.createProject('Default');
    //     tab = this.activeTab();
    //     if (!tab) { alert('Impossibile aprire la tab Default.'); return; }
    //   }

    //   tab.messages.push({role:'user', content:text});
    //   this.composer.text = '';
    //   this.thinking = true;

    //   try{
    //     const res = await fetch(`/send`, {
    //       method: 'POST',
    //       headers: {
    //         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    //         'Accept': 'application/json',
    //         'Content-Type': 'application/x-www-form-urlencoded'
    //       },
    //       body: new URLSearchParams({
    //         project_path: tab.path,
    //         prompt: text,
    //         auto: this.autoContext ? '1' : '0'
    //       })
    //     });

    //     const data = await res.json();
    //     const ans = data.answer || data.error || 'Errore sconosciuto.';
    //     tab.messages.push({role:'assistant', content: String(ans)});
    //   }catch(e){
    //     tab.messages.push({role:'assistant', content: 'Errore: '+e.message});
    //   }finally{
    //     this.thinking = false;
    //     this.persistTabs();
    //   }
    // }

    setModel(v) {
      this.model = String(v || '').trim() || 'openai:gpt-5';
      this.persistTabs();
    },
    setCompressModel(v) {
      this.compress_model = String(v || '').trim() || 'openai:gpt-4o-mini';
      this.persistTabs();
    },
    setAutoContext(checked) {
      this.autoContext = !!checked;
      this.persistTabs();
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

      // append immediato del messaggio utente
      tab.messages = [...tab.messages, { role: 'user', content: text }];
      this.composer.text = '';
      this.thinking = true;

      try {
        const selectedModel = String(this.model || '').trim() || 'openai:gpt-5';
        const selectedCompressor = String(this.compress_model || '').trim() || 'openai:gpt-4o-mini';
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
            auto: this.autoContext ? '1' : '0',
            model: selectedModel,
            compress_model: selectedCompressor,
          })
        });

        const data = await res.json();

        if (!res.ok) {
          const err = data?.error || `HTTP ${res.status}`;
          tab.messages = [...tab.messages, { role: 'assistant', content: `Errore: ${err}` }];
          return;
        }

        const ans = data.answer || 'Risposta vuota.';

        // ⬇️ aggiorna i contatori mese se il controller manda usage
        if (data.usage) {
          const addTokens = Number(data.usage.total || 0);
          const addCost   = Number(data.usage.cost  || 0);
          this.monthTokens = (this.monthTokens || 0) + addTokens;
          // evito drifting di floating point
          this.monthCost   = Number(((this.monthCost || 0) + addCost).toFixed(6));
        }

        // append risposta assistant
        tab.messages = [...tab.messages, { role:'assistant', content:String(ans) }];
        this.highlightCodes();

      } catch (e) {
        tab.messages = [...tab.messages, { role:'assistant', content:`Errore: ${e.message}` }];
      } finally {
        this.thinking = false;
        this.persistTabs();
        this.highlightCodes();
      }
    },
    parseSegments(text = '') {
      const src = String(text);
      const re = /```([^\r\n]*)\r?\n([\s\S]*?)```/g;
      const out = [];
      let last = 0, m;

      while ((m = re.exec(src)) !== null) {
        const [full, lang, code] = m;
        const start = m.index;

        if (start > last) {
          const plain = src.slice(last, start);
          if (plain.length) out.push({ type: 'text', content: plain });
        }

        out.push({ type: 'code', lang: (lang || '').trim(), content: (code ?? '').replace(/\r/g, '') });

        last = start + full.length;
      }

      if (last < src.length) {
        const plain = src.slice(last);
        if (plain.length) out.push({ type: 'text', content: plain });
      }

      if (out.length === 0) return [{ type: 'text', content: src }];
      return out;
    },

    highlightCodes() {
      // evidenzia tutti i blocchi nel container messaggi
      requestAnimationFrame(() => {
        const root = document.getElementById('chat-messages');
        if (!root || !window.hljs) return;
        root.querySelectorAll('pre code').forEach(el => {
          try { 
              if (el.dataset && el.dataset.highlighted) {
                el.removeAttribute('data-highlighted');
              }
          } catch(_) {}
        window.hljs.highlightElement(el);
        });
      });
    },

    copyToClipboard(text = '') {
      if (navigator.clipboard?.writeText) {
        return navigator.clipboard.writeText(text);
      }
      // fallback old-school
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch(_) {}
      document.body.removeChild(ta);
    },
    async ensureLoaded(tab) {
      if (!tab) return;
      const need = !tab._loaded || !(tab.messages && tab.messages.length);
      if (!need) return;
      try {
        await this.loadMessages(tab.project_id, tab);
      } catch (e) {
        console.error('[ensureLoaded] fail:', e);
      }
    },






  });
}
