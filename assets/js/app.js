/* ============================================================
   VeriLens — AI Media Detector · Frontend Logic
   ============================================================ */

(function () {
  'use strict';

  /* ── DOM references ─────────────────────────────────────── */
  const dropZone        = document.getElementById('dropZone');
  const fileInput       = document.getElementById('fileInput');
  const uploadForm      = document.getElementById('uploadForm');
  const urlForm         = document.getElementById('urlForm');
  const urlInput        = document.getElementById('urlInput');
  const urlPreviewBtn   = document.getElementById('urlPreviewBtn');
  const urlPreviewWrap  = document.getElementById('urlPreviewWrap');
  const urlPreviewImg   = document.getElementById('urlPreviewImg');
  const filePreviewWrap = document.getElementById('filePreviewWrap');
  const filePreviewImg  = document.getElementById('filePreviewImg');
  const fileRemoveBtn   = document.getElementById('fileRemoveBtn');
  const loadingOverlay  = document.getElementById('loadingOverlay');
  const resultsPanel    = document.getElementById('resultsPanel');
  const analyzeBtn      = document.getElementById('analyzeBtn');
  const analyzeUrlBtn   = document.getElementById('analyzeUrlBtn');
  const anotherBtn      = document.getElementById('analyzeAnotherBtn');

  /* ── Drop-zone interactions ─────────────────────────────── */
  if (dropZone) {
    dropZone.addEventListener('click', () => fileInput && fileInput.click());

    dropZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropZone.classList.add('drag-over');
    });

    dropZone.addEventListener('dragleave', () => {
      dropZone.classList.remove('drag-over');
    });

    dropZone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZone.classList.remove('drag-over');
      const file = e.dataTransfer.files[0];
      if (file) handleFileSelected(file);
    });
  }

  /* ── File input change ──────────────────────────────────── */
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      if (fileInput.files[0]) handleFileSelected(fileInput.files[0]);
    });
  }

  function handleFileSelected(file) {
    const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif',
                     'video/mp4', 'video/mpeg', 'video/quicktime', 'video/webm'];
    if (!allowed.includes(file.type)) {
      showToast('Unsupported file type. Please upload an image (JPEG, PNG, WebP, GIF).', 'error');
      return;
    }

    // Transfer to real input if it came from drop
    if (fileInput && fileInput.files[0] !== file) {
      const dt = new DataTransfer();
      dt.items.add(file);
      fileInput.files = dt.files;
    }

    if (file.type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = (e) => {
        if (filePreviewImg)  filePreviewImg.src = e.target.result;
        if (filePreviewWrap) filePreviewWrap.classList.remove('d-none');
        if (dropZone)        dropZone.style.display = 'none';
      };
      reader.readAsDataURL(file);
    } else {
      // Video — show placeholder
      if (filePreviewImg)  filePreviewImg.src = '';
      if (filePreviewWrap) filePreviewWrap.classList.remove('d-none');
      if (dropZone)        dropZone.style.display = 'none';
    }

    if (analyzeBtn) analyzeBtn.disabled = false;
  }

  /* ── Remove selected file ───────────────────────────────── */
  if (fileRemoveBtn) {
    fileRemoveBtn.addEventListener('click', () => {
      if (fileInput)       fileInput.value = '';
      if (filePreviewWrap) filePreviewWrap.classList.add('d-none');
      if (dropZone)        dropZone.style.display = '';
      if (analyzeBtn)      analyzeBtn.disabled = true;
    });
  }

  /* ── URL preview ────────────────────────────────────────── */
  if (urlPreviewBtn) {
    urlPreviewBtn.addEventListener('click', loadUrlPreview);
  }

  if (urlInput) {
    urlInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); loadUrlPreview(); }
    });
  }

  function loadUrlPreview() {
    const url = urlInput ? urlInput.value.trim() : '';
    if (!url) { showToast('Please enter an image URL.', 'error'); return; }

    if (urlPreviewImg) {
      urlPreviewImg.src = url;
      urlPreviewImg.onerror = () => {
        showToast('Could not load image from that URL. Please check the URL.', 'error');
        if (urlPreviewWrap) urlPreviewWrap.classList.add('d-none');
        if (analyzeUrlBtn)  analyzeUrlBtn.disabled = true;
      };
      urlPreviewImg.onload = () => {
        if (urlPreviewWrap) urlPreviewWrap.classList.remove('d-none');
        if (analyzeUrlBtn)  analyzeUrlBtn.disabled = false;
      };
    }
  }

  /* ── Form submission with loading overlay ───────────────── */
  function attachFormSubmit(form) {
    if (!form) return;
    form.addEventListener('submit', (e) => {
      showLoadingOverlay();
    });
  }

  attachFormSubmit(uploadForm);
  attachFormSubmit(urlForm);

  /* ── Results panel animation ────────────────────────────── */
  if (resultsPanel) {
    // Trigger animation after slight delay so CSS transition fires
    setTimeout(() => {
      resultsPanel.classList.add('visible');
      animateConfidenceBar();
    }, 80);
  }

  function animateConfidenceBar() {
    const fill       = document.querySelector('.confidence-fill');
    const targetAttr = fill ? fill.getAttribute('data-target') : null;
    if (!fill || !targetAttr) return;
    setTimeout(() => {
      fill.style.width = targetAttr + '%';
    }, 150);
  }

  /* ── "Analyze Another" ──────────────────────────────────── */
  if (anotherBtn) {
    anotherBtn.addEventListener('click', () => {
      window.location.href = 'index.php';
    });
  }

  /* ── Loading overlay helpers ────────────────────────────── */
  function showLoadingOverlay() {
    if (loadingOverlay) loadingOverlay.classList.add('show');
  }

  function hideLoadingOverlay() {
    if (loadingOverlay) loadingOverlay.classList.remove('show');
  }

  /* ── History filter buttons ─────────────────────────────── */
  const filterBtns = document.querySelectorAll('.filter-btn[data-filter]');
  filterBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      filterBtns.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');

      const filter = btn.getAttribute('data-filter');
      const rows   = document.querySelectorAll('.history-row');
      rows.forEach((row) => {
        const verdict = row.getAttribute('data-verdict') || '';
        if (filter === 'all' || verdict === filter) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });
  });

  /* ── Delete confirmation ────────────────────────────────── */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-delete-id]');
    if (!btn) return;
    const id = btn.getAttribute('data-delete-id');
    if (confirm('Delete this scan record? This cannot be undone.')) {
      window.location.href = 'history.php?delete=' + encodeURIComponent(id);
    }
  });

  /* ── Clear all history confirmation ─────────────────────── */
  const clearAllBtn = document.getElementById('clearAllBtn');
  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', () => {
      if (confirm('Clear ALL scan history? This cannot be undone.')) {
        window.location.href = 'history.php?clear=1';
      }
    });
  }

  /* ── Toast notification ─────────────────────────────────── */
  function showToast(message, type = 'info') {
    const existing = document.getElementById('vl-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'vl-toast';
    toast.style.cssText = `
      position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 99999;
      padding: .75rem 1.2rem; border-radius: .5rem; font-size: .875rem;
      max-width: 340px; display: flex; align-items: center; gap: .5rem;
      animation: slideUp .25s ease; box-shadow: 0 4px 20px rgba(0,0,0,.5);
    `;

    if (type === 'error') {
      toast.style.background = 'rgba(248,81,73,.15)';
      toast.style.border     = '1px solid rgba(248,81,73,.35)';
      toast.style.color      = '#ff7b72';
      toast.innerHTML        = '<i class="fas fa-exclamation-circle"></i> ' + message;
    } else {
      toast.style.background = 'rgba(88,166,255,.12)';
      toast.style.border     = '1px solid rgba(88,166,255,.3)';
      toast.style.color      = '#58a6ff';
      toast.innerHTML        = '<i class="fas fa-info-circle"></i> ' + message;
    }

    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
  }

  /* ── Inject keyframe for toast ──────────────────────────── */
  const style = document.createElement('style');
  style.textContent = `@keyframes slideUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }`;
  document.head.appendChild(style);

})();
