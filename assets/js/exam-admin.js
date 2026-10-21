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
