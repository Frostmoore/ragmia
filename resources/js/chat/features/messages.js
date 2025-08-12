// resources/js/chat/features/messages.js
import { api } from '../services/api';
import { highlightCodes } from '../utils/highlightCodes';
import { parseSegments } from '../utils/parseSegments';
import { copyToClipboard } from '../utils/clipboard';

export function attachMessages(store) {
  store.parseSegments = parseSegments;
  store.copyToClipboard = copyToClipboard;

  store.loadMessages = async function(projectId, tabRef){
    try {
      const { ok, data } = await api.listMessages(projectId);
      if (!ok) throw new Error('HTTP error');
      tabRef.messages = (data.messages || []).map(m => ({ role: m.role, content: m.content }));
      tabRef._loaded = true;
      this.persistTabs();
      highlightCodes();
    } catch (e) {
      tabRef.messages = [{ role: 'assistant', content: 'Errore caricando messaggi: ' + e.message }];
    }
  };

  store.sendMessage = async function(){
    const text = (this.composer.text || '').trim();
    if (!text) return;

    let tab = this.activeTab();
    if (!tab) {
      await this.createProject('Default'); tab = this.activeTab();
      if (!tab) { alert('Impossibile aprire la tab Default.'); return; }
    }

    tab.messages = [...tab.messages, { role: 'user', content: text }];
    this.composer.text = '';
    this.thinking = true;

    try {
      const model = String(this.model || '').trim() || 'openai:gpt-5';
      const compressor = String(this.compress_model || '').trim() || 'openai:gpt-4o-mini';

      const { ok, data, status } = await api.send({
        project_path: tab.path,
        prompt: text,
        auto: this.autoContext ? '1' : '0',
        model,
        compress_model: compressor,
        raw_user: this.useCompressor ? '0' : '1',
      });

      if (!ok) {
        const err = data?.error || `HTTP ${status}`;
        tab.messages = [...tab.messages, { role:'assistant', content:`Errore: ${err}` }];
        return;
      }

      const ans = data.answer || 'Risposta vuota.';
      if (data.debug?.compressor_input) this.pushDebugBubble('compressor', data.debug.compressor_input);
      if (data.debug?.final_input) this.pushDebugBubble('final', data.debug.final_input);
      if (data.debug?.current_memory !== undefined) this.pushDebugBubble('final','MEMORY CORRENTE:\n'+(data.debug.current_memory || '—'));

      if (data.usage) {
        const addTokens = Number(data.usage.total || 0);
        const addCost   = Number(data.usage.cost  || 0);
        this.monthTokens = (this.monthTokens || 0) + addTokens;
        this.monthCost   = Number(((this.monthCost || 0) + addCost).toFixed(6));
      }

      tab.messages = [...tab.messages, { role:'assistant', content:String(ans) }];
      highlightCodes();
    } catch (e) {
      tab.messages = [...tab.messages, { role:'assistant', content:`Errore: ${e.message}` }];
    } finally {
      this.thinking = false;
      this.persistTabs();
      highlightCodes();
    }
  };

  store.pushDebugBubble = function(kind, content) {
    if (!content) return;
    const tab = this.activeTab(); if (!tab) return;
    const title = kind === 'compressor' ? 'Prompt al compressore' : 'Prompt al modello finale';
    tab.messages = [...tab.messages, { role:'debug', kind, debugTitle:title, content:String(content) }];
    this.persistTabs();
    highlightCodes();
  };

  store.removeMessage = function(index) {
    const tab = this.activeTab(); if (!tab) return;
    if (index < 0 || index >= tab.messages.length) return;
    if (tab.messages[index]?.role === 'debug') {
      tab.messages.splice(index,1);
      tab.messages = [...tab.messages];
      this.persistTabs();
    }
  };

  store.openCanvas = function(code, lang = 'plaintext') {
    // Se hai un handler globale, lo puoi ascoltare altrove (modal, pannello, ecc.)
    try {
        window.dispatchEvent(new CustomEvent('open-canvas', { detail: { code, lang } }));
    } catch(_) {
        // fallback: copia
        copyToClipboard(code);
    }
  };
}
