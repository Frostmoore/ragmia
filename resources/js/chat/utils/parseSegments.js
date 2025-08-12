// resources/js/chat/utils/parseSegments.js
// Converte testo con fence (```  '''  ~~~) in segmenti [{type:'text'|'code', lang, content}]
export function parseSegments(raw) {
  if (!raw || typeof raw !== 'string') return [];

  const out = [];
  let textBuf = '';

  const flushText = () => {
    if (textBuf.length) {
      out.push({ type: 'text', content: textBuf });
      textBuf = '';
    }
  };

  const nlFix = raw.replace(/\r\n?/g, '\n').split('\n');
  let inCode = false;
  let fence = '';
  let info = '';
  let codeLines = [];

  for (const line of nlFix) {
    // apertura fence
    if (!inCode) {
      const m = line.match(/^(```|'''|~~~)\s*([A-Za-z0-9:+_-]*)\s*$/);
      if (m) {
        flushText();
        inCode = true;
        fence = m[1];
        info = (m[2] || '').toLowerCase(); // es: canvas:blade, python, js
        codeLines = [];
        continue;
      }
      // testo “normale”
      textBuf += (textBuf ? '\n' : '') + line;
      continue;
    }

    // chiusura fence
    if (inCode && line.startsWith(fence)) {
      const lang = info || 'plaintext';
      out.push({ type: 'code', lang, content: codeLines.join('\n') });
      inCode = false;
      fence = '';
      info = '';
      codeLines = [];
      continue;
    }

    // dentro codice
    codeLines.push(line);
  }

  // blocco non chiuso → re-inserisci come testo
  if (inCode) {
    textBuf += (textBuf ? '\n' : '') + fence + (info ? (' ' + info) : '') + '\n' + codeLines.join('\n');
  }
  flushText();

  // compat: unisci text adiacenti
  const compact = [];
  for (const seg of out) {
    if (seg.type === 'text' && compact.length && compact[compact.length - 1].type === 'text') {
      compact[compact.length - 1].content += '\n' + seg.content;
    } else {
      compact.push(seg);
    }
  }
  return compact;
}
