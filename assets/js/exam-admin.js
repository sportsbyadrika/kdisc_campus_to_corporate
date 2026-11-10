/* Online exam administration: question form options and question-series picking. */
(function () {
  'use strict';

  // ---- Question form: option rows, single-selection behaves like a radio group -----------
  const type = document.getElementById('q-type');
  const list = document.getElementById('q-option-list');
  if (type && list) {
    const box = document.getElementById('q-options');
    const tpl = document.getElementById('q-option-template');
    const hint = document.getElementById('q-options-hint');
    const descHint = document.getElementById('q-descriptive-hint');
    const sync = () => {
      const desc = type.value === 'descriptive';
      box.classList.toggle('hidden', desc);
      descHint.classList.toggle('hidden', !desc);
      box.querySelectorAll('textarea,input').forEach((el) => { el.disabled = desc; });
      hint.textContent = type.value === 'multi' ? '— tick every correct answer' : '— tick the one correct answer';
      if (type.value === 'single') {
        const ticked = [...list.querySelectorAll('[data-correct]:checked')];
        ticked.slice(1).forEach((c) => { c.checked = false; });
      }
    };
    document.getElementById('q-add-option').addEventListener('click', () => {
      const i = parseInt(list.dataset.next, 10) || 0;
      list.dataset.next = i + 1;
      list.insertAdjacentHTML('beforeend', tpl.innerHTML.replaceAll('__i__', i));
      list.lastElementChild.querySelector('textarea').focus();
    });
    list.addEventListener('click', (e) => {
      const rm = e.target.closest('[data-remove-option]');
      if (rm) rm.closest('[data-option-row]').remove();
    });
    list.addEventListener('change', (e) => {
      if (type.value === 'single' && e.target.matches('[data-correct]') && e.target.checked) {
        list.querySelectorAll('[data-correct]').forEach((c) => { if (c !== e.target) c.checked = false; });
      }
    });
    type.addEventListener('change', sync);
    sync();
  }

  // ---- Question series: counts, select all / none, random pick ----------------------------
  const picker = document.getElementById('series-picker');
  if (picker) {
    const boxes = () => [...picker.querySelectorAll('input[name="questions[]"]')];
    const count = document.getElementById('series-count');
    const marks = document.getElementById('series-marks');
    const refresh = () => {
      const sel = boxes().filter((b) => b.checked);
      count.textContent = sel.length;
      marks.textContent = sel.reduce((s, b) => s + parseFloat(b.dataset.marks || 0), 0).toString();
    };
    picker.addEventListener('change', refresh);
    document.querySelectorAll('[data-series-pick]').forEach((btn) => btn.addEventListener('click', () => {
      const mode = btn.dataset.seriesPick;
      const type = document.getElementById('series-random-type').value;
      const pool = boxes().filter((b) => !type || b.dataset.type === type);
      if (mode === 'none') boxes().forEach((b) => { b.checked = false; });
      if (mode === 'all') pool.forEach((b) => { b.checked = true; });
      if (mode === 'random') {
        const n = parseInt(document.getElementById('series-random-n').value, 10) || 0;
        pool.forEach((b) => { b.checked = false; });
        const shuffled = pool.slice().sort(() => Math.random() - 0.5);
        shuffled.slice(0, n).forEach((b) => { b.checked = true; });
      }
      refresh();
    }));
    refresh();
  }
})();

/* Case study scenario editor: Bold / Italic / Bullets / Numbering buttons and a live preview.
   The marks match what the server renders (app/exams.php exam_rich_text): **bold**, *italic*,
   "- " bullet lines, "1. " numbered lines, blank line = new paragraph. */
(function () {
  'use strict';
  const box = document.querySelector('[data-rich-editor]');
  if (!box) return;
  const ta = box.querySelector('[data-rich-input]');
  const preview = box.querySelector('[data-rich-preview]');
  const esc = (t) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inline = (line) => esc(line)
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/g, '$1<em>$2</em>')
    .replace(/(^|[^\w])_(?!\s)(.+?)(?<!\s)_(?!\w)/g, '$1<em>$2</em>');
  const render = (text) => {
    let out = '', list = null, para = [];
    const flush = () => { if (para.length) { out += `<p>${para.map(inline).join('<br>')}</p>`; para = []; } };
    const close = () => { if (list) { out += `</${list}>`; list = null; } };
    text.split(/\r?\n/).forEach((line) => {
      let m;
      if ((m = line.match(/^\s*[-*•]\s+(.*)$/))) { flush(); if (list !== 'ul') { close(); out += '<ul>'; list = 'ul'; } out += `<li>${inline(m[1])}</li>`; }
      else if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) { flush(); if (list !== 'ol') { close(); out += '<ol>'; list = 'ol'; } out += `<li>${inline(m[1])}</li>`; }
      else if (!line.trim()) { flush(); close(); }
      else { close(); para.push(line); }
    });
    flush(); close();
    return out;
  };
  const update = () => { preview.innerHTML = render(ta.value) || '<p class="text-slate-400">The formatted scenario appears here.</p>'; };

  // Wrap the selection in a mark, or prefix each selected line for lists
  const wrap = (mark) => {
    const [a, b] = [ta.selectionStart, ta.selectionEnd];
    const sel = ta.value.slice(a, b) || 'text';
    ta.setRangeText(mark + sel + mark, a, b, 'select');
    ta.setSelectionRange(a + mark.length, a + mark.length + sel.length);
  };
  const prefix = (numbered) => {
    const start = ta.value.lastIndexOf('\n', ta.selectionStart - 1) + 1;
    let end = ta.value.indexOf('\n', ta.selectionEnd);
    if (end === -1) end = ta.value.length;
    let n = 0;
    const lines = ta.value.slice(start, end).split('\n').map((l) => {
      const bare = l.replace(/^\s*([-*•]|\d+[.)])\s+/, '');
      return numbered ? `${++n}. ${bare}` : `- ${bare}`;
    });
    ta.setRangeText(lines.join('\n'), start, end, 'select');
  };
  box.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-rich]');
    if (!btn) return;
    const what = btn.dataset.rich;
    if (what === 'bold') wrap('**');
    if (what === 'italic') wrap('*');
    if (what === 'bullets') prefix(false);
    if (what === 'numbers') prefix(true);
    ta.focus();
    update();
  });
  ta.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && ['b', 'i'].includes(e.key.toLowerCase())) {
      e.preventDefault();
      wrap(e.key.toLowerCase() === 'b' ? '**' : '*');
      update();
    }
  });
  ta.addEventListener('input', update);
  update();
})();
