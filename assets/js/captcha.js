/* CAPTCHA refresh button for public forms (kept out of inline scripts for the CSP). */
(function () {
  const btn = document.getElementById('captcha-refresh');
  const img = document.getElementById('captcha-img');
  if (!btn || !img) return;
  btn.addEventListener('click', () => {
    img.src = `${btn.dataset.src}&t=${Date.now()}`;
    const input = document.getElementById('captcha');
    if (input) { input.value = ''; input.focus(); }
  });
})();
