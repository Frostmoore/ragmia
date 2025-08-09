import '../css/app.css';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import registerChatStore from './chat-app';

window.Alpine = Alpine;
Alpine.plugin(collapse);

// 👇 registra gli store PRIMA di start
registerChatStore(Alpine);
Alpine.store('ui', { sidebarOpen: false });

Alpine.start();
