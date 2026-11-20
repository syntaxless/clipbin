// Clipbin uploader: converts video to web-friendly MP4 in the browser
// (WebCodecs via mediabunny), makes a thumbnail, then uploads in chunks.
import * as MB from './vendor/mediabunny.min.mjs';
import './copy.js';

const C = window.CLIPBIN;
const drop = document.getElementById('drop');
const fileInput = document.getElementById('file');
const jobs = document.getElementById('jobs');
const grid = document.getElementById('grid');

const queue = [];
let busy = false;

function enqueue(files) {
  for (const f of files) {
    if (f && (f.type.startsWith('video/') || /\.(mp4|mov|m4v|mkv|webm|avi)$/i.test(f.name))) {
      queue.push(makeJob(f));
    }
  }
  run();
}

async function run() {
  if (busy) return;
  busy = true;
  // Keep the phone screen on while converting/uploading; a locked screen
  // pauses the page. Also warn before navigating away mid-upload.
  let lock = null;
  // Not awaited: some browsers leave this pending, and it must never block uploads.
  navigator.wakeLock?.request('screen').then((l) => { lock = l; }, () => {});
  window.addEventListener('beforeunload', warnLeave);
  while (queue.length) {
    const job = queue.shift();
    try {
      await processJob(job);
    } catch (err) {
      console.error(err);
      job.fail(err && err.message ? err.message : String(err));
    }
  }
  busy = false;
  window.removeEventListener('beforeunload', warnLeave);
  lock?.release().catch(() => {});
}

function warnLeave(e) { e.preventDefault(); e.returnValue = ''; }

// Re-take the wake lock when coming back to the tab.
document.addEventListener('visibilitychange', async () => {
  if (busy && document.visibilityState === 'visible') {
    navigator.wakeLock?.request('screen').catch(() => {});
  }
});

// Videos shared from the phone's share sheet (see sw.js).
async function takeShared() {
  if (!('caches' in window)) return;
  try {
    const cache = await caches.open('clipbin-share');
    const keys = await cache.keys();
    const files = [];
    for (const req of keys) {
      const res = await cache.match(req);
      const blob = await res.blob();
      const name = decodeURIComponent(res.headers.get('X-Name') || 'shared.mp4');
      files.push(new File([blob], name, { type: blob.type || 'video/mp4' }));
      await cache.delete(req);
    }
    if (files.length) enqueue(files);
  } catch (e) { console.warn('share pickup failed', e); }
  if (location.search.includes('shared=1')) history.replaceState(null, '', location.pathname);
}
takeShared();

// ---- UI --------------------------------------------------------------------

function el(tag, cls, text) {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (text != null) n.textContent = text;
  return n;
}

