/* Online exam (participants): confirmations, countdown with auto-submit, copy/paste lock,
   tab-switch reporting and a guard against the browser Back button. No inline script (strict CSP). */
(function () {
  'use strict';

  document.addEventListener('submit', (e) => {
    const form = e.target;
    const msg = form.dataset.examConfirm;
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    const btn = e.submitter;
    if (btn) setTimeout(() => { btn.disabled = true; btn.classList.add('opacity-70'); }, 0);
  });

  const area = document.getElementById('exam-area');
  if (!area) return;

  // ---- Countdown: the server fixed the deadline; we count the seconds it reported ---------
  const form = document.getElementById('exam-answer-form');
  const clock = document.getElementById('exam-clock');
  const box = document.getElementById('exam-clock-box');
  const endsAt = Date.now() + (parseInt(area.dataset.remaining, 10) || 0) * 1000;
  let submitted = false;
  const tick = () => {
    const left = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
    const h = Math.floor(left / 3600);
    const m = Math.floor((left % 3600) / 60);
    const s = left % 60;
    clock.textContent = (h ? `${h}:${String(m).padStart(2, '0')}` : String(m).padStart(2, '0')) + `:${String(s).padStart(2, '0')}`;
    box.classList.toggle('border-rose-300', left <= 60);
    box.classList.toggle('bg-rose-50', left <= 60);
    clock.classList.toggle('text-rose-600', left <= 60);
    if (left === 0 && !submitted) {
      submitted = true;
      document.getElementById('exam-auto').value = '1';
      form.submit(); // time is up: send whatever is on screen
    }
  };
  tick();
  setInterval(tick, 1000);
  form.addEventListener('submit', () => { submitted = true; });

  // ---- No copy / cut / paste / drag / context menu inside the exam -----------------------
  // (capture phase, so no element can handle the event first)
  ['copy', 'cut', 'paste', 'contextmenu', 'drop', 'dragstart', 'beforeinput'].forEach((evt) => document.addEventListener(evt, (e) => {
    if (evt !== 'beforeinput' || ['insertFromPaste', 'insertFromDrop', 'insertFromPasteAsQuotation'].includes(e.inputType)) e.preventDefault();
  }, true));
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && ['c', 'x', 'v', 'a', 'p', 's', 'u'].includes(e.key.toLowerCase())) e.preventDefault();
    if (e.shiftKey && e.key === 'Insert') e.preventDefault();
  }, true);

  // ---- Report the exam tab losing focus (switching to another tab / app) -----------------
  let lastReport = 0;
  const report = () => {
    if (submitted || Date.now() - lastReport < 2000) return;
    lastReport = Date.now();
    const data = new FormData();
    data.append('action', 'focus');
    data.append('_csrf', area.dataset.csrf);
    if (navigator.sendBeacon) navigator.sendBeacon(area.dataset.focusUrl, data);
  };
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') report(); });
  window.addEventListener('blur', report);

  // ---- Back button: stay on the current question (the server never serves earlier ones) ---
  history.pushState(null, '', location.href);
  window.addEventListener('popstate', () => history.pushState(null, '', location.href));
})();
