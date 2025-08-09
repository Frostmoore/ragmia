// resources/js/app.js
import '../css/app.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import registerChatStore from './chat-app';
import hljs from 'highlight.js';
import 'highlight.js/styles/github-dark-dimmed.css';

window.hljs = hljs;
window.Alpine = Alpine;
Alpine.plugin(collapse);

document.addEventListener('alpine:init', () => {
  console.log('[alpine] init, version:', Alpine.version);

  registerChatStore(Alpine);

  if (!Alpine.store('ui')) {
    Alpine.store('ui', { sidebarOpen: false });
  }

  const ui = Alpine.store('ui');
  console.log('[ui] initial:', ui);

  // logger delle modifiche
  let _open = ui.sidebarOpen;
  Object.defineProperty(ui, 'sidebarOpen', {
    get(){ return _open; },
    set(v){ console.log('[ui] sidebarOpen ->', v); _open = v; },
    configurable: true
  });

  // helper da console
  window.__dbg = {
    open(){ ui.sidebarOpen = true; },
    close(){ ui.sidebarOpen = false; },
    ui(){ return ui; },
    chat(){ return Alpine.store('chat'); }
  };
});

Alpine.start();
