// resources/js/chat/features/tabs.js
import { highlightCodes } from '../utils/highlightCodes';

export function attachTabs(store) {
  store.ensureLoaded = async function(tab) {
    if (!tab) return;
    const need = !tab._loaded || !(tab.messages && tab.messages.length);
    if (!need) return;
    try {
      await this.loadMessages(tab.project_id, tab);
    } catch (e) {
      console.error('[ensureLoaded] fail:', e);
    }
  };

  store.activateTab = function(id) {
    this.activeTabId = id;
    this.persistTabs();
    this.ensureLoaded(this.activeTab());
    requestAnimationFrame(() => highlightCodes());
  };

  store.closeTab = function(id) {
    const i = this.tabs.findIndex(t => t.id === id);
    if (i === -1) return;
    const wasActive = this.activeTabId === id;
    this.tabs.splice(i, 1);
    if (wasActive) this.activeTabId = this.tabs[0]?.id ?? null;
    this.persistTabs();
    requestAnimationFrame(() => highlightCodes());
  };
}
