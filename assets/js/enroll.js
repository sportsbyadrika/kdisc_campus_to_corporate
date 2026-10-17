/* Public "Enroll my Institution" form: district -> institution list, CAPTCHA refresh, client-side checks. */
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
    if (!form.checkValidity()) {
      e.preventDefault();
      form.reportValidity();
      return;
    }
    const btn = form.querySelector('button[class*="btn-gradient"]');
    if (btn) { btn.disabled = true; btn.classList.add('opacity-70'); }
  });
})();
