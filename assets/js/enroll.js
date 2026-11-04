/* Public "Enroll my Institution" form: district -> institution list (searchable by typing), CAPTCHA refresh, client-side checks. */
(function () {
  'use strict';
  const form = document.getElementById('enroll-form');
  if (!form) return;

  const district = document.getElementById('district');
  const inst = document.getElementById('institution');
  const hint = document.getElementById('institution-hint');
  const labels = { pending: 'request pending approval', enrolled: 'already enrolled' };

  const setOptions = (items, selected) => {
    inst.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = items.length ? 'Select your institution' : 'No institutions found in this district';
    inst.appendChild(first);
    items.forEach((it) => {
      const o = document.createElement('option');
      o.value = String(it.id);
      o.textContent = it.state === 'available' ? it.name : `${it.name} (${labels[it.state]})`;
      o.disabled = it.state !== 'available';
      if (String(it.id) === String(selected) && !o.disabled) o.selected = true;
      inst.appendChild(o);
    });
    inst.disabled = !items.length;
  };

  // ---- Searchable institution field ---------------------------------------------------
  // The <select> stays the submitted value; a text box over it filters its options as you type
  // (every word anywhere in the name, in any order). Arrow keys, Enter and Escape work too.
  const combo = (() => {
    const wrap = document.createElement('div');
    wrap.className = 'relative';
    const input = document.createElement('input');
    input.type = 'text';
    input.id = 'institution-search';
    input.className = 'input pr-10';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', 'institution-options');
    const caret = document.createElement('span');
    caret.className = 'pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400';
    caret.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>';
    const list = document.createElement('ul');
    list.id = 'institution-options';
    list.setAttribute('role', 'listbox');
    list.className = 'hidden absolute z-30 mt-1 w-full max-h-72 overflow-auto bg-white border border-slate-200 rounded-xl shadow-lg py-1 text-sm';
    inst.parentNode.insertBefore(wrap, inst);
    wrap.append(input, caret, list, inst);
    inst.classList.add('sr-only');
    inst.tabIndex = -1;
    inst.required = false;            // validated through the text box instead
    inst.setAttribute('aria-hidden', 'true');
    const label = document.querySelector('label[for="institution"]');
    if (label) label.htmlFor = 'institution-search';

    let shown = [];
    let active = -1;
    const esc = (t) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const mark = (text, words) => {
      let html = esc(text);
      words.forEach((w) => { html = html.replace(new RegExp(`(${w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig'), '<mark class="bg-amber-100 text-inherit rounded">$1</mark>'); });
      return html;
    };
    const sync = () => {
      const sel = inst.options[inst.selectedIndex];
      input.disabled = inst.disabled;
      input.value = sel && sel.value ? sel.textContent : '';
      input.placeholder = inst.disabled ? (inst.options[0] ? inst.options[0].textContent : '') : 'Type any part of the name to search, or pick from the list';
    };
    const close = () => { list.classList.add('hidden'); input.setAttribute('aria-expanded', 'false'); active = -1; };
    const highlight = (i) => {
      active = i;
      [...list.children].forEach((li, n) => li.classList.toggle('bg-sky-50', n === i));
      if (list.children[i]) { list.children[i].scrollIntoView({ block: 'nearest' }); input.setAttribute('aria-activedescendant', list.children[i].id); }
    };
    const render = (q) => {
      const words = q.toLowerCase().split(/\s+/).filter(Boolean);
      shown = [...inst.options].filter((o) => o.value && words.every((w) => o.textContent.toLowerCase().includes(w)));
      list.innerHTML = '';
      shown.forEach((o, i) => {
        const li = document.createElement('li');
        li.id = `inst-opt-${o.value}`;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-disabled', o.disabled ? 'true' : 'false');
        li.className = `px-4 py-2 ${o.disabled ? 'text-slate-400 cursor-not-allowed' : 'text-slate-800 cursor-pointer hover:bg-sky-50'} ${o.value === inst.value ? 'font-semibold' : ''}`;
        li.innerHTML = mark(o.textContent, words);
        li.addEventListener('mousedown', (e) => { e.preventDefault(); if (!o.disabled) choose(o); });
        list.appendChild(li);
      });
      if (!shown.length) list.innerHTML = `<li class="px-4 py-2 text-slate-500">No institution matches “${esc(q)}”.</li>`;
      list.classList.remove('hidden');
      input.setAttribute('aria-expanded', 'true');
      highlight(shown.findIndex((o) => !o.disabled));
    };
    const choose = (o) => {
      inst.value = o.value;
      inst.dispatchEvent(new Event('change', { bubbles: true }));
      input.setCustomValidity('');
      sync();
      close();
    };
    input.addEventListener('focus', () => { input.select(); render(''); });
    input.addEventListener('input', () => { inst.value = ''; input.setCustomValidity(''); render(input.value); });
    input.addEventListener('keydown', (e) => {
      const step = (d) => {
        if (list.classList.contains('hidden')) render(input.value);
        let i = active;
        do { i += d; } while (shown[i] && shown[i].disabled);
        if (shown[i]) highlight(i);
      };
      if (e.key === 'ArrowDown') { e.preventDefault(); step(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); step(-1); }
      else if (e.key === 'Enter' && !list.classList.contains('hidden')) { e.preventDefault(); if (shown[active]) choose(shown[active]); }
      else if (e.key === 'Escape') { close(); sync(); }
    });
    input.addEventListener('blur', () => {
      // a single available match is taken; otherwise the typed text stays for the person to finish
      const avail = shown.filter((o) => !o.disabled);
      if (!inst.value && input.value.trim() && avail.length === 1) choose(avail[0]);
      close();
    });
    // The list is rebuilt when the district or filters change: keep the text box in step
    new MutationObserver(sync).observe(inst, { childList: true, attributes: true, attributeFilter: ['disabled'] });
    inst.addEventListener('change', sync);
    sync();
    return { input };
  })();

  const fType = document.getElementById('filter-type');
  const fCat = document.getElementById('filter-category');
  let all = [];

  // Apply the Type / Category filters to the loaded list (the selection is kept when it still matches)
  const applyFilters = (selected) => {
    if (!district.value) return; // nothing loaded yet - keep "Select a district first"
    const t = fType.value, c = fCat.value;
    const items = all.filter((it) => (!t || String(it.type) === t) && (!c || String(it.category) === c));
    setOptions(items, selected !== undefined ? selected : inst.value);
    const blocked = items.filter((i) => i.state !== 'available').length;
    const filtered = t || c;
    hint.textContent = (filtered ? `${items.length} of ${all.length} institutions match the selected type / category. ` : '')
      + (blocked ? `${blocked} shown are already enrolled or have a pending request. ` : '')
      + 'Not listed? Ask your district office to add the institution to the portal.';
    if (filtered && !items.length) inst.innerHTML = '<option value="">No institutions match - clear the type / category filter</option>';
  };
  fType.addEventListener('change', () => applyFilters());
  fCat.addEventListener('change', () => applyFilters());

  const load = async (selected) => {
    const id = district.value;
    inst.disabled = true;
    if (!id) {
      inst.innerHTML = '<option value="">Select a district first</option>';
      return;
    }
    inst.innerHTML = '<option value="">Loading institutions…</option>';
    try {
      const res = await fetch(`${district.dataset.listUrl}?list=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Could not load institutions');
      all = data.institutions || [];
      applyFilters(selected);
    } catch (e) {
      inst.innerHTML = '<option value="">Could not load institutions - please try again</option>';
    }
  };
  district.addEventListener('change', () => load(''));
  if (district.value) load(district.dataset.selected || '');

  // CAPTCHA refresh
  const img = document.getElementById('captcha-img');
  document.getElementById('captcha-refresh').addEventListener('click', (e) => {
    img.src = `${e.currentTarget.dataset.src}&t=${Date.now()}`;
    document.getElementById('captcha').value = '';
  });

  // Head and placement officer must have different email / phone
  const digits = (v) => v.replace(/\D/g, '').replace(/^(91|0)(?=\d{10}$)/, '');
  const warn = document.getElementById('same-contact-warning');
  const check = () => {
    const he = form.head_email.value.trim().toLowerCase();
    const pe = form.po_email.value.trim().toLowerCase();
    const hp = digits(form.head_phone.value);
    const pp = digits(form.po_phone.value);
    const same = (he && he === pe) || (hp && hp === pp);
    warn.classList.toggle('hidden', !same);
    return !same;
  };
  form.addEventListener('input', check);

  form.addEventListener('submit', (e) => {
    if (!check()) {
      e.preventDefault();
      warn.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    combo.input.setCustomValidity(inst.value ? '' : 'Select your institution from the list.');
    if (!form.checkValidity()) {
      e.preventDefault();
      form.reportValidity();
      return;
    }
    const btn = form.querySelector('button[class*="btn-gradient"]');
    if (btn) { btn.disabled = true; btn.classList.add('opacity-70'); }
  });
})();
