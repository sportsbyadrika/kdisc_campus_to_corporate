/* Officer photo / portal logo: choose -> crop to a square (Cropper.js) -> sent as a JPEG data URL in #photo-data.
   If Cropper.js could not load, the chosen file is uploaded as-is and cropped to a square on the server. */
(function () {
  'use strict';
  const form = document.querySelector('[data-photo-form]');
  if (!form) return;

  const fileInput = document.getElementById('photo-file');
  const dataInput = document.getElementById('photo-data');
  const preview = document.getElementById('photo-preview');
  const placeholder = document.getElementById('photo-placeholder');
  const modal = document.getElementById('crop-modal');
  const cropImg = document.getElementById('crop-image');
  let cropper = null;
  let objectUrl = null;

  const showPreview = (src) => {
    preview.src = src;
    preview.classList.remove('hidden');
    placeholder.classList.add('hidden');
    const remove = form.querySelector('input[name="remove_photo"]');
    if (remove) remove.checked = false;
  };
  const closeModal = () => {
    if (cropper) { cropper.destroy(); cropper = null; }
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    modal.classList.add('hidden');
  };

  form.querySelector('[data-photo-choose]').addEventListener('click', () => fileInput.click());

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      window.showToast && showToast('Please choose a JPG, PNG or WEBP image.');
      fileInput.value = '';
      return;
    }
    if (file.size > 8 * 1024 * 1024) {
      window.showToast && showToast('The image is larger than 8 MB. Please choose a smaller photo.');
      fileInput.value = '';
      return;
    }
    objectUrl = URL.createObjectURL(file);
    if (typeof window.Cropper === 'undefined') {
      // Fallback: plain upload (the server crops it to a centred square)
      if (file.size > 2 * 1024 * 1024) {
        window.showToast && showToast('Without the crop tool the photo must be under 2 MB.');
        fileInput.value = '';
        return;
      }
      dataInput.value = '';
      showPreview(objectUrl);
      return;
    }
    cropImg.src = objectUrl;
    modal.classList.remove('hidden');
    cropImg.onload = () => {
      cropper = new window.Cropper(cropImg, {
        aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 0.9,
        background: false, responsive: true, guides: true, movable: true, zoomable: true,
      });
    };
  });

  modal.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-crop]');
    if (!btn) {
      if (e.target === modal) { fileInput.value = ''; closeModal(); }
      return;
    }
    const act = btn.dataset.crop;
    if (act === 'zoom-in' && cropper) cropper.zoom(0.1);
    if (act === 'zoom-out' && cropper) cropper.zoom(-0.1);
    if (act === 'rotate' && cropper) cropper.rotate(90);
    if (act === 'cancel') { fileInput.value = ''; closeModal(); }
    if (act === 'apply' && cropper) {
      const size = parseInt(form.dataset.cropSize || '400', 10);
      const type = form.dataset.cropType || 'image/jpeg'; // image/png keeps transparency (logos)
      const canvas = cropper.getCroppedCanvas({ width: size, height: size, imageSmoothingQuality: 'high', ...(type === 'image/png' ? {} : { fillColor: '#fff' }) });
      const url = type === 'image/png' ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', 0.9);
      dataInput.value = url;
      fileInput.value = ''; // the cropped version is sent instead of the original file
      showPreview(url);
      closeModal();
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) { fileInput.value = ''; closeModal(); }
  });
})();
