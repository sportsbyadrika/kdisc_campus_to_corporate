/* Campus to Corporate portal - shared UI behaviour (no framework). */
(function () {
  'use strict';

  // ---- Toast --------------------------------------------------------------
  window.showToast = function (msg) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    document.getElementById('toast-msg').innerText = msg;
    toast.classList.remove('translate-y-20', 'opacity-0');
    toast.classList.add('translate-y-0', 'opacity-100');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => {
      toast.classList.add('translate-y-20', 'opacity-0');
      toast.classList.remove('translate-y-0', 'opacity-100');
    }, 3200);
  };

  // ---- Dropdowns & mobile nav --------------------------------------------
  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-dropdown-toggle]');
    document.querySelectorAll('[data-dropdown]').forEach((dd) => {
      const menu = dd.querySelector('[data-dropdown-menu]');
      if (toggle && dd.contains(toggle)) {
        menu.classList.toggle('hidden');
      } else if (!dd.contains(e.target)) {
        menu.classList.add('hidden');
      }
    });
    if (e.target.closest('[data-nav-toggle]')) {
      const nav = document.getElementById('main-nav');
      nav.classList.toggle('hidden');
      nav.classList.toggle('flex');
    }
  });

  // ---- Confirmations: <form data-confirm="..."> / <button data-confirm> -----
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const btn = e.submitter;
    const msg = (btn && btn.dataset.confirm) || form.dataset.confirm;
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      return;
    }
    if (btn && !btn.hasAttribute('data-no-busy')) {
      setTimeout(() => { btn.disabled = true; btn.classList.add('opacity-70'); }, 0);
    }
  });

  // ---- Auto-dismiss flash messages ----------------------------------------
  document.querySelectorAll('[data-flash]').forEach((el) => {
    setTimeout(() => el.classList.add('opacity-0', 'transition-opacity', 'duration-500'), 6000);
    setTimeout(() => el.remove(), 6600);
  });

  // ---- Image preview: <input type=file data-preview="#img-id"> ------------
  document.querySelectorAll('input[type=file][data-preview]').forEach((input) => {
    input.addEventListener('change', () => {
      const img = document.querySelector(input.dataset.preview);
      const file = input.files && input.files[0];
      if (img && file) {
        img.src = URL.createObjectURL(file);
        img.classList.remove('hidden');
        const ph = document.querySelector(input.dataset.preview + '-placeholder');
        if (ph) ph.classList.add('hidden');
      }
    });
  });

  // ---- Clickable table rows: <tr data-href="..."> -------------------------
  document.querySelectorAll('tr[data-href]').forEach((tr) => {
    tr.classList.add('cursor-pointer');
    tr.addEventListener('click', (e) => {
      if (e.target.closest('a,button,input,select,form')) return;
      window.location = tr.dataset.href;
    });
  });

  // ---- Repeatable rows: departments table ---------------------------------
  const deptBody = document.getElementById('dept-table-body');
  if (deptBody) {
    const tpl = document.getElementById('dept-row-template');
    const recalc = () => {
      let finalYear = 0, total = 0;
      deptBody.querySelectorAll('tr').forEach((tr) => {
        finalYear += parseInt(tr.querySelector('[data-final]')?.value, 10) || 0;
        total += parseInt(tr.querySelector('[data-total]')?.value, 10) || 0;
      });
      document.getElementById('calc-total-final').innerText = finalYear.toLocaleString('en-IN');
      document.getElementById('calc-total-all').innerText = total.toLocaleString('en-IN');
    };
    document.getElementById('btn-add-dept')?.addEventListener('click', () => {
      deptBody.appendChild(tpl.content.cloneNode(true));
      recalc();
    });
    deptBody.addEventListener('click', (e) => {
      const rm = e.target.closest('[data-remove-row]');
      if (!rm) return;
      if (deptBody.querySelectorAll('tr').length <= 1) {
        showToast('At least one department is required.');
        return;
      }
      rm.closest('tr').remove();
      recalc();
    });
    deptBody.addEventListener('input', recalc);
    recalc();
  }

  // ---- Cohort live calculations (mirrors the onboarding design) ----------
  const cohortForm = document.getElementById('form-cohort');
  if (cohortForm) {
    const val = (id) => parseInt(document.getElementById(id)?.value, 10) || 0;
    const set = (id, text) => { const el = document.getElementById(id); if (el) el.innerText = text; };
    const update = () => {
      const total = val('cohort-total-final');
      const dwms = val('cohort-dwms-reg');
      const js = val('cohort-job-seekers');
      const dwmsPct = total > 0 ? Math.min(100, Math.round((dwms / total) * 100)) : 0;
      const jsPct = total > 0 ? Math.round((js / total) * 100) : 0;
      set('dwms-ratio-note', `DWMS Coverage: ${dwmsPct}% of total students`);
      set('jobseeker-ratio-note', `Primary Target: ${jsPct}% (${js} of ${total})`);
      const basis = js > 0 ? js : (total || 1);
      document.querySelectorAll('[data-assess-row]').forEach((row) => {
        const done = parseInt(row.querySelector('[data-done]')?.value, 10) || 0;
        const p = Math.min(100, Math.round((done / basis) * 100));
        row.querySelector('[data-pct]').innerText = `${p}%`;
        row.querySelector('[data-bar]').style.width = `${p}%`;
        const badge = row.querySelector('[data-badge]');
        if (badge) badge.innerText = `${done} / ${basis} Done`;
      });
    };
    cohortForm.addEventListener('input', update);
    update();
  }

  // ---- Service cards: reveal detail fields when ticked --------------------
  document.querySelectorAll('[data-service-card]').forEach((card) => {
    const cb = card.querySelector('input[type=checkbox]');
    const details = card.querySelector('[data-service-details]');
    const sync = () => {
      details.classList.toggle('hidden', !cb.checked);
      card.classList.toggle('border-sky-400', cb.checked);
      card.classList.toggle('bg-sky-50/40', cb.checked);
    };
    cb.addEventListener('change', sync);
    sync();
  });

  // ---- Role-dependent fields on the user form -----------------------------
  const roleSelect = document.getElementById('user-role');
  if (roleSelect) {
    const sync = () => {
      const r = roleSelect.value;
      document.querySelectorAll('[data-role-field]').forEach((el) => {
        const show = el.dataset.roleField.split(',').includes(r);
        el.classList.toggle('hidden', !show);
        el.querySelectorAll('select,input').forEach((i) => { i.disabled = !show; });
      });
    };
    roleSelect.addEventListener('change', sync);
    sync();
  }

  // ---- Institution checklist filter (user form) --------------------------
  const instFilter = document.getElementById('inst-filter');
  if (instFilter) {
    const distSel = document.getElementById('user-district');
    const apply = () => {
      const q = instFilter.value.toLowerCase();
      const d = distSel && !distSel.disabled ? distSel.value : '';
      document.querySelectorAll('[data-inst-option]').forEach((el) => {
        const okText = el.dataset.name.includes(q);
        const okDist = !d || el.dataset.district === d || el.querySelector('input').checked;
        el.classList.toggle('hidden', !(okText && okDist));
      });
    };
    instFilter.addEventListener('input', apply);
    distSel && distSel.addEventListener('change', apply);
    apply();
  }
})();

