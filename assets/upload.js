// Pastey uploader: converts video to web-friendly MP4 in the browser
// (WebCodecs via mediabunny), makes a thumbnail, then uploads in chunks.
// Photos, audio, PDFs and ZIPs go up as they are (HEIC photos become JPEG
// first where the browser can read them). Several photos picked together
// become one gallery.
import * as MB from './vendor/mediabunny.min.mjs';
import './copy.js';

const C = window.PASTEY;
const drop = document.getElementById('drop');
const fileInput = document.getElementById('file');
const jobs = document.getElementById('jobs');
const grid = document.getElementById('grid');
const hint = el('p', 'muted small upload-hint',
  'Keep this page open while it works. If you lock your phone or switch apps, it pauses and picks up where it left off when you come back.');
hint.hidden = true;
jobs.before(hint);

const queue = [];
let busy = false;

// What the server takes; it checks the file itself again when it arrives.
function kindOf(f) {
  const t = (f.type || '').toLowerCase(), n = (f.name || '').toLowerCase();
  if (t.startsWith('video/') || /\.(mp4|mov|m4v|mkv|webm|avi)$/.test(n)) return 'video';
  if (/^image\/(jpeg|png|gif|webp|avif|heic|heif)/.test(t) || /\.(jpe?g|png|gif|webp|avif|heic|heif)$/.test(n)) return 'image';
  if (t.startsWith('audio/') || /\.(mp3|m4a|aac|wav|ogg|oga|opus|flac)$/.test(n)) return 'audio';
  if (/^application\/(pdf|zip|x-zip-compressed)$/.test(t) || /\.(pdf|zip)$/.test(n)) return 'file';
  return null;
}

function enqueue(files) {
  files = [...files].filter(Boolean);
  // Two or more photos picked together share one gallery, made when the first one uploads.
  const photos = files.filter((f) => kindOf(f) === 'image' && !waitingCards.has(fileSig(f)));
  const group = photos.length > 1 ? { id: null, url: null, making: null } : null;
  for (const f of files) {
    const kind = kindOf(f);
    if (!kind) { showUnsupported(f); continue; }
    const sig = fileSig(f);
    // Picked again after an interrupted upload that could not be kept on the phone.
    const waiting = waitingCards.get(sig);
    if (waiting) { waitingCards.delete(sig); waiting.card.remove(); }
    queue.push(makeJob(f, {
      kind,
      saved: waiting ? waiting.rec : null,
      group: waiting?.rec.meta?.gallery ? { id: waiting.rec.meta.gallery } : (kind === 'image' ? group : null),
    }));
  }
  run();
}

function showUnsupported(f) {
  const card = el('div', 'job err');
  card.append(el('div', 'job-name', f.name || 'File'),
    el('div', 'job-status small', "Pastey can't take this kind of file. It takes videos, photos, audio, PDF and ZIP files."));
  const actions = el('div', 'job-actions');
  const ok = el('button', 'btn ghost small', 'OK');
  ok.onclick = () => card.remove();
  actions.append(ok);
  card.append(actions);
  jobs.prepend(card);
}

async function run() {
  if (busy) return;
  busy = true;
  window.pasteyUploading = true; // pull to refresh waits while this is set
  keepAwake.start();
  hint.hidden = false;
  window.addEventListener('beforeunload', warnLeave);
  while (queue.length) {
    const job = queue.shift();
    try {
      await processJob(job);
    } catch (err) {
      console.error(err);
      job.fail(err && err.message ? err.message : String(err), err && err.retry);
    }
  }
  busy = false;
  window.pasteyUploading = false;
  hint.hidden = true;
  window.removeEventListener('beforeunload', warnLeave);
  keepAwake.stop();
}

function warnLeave(e) { e.preventDefault(); e.returnValue = ''; }

// ---- Interruptions ----------------------------------------------------------
// Phones pause a web page when you lock the screen or switch apps: timers stop,
// requests fail or hang, and the video encoder can be shut down. Everything
// below is about noticing that and carrying on when the page is back.

// Keep the screen on while working. The browser drops the lock whenever the
// page is hidden, so take it again each time the page comes back.
const keepAwake = {
  want: false,
  lock: null,
  asking: false,
  grab() {
    if (!this.want || this.lock || this.asking || !navigator.wakeLock || document.visibilityState !== 'visible') return;
    this.asking = true;
    // Not awaited anywhere: some browsers leave this pending, and it must never block uploads.
    navigator.wakeLock.request('screen').then((l) => {
      this.asking = false;
      if (!this.want) { l.release().catch(() => {}); return; }
      this.lock = l;
      l.addEventListener('release', () => { if (this.lock === l) this.lock = null; });
    }, () => { this.asking = false; });
    setTimeout(() => { this.asking = false; }, 5000);
  },
  start() { this.want = true; this.grab(); },
  stop() {
    this.want = false;
    const l = this.lock;
    this.lock = null;
    l?.release().catch(() => {});
  },
};

