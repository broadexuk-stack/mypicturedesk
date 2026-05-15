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
  const { csrfToken, partySlug, uploadUrl, galleryUrl, refreshMs } = window.PARTY_CONFIG;

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
  const lightboxPrev  = document.getElementById('lightbox-prev');
  const lightboxNext  = document.getElementById('lightbox-next');
  const viewfinderUI        = document.getElementById('viewfinder-ui');
  const viewfinderVideo     = document.getElementById('viewfinder-video');
  const timerOverlay        = document.getElementById('timer-overlay');
  const btnTimerCamera      = document.getElementById('btn-timer-camera');
  const btnTimerStart       = document.getElementById('btn-timer-start');
  const btnFlipCamera       = document.getElementById('btn-flip-camera');
  const btnViewfinderCancel = document.getElementById('btn-viewfinder-cancel');

  // Current selected File object
  let selectedFile = null;
  // Timer for success countdown
  let countdownInterval = null;
  // Paused-state tracking
  let isPaused      = false;
  let currentState  = 'camera';
  // Track whether the current preview came from the timer selfie camera
  let lastWasTimerSelfie = false;

  const pausedModal    = document.getElementById('paused-modal');
  const pausedModalMsg = document.getElementById('paused-modal-msg');

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

  // ── Privacy modal ─────────────────────────────────────────────
  const privacyModal    = document.getElementById('privacy-modal');
  const btnPrivacy      = document.getElementById('btn-privacy');
  const btnPrivacyClose = document.getElementById('btn-privacy-close');

  btnPrivacy.addEventListener('click', () => { privacyModal.hidden = false; });
  btnPrivacyClose.addEventListener('click', () => { privacyModal.hidden = true; });
  privacyModal.addEventListener('click', (e) => { if (e.target === privacyModal) privacyModal.hidden = true; });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !privacyModal.hidden) privacyModal.hidden = true;
  });

  // Show modal on first visit (after a short delay so page renders first)
  if (!currentName) {
    setTimeout(openNameModal, 350);
  }
  updateByline();

  // ── State management ─────────────────────────────────────────

  function showState(state) {
    // state: 'camera' | 'preview' | 'success' | 'error' | 'viewfinder'
    currentState = state;
    uploadUI.hidden  = state !== 'camera';
    previewUI.hidden = state !== 'preview';
    successUI.hidden = state !== 'success';
    errorUI.hidden   = state !== 'error';
    if (viewfinderUI) viewfinderUI.hidden = state !== 'viewfinder';
    progressWrap.hidden = true;

    // Keep upload button disabled behind the overlay when paused
    if (isPaused && state === 'preview') btnUpload.disabled = true;
  }

  function showPaused(organiserName) {
    if (isPaused) return;
    isPaused = true;
    stopCameraStream();
    clearTimerTick();

    cameraInput.disabled  = true;
    libraryInput.disabled = true;
    if (currentState === 'preview') {
      btnUpload.disabled  = true;
      progressWrap.hidden = true;
    }

    const who = organiserName || window.PARTY_CONFIG.organiserName || '';
    if (currentState === 'preview') {
      pausedModalMsg.textContent = who
        ? 'Your photo is safe — the gallery has been paused by ' + who + '. It will upload as soon as the gallery reopens.'
        : 'Your photo is safe — the gallery has been paused. It will upload as soon as the gallery reopens.';
    } else {
      pausedModalMsg.textContent = who
        ? 'The gallery has been paused by ' + who + '.'
        : 'The gallery has been paused.';
    }

    pausedModal.hidden = false;
  }

  function hidePaused() {
    if (!isPaused) return;
    isPaused = false;

    cameraInput.disabled  = false;
    libraryInput.disabled = false;
    if (currentState === 'preview') btnUpload.disabled = false;

    pausedModal.hidden = true;
  }

  function resetToCamera() {
    clearCountdown();
    stopCameraStream();
    clearTimerTick();
    selectedFile = null;
    lastWasTimerSelfie = false;
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
    lastWasTimerSelfie = false;
    onFileSelected(cameraInput.files[0] || null);
  });

  libraryInput.addEventListener('change', () => {
    lastWasTimerSelfie = false;
    onFileSelected(libraryInput.files[0] || null);
  });

  // ── Retake button ─────────────────────────────────────────────
  // Clears the preview and re-triggers the camera input.
  // Note: we call .click() only inside a user-gesture handler,
  // which satisfies iOS Safari's requirement.
  btnRetake.addEventListener('click', () => {
    selectedFile = null;
    previewImg.src = '';
    if (lastWasTimerSelfie) {
      startViewfinder();
    } else {
      cameraInput.value = '';
      showState('camera');
      setTimeout(() => cameraInput.click(), 80);
    }
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
    fd.append('party_slug',  partySlug);
    fd.append('uploaded_by', currentName);

    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const pct = Math.round((e.loaded / e.total) * 100);
        progressFill.style.width = pct + '%';
      }
    });

    xhr.addEventListener('load', () => {
      btnRetake.disabled = false;
      if (!isPaused) btnUpload.disabled = false;

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
      } else if (data.party_paused) {
        progressWrap.hidden = true;
        showPaused(data.organiser_name || '');
      } else {
        showError(data.error || 'Upload failed. Please try again.');
      }
    });

    xhr.addEventListener('error', () => {
      btnRetake.disabled = false;
      if (!isPaused) btnUpload.disabled = false;
      showError('Network error. Check your connection and try again.');
    });

    xhr.addEventListener('abort', () => {
      btnRetake.disabled = false;
      if (!isPaused) btnUpload.disabled = false;
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
      .then(data => renderGallery(data))
      .catch(() => {
        // Silent failure on refresh — don't disrupt the UI
      });
  }

  function renderGallery(data) {
    if (data.active === false) {
      showPaused(data.organiser_name || '');
      return;
    }
    if (isPaused) hidePaused();

    const photos = data.photos || [];

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

    // Reverse so that prepending in a loop ends with the newest at the top
    photos.slice().reverse().forEach(photo => {
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

  let lbPhotos = [];
  let lbIdx    = 0;

  function lbGetPhotos() {
    return [...galleryGrid.querySelectorAll('.gallery-thumb')].map(t => t.dataset.full);
  }

  function lbShow() {
    lightboxImg.src          = lbPhotos[lbIdx] || '';
    lightboxPrev.disabled    = lbIdx === 0;
    lightboxNext.disabled    = lbIdx === lbPhotos.length - 1;
  }

  function openLightbox(src) {
    lbPhotos = lbGetPhotos();
    lbIdx    = lbPhotos.indexOf(src);
    if (lbIdx === -1) lbIdx = 0;
    lbShow();
    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
    lightboxClose.focus();
  }

  function closeLightbox() {
    lightbox.hidden = true;
    lightboxImg.src = '';
    document.body.style.overflow = '';
  }

  function lbPrev() { if (lbIdx > 0)                    { lbIdx--; lbShow(); } }
  function lbNext() { if (lbIdx < lbPhotos.length - 1)  { lbIdx++; lbShow(); } }

  lightboxPrev.addEventListener('click', lbPrev);
  lightboxNext.addEventListener('click', lbNext);
  lightboxClose.addEventListener('click', closeLightbox);

  // Click the backdrop (not the image or buttons) to close
  lightbox.addEventListener('click', (e) => {
    if (e.target === lightbox) closeLightbox();
  });

  document.addEventListener('keydown', (e) => {
    if (lightbox.hidden) return;
    if (e.key === 'Escape')     closeLightbox();
    if (e.key === 'ArrowLeft')  lbPrev();
    if (e.key === 'ArrowRight') lbNext();
  });

  // Swipe detection
  let swipeStartX = 0;
  lightbox.addEventListener('touchstart', (e) => {
    swipeStartX = e.changedTouches[0].clientX;
  }, { passive: true });
  lightbox.addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - swipeStartX;
    if (Math.abs(dx) < 40) return; // too short to count
    if (dx < 0) lbNext(); else lbPrev();
  }, { passive: true });

  // ── Timer selfie camera ──────────────────────────────────────

  // Audio (Web Audio API — lazy init inside user gesture)
  let audioCtx = null;

  function ensureAudio() {
    if (audioCtx) { if (audioCtx.state === 'suspended') audioCtx.resume(); return; }
    try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
  }

  function playTone(freqStart, freqEnd, duration, gainVal) {
    if (!audioCtx) return;
    try {
      const osc  = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freqStart, audioCtx.currentTime);
      if (freqEnd !== freqStart) osc.frequency.exponentialRampToValueAtTime(freqEnd, audioCtx.currentTime + duration);
      gain.gain.setValueAtTime(gainVal, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
      osc.start(audioCtx.currentTime);
      osc.stop(audioCtx.currentTime + duration + 0.01);
    } catch (e) {}
  }

  function playPip()     { playTone(880,  880,  0.08, 0.25); }
  function playShutter() { playTone(1760, 1760, 0.24, 0.35); }

  // Flash — front camera only: dynamically created full-screen white overlay
  function triggerFlash() {
    if (facingMode !== 'user') return;
    const el = document.createElement('div');
    el.style.position   = 'fixed';
    el.style.top        = '0';
    el.style.left       = '0';
    el.style.width      = '100%';
    el.style.height     = '100%';
    el.style.background = '#ffffff';
    el.style.opacity    = '1';
    el.style.zIndex     = '99999';
    el.style.pointerEvents = 'none';
    document.body.appendChild(el);
    setTimeout(() => {
      el.style.transition = 'opacity 0.3s ease-out';
      el.style.opacity = '0';
      setTimeout(() => { if (el.parentNode) el.parentNode.removeChild(el); }, 350);
    }, 1000);
  }

  let cameraStream = null;
  let facingMode   = 'user'; // front camera default for selfies
  let timerTick    = null;

  function stopCameraStream() {
    if (cameraStream) {
      cameraStream.getTracks().forEach(t => t.stop());
      cameraStream = null;
    }
    if (viewfinderVideo) viewfinderVideo.srcObject = null;
  }

  function clearTimerTick() {
    if (timerTick) { clearInterval(timerTick); timerTick = null; }
    if (timerOverlay) timerOverlay.hidden = true;
    if (btnTimerStart) btnTimerStart.disabled = false;
    if (btnFlipCamera) btnFlipCamera.disabled = false;
  }

  async function startViewfinder() {
    try {
      cameraStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode, width: { ideal: 1920 }, height: { ideal: 1080 } },
        audio: false
      });
      viewfinderVideo.srcObject = cameraStream;
      if (facingMode === 'user') {
        viewfinderVideo.classList.remove('rear');
      } else {
        viewfinderVideo.classList.add('rear');
      }
      showState('viewfinder');
    } catch (err) {
      showError('Camera access was denied. Please allow camera permission and try again.');
    }
  }

  function captureFrame() {
    const canvas = document.createElement('canvas');
    canvas.width  = viewfinderVideo.videoWidth;
    canvas.height = viewfinderVideo.videoHeight;
    const ctx = canvas.getContext('2d');
    if (facingMode === 'user') {
      ctx.translate(canvas.width, 0);
      ctx.scale(-1, 1);
    }
    ctx.drawImage(viewfinderVideo, 0, 0);
    stopCameraStream();
    lastWasTimerSelfie = true;
    canvas.toBlob(blob => {
      const file = new File([blob], 'selfie.jpg', { type: 'image/jpeg' });
      onFileSelected(file);
    }, 'image/jpeg', 0.92);
  }

  function startTimer() {
    if (timerTick) return;
    ensureAudio();
    btnTimerStart.disabled = true;
    btnFlipCamera.disabled = true;
    let secs = 3;
    timerOverlay.textContent = secs;
    timerOverlay.hidden = false;
    playPip();
    timerTick = setInterval(() => {
      secs--;
      if (secs > 0) {
        timerOverlay.textContent = secs;
        playPip();
      } else {
        clearTimerTick();
        triggerFlash();
        playShutter();
        setTimeout(captureFrame, 80); // brief yield so white paints before canvas work
      }
    }, 1000);
  }

  // Only show timer button if feature enabled and getUserMedia available
  if (window.PARTY_CONFIG.timerCamera &&
      navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    if (btnTimerCamera) btnTimerCamera.hidden = false;
  }

  if (btnTimerCamera)      btnTimerCamera.addEventListener('click', () => { facingMode = 'user'; startViewfinder(); });
  if (btnTimerStart)       btnTimerStart.addEventListener('click', startTimer);
  if (btnFlipCamera)       btnFlipCamera.addEventListener('click', async () => { clearTimerTick(); stopCameraStream(); facingMode = facingMode === 'user' ? 'environment' : 'user'; await startViewfinder(); });
  if (btnViewfinderCancel) btnViewfinderCancel.addEventListener('click', () => { clearTimerTick(); stopCameraStream(); resetToCamera(); });

  // ── Init ──────────────────────────────────────────────────────

  showState('camera');
  loadGallery();
  setInterval(loadGallery, refreshMs);

  // Poll immediately when the tab becomes visible again (iOS throttles
  // setInterval while the screen is off or another app is in front)
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadGallery();
  });

})();