function makeJob(file) {
  const root = el('div', 'job');
  const name = el('div', 'job-name', file.name);
  const status = el('div', 'job-status muted small', 'Waiting…');
  const bar = el('div', 'bar');
  const fill = el('div', 'bar-fill');
  bar.appendChild(fill);
  const actions = el('div', 'job-actions');
  root.append(name, status, bar, actions);
  jobs.prepend(root);
  let cancelled = false;
  let cancelHook = null;
  const cancelBtn = el('button', 'btn ghost small', 'Cancel');
  cancelBtn.onclick = () => { cancelled = true; if (cancelHook) cancelHook(); };
  actions.appendChild(cancelBtn);
  return {
    file,
    get cancelled() { return cancelled; },
    onCancel(fn) { cancelHook = fn; },
    set(text, frac) {
      status.textContent = text;
      if (frac != null) fill.style.width = (Math.max(0, Math.min(1, frac)) * 100).toFixed(1) + '%';
    },
    done(url) {
      root.classList.add('ok');
      fill.style.width = '100%';
      status.textContent = 'Ready';
      actions.innerHTML = '';
      const link = el('a', 'job-link', url);
      link.href = url;
      link.target = '_blank';
      const copy = el('button', 'btn primary small', 'Copy link');
      copy.dataset.copy = url;
      const share = el('button', 'btn small', 'Share');
      share.dataset.share = url;
      actions.append(link, copy, share);
      window.clipbinSetupShare?.(actions);
      navigator.clipboard?.writeText(url).then(() => { status.textContent = 'Ready, link copied'; }, () => {});
    },
    fail(msg) {
      root.classList.add('err');
      status.textContent = cancelled ? 'Cancelled' : 'Failed: ' + msg;
      actions.innerHTML = '';
    },
  };
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

async function api(action, body, query = '') {
  let payload;
  const headers = { 'X-CSRF': C.csrf };
  if (body instanceof Blob) {
    // Send binary as a multipart file field: shared-hosting firewalls often
    // reject raw binary request bodies but allow normal file uploads.
    payload = new FormData();
    payload.append('chunk', body, 'chunk.bin');
  } else {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body || {});
  }
  const res = await fetch(`${C.base}/api.php?action=${action}${query}`, {
    method: 'POST',
    headers,
    body: payload,
    credentials: 'same-origin',
  });
  let data = {};
  try { data = await res.json(); } catch { /* not JSON */ }
  if (!res.ok) {
    const err = new Error(data.error || (res.status === 418 || res.status === 403
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

  let blob = file;
  if (!webFriendly) {
    blob = await convert(job, input, vt, at, longest);
  }
  input.dispose?.();
  if (job.cancelled) throw new Error('Cancelled');

  if (blob.size > C.maxBytes) {
    throw new Error(`File is ${(blob.size / 1048576).toFixed(0)} MB, the limit is ${(C.maxBytes / 1048576).toFixed(0)} MB`);
  }

  job.set('Making thumbnail…');
  const meta = await thumbnailAndMeta(blob).catch(() => null);

  await upload(job, blob, {
    title: file.name.replace(/\.[^.]+$/, ''),
    width: meta?.width || w,
    height: meta?.height || h,
    duration: meta?.duration || duration,
    thumb: meta?.thumb || '',
  });
}

async function convert(job, input, vt, at, longest) {
  const videoOk = await MB.canEncodeVideo('avc');
  if (!videoOk) {
    throw new Error("This browser can't convert video. Try Chrome, Edge or Safari, or upload an H.264 MP4.");
  }
  const video = { codec: 'avc', bitrate: MB.QUALITY_HIGH };
  if (longest > C.maxDim) {
    // Scale the longest side down; the other side follows the aspect ratio.
    const even = (n) => Math.round(n / 2) * 2;
    if (vt.displayWidth >= vt.displayHeight) video.width = even(C.maxDim);
    else video.height = even(C.maxDim);
  }
  let audio = { discard: true };
  if (at) {
    const codec = await MB.getFirstEncodableAudioCodec(['aac', 'opus'], {
      numberOfChannels: Math.min(2, at.numberOfChannels || 2),
      sampleRate: at.sampleRate || 48000,
    });
    audio = codec ? { codec, bitrate: MB.QUALITY_HIGH, numberOfChannels: Math.min(2, at.numberOfChannels || 2) } : { discard: true };
  }

  const output = new MB.Output({
    format: new MB.Mp4OutputFormat({ fastStart: 'in-memory' }),
    target: new MB.BufferTarget(),
  });
  const conversion = await MB.Conversion.init({ input, output, video, audio, tracks: 'primary' });
  const videoDropped = conversion.discardedTracks.some((d) => d.track.type === 'video');
  if (!conversion.isValid || videoDropped) {
    const why = conversion.discardedTracks.map((d) => d.reason).join(', ');
    throw new Error(`This browser can't convert ${vt.codec ? vt.codec.toUpperCase() + ' ' : ''}video`
      + (why ? ` (${why})` : '') + '. Try Safari or Chrome, or export the clip as H.264 MP4.');
  }
  job.onCancel(() => conversion.cancel());
  job.set('Converting to MP4… 0%', 0);
  conversion.onProgress = (p) => job.set(`Converting to MP4… ${Math.round(p * 100)}%`, p);
  await conversion.execute();
  job.onCancel(null);
  return new Blob([output.target.buffer], { type: 'video/mp4' });
}

function thumbnailAndMeta(blob) {
  return new Promise((resolve, reject) => {
    const v = document.createElement('video');
    const url = URL.createObjectURL(blob);
    let done = false;
    const finish = (val, err) => {
      if (done) return;
      done = true;
      URL.revokeObjectURL(url);
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

async function upload(job, blob, meta) {
  job.set('Starting upload…', 0);
  const { id, chunkSize: serverChunk } = await api('init', {
    size: blob.size, title: meta.title, width: meta.width, height: meta.height, duration: meta.duration,
  });
  let offset = 0;
  let chunkSize = serverChunk;
  const started = performance.now();
  while (offset < blob.size) {
    if (job.cancelled) {
      api('delete', { id }).catch(() => {});
      throw new Error('Cancelled');
    }
    const chunk = blob.slice(offset, offset + chunkSize);
    let attempt = 0;
    for (;;) {
      try {
        const r = await api('chunk', chunk, `&id=${id}&offset=${offset}`);
        offset = r.received;
        break;
      } catch (e) {
        if (e.status === 409 && e.data && e.data.received != null) { offset = e.data.received; break; }
        // Host rejected the request size (PHP post_max_size or a proxy limit): use smaller chunks.
        if ((e.status === 413 || e.data?.error === 'Bad chunk size') && chunkSize > 256 * 1024) {
          chunkSize = Math.floor(chunkSize / 2);
          job.set(`Using smaller upload pieces (${(chunkSize / 1048576).toFixed(1)} MB)…`);
          break;
        }
        if (e.status && e.status < 500) throw e;
        if (++attempt > 4) throw e;
        job.set(`Connection hiccup, retrying (${attempt})…`);
        await new Promise((r) => setTimeout(r, 1000 * 2 ** attempt));
      }
    }
    const secs = (performance.now() - started) / 1000;
    const mbps = offset / 1048576 / Math.max(secs, 0.1);
    job.set(`Uploading… ${Math.round((offset / blob.size) * 100)}% · ${mbps.toFixed(1)} MB/s`, offset / blob.size);
  }
  job.set('Finishing…', 1);
  const res = await api('finish', meta, `&id=${id}`);
  job.done(res.url);
}