// Bumped every time the page is hidden, so work can tell it was interrupted.
let hiddenCount = 0;
const inflight = new Set();
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') {
    hiddenCount++;
  } else {
    keepAwake.grab();
    // Requests that were running when the page went away often never finish.
    // Give them a moment, then drop them; the upload asks the server how far
    // it got and carries on from there.
    const seen = hiddenCount;
    setTimeout(() => {
      for (const r of inflight) if (r.hidden < seen) r.ctrl.abort();
    }, 15000);
  }
});
window.addEventListener('pageshow', () => keepAwake.grab());

const isActive = () => document.visibilityState === 'visible' && navigator.onLine !== false;

// Resolves once the page is on screen and online again.
function whenActive() {
  if (isActive()) return Promise.resolve();
  return new Promise((resolve) => {
    const check = () => {
      if (!isActive()) return;
      document.removeEventListener('visibilitychange', check);
      window.removeEventListener('online', check);
      window.removeEventListener('focus', check);
      clearInterval(t);
      resolve();
    };
    document.addEventListener('visibilitychange', check);
    window.addEventListener('online', check);
    window.addEventListener('focus', check);
    const t = setInterval(check, 2000);
  });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- Saved uploads ----------------------------------------------------------
// The video that is being uploaded (after conversion) is kept in the browser's
// storage until the upload finishes, so if the phone reloads the page while it
// was in the background, the upload carries on from the last piece.

const store = {
  db: null,
  open() {
    if (this.db) return this.db;
    this.db = new Promise((resolve, reject) => {
      if (!window.indexedDB) return reject(new Error('no IndexedDB'));
      const req = indexedDB.open('pastey', 1);
      req.onupgradeneeded = () => {
        req.result.createObjectStore('uploads', { keyPath: 'key' }); // details
        req.result.createObjectStore('blobs'); // the video itself, by the same key
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    this.db.catch(() => { this.db = null; });
    return this.db;
  },
  async tx(name, mode, fn) {
    const db = await this.open();
    return new Promise((resolve, reject) => {
      const t = db.transaction(name, mode);
      const req = fn(t.objectStore(name));
      t.oncomplete = () => resolve(req && req.result);
      t.onerror = t.onabort = () => reject(t.error || new Error('storage failed'));
    });
  },
  put(rec) { return this.tx('uploads', 'readwrite', (s) => s.put(rec)); },
  putBlob(key, blob) { return this.tx('blobs', 'readwrite', (s) => s.put(blob, key)); },
  getBlob(key) { return this.tx('blobs', 'readonly', (s) => s.get(key)).catch(() => null); },
  del(key) {
    return Promise.all([
      this.tx('uploads', 'readwrite', (s) => s.delete(key)),
      this.tx('blobs', 'readwrite', (s) => s.delete(key)),
    ]).catch(() => {});
  },
  all() { return this.tx('uploads', 'readonly', (s) => s.getAll()).catch(() => []); },
};

const fileSig = (f) => `${f.name}|${f.size}|${f.lastModified || 0}`;
const newKey = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

// Uploads that were interrupted and whose video could not be kept: wait for
// the person to pick the same file again. sig -> { rec, card }
const waitingCards = new Map();

async function resumeSaved() {
  const recs = await store.all();
  const now = Date.now();
  for (const rec of recs.sort((a, b) => a.saved - b.saved)) {
    if (now - rec.saved > 2 * 86400 * 1000) { store.del(rec.key); continue; }
    const blob = rec.hasBlob ? await store.getBlob(rec.key) : null;
    if (blob && blob.size === rec.size) {
      queue.push(makeJob({ name: rec.name }, {
        saved: rec, blob, resumed: true, kind: rec.kind || 'video',
        group: rec.meta?.gallery ? { id: rec.meta.gallery } : null,
      }));
    } else {
      showWaiting(rec);
    }
  }
  if (queue.length) run();
}

function showWaiting(rec) {
  const card = el('div', 'job err');
  card.append(el('div', 'job-name', rec.name),
    el('div', 'job-status small', 'This upload was interrupted. Choose the same file again to finish it.'));
  const actions = el('div', 'job-actions');
  const pick = el('button', 'btn primary small', 'Choose it again');
  pick.onclick = () => fileInput.click();
  const forget = el('button', 'btn ghost small', 'Forget it');
  forget.onclick = () => {
    card.remove();
    waitingCards.delete(rec.sig);
    store.del(rec.key);
    if (rec.uploadId) api('delete', { id: rec.uploadId }).catch(() => {});
  };
  actions.append(pick, forget);
  card.append(actions);
  jobs.prepend(card);
  waitingCards.set(rec.sig, { rec, card });
}

// Videos shared from the phone's share sheet (see sw.js).
async function takeShared() {
  if (!('caches' in window)) return;
  try {
    const files = [];
    // 'clipbin-share' is where the service worker kept shares before the app
    // was renamed; a phone still running the old worker may have left one there.
    for (const name of ['pastey-share', 'clipbin-share']) {
      if (name !== 'pastey-share' && !(await caches.has(name))) continue;
      const cache = await caches.open(name);
      for (const req of await cache.keys()) {
        const res = await cache.match(req);
        const blob = await res.blob();
        const fname = decodeURIComponent(res.headers.get('X-Name') || 'shared.mp4');
        const type = blob.type && blob.type !== 'application/octet-stream' ? blob.type : (/\.\w+$/.test(fname) ? '' : 'video/mp4');
        files.push(new File([blob], fname, { type }));
        await cache.delete(req);
      }
      if (name !== 'pastey-share') await caches.delete(name);
    }
    if (files.length) enqueue(files);
  } catch (e) { console.warn('share pickup failed', e); }
  if (location.search.includes('shared=1')) history.replaceState(null, '', location.pathname);
}
resumeSaved().then(takeShared).then(doTodo);
// Upload storage from before the rename (it only ever held unfinished uploads).
try { window.indexedDB?.deleteDatabase('clipbin'); } catch { /* ignore */ }

// Things the iPhone shortcuts upload arrive without the browser's help: make
// the missing thumbnails and lengths here, and turn HEIC photos the server
// couldn't read into JPEG. One at a time, quietly.
async function doTodo() {
  for (const t of C.todo || []) {
    try {
      const tile = document.querySelector(`.tile[data-id="${t.id}"]`);
      let res;
      if (t.kind === 'held') {
        res = await convertHeld(t.id);
        if (tile) tile.querySelector('.meta .small').textContent = tile.querySelector('.meta .small').textContent.replace(' · converting…', '');
      } else {
        const m = t.kind === 'video' ? await thumbnailAndMeta(t.src)
          : t.kind === 'image' ? await imageThumb(await (await fetch(t.src)).blob())
            : await audioMeta(t.src);
        res = await api('thumb', { id: t.id, ...m });
        if (m.duration && tile) tile.querySelector('.dur') && (tile.querySelector('.dur').textContent = fmtDur(m.duration));
      }
      if (res.thumb && tile) {
        const a = tile.querySelector('.thumb');
        a.querySelector('img')?.remove();
        const img = el('img');
        img.alt = '';
        img.src = res.thumb + '?' + Date.now();
        a.prepend(img);
      }
    } catch (e) { console.warn('could not finish', t.id, e); }
  }
}

async function convertHeld(id) {
  const res = await fetch(`${C.base}/api.php?action=held`, {
    method: 'POST', headers: { 'X-CSRF': C.csrf, 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }), credentials: 'same-origin',
  });
  if (!res.ok) throw new Error('could not fetch photo');
  const jpeg = await toJpeg(await res.blob());
  if (!jpeg) throw new Error("this browser can't read HEIC");
  const t = await imageThumb(jpeg);
  const form = new FormData();
  form.append('id', id);
  form.append('thumb', t.thumb);
  form.append('file', jpeg, 'photo.jpg');
  return api('replace', form);
}

function fmtDur(s) {
  s = Math.round(s);
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = String(s % 60).padStart(2, '0');
  return h ? `${h}:${String(m).padStart(2, '0')}:${x}` : `${m}:${x}`;
}

// ---- UI --------------------------------------------------------------------

function el(tag, cls, text) {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (text != null) n.textContent = text;
  return n;
}

function makeJob(file, opts = {}) {
  const kind = opts.kind || 'video';
  const root = el('div', 'job');
  const name = el('div', 'job-name', file.name);
  const status = el('div', 'job-status muted small', opts.resumed ? 'Picking up where it left off…' : 'Waiting…');
  const bar = el('div', 'bar');
  const fill = el('div', 'bar-fill');
  bar.appendChild(fill);
  const actions = el('div', 'job-actions');
  root.append(name, status, bar, actions);
  jobs.prepend(root);
  let cancelled = false;
  let cancelHook = null;
  const cancelBtn = el('button', 'btn ghost small', 'Cancel');
  actions.appendChild(cancelBtn);
  const job = {
    file,
    kind,
    group: opts.group || null, // shared by the photos of one gallery
    // Saved state from the browser's storage: { key, blob, meta, uploadId, ... }.
    saved: opts.saved || null,
    blob: opts.blob || null, // the video being uploaded, once known
    get cancelled() { return cancelled; },
    onCancel(fn) { cancelHook = fn; },
    set(text, frac) {
      status.textContent = text;
      if (frac != null) fill.style.width = (Math.max(0, Math.min(1, frac)) * 100).toFixed(1) + '%';
    },
    forget() {
      const rec = job.saved;
      job.saved = null;
      if (rec) store.del(rec.key);
      return rec;
    },
    done(url, inGallery) {
      job.forget();
      root.classList.add('ok');
      fill.style.width = '100%';
      status.textContent = inGallery ? 'Added to the gallery' : 'Ready';
      actions.innerHTML = '';
      const link = el('a', 'job-link', url);
      link.href = url;
      link.target = '_blank';
      const copy = el('button', 'btn primary small', 'Copy link');
      copy.dataset.copy = url;
      const share = el('button', 'btn small', 'Share');
      share.dataset.share = url;
      actions.append(link, copy, share);
      window.pasteySetupShare?.(actions);
      navigator.clipboard?.writeText(url).then(() => {
        status.textContent = inGallery ? 'Added to the gallery, link copied' : 'Ready, link copied';
      }, () => {});
    },
    // retry: the upload can still be finished (it's saved), so offer a button.
    fail(msg, retry) {
      root.classList.add('err');
      status.textContent = cancelled ? 'Cancelled' : 'Failed: ' + msg;
      actions.innerHTML = '';
      if (cancelled) return;
      if (retry && job.saved && job.blob) {
        status.textContent = 'Paused: ' + msg;
        const again = el('button', 'btn primary small', 'Try again');
        again.onclick = () => {
          root.classList.remove('err');
          actions.innerHTML = '';
          actions.appendChild(cancelBtn);
          job.set('Waiting…');
          queue.push(job);
          run();
        };
        const drop = el('button', 'btn ghost small', 'Discard');
        drop.onclick = () => { cancelBtn.onclick(); root.remove(); };
        actions.append(again, drop);
      } else {
        const rec = job.forget();
        if (rec?.uploadId) api('delete', { id: rec.uploadId }).catch(() => {});
      }
    },
  };
  cancelBtn.onclick = () => {
    cancelled = true;
    if (cancelHook) cancelHook();
    const rec = job.forget();
    if (rec?.uploadId) api('delete', { id: rec.uploadId }).catch(() => {});
    // Still waiting in line (not running): take it out now. A running job
    // notices the flag and stops by itself.
    const i = queue.indexOf(job);
    if (i >= 0) {
      queue.splice(i, 1);
      root.classList.add('err');
      status.textContent = 'Cancelled';
      actions.innerHTML = '';
    }
  };
  return job;
}

drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
drop.addEventListener('dragleave', () => drop.classList.remove('over'));
drop.addEventListener('drop', (e) => {
  e.preventDefault();
  drop.classList.remove('over');
  enqueue(e.dataTransfer.files);
});
drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
fileInput.addEventListener('change', () => { enqueue(fileInput.files); fileInput.value = ''; });
document.addEventListener('paste', (e) => enqueue(e.clipboardData?.files || []));
// Drops anywhere on the page count too.
document.addEventListener('dragover', (e) => e.preventDefault());
document.addEventListener('drop', (e) => { if (!drop.contains(e.target)) { e.preventDefault(); enqueue(e.dataTransfer.files); } });

// ---- Library actions --------------------------------------------------------

async function api(action, body, query = '', { timeout = 0 } = {}) {
  let payload;
  const headers = { 'X-CSRF': C.csrf };
  if (body instanceof FormData) {
    payload = body;
  } else if (body instanceof Blob) {
    // Send binary as a multipart file field: shared-hosting firewalls often
    // reject raw binary request bodies but allow normal file uploads.
    payload = new FormData();
    payload.append('chunk', body, 'chunk.bin');
  } else {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body || {});
  }
  // Aborted if the page was hidden while this ran (see visibilitychange) or it takes too long.
  const req = { ctrl: new AbortController(), hidden: hiddenCount };
  inflight.add(req);
  const timer = timeout ? setTimeout(() => req.ctrl.abort(), timeout) : 0;
  let res;
  try {
    res = await fetch(`${C.base}/api.php?action=${action}${query}`, {
      method: 'POST',
      headers,
      body: payload,
      credentials: 'same-origin',
      signal: req.ctrl.signal,
    });
  } catch (e) {
    const err = new Error(navigator.onLine === false ? 'No internet connection' : 'Connection lost');
    err.network = true;
    throw err;
  } finally {
    clearTimeout(timer);
    inflight.delete(req);
  }
  let data = {};
  try { data = await res.json(); } catch { /* not JSON */ }
  if (!res.ok) {
    const err = new Error(data.error || (res.status === 418 || (res.status === 403 && !data.error)
      ? `Blocked by the web host's firewall (${res.status})`
      : `Server error ${res.status}`));
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

grid?.addEventListener('click', async (e) => {
  const tile = e.target.closest('.tile');
  if (!tile) return;
  const id = tile.dataset.id;
  if (e.target.closest('[data-delete]')) {
    if (!confirmInline(e.target.closest('[data-delete]'))) return;
    await api('delete', { id });
    tile.remove();
  } else if (e.target.closest('[data-rename]')) {
    const t = tile.querySelector('.title');
    const input = el('input', 'rename');
    input.value = t.textContent === 'Untitled' ? '' : t.textContent;
    t.replaceWith(input);
    input.focus();
    const save = async () => {
      const title = input.value.trim();
      await api('rename', { id, title });
      t.textContent = title || 'Untitled';
      input.replaceWith(t);
    };
    input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') input.blur(); if (ev.key === 'Escape') input.replaceWith(t); });
    input.addEventListener('blur', save, { once: true });
  }
});

// Two-click delete instead of a blocking confirm() dialog.
function confirmInline(btn) {
  if (btn.dataset.armed) return true;
  btn.dataset.armed = '1';
  const old = btn.textContent;
  btn.textContent = 'Really delete?';
  setTimeout(() => { delete btn.dataset.armed; btn.textContent = old; }, 3000);
  return false;
}

// ---- Processing -------------------------------------------------------------

async function processJob(job) {
  // Resuming an upload whose file is saved in the browser: skip straight to uploading.
  if (job.blob && job.saved) {
    await upload(job, job.blob, job.saved.meta);
    return;
  }
  if (job.kind !== 'video') {
    await processOther(job);
    return;
  }
  const { file } = job;
  job.set('Reading video…', 0);

  const input = new MB.Input({ source: new MB.BlobSource(file), formats: MB.ALL_FORMATS });
  let format, vt, at, duration;
  try {
    format = await input.getFormat();
    vt = await input.getPrimaryVideoTrack();
    at = await input.getPrimaryAudioTrack();
    duration = await input.computeDuration();
  } catch (e) {
    throw new Error("Couldn't read this file. Is it a video?");
  }
  if (!vt) throw new Error('No video track found');

  const w = vt.displayWidth, h = vt.displayHeight;
  const longest = Math.max(w, h);
  const webFriendly = format === MB.MP4 && vt.codec === 'avc' && (!at || at.codec === 'aac')
    && longest <= C.maxDim * 1.05;
  const info = { codec: vt.codec, width: w, height: h, longest, hasAudio: !!at,
    channels: at?.numberOfChannels, sampleRate: at?.sampleRate };
  input.dispose?.();

  // An earlier, interrupted upload of this same file. Only an untouched file
  // gives the same bytes again; a conversion would come out slightly different.
  const old = job.saved;
  const reuse = old && old.original && webFriendly && old.size === file.size ? old : null;
  if (old && !reuse) {
    job.forget();
    if (old.uploadId) api('delete', { id: old.uploadId }).catch(() => {});
  }

  let blob = file;
  if (!webFriendly) {
    blob = await convertWithRestarts(job, file, info);
  }
  if (job.cancelled) throw new Error('Cancelled');

  if (blob.size > C.maxBytes) {
    throw new Error(`File is ${(blob.size / 1048576).toFixed(0)} MB, the limit is ${(C.maxBytes / 1048576).toFixed(0)} MB`);
  }

  job.set('Making thumbnail…');
  const thumbMeta = await thumbnailAndMeta(blob).catch(() => null);
  const meta = {
    title: file.name.replace(/\.[^.]+$/, ''),
    width: thumbMeta?.width || w,
    height: thumbMeta?.height || h,
    duration: thumbMeta?.duration || duration,
    thumb: thumbMeta?.thumb || '',
  };

  // Keep the video in the browser until it's uploaded, so a reload doesn't lose it.
  const rec = {
    kind: 'video',
    key: reuse ? reuse.key : newKey(),
    name: file.name,
    sig: fileSig(file),
    size: blob.size,
    original: blob === file,
    meta,
    uploadId: reuse ? reuse.uploadId : null,
    hasBlob: false,
    saved: Date.now(),
  };
  job.saved = rec;
  job.blob = blob;
  saveVideo(job);
  await upload(job, blob, meta);
}

// Photos, audio and files: no conversion, except HEIC photos, which only
// Safari can show; those become JPEG here when the browser can read them.
async function processOther(job) {
  const { file, kind } = job;
  let blob = file;
  let meta = { title: file.name.replace(/\.[^.]+$/, ''), kind };
  // Names the phone makes up (IMG_1234) say nothing; leave those untitled.
  if (/^(img|image|photo|dsc|pxl|screenshot)[\s_-]*[\d_-]*$/i.test(meta.title)) meta.title = '';
  if (kind === 'image') {
    if (isHeic(file)) {
      job.set('Converting photo to JPEG…', 0);
      blob = (await toJpeg(file).catch(() => null)) || file;
    }
    job.set('Making thumbnail…');
    const t = await imageThumb(blob).catch(() => null);
    if (t) Object.assign(meta, t);
  } else if (kind === 'audio') {
    job.set('Reading audio…');
    const m = await audioMeta(blob).catch(() => null);
    if (m) Object.assign(meta, m);
  }
  if (job.cancelled) throw new Error('Cancelled');
  if (blob.size > C.maxBytes) {
    throw new Error(`File is ${(blob.size / 1048576).toFixed(0)} MB, the limit is ${(C.maxBytes / 1048576).toFixed(0)} MB`);
  }
  const old = job.saved;
  const reuse = old && old.original && blob === file && old.size === file.size ? old : null;
  if (old && !reuse) {
    job.forget();
    if (old.uploadId) api('delete', { id: old.uploadId }).catch(() => {});
  }
  if (reuse?.meta?.gallery) meta.gallery = reuse.meta.gallery;
  job.saved = {
    kind,
    key: reuse ? reuse.key : newKey(),
    name: file.name,
    sig: fileSig(file),
    size: blob.size,
    original: blob === file,
    meta,
    uploadId: reuse ? reuse.uploadId : null,
    hasBlob: false,
    saved: Date.now(),
  };
  job.blob = blob;
  saveVideo(job);
  await upload(job, blob, meta);
}

const isHeic = (f) => /^image\/hei[cf]/i.test(f.type || '') || /\.hei[cf]$/i.test(f.name || '');

// Draws a photo (upright, as the phone took it) onto a canvas no bigger than
// max pixels on its longest side. Phones can't make huge canvases, so very
// large photos are scaled to stay under about 16 megapixels.
async function drawImage(blob, max) {
  let src;
  try {
    src = await createImageBitmap(blob, { imageOrientation: 'from-image' });
  } catch {
    // Older Safari: fall back to an <img>.
    src = await new Promise((resolve, reject) => {
      const i = new Image();
      const u = URL.createObjectURL(blob);
      i.onload = () => { URL.revokeObjectURL(u); resolve(i); };
      i.onerror = () => { URL.revokeObjectURL(u); reject(new Error('cannot read image')); };
      i.src = u;
    });
  }
  const W = src.width || src.naturalWidth, H = src.height || src.naturalHeight;
  if (!W || !H) throw new Error('no size');
  const scale = Math.min(1, max / Math.max(W, H), Math.sqrt(16e6 / (W * H)));
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(W * scale));
  c.height = Math.max(1, Math.round(H * scale));
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#fff'; // transparent PNGs get a white background in the JPEG thumbnail
  ctx.fillRect(0, 0, c.width, c.height);
  ctx.drawImage(src, 0, 0, c.width, c.height);
  src.close?.();
  return { canvas: c, width: W, height: H };
}

const canvasBlob = (c, q) => new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', q));

async function imageThumb(blob) {
  const { canvas, width, height } = await drawImage(blob, 800);
  return { width, height, thumb: canvas.toDataURL('image/jpeg', 0.82) };
}

// HEIC (or anything the browser can read) to JPEG at full size. Drawing it
// fresh also leaves the photo's location and other details behind.
async function toJpeg(blob) {
  const { canvas } = await drawImage(blob, 8192);
  const out = await canvasBlob(canvas, 0.9);
  return out && out.size ? new File([out], 'photo.jpg', { type: 'image/jpeg' }) : null;
}

function audioMeta(source) {
  return new Promise((resolve, reject) => {
    const a = document.createElement('audio');
    const url = typeof source === 'string' ? source : URL.createObjectURL(source);
    const finish = (val, err) => {
      if (url !== source) URL.revokeObjectURL(url);
      a.removeAttribute('src');
      err ? reject(err) : resolve(val);
    };
    setTimeout(() => finish(null, new Error('timeout')), 15000);
    a.preload = 'metadata';
    a.onloadedmetadata = () => finish({ duration: isFinite(a.duration) ? a.duration : 0 });
    a.onerror = () => finish(null, new Error('cannot read audio'));
    a.src = url;
  });
}

// Details are saved first (small, quick), then the video itself. If the
// phone won't store that much, only the details stay, and the person can
// pick the same file again to resume.
let saving = Promise.resolve();
function saveRecord(job) {
  const rec = job.saved;
  if (!rec) return saving;
  // Chained so writes land in order.
  saving = saving.then(() => (job.saved === rec ? store.put({ ...rec }) : null)).catch(() => {});
  return saving;
}

function saveVideo(job) {
  const rec = job.saved;
  saveRecord(job);
  store.putBlob(rec.key, job.blob).then(() => {
    if (job.saved !== rec) { store.del(rec.key); return; } // finished meanwhile
    rec.hasBlob = true;
    saveRecord(job);
  }, (e) => console.warn('could not keep video for resuming', e));
}

function updateRecord(job, fields) {
  if (!job.saved) return;
  Object.assign(job.saved, fields);
  saveRecord(job);
}

// Converting can't continue from the middle, but if the phone stopped it
// (screen locked, app switched) it starts over by itself once you're back.
async function convertWithRestarts(job, file, info) {
  for (let attempt = 1; ; attempt++) {
    const hiddenBefore = hiddenCount;
    try {
      return await convert(job, file, info);
    } catch (e) {
      if (job.cancelled) throw new Error('Cancelled');
      const interrupted = e.stalled || hiddenCount !== hiddenBefore || document.visibilityState !== 'visible';
      if (!interrupted || attempt >= 4) throw e;
      console.warn('conversion interrupted, restarting', e);
      if (!isActive()) {
        job.set('Paused while the screen was off. Conversion restarts when you come back…', 0);
        await whenActive();
      }
      job.set('Restarting conversion…', 0);
      await sleep(300);
    }
  }
}

async function convert(job, file, info) {
  const videoOk = await MB.canEncodeVideo('avc');
  if (!videoOk) {
    throw new Error("This browser can't convert video. Try Chrome, Edge or Safari, or upload an H.264 MP4.");
  }
  const input = new MB.Input({ source: new MB.BlobSource(file), formats: MB.ALL_FORMATS });
  const video = { codec: 'avc', bitrate: MB.QUALITY_HIGH };
  if (info.longest > C.maxDim) {
    // Scale the longest side down; the other side follows the aspect ratio.
    const even = (n) => Math.round(n / 2) * 2;
    if (info.width >= info.height) video.width = even(C.maxDim);
    else video.height = even(C.maxDim);
  }
  let audio = { discard: true };
  if (info.hasAudio) {
    const channels = Math.min(2, info.channels || 2);
    const codec = await MB.getFirstEncodableAudioCodec(['aac', 'opus'], {
      numberOfChannels: channels,
      sampleRate: info.sampleRate || 48000,
    });
    audio = codec ? { codec, bitrate: MB.QUALITY_HIGH, numberOfChannels: channels } : { discard: true };
  }

  const output = new MB.Output({
    format: new MB.Mp4OutputFormat({ fastStart: 'in-memory' }),
    target: new MB.BufferTarget(),
  });
  const conversion = await MB.Conversion.init({ input, output, video, audio, tracks: 'primary' });
  const videoDropped = conversion.discardedTracks.some((d) => d.track.type === 'video');
  if (!conversion.isValid || videoDropped) {
    const why = conversion.discardedTracks.map((d) => d.reason).join(', ');
    input.dispose?.();
    throw new Error(`This browser can't convert ${info.codec ? info.codec.toUpperCase() + ' ' : ''}video`
      + (why ? ` (${why})` : '') + '. Try Safari or Chrome, or export the clip as H.264 MP4.');
  }
  job.onCancel(() => conversion.cancel());
  job.set('Converting to MP4… 0%', 0);

  // Watchdog: after the page comes back, a conversion the phone froze may
  // never move again. If it makes no progress for a while, start over.
  let lastProgress = performance.now();
  let stalled = false;
  conversion.onProgress = (p) => {
    lastProgress = performance.now();
    job.set(`Converting to MP4… ${Math.round(p * 100)}%`, p);
  };
  const onVisible = () => { if (document.visibilityState === 'visible') lastProgress = performance.now(); };
  document.addEventListener('visibilitychange', onVisible);
  const watchdog = setInterval(() => {
    if (document.visibilityState === 'visible' && performance.now() - lastProgress > 30000) {
      stalled = true;
      conversion.cancel();
    }
  }, 5000);
  try {
    await conversion.execute();
  } catch (e) {
    if (stalled && !job.cancelled) {
      const err = new Error('Conversion stopped responding');
      err.stalled = true;
      throw err;
    }
    throw e;
  } finally {
    clearInterval(watchdog);
    document.removeEventListener('visibilitychange', onVisible);
    job.onCancel(null);
    input.dispose?.();
  }
  return new Blob([output.target.buffer], { type: 'video/mp4' });
}

function thumbnailAndMeta(source) {
  return new Promise((resolve, reject) => {
    const v = document.createElement('video');
    const url = typeof source === 'string' ? source : URL.createObjectURL(source);
    let done = false;
    const finish = (val, err) => {
      if (done) return;
      done = true;
      if (url !== source) URL.revokeObjectURL(url);
      v.removeAttribute('src');
      v.load();
      err ? reject(err) : resolve(val);
    };
    setTimeout(() => finish(null, new Error('timeout')), 15000);
    v.muted = true;
    v.playsInline = true;
    v.preload = 'auto';
    v.onerror = () => finish(null, new Error('cannot decode'));
    v.onloadedmetadata = () => {
      const d = isFinite(v.duration) ? v.duration : 0;
      v.currentTime = Math.min(1, d * 0.25);
    };
    v.onseeked = () => {
      const W = v.videoWidth, H = v.videoHeight;
      if (!W || !H) return finish(null, new Error('no size'));
      const scale = Math.min(1, 1280 / Math.max(W, H));
      const c = document.createElement('canvas');
      c.width = Math.round(W * scale);
      c.height = Math.round(H * scale);
      c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
      finish({ width: W, height: H, duration: v.duration, thumb: c.toDataURL('image/jpeg', 0.85) });
    };
    v.src = url;
  });
}

// Each piece is sent with its byte offset; the server keeps what it got, so
// after any interruption we ask it how far it is and continue from there.
async function upload(job, blob, meta) {
  job.set('Starting upload…', 0);
  let id = job.saved?.uploadId || null;
  let offset = 0;
  let chunkSize = 0;
  let pausedMsg = '';

  if (id) {
    const s = await retrying(job, () => api('status', { id }), 'Checking upload…').catch((e) => {
      if (e.status === 404) return null; // expired or removed: start again
      throw e;
    });
    if (s && s.status !== 'uploading') { job.done(s.gallery || s.url, !!s.gallery); return; }
    if (s && s.size === blob.size) {
      offset = s.received;
      chunkSize = s.chunkSize;
    } else {
      if (s) api('delete', { id }).catch(() => {});
      id = null;
    }
  }
  if (job.group && !meta.gallery) {
    meta.gallery = await ensureGallery(job);
    updateRecord(job, { meta });
  }
  if (!id) {
    const r = await retrying(job, () => api('init', {
      size: blob.size, title: meta.title, width: meta.width, height: meta.height, duration: meta.duration,
      kind: job.kind,
    }), 'Starting upload…');
    id = r.id;
    chunkSize = r.chunkSize;
    updateRecord(job, { uploadId: id });
  }

  const started = performance.now();
  const startOffset = offset;
  const showProgress = () => {
    const secs = (performance.now() - started) / 1000;
    const mbps = (offset - startOffset) / 1048576 / Math.max(secs, 0.1);
    job.set(`Uploading… ${Math.round((offset / blob.size) * 100)}%`
      + (offset > startOffset ? ` · ${mbps.toFixed(1)} MB/s` : ''), offset / blob.size);
  };
  showProgress();

  let attempt = 0;
  while (offset < blob.size) {
    if (job.cancelled) throw new Error('Cancelled');
    const chunk = blob.slice(offset, offset + chunkSize);
    const hiddenBefore = hiddenCount;
    try {
      // Generous timeout: a piece on a slow phone connection can take a while.
      const r = await api('chunk', chunk, `&id=${id}&offset=${offset}`, { timeout: 180000 });
      offset = r.received;
      attempt = 0;
      showProgress();
      continue;
    } catch (e) {
      if (job.cancelled) throw new Error('Cancelled');
      if (e.status === 409 && e.data && e.data.received != null) { offset = e.data.received; continue; }
      // Host rejected the request size (PHP post_max_size or a proxy limit): use smaller chunks.
      if ((e.status === 413 || e.data?.error === 'Bad chunk size') && chunkSize > 256 * 1024) {
        chunkSize = Math.floor(chunkSize / 2);
        job.set(`Using smaller upload pieces (${(chunkSize / 1048576).toFixed(1)} MB)…`);
        continue;
      }
      if (e.status === 401 || (e.status === 403 && /CSRF/.test(e.message))) {
        throw resumable('You were signed out. Reload the page and sign in, and the upload continues.');
      }
      if (e.status === 404) throw new Error('The server lost this upload. Please upload the file again.');
      if (e.status && e.status < 500) throw e;
      // Connection lost or server hiccup.
      const interrupted = hiddenCount !== hiddenBefore || !isActive();
      if (interrupted) {
        // The phone paused us. Wait until it's back, then ask the server where we are.
        job.set(`Paused at ${Math.round((offset / blob.size) * 100)}%. It continues when you come back to this page.`, offset / blob.size);
        await whenActive();
        attempt = 0;
      } else {
        if (++attempt > 6) throw resumable(e.message);
        job.set(`Connection hiccup, retrying (${attempt})…`);
        await sleep(Math.min(30000, 1000 * 2 ** attempt));
      }
      // Find out how much actually arrived (the last piece may have made it).
      const s = await retrying(job, () => api('status', { id }), 'Reconnecting…');
      if (s.status !== 'uploading') { job.done(s.gallery || s.url, !!s.gallery); return; }
      offset = s.received;
      showProgress();
    }
  }
  job.set('Finishing…', 1);
  const res = await retrying(job, () => api('finish', meta, `&id=${id}`), 'Finishing…');
  job.done(res.gallery || res.url, !!res.gallery);
}

// The first photo of a group makes the gallery; the rest wait for it and join.
async function ensureGallery(job) {
  const g = job.group;
  if (g.id) return g.id;
  g.making ||= retrying(job, () => api('gallery', {}), 'Making gallery…')
    .then((r) => { g.id = r.id; return r.id; })
    .finally(() => { g.making = null; });
  return g.making;
}

function resumable(msg) {
  const err = new Error(msg);
  err.retry = true;
  return err;
}

// Runs a small request, waiting out interruptions and retrying network errors.
async function retrying(job, fn, label) {
  for (let attempt = 1; ; attempt++) {
    if (job.cancelled) throw new Error('Cancelled');
    try {
      return await fn();
    } catch (e) {
      if (job.cancelled) throw new Error('Cancelled');
      if (e.status === 401 || (e.status === 403 && /CSRF/.test(e.message))) {
        throw resumable('You were signed out. Reload the page and sign in, and the upload continues.');
      }
      if (e.status && e.status < 500) throw e;
      if (!isActive()) {
        await whenActive();
      } else {
        if (attempt > 6) throw resumable(e.message);
        job.set(`${label} (retrying ${attempt})`);
        await sleep(Math.min(30000, 1000 * 2 ** attempt));
      }
    }
  }
}
