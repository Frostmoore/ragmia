// resources/js/app.js
import '../css/app.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

// ⬇️ nuovo entrypoint dello store modulare
import registerChatStore from './chat/index';

import hljs from 'highlight.js';
import 'highlight.js/styles/github-dark-dimmed.css';

window.hljs = hljs;
window.Alpine = Alpine;
Alpine.plugin(collapse);

document.addEventListener('alpine:init', () => {
  console.log('[alpine] init, version:', Alpine.version);

  // registra lo store "chat" (modulare)
  registerChatStore(Alpine);

  // store UI minimale (come prima)
  if (!Alpine.store('ui')) {
    Alpine.store('ui', { sidebarOpen: false });
  }

  const ui = Alpine.store('ui');
  console.log('[ui] initial:', ui);

  // piccolo logger dei cambi di sidebar
  let _open = ui.sidebarOpen;
  Object.defineProperty(ui, 'sidebarOpen', {
    get(){ return _open; },
    set(v){ console.log('[ui] sidebarOpen ->', v); _open = v; },
    configurable: true
  });

  // helpers da console
  window.__dbg = {
    open(){ ui.sidebarOpen = true; },
    close(){ ui.sidebarOpen = false; },
    ui(){ return ui; },
    chat(){ return Alpine.store('chat'); }
  };
});

Alpine.start();
