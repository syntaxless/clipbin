// Pull to refresh for when Pastey is opened from the Home Screen.
// iOS gives Home Screen apps no pull-to-refresh of their own, so this adds one:
// at the top of the page, drag down past the line and let go to reload.
// In a normal browser tab it does nothing (the browser already has its own).
(() => {
  const standalone = navigator.standalone === true
    || matchMedia('(display-mode: standalone)').matches;
  if (!standalone || !('ontouchstart' in window)) return;

  document.documentElement.classList.add('ptr-on');

  const THRESHOLD = 70; // px of pull (after resistance) that triggers a reload
  const MAX = 110;
  const ind = document.createElement('div');
  ind.className = 'ptr';
  ind.setAttribute('aria-hidden', 'true');
  ind.innerHTML = '<span class="ptr-icon"></span>';
  document.body.appendChild(ind);

  let startY = 0, startX = 0, pull = 0, tracking = false, decided = false, loading = false;

  const atTop = () => (window.scrollY || document.documentElement.scrollTop) <= 0;
  const skip = (t) => t.closest && t.closest('input, textarea, select, [contenteditable], .no-ptr');

  function show(px) {
    ind.style.transform = `translate(-50%, ${px - 50}px)`;
    ind.style.opacity = Math.min(1, px / THRESHOLD);
    ind.querySelector('.ptr-icon').style.transform = `rotate(${(px / THRESHOLD) * 270}deg)`;
    ind.classList.toggle('ready', px >= THRESHOLD);
  }
  function reset() {
    ind.classList.add('back');
    show(0);
    setTimeout(() => ind.classList.remove('back', 'ready'), 250);
  }

  window.addEventListener('touchstart', (e) => {
    if (loading || e.touches.length !== 1 || !atTop() || skip(e.target)) { tracking = false; return; }
    startY = e.touches[0].clientY;
    startX = e.touches[0].clientX;
    pull = 0; tracking = true; decided = false;
    ind.classList.remove('back');
  }, { passive: true });

  window.addEventListener('touchmove', (e) => {
    if (!tracking) return;
    const dy = e.touches[0].clientY - startY;
    const dx = e.touches[0].clientX - startX;
    if (!decided) {
      if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
      decided = true;
      // Only a downward, mostly vertical drag from the very top counts.
      if (dy <= 0 || Math.abs(dx) > Math.abs(dy) || !atTop()) { tracking = false; return; }
    }
    pull = Math.min(MAX, Math.max(0, dy) * 0.5);
    show(pull);
  }, { passive: true });

  function end() {
    if (!tracking) return;
    tracking = false;
    if (pull >= THRESHOLD && !window.pasteyUploading) {
      loading = true;
      ind.classList.add('back', 'loading');
      show(THRESHOLD);
      setTimeout(() => location.reload(), 150);
    } else {
      reset();
    }
  }
  window.addEventListener('touchend', end, { passive: true });
  window.addEventListener('touchcancel', () => { if (tracking) { tracking = false; reset(); } }, { passive: true });
  // Coming back to a page from the back/forward cache: clear the spinner.
  window.addEventListener('pageshow', (e) => {
    if (e.persisted) { loading = false; ind.classList.remove('loading'); reset(); }
  });
})();