/* Tooltips inside <label>: keep a tap/click on the info icon from focusing the input instead. */
document.addEventListener('click', (e) => {
  const tip = e.target.closest('.tip');
  if (!tip) return;
  e.preventDefault();
  tip.focus();
});

/* Keep tooltip bubbles inside the viewport (layouts collapse to one column on phones). */
['mouseover', 'focusin'].forEach((evt) => document.addEventListener(evt, (e) => {
  const tip = e.target.closest && e.target.closest('.tip');
  if (!tip) return;
  const body = tip.querySelector('.tip-body');
  if (!body) return;
  body.style.transform = '';
  requestAnimationFrame(() => {
    const r = body.getBoundingClientRect();
    const vw = document.documentElement.clientWidth;
    let dx = 0;
    if (r.left < 8) dx = 8 - r.left;
    else if (r.right > vw - 8) dx = vw - 8 - r.right;
    if (dx) body.style.transform = `translateX(${Math.round(dx)}px)`;
  });
}));

/* Cohorts page: compare the field-entered completions with the vendor-reported count. */
(function () {
  const rows = document.querySelectorAll('[data-assess-row]');
  if (!rows.length) return;
  const sync = () => rows.forEach((row) => {
    const box = row.querySelector('[data-vendor]');
    const note = row.querySelector('[data-vendor-note]');
    if (!box || !note) return;
    const vendor = parseInt(box.dataset.vendor, 10) || 0;
    const done = parseInt(row.querySelector('[data-done]')?.value, 10) || 0;
    const diff = done - vendor;
    note.textContent = diff === 0 ? 'Matches the vendor count.'
      : `Field figure is ${Math.abs(diff)} ${diff > 0 ? 'above' : 'below'} the vendor count.`;
    note.className = 'hint ' + (diff === 0 ? 'text-emerald-600' : 'text-amber-600');
  });
  document.addEventListener('input', (e) => { if (e.target.matches('[data-done]')) sync(); });
  sync();
})();
