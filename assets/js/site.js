/* Public site menu: underline the item the visitor selected, and follow the section in view
   while scrolling the home page. Items that open other pages keep the server's choice. */
(function () {
  'use strict';
  const links = document.querySelectorAll('[data-nav]');
  const sections = document.querySelectorAll('[data-spy]');
  const setActive = (key) => links.forEach((a) => {
    const on = a.dataset.nav === key;
    a.toggleAttribute('data-active', on);
    if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });

  let clickedAt = 0;
  links.forEach((a) => a.addEventListener('click', () => {
    clickedAt = Date.now();
    setActive(a.dataset.nav);
    const menu = a.closest('details');
    if (menu) menu.open = false;
  }));
  if (!document.querySelector('[data-spy="home"]')) return; // other pages: keep the server's choice

  const map = { top: 'home', about: 'about', journey: 'how', updates: 'resources', faqs: 'faqs', contact: 'contact' };
  if (map[location.hash.slice(1)]) setActive(map[location.hash.slice(1)]);

  // The section in view = the last one whose top has passed just below the sticky header
  const spy = () => {
    if (Date.now() - clickedAt < 900) return; // let the smooth scroll after a click finish first
    const doc = document.documentElement;
    if (window.innerHeight + window.scrollY >= doc.scrollHeight - 4) { setActive('contact'); return; }
    let current = 'home';
    sections.forEach((sec) => { if (sec.getBoundingClientRect().top <= 140) current = sec.dataset.spy; });
    setActive(current);
  };
  let ticking = false;
  window.addEventListener('scroll', () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => { ticking = false; spy(); });
  }, { passive: true });
  window.addEventListener('scrollend', spy);
})();
