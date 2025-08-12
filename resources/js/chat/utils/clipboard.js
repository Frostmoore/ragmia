// resources/js/chat/utils/clipboard.js
export function copyToClipboard(text = '') {
  if (navigator.clipboard?.writeText) {
    return navigator.clipboard.writeText(text);
  }
  const ta = document.createElement('textarea');
  ta.value = String(text);
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch(_) {}
  document.body.removeChild(ta);
}
