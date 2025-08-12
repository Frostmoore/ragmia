// resources/js/chat/state.js
export function createInitialState() {
  return {
    folders: [],
    projectsNoFolder: [],
    search: '',
    openFolderIds: {},
    tabs: [],
    activeTabId: null,
    autoContext: true,
    model: 'openai:gpt-5',
    compress_model: 'openai:gpt-4o-mini',
    useCompressor: true,
    composer: { text: '' },
    thinking: false,
    monthTokens: 0,
    monthCost: 0,
    // getter/utility “puri”
    activeTab() { return this.tabs.find(t => t.id === this.activeTabId) || null; },
    isOpen(id){ return !!this.openFolderIds[id]; },
  };
}
