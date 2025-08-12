// Lightweight auto-scroll utility for the chat message container.
// Usage (Blade):
//   x-init="cleanup = window.ChatScrolling.init($el, {
//     getSignature: () => `${$store.chat.activeTabId}:${$store.chat.activeTab()?.messages?.length||0}`
//   })"
//   x-effect="() => {}"  // non serve altro, l'observer reagisce da solo
//
// In app.js assicurati di esportarlo su window:
//   import * as ChatScrolling from './chat/features/scrolling';
//   window.ChatScrolling = ChatScrolling;

const DEFAULT_THRESHOLD = 48;

/** Ritorna true se il container è “incollato” al fondo. */
export function isNearBottom(el, threshold = DEFAULT_THRESHOLD) {
  if (!el) return true;
  const distance = el.scrollHeight - el.scrollTop - el.clientHeight;
  return distance <= threshold;
}

/** Scrolla in fondo (con doppio rAF per layout stabili). */
export function scrollToBottom(el, { behavior = 'auto' } = {}) {
  if (!el) return;
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      try {
        el.scrollTo({ top: el.scrollHeight, behavior });
      } catch {
        // Safari vecchi
        el.scrollTop = el.scrollHeight;
      }
    });
  });
}

/**
 * Installa auto-scroll “smart”:
 * - resta appiccicato al fondo finché l’utente non scrolla su
 * - torna in fondo quando cambia tab o arriva un nuovo messaggio
 * - reagisce a mutazioni DOM e resize del container
 *
 * @param {HTMLElement} container - l’elemento scrollabile (il wrapper dei messaggi)
 * @param {Object} opts
 * @param {() => string} opts.getSignature - funzione che ritorna una stringa che
 *        cambia quando deve autoscrollare (es: `${tabId}:${msgCount}`)
 * @param {number} opts.threshold - px dal fondo considerati “in fondo”
 * @param {(stick:boolean)=>void} opts.onStickChange - callback quando cambia stickiness
 * @returns {() => void} cleanup function
 */
export function init(
  container,
  { getSignature = () => String(Date.now()), threshold = DEFAULT_THRESHOLD, onStickChange = null } = {}
) {
  if (!container) return () => {};

  let stickToBottom = true; // finché l’utente non scorre su
  let prevSig = getSignature();

  // primo scroll in fondo all’avvio
  scrollToBottom(container);

  const handleScroll = () => {
    const nowStick = isNearBottom(container, threshold);
    if (nowStick !== stickToBottom) {
      stickToBottom = nowStick;
      if (typeof onStickChange === 'function') onStickChange(stickToBottom);
    }
  };

  container.addEventListener('scroll', handleScroll, { passive: true });

  // Osserva mutazioni del contenuto (nuovi messaggi)
  const mo = new MutationObserver(() => {
    const sig = getSignature();
    const tabChanged = sig.split(':')[0] !== prevSig.split(':')[0];
    if (stickToBottom || tabChanged) {
      scrollToBottom(container);
    }
    prevSig = sig;
  });
  mo.observe(container, { childList: true, subtree: true });

  // Reagisce anche a resize/layout (font size/theme ecc.)
  const ro = new ResizeObserver(() => {
    if (stickToBottom) scrollToBottom(container);
  });
  try { ro.observe(container); } catch {}

  // Poll super-leggero come ulteriore safety net per cambi “silenziosi”
  let rafId = 0;
  const tick = () => {
    rafId = requestAnimationFrame(tick);
    const sig = getSignature();
    if (sig !== prevSig) {
      const tabChanged = sig.split(':')[0] !== prevSig.split(':')[0];
      if (stickToBottom || tabChanged) {
        scrollToBottom(container);
      }
      prevSig = sig;
    }
  };
  rafId = requestAnimationFrame(tick);

  // Eventi manuali (puoi fare: window.dispatchEvent(new Event('chat:scroll-bottom')))
  const forceDown = () => scrollToBottom(container, { behavior: 'smooth' });
  window.addEventListener('chat:scroll-bottom', forceDown);

  // Cleanup
  const cleanup = () => {
    container.removeEventListener('scroll', handleScroll);
    window.removeEventListener('chat:scroll-bottom', forceDown);
    try { mo.disconnect(); } catch {}
    try { ro.disconnect(); } catch {}
    if (rafId) cancelAnimationFrame(rafId);
  };

  // Esponi anche sul nodo (utile se vuoi richiamarlo da Alpine)
  container.__chatScrollCleanup = cleanup;

  return cleanup;
}

export default { init, scrollToBottom, isNearBottom };
