// Pull to refresh for when Pastey is opened from the Home Screen.
// iOS gives Home Screen apps no pull-to-refresh of their own, so this adds one
// that behaves like Safari's: at the top of the page, drag down and the page
// follows your finger, with a spinner in the gap above it. Let go past the
// line to reload. In a normal browser tab it does nothing (Safari has its own).
(() => {
  const standalone = navigator.standalone === true
    || matchMedia('(display-mode: standalone)').matches;
  if (!standalone || !('ontouchstart' in window)) return;

  document.documentElement.classList.add('ptr-on');

  const THRESHOLD = 64; // how far the page must move down to reload
  const HOLD = 52;      // where the page rests while reloading
  const SPOKES = 8;

  // Safari-style spinner: spokes appear one by one as you pull.
  const ind = document.createElement('div');
  ind.className = 'ptr';
  ind.setAttribute('aria-hidden', 'true');
  let svg = '<svg viewBox="0 0 24 24" width="26" height="26">';
  for (let i = 0; i < SPOKES; i++) {
    svg += `<line x1="12" y1="3" x2="12" y2="7.5" transform="rotate(${i * 360 / SPOKES} 12 12)"/>`;
  }
  ind.innerHTML = svg + '</svg>';
  document.body.appendChild(ind);
  const lines = [...ind.querySelectorAll('line')];

  // Everything that moves down with your finger.
  const moving = () => [...document.body.children].filter((n) => n !== ind && n.tagName !== 'SCRIPT');

  let startY = 0, startX = 0, pull = 0, tracking = false, decided = false, loading = false;

  const atTop = () => (window.scrollY || document.documentElement.scrollTop) <= 0;
  const skip = (t) => t.closest && t.closest('input, textarea, select, [contenteditable], .no-ptr');
  // Rubber band: the further you drag, the less the page follows.
  const band = (d) => (1 - 1 / (d * 0.55 / 400 + 1)) * 400;

  function render(px, animate) {
    const els = moving();
    for (const el of els) {
      el.style.transition = animate ? 'transform .3s cubic-bezier(.2,.8,.2,1)' : 'none';
      el.style.transform = px ? `translateY(${px}px)` : '';
    }
    ind.style.transition = animate ? 'transform .3s cubic-bezier(.2,.8,.2,1), opacity .2s' : 'none';
    // Keep the spinner centred in the gap the page leaves behind.
    ind.style.transform = `translate(-50%, ${Math.max(0, px / 2 - 13)}px)`;
    ind.style.opacity = px > 8 ? 1 : 0;
    if (!loading) {
      const shown = Math.min(SPOKES, Math.ceil((px / THRESHOLD) * SPOKES));
      lines.forEach((l, i) => { l.style.opacity = i < shown ? '' : 0; });
    }
  }

  window.addEventListener('touchstart', (e) => {
    if (loading || e.touches.length !== 1 || !atTop() || skip(e.target)) { tracking = false; return; }
    startY = e.touches[0].clientY;
    startX = e.touches[0].clientX;
    pull = 0; tracking = true; decided = false;
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
    pull = band(Math.max(0, dy));
    render(pull, false);
  }, { passive: true });

  function end() {
    if (!tracking) return;
    tracking = false;
    if (pull >= THRESHOLD && !window.pasteyUploading) {
      loading = true;
      lines.forEach((l) => { l.style.opacity = ''; });
      ind.classList.add('loading');
      render(HOLD, true);
      setTimeout(() => location.reload(), 250);
    } else {
      render(0, true);
    }
  }
  window.addEventListener('touchend', end, { passive: true });
  window.addEventListener('touchcancel', () => { if (tracking) { tracking = false; render(0, true); } }, { passive: true });
  // Coming back to a page from the back/forward cache: put everything back.
  window.addEventListener('pageshow', (e) => {
    if (e.persisted) { loading = false; ind.classList.remove('loading'); render(0, false); }
  });
})();
