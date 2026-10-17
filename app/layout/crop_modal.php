<?php /* Square image crop dialog used with assets/js/photo-crop.js (officer photos, portal logo). */ ?>
<!-- Crop dialog -->
<div id="crop-modal" class="hidden fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="crop-title">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-5">
    <h3 id="crop-title" class="card-title mb-1">Crop photo</h3>
    <p class="card-subtitle mb-3">Drag to position, scroll or use the buttons to zoom.</p>
    <div class="h-80 bg-slate-100 rounded-xl overflow-hidden"><img id="crop-image" alt="" class="block max-w-full"></div>
    <div class="flex flex-wrap items-center justify-between gap-2 mt-4">
      <div class="flex gap-1.5">
        <button type="button" class="btn-secondary btn-xs" data-crop="zoom-in" title="Zoom in">＋</button>
        <button type="button" class="btn-secondary btn-xs" data-crop="zoom-out" title="Zoom out">－</button>
        <button type="button" class="btn-secondary btn-xs" data-crop="rotate" title="Rotate">⟳ Rotate</button>
      </div>
      <div class="flex gap-2">
        <button type="button" class="btn-secondary btn-xs" data-crop="cancel">Cancel</button>
        <button type="button" class="btn-primary btn-xs" data-crop="apply"><?= icon('check', 'w-3.5 h-3.5') ?> Use photo</button>
      </div>
    </div>
  </div>
</div>
