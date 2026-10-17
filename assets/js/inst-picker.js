/* "Assigned institutions" picker on the user form: server-side filtered search (District,
   Institution Type, Management Type, name) instead of loading every institution with the page.
   Selected institutions are kept as chips holding hidden institutions[] inputs. */
(function () {
  'use strict';
  const box = document.getElementById('inst-picker');
  if (!box) return;

  const $ = (id) => document.getElementById(id);
  const district = $('pick-district');         // absent for district users (fixed to their district)
  const type = $('pick-type');
  const category = $('pick-category');
  const q = $('pick-q');
  const results = $('inst-results');
  const hint = $('inst-results-hint');
  const chips = $('inst-selected');
  const none = $('inst-none');
  const count = $('inst-selected-count');
  const userDistrict = $('user-district');     // the district chosen for the user being edited (admins)

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const selectedIds = () => new Set([...chips.querySelectorAll('[data-chip]')].map((c) => c.dataset.chip));
  const refreshCount = () => {
    const n = chips.querySelectorAll('[data-chip]').length;
    count.textContent = n;
    none.classList.toggle('hidden', n > 0);
  };

  const addChip = (it) => {
    if (selectedIds().has(String(it.id))) return;
    const chip = document.createElement('span');
    chip.className = 'inline-flex items-center gap-1.5 pl-2.5 pr-1 py-1 rounded-lg bg-sky-100 text-sky-800 text-xs font-medium';
    chip.dataset.chip = it.id;
    chip.innerHTML = `${esc(it.name)}${district ? ` <span class="text-sky-600/70">· ${esc(it.district)}</span>` : ''}`
      + `<input type="hidden" name="institutions[]" value="${esc(it.id)}">`
      + `<button type="button" class="p-0.5 rounded hover:bg-sky-200" data-chip-remove aria-label="Remove">✕</button>`;
    chips.insertBefore(chip, none);
    refreshCount();
  };
  const removeChip = (id) => {
    const chip = chips.querySelector(`[data-chip="${CSS.escape(String(id))}"]`);
    if (chip) chip.remove();
    const cb = results.querySelector(`input[value="${CSS.escape(String(id))}"]`);
    if (cb) cb.checked = false;
    refreshCount();
  };
  chips.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-chip-remove]');
    if (btn) removeChip(btn.closest('[data-chip]').dataset.chip);
  });

  let timer = null;
  let seq = 0;
  const search = async () => {
    const params = new URLSearchParams();
    if (district && district.value) params.set('district', district.value);
    if (type.value) params.set('type', type.value);
    if (category.value) params.set('category', category.value);
    if (q.value.trim()) params.set('q', q.value.trim());
    const mine = ++seq;
    hint.textContent = 'Searching…';
    try {
      const res = await fetch(`${box.dataset.searchUrl}&${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await res.json();
      if (mine !== seq) return; // a newer search is on its way
      const items = data.items || [];
      const sel = selectedIds();
      results.innerHTML = items.map((it) => `
        <label class="flex items-start gap-2 p-2 rounded-lg hover:bg-white text-sm cursor-pointer">
          <input type="checkbox" value="${esc(it.id)}" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600" ${sel.has(String(it.id)) ? 'checked' : ''}
                 data-name="${esc(it.name)}" data-district="${esc(it.district)}">
          <span>${esc(it.name)}<span class="block text-[11px] text-slate-400">${[district ? it.district : '', it.type, it.category].filter(Boolean).map(esc).join(' · ')}</span></span>
        </label>`).join('');
      hint.textContent = data.hint
        || (items.length ? `${data.total > items.length ? `Showing the first ${items.length} of ${data.total} - narrow the filters to see more. ` : `${items.length} institution(s) found. `}Tick to assign.`
          : 'No institutions match these filters.');
    } catch (e) {
      hint.textContent = 'Could not load institutions - please try again.';
    }
  };
  const later = () => { clearTimeout(timer); timer = setTimeout(search, 300); };

  results.addEventListener('change', (e) => {
    const cb = e.target;
    if (!cb.matches('input[type=checkbox]')) return;
    if (cb.checked) addChip({ id: cb.value, name: cb.dataset.name, district: cb.dataset.district });
    else removeChip(cb.value);
  });
  [district, type, category].forEach((el) => el && el.addEventListener('change', search));
  q.addEventListener('input', later);
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(); } });

  // Admins: the district chosen for the user pre-selects the district filter
  if (district && userDistrict) {
    userDistrict.addEventListener('change', () => {
      if (userDistrict.value) { district.value = userDistrict.value; search(); }
    });
    if (userDistrict.value) district.value = userDistrict.value;
  }
  refreshCount();
  if (!district || district.value) search(); // district users see their district straight away
})();
