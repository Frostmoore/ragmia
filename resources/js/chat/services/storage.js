import { LS_KEY } from '../config';

export function loadPersisted() {
  try { return JSON.parse(localStorage.getItem(LS_KEY) || '{}'); }
  catch { return {}; }
}
export function savePersisted(store) {
  localStorage.setItem(LS_KEY, JSON.stringify({
    tabs: store.tabs,
    active: store.activeTabId,
    model: store.model,
    compress_model: store.compress_model,
    useCompressor: store.useCompressor,
  }));
}
