// resources/js/chat/index.js
import { createInitialState } from './state';
import { api } from './services/api';
import { loadPersisted, savePersisted } from './services/storage';
import { attachMessages } from './features/messages';
import { attachTabs } from './features/tabs';
import { attachProjects } from './features/projects';
import { attachSettings } from './features/settings';
import { DEFAULT_MODEL, DEFAULT_COMPRESSOR } from './config';

export default function registerChatStore(Alpine) {
  const store = {
    ...createInitialState(),
    persistTabs() { savePersisted(this); },
    async init({ folders = [], projectsNoFolder = [] } = {}) {
      this.folders = folders;
      this.projectsNoFolder = projectsNoFolder;
      (this.folders || []).forEach(f => this.openFolderIds[f.id] = true);

      const saved = loadPersisted();
      if (saved) {
        this.tabs = saved.tabs || [];
        this.activeTabId = saved.active || (this.tabs[0]?.id ?? null);
        this.model = saved.model || this.model || DEFAULT_MODEL;
        this.compress_model = saved.compress_model || this.compress_model || DEFAULT_COMPRESSOR;
        this.useCompressor = typeof saved.useCompressor === 'boolean' ? saved.useCompressor : true;
      }

      if (!this.model) this.model = DEFAULT_MODEL;
      if (!this.compress_model) this.compress_model = DEFAULT_COMPRESSOR;
      if (typeof this.useCompressor !== 'boolean') this.useCompressor = true;

      queueMicrotask(() => this.ensureLoaded(this.activeTab()));

      const { ok, data } = await api.stats();
      if (ok) { this.monthTokens = data.tokens; this.monthCost = data.cost; }
    },
  };

  // Attach features
  attachSettings(store);
  attachTabs(store);
  attachProjects(store);
  attachMessages(store);

  Alpine.store('chat', store);
}
