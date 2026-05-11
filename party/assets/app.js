/**
 * app.js — Party Photo Gallery guest page logic.
 *
 * Responsibilities:
 *  1. Camera / library input handling
 *  2. Inline photo preview
 *  3. Confirmed upload with XMLHttpRequest (for real progress events)
 *  4. Success / error UI states with auto-dismiss
 *  5. Public gallery loading and 30-second auto-refresh
 *  6. Lightbox
 */

(function () {
  'use strict';

  // ── Config injected by index.php ─────────────────────────────
  const { csrfToken, uploadUrl, galleryUrl, refreshMs } = window.PARTY_CONFIG;

  // ── Element refs ─────────────────────────────────────────────
  const cameraInput   = document.getElementById('camera-input');
  const libraryInput  = document.getElementById('library-input');
  const cameraLabel   = document.getElementById('camera-label');
  const uploadUI      = document.getElementById('upload-ui');
  const previewUI     = document.getElementById('preview-ui');
  const previewImg    = document.getElementById('preview-img');
  const btnUpload     = document.getElementById('btn-upload');
  const btnRetake     = document.getElementById('btn-retake');
  const progressWrap  = document.getElementById('progress-wrap');
  const progressFill  = document.getElementById('progress-fill');
  const successUI     = document.getElementById('success-ui');
  const errorUI       = document.getElementById('error-ui');
  const errorMsg      = document.getElementById('error-msg');
  const btnRetry      = document.getElementById('btn-retry');
  const countdown     = document.getElementById('countdown');
  const galleryGrid   = document.getElementById('gallery-grid');
  const galleryEmpty  = document.getElementById('gallery-empty');
  const lightbox      = document.getElementById('lightbox');
  const lightboxImg   = document.getElementById('lightbox-img');
  const lightboxClose = document.getElementById('lightbox-close');

  // Current selected File object
  let selectedFile = null;
  // Timer for success countdown
  let countdownInterval = null;

  // ── Name management ──────────────────────────────────────────
  const NAME_KEY   = 'party_uploader_name';
  const NAME_TS    = 'party_uploader_ts';
  const NAME_TTL   = 12 * 60 * 60 * 1000; // 12 hours in ms

  function storedName() {
    try {
      const ts = parseInt(localStorage.getItem(NAME_TS) || '0', 10);
      if (Date.now() - ts > NAME_TTL) return '';
      return localStorage.getItem(NAME_KEY) || '';
    } catch { return ''; }
  }

  function saveName(name) {
    try {
      localStorage.setItem(NAME_KEY, name);
      localStorage.setItem(NAME_TS,  String(Date.now()));
    } catch {}
  }

  let currentName = storedName();

  const nameModal      = document.getElementById('name-modal');
  const nameInput      = document.getElementById('name-input');
  const btnNameSubmit  = document.getElementById('btn-name-submit');
  const btnNameSkip    = document.getElementById('btn-name-skip');
  const nameByline     = document.getElementById('name-byline');
  const nameBylineText = document.getElementById('name-byline-text');
  const btnChangeName  = document.getElementById('btn-change-name');

  function updateByline() {
    if (currentName) {
      nameBylineText.textContent = currentName;
      nameByline.hidden = false;
    } else {
      nameByline.hidden = true;
    }
  }

  function openNameModal() {
    nameInput.value = currentName;
    nameModal.hidden = false;
    // Focus after transition so iOS keyboard doesn't immediately dismiss
    setTimeout(() => nameInput.focus(), 80);
  }

  function closeNameModal() {
    nameModal.hidden = true;
  }

  function submitName() {
    const name = nameInput.value.trim().substring(0, 50);
    currentName = name;
    if (name) saveName(name);
    closeNameModal();
    updateByline();
  }

  btnNameSubmit.addEventListener('click', submitName);
  btnNameSkip.addEventListener('click', () => { currentName = ''; closeNameModal(); updateByline(); });
  btnChangeName.addEventListener('click', openNameModal);
  nameInput.addEventListener('keydown', e => { if (e.key === 'Enter') submitName(); });

  // Show modal on first visit (after a short delay so page renders first)
  if (!currentName) {
    setTimeout(openNameModal, 350);
  }
  updateByline();

  // ── State management ─────────────────────────────────────────

  function showState(state) {
    // state: 'camera' | 'preview' | 'success' | 'error'
    uploadUI.hidden  = state !== 'camera';
    previewUI.hidden = state !== 'preview';
    successUI.hidden = state !== 'success';
    errorUI.hidden   = state !== 'error';
    progressWrap.hidden = true;
  }

  function resetToCamera() {
    clearCountdown();
    selectedFile = null;
    cameraInput.value  = '';
    libraryInput.value = '';
    showState('camera');
  }

  // ── File selection ───────────────────────────────────────────

  function onFileSelected(file) {
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      showError('Please select an image file.');
      return;
    }

    selectedFile = file;
    showState('preview');
    progressWrap.hidden = true;

    // Render inline preview via FileReader — no upload yet
    const reader = new FileReader();
    reader.onload = (e) => {
      previewImg.src = e.target.result;
    };
    reader.readAsDataURL(file);
  }

  cameraInput.addEventListener('change', () => {
    onFileSelected(cameraInput.files[0] || null);
  });

  libraryInput.addEventListener('change', () => {
    onFileSelected(libraryInput.files[0] || null);
  });

  // ── Retake button ─────────────────────────────────────────────
  // Clears the preview and re-triggers the camera input.
  // Note: we call .click() only inside a user-gesture handler,
  // which satisfies iOS Safari's requirement.
  btnRetake.addEventListener('click', () => {
    cameraInput.value = '';
    selectedFile = null;
    previewImg.src = '';
    showState('camera');
    // Brief delay so the DOM settles before triggering
    setTimeout(() => cameraInput.click(), 80);
  });

  // Retry button on error screen
  btnRetry.addEventListener('click', resetToCamera);

  // ── Upload ────────────────────────────────────────────────────

  btnUpload.addEventListener('click', () => {
    if (!selectedFile) return;
    startUpload(selectedFile);
  });

  function startUpload(file) {
    btnUpload.disabled = true;
    btnRetake.disabled = true;
    progressWrap.hidden = false;
    progressFill.style.width = '0%';

    const fd = new FormData();
    fd.append('photo',       file);
    fd.append('csrf_token',  csrfToken);
    fd.append('uploaded_by', currentName);

    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const pct = Math.round((e.loaded / e.total) * 100);
        progressFill.style.width = pct + '%';
      }
    });

    xhr.addEventListener('load', () => {
      btnUpload.disabled = false;
      btnRetake.disabled = false;

      let data;
      try {
        data = JSON.parse(xhr.responseText);
      } catch {
        showError('Unexpected server response. Please try again.');
        return;
      }

      if (xhr.status === 200 && data.ok) {
        showSuccess();
        // Trigger an immediate gallery refresh so the admin sees the new upload
        loadGallery();
      } else {
        showError(data.error || 'Upload failed. Please try again.');
      }
    });

    xhr.addEventListener('error', () => {
      btnUpload.disabled = false;
      btnRetake.disabled = false;
      showError('Network error. Check your connection and try again.');
    });

    xhr.addEventListener('abort', () => {
      btnUpload.disabled = false;
      btnRetake.disabled = false;
      showError('Upload was cancelled. Please try again.');
    });

    xhr.open('POST', uploadUrl, true);
    xhr.send(fd);
  }

  // ── Success state ─────────────────────────────────────────────

  function showSuccess() {
    showState('success');
    let secs = 4;
    countdown.textContent = secs;

    countdownInterval = setInterval(() => {
      secs--;
      countdown.textContent = secs;
      if (secs <= 0) resetToCamera();
    }, 1000);
  }

  function clearCountdown() {
    if (countdownInterval !== null) {
      clearInterval(countdownInterval);
      countdownInterval = null;
    }
  }

  // ── Error state ───────────────────────────────────────────────

  function showError(msg) {
    errorMsg.textContent = msg || 'Something went wrong. Please try again.';
    showState('error');
  }

  // ── Gallery loading ───────────────────────────────────────────

  let knownUuids = new Set(); // track loaded thumbs to avoid re-adding them

  function loadGallery() {
    fetch(galleryUrl, { cache: 'no-store' })
      .then(r => r.json())
      .then(data => renderGallery(data.photos || []))
      .catch(() => {
        // Silent failure on refresh — don't disrupt the UI
      });
  }

  function renderGallery(photos) {
    if (photos.length === 0) {
      if (knownUuids.size === 0) {
        // First load with no photos yet
        if (galleryEmpty) {
          galleryEmpty.textContent = 'No photos yet — be the first to add one! 🎉';
          galleryEmpty.hidden = false;
        }
      }
      return;
    }

    // Hide empty placeholder once we have at least one photo
    if (galleryEmpty) galleryEmpty.hidden = true;

    // Prepend new photos (they arrive newest-first from the API)
    photos.forEach(photo => {
      // Derive a stable ID from the thumb URL
      const uid = photo.thumb;
      if (knownUuids.has(uid)) return; // already displayed
      knownUuids.add(uid);

      const tile = document.createElement('div');
      tile.className = 'gallery-thumb fade-in';
      tile.setAttribute('role', 'listitem');
      tile.setAttribute('tabindex', '0');
      tile.setAttribute('aria-label', 'View photo');

      const img = document.createElement('img');
      img.src     = photo.thumb;
      img.alt     = 'Party photo';
      img.loading = 'lazy';
      img.decoding = 'async';

      tile.appendChild(img);
      tile.dataset.full = photo.full;

      // Open lightbox on click or Enter
      tile.addEventListener('click',   () => openLightbox(photo.full));
      tile.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openLightbox(photo.full);
        }
      });

      // Prepend so newest photos appear at the top
      galleryGrid.insertBefore(tile, galleryGrid.firstChild);
    });
  }

  // ── Lightbox ──────────────────────────────────────────────────

  function openLightbox(src) {
    lightboxImg.src = src;
    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
    lightboxClose.focus();
  }

  function closeLightbox() {
    lightbox.hidden = true;
    lightboxImg.src = '';
    document.body.style.overflow = '';
  }

  lightboxClose.addEventListener('click', closeLightbox);

  // Click the backdrop (not the image) to close
  lightbox.addEventListener('click', (e) => {
    if (e.target === lightbox) closeLightbox();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !lightbox.hidden) closeLightbox();
  });

  // ── Init ──────────────────────────────────────────────────────

  showState('camera');
  loadGallery();
  setInterval(loadGallery, refreshMs);

})();
