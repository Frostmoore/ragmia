// resources/js/chat/utils/highlightCodes.js
export function highlightCodes() {
  requestAnimationFrame(() => {
    const root = document.getElementById('chat-messages');
    if (!root || !window.hljs) return;
    root.querySelectorAll('pre code').forEach(el => {
      try {
        if (el.dataset && el.dataset.highlighted) {
          el.removeAttribute('data-highlighted');
        }
      } catch(_) {}
      try {
        window.hljs.highlightElement(el);
      } catch(_) {}
    });
  });
}
