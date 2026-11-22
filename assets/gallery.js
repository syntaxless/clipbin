// Gallery page: tap a photo to see it full screen. Swipe or use the arrow
// keys to move between photos, swipe down or tap X to close. Without
// JavaScript each photo still links to its own page.
(() => {
  const grid = document.getElementById('gallery');
  if (!grid) return;
  const links = [...grid.querySelectorAll('a[data-full]')];
  let index = -1;

  const box = document.createElement('div');
  box.className = 'lightbox';
  box.hidden = true;
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-modal', 'true');
  box.setAttribute('aria-label', 'Photo viewer');
  box.innerHTML = `
    <div class="lb-stage"><img class="lb-img" alt=""></div>
    <div class="lb-bar">
      <span class="lb-count"></span>
      <span class="lb-tools">
        <a class="lb-btn lb-page" aria-label="Open this photo's page">Link</a>
        <a class="lb-btn lb-dl" aria-label="Download this photo">Save</a>
        <button class="lb-btn lb-close" type="button" aria-label="Close">✕</button>
      </span>
    </div>
    <button class="lb-nav lb-prev" type="button" aria-label="Previous photo">‹</button>
    <button class="lb-nav lb-next" type="button" aria-label="Next photo">›</button>`;
  document.body.appendChild(box);
  const img = box.querySelector('.lb-img');
  const stage = box.querySelector('.lb-stage');
  const count = box.querySelector('.lb-count');
  const dl = box.querySelector('.lb-dl');
  const pageLink = box.querySelector('.lb-page');
  const prev = box.querySelector('.lb-prev');
  const next = box.querySelector('.lb-next');

  const preload = (i) => { if (links[i]) new Image().src = links[i].dataset.full; };

  function show(i) {
    index = (i + links.length) % links.length;
    const a = links[index];
    img.src = a.dataset.full;
    img.alt = `Photo ${index + 1}`;
    count.textContent = `${index + 1} / ${links.length}`;
    dl.href = a.dataset.full;
    dl.setAttribute('download', a.dataset.name || '');
    pageLink.href = a.href;
    prev.hidden = next.hidden = links.length < 2;
    preload(index + 1);
    preload(index - 1);
  }

  function open(i) {
    show(i);
    box.hidden = false;
    document.documentElement.classList.add('lb-open');
    history.pushState({ lightbox: true }, '');
    box.querySelector('.lb-close').focus({ preventScroll: true });
  }

  function close(fromHistory) {
    if (box.hidden) return;
    box.hidden = true;
    document.documentElement.classList.remove('lb-open');
    img.removeAttribute('src');
    links[index]?.focus({ preventScroll: true });
    if (!fromHistory && history.state?.lightbox) history.back();
  }

  grid.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-full]');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    open(links.indexOf(a));
  });
  // The back button (or back swipe) closes the viewer instead of leaving the page.
  window.addEventListener('popstate', () => close(true));
  box.querySelector('.lb-close').addEventListener('click', () => close());
  prev.addEventListener('click', () => show(index - 1));
  next.addEventListener('click', () => show(index + 1));
  stage.addEventListener('click', (e) => { if (e.target === stage) close(); });
  document.addEventListener('keydown', (e) => {
    if (box.hidden) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowLeft') show(index - 1);
    else if (e.key === 'ArrowRight') show(index + 1);
  });

  // Swipe left/right for the next or previous photo, down to close. A pinch
  // (two fingers) is left to the browser so you can zoom in.
  let sx = 0, sy = 0, dx = 0, dy = 0, tracking = false;
  stage.addEventListener('touchstart', (e) => {
    tracking = e.touches.length === 1 && (!window.visualViewport || visualViewport.scale <= 1.01);
    if (!tracking) return;
    sx = e.touches[0].clientX; sy = e.touches[0].clientY; dx = dy = 0;
    img.style.transition = 'none';
  }, { passive: true });
  stage.addEventListener('touchmove', (e) => {
    if (!tracking || e.touches.length !== 1) { tracking = false; img.style.transform = ''; return; }
    dx = e.touches[0].clientX - sx; dy = e.touches[0].clientY - sy;
    if (Math.abs(dx) > Math.abs(dy)) img.style.transform = `translateX(${dx}px)`;
    else if (dy > 0) img.style.transform = `translateY(${dy}px)`;
    e.preventDefault();
  }, { passive: false });
  stage.addEventListener('touchend', () => {
    if (!tracking) return;
    tracking = false;
    img.style.transition = '';
    img.style.transform = '';
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy)) show(index + (dx < 0 ? 1 : -1));
    else if (dy > 90 && dy > Math.abs(dx)) close();
  });
})();
