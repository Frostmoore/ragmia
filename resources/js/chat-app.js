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
    useCompressor: true,


    init({ folders = [], projectsNoFolder = [] } = {}) {
      this.folders = folders;
      this.projectsNoFolder = projectsNoFolder;
      (this.folders || []).forEach(f => this.openFolderIds[f.id] = true);

      const saved = localStorage.getItem('chatTabsV4');
      if (saved) {
        try {
          const { tabs, active, model, compress_model, useCompressor } = JSON.parse(saved);
          this.tabs = tabs || [];
          this.activeTabId = active || (this.tabs[0]?.id ?? null);
          if (model) this.model = model;
          if (compress_model) this.compress_model = compress_model;
          if (typeof useCompressor === 'boolean') this.useCompressor = useCompressor;
        } catch {}
      }

      // normalizza comunque se vuoti
      if (!this.model) this.model = 'openai:gpt-5';
      if (!this.compress_model) this.compress_model = 'openai:gpt-4o-mini';
      if (typeof this.useCompressor !== 'boolean') this.useCompressor = true;

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
        compress_model: this.compress_model,
        useCompressor: this.useCompressor, // 👈 nuovo
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
            raw_user: this.useCompressor ? '0' : '1',
            max_tokens: '4000',
          })
        });

        const data = await res.json();

        if (!res.ok) {
          const err = data?.error || `HTTP ${res.status}`;
          tab.messages = [...tab.messages, { role: 'assistant', content: `Errore: ${err}` }];
          return;
        }

        const ans = data.answer || 'Risposta vuota.';

        if (data.debug?.compressor_input) {
          this.pushDebugBubble('compressor', data.debug.compressor_input);
        }
        if (data.debug?.final_input) {
          this.pushDebugBubble('final', data.debug.final_input);
        }
        if (data.debug?.current_memory !== undefined) {
          this.pushDebugBubble('final', 'MEMORY CORRENTE:\n' + (data.debug.current_memory || '—'));
        }


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
    // resources/js/stores/chat.js (o dove definisci lo store)
    parseSegments(raw) {
      if (!raw || typeof raw !== 'string') return [];

      const fences = ['```', "'''", '~~~'];
      const out = [];
      let textBuf = '';

      const flushText = () => {
        if (textBuf.length) {
          out.push({ type: 'text', content: textBuf });
          textBuf = '';
        }
      };

      const nlFix = raw.replace(/\r\n?/g, '\n').split('\n');
      let inCode = false;
      let fence = '';
      let info = '';
      let codeLines = [];

      for (const line of nlFix) {
        // apertura
        if (!inCode) {
          const m = line.match(/^(```|'''|~~~)\s*([A-Za-z0-9:+_-]*)\s*$/);
          if (m) {
            flushText();
            inCode = true;
            fence = m[1];
            info = (m[2] || '').toLowerCase(); // es: canvas:blade, python
            codeLines = [];
            continue;
          }
          // testo “normale”
          textBuf += (textBuf ? '\n' : '') + line;
          continue;
        }

        // chiusura
        if (inCode && line.startsWith(fence)) {
          const lang = info || 'plaintext';
          out.push({ type: 'code', lang, content: codeLines.join('\n') });
          inCode = false;
          fence = '';
          info = '';
          codeLines = [];
          continue;
        }

        // dentro codice
        codeLines.push(line);
      }

      // blocco non chiuso → re-inserisci come testo
      if (inCode) {
        textBuf += (textBuf ? '\n' : '') + fence + (info ? (' ' + info) : '') + '\n' + codeLines.join('\n');
      }
      flushText();

      // compat: unisci text adiacenti
      const compact = [];
      for (const seg of out) {
        if (seg.type === 'text' && compact.length && compact[compact.length - 1].type === 'text') {
          compact[compact.length - 1].content += '\n' + seg.content;
        } else {
          compact.push(seg);
        }
      }
      return compact;
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




    pushDebugBubble(kind, content) {
      if (!content) return;
      const tab = this.activeTab();
      if (!tab) return;

      const title = kind === 'compressor'
        ? 'Prompt al compressore'
        : 'Prompt al modello finale';

      // aggiungo il messaggio debug
      tab.messages = [
        ...tab.messages,
        { role: 'debug', kind, debugTitle: title, content: String(content) }
      ];

      this.persistTabs();
      this.highlightCodes();
    },

    removeMessage(index) {
      const tab = this.activeTab();
      if (!tab) return;
      if (index < 0 || index >= tab.messages.length) return;
      // rimuovo solo se è un messaggio debug
      if (tab.messages[index]?.role === 'debug') {
        tab.messages.splice(index, 1);
        tab.messages = [...tab.messages]; // forza reattività
        this.persistTabs();
      }
    },







  });
}
