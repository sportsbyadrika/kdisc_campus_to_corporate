/* OpenStreetMap (Leaflet) location picker for the institution profile. */
(function () {
  const el = document.getElementById('map');
  if (!el || typeof L === 'undefined') return;

  const latInput = document.getElementById('latitude');
  const lngInput = document.getElementById('longitude');
  const editable = el.dataset.editable === '1';
  const lat = parseFloat(el.dataset.lat);
  const lng = parseFloat(el.dataset.lng);
  const hasPoint = !isNaN(lat) && !isNaN(lng);
  const KERALA = [10.35, 76.35];

  const map = L.map(el, { scrollWheelZoom: false }).setView(hasPoint ? [lat, lng] : KERALA, hasPoint ? 15 : 7);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map);

  let marker = null;
  const place = (p, pan) => {
    if (!marker) {
      marker = L.marker(p, { draggable: editable }).addTo(map);
      marker.on('dragend', () => write(marker.getLatLng()));
    } else {
      marker.setLatLng(p);
    }
    if (pan) map.setView(p, Math.max(map.getZoom(), 15));
  };
  const write = (p) => {
    latInput.value = p.lat.toFixed(7);
    lngInput.value = p.lng.toFixed(7);
  };

  if (hasPoint) place([lat, lng], false);
  if (!editable) return;

  map.on('click', (e) => { place(e.latlng, false); write(e.latlng); });
  [latInput, lngInput].forEach((i) => i.addEventListener('change', () => {
    const a = parseFloat(latInput.value), b = parseFloat(lngInput.value);
    if (!isNaN(a) && !isNaN(b)) place([a, b], true);
  }));

  // Place search via OpenStreetMap Nominatim (limited to India, biased to Kerala)
  const q = document.getElementById('map-search');
  const btn = document.getElementById('map-search-btn');
  const results = document.getElementById('map-results');
  const search = async () => {
    const term = q.value.trim();
    if (term.length < 3) return;
    results.innerHTML = '<div class="p-3 text-slate-500">Searching…</div>';
    results.classList.remove('hidden');
    try {
      const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=6&countrycodes=in&viewbox=74.8,12.8,77.5,8.1&q='
        + encodeURIComponent(term + ', Kerala');
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      results.innerHTML = '';
      if (!data.length) {
        results.innerHTML = '<div class="p-3 text-slate-500">No places found. Try clicking on the map instead.</div>';
        return;
      }
      data.forEach((r) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'block w-full text-left p-2.5 hover:bg-sky-50';
        b.textContent = r.display_name;
        b.addEventListener('click', () => {
          const p = L.latLng(parseFloat(r.lat), parseFloat(r.lon));
          place(p, true); write(p);
          results.classList.add('hidden');
        });
        results.appendChild(b);
      });
    } catch (err) {
      results.innerHTML = '<div class="p-3 text-rose-600">Search is unavailable right now.</div>';
    }
  };
  btn.addEventListener('click', search);
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
})();
