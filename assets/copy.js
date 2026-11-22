// Copy-to-clipboard for any element with data-copy="text".
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('[data-copy]');
  if (!btn) return;
  const text = btn.getAttribute('data-copy');
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
  }
  const old = btn.textContent;
  btn.textContent = 'Copied!';
  btn.classList.add('ok');
  setTimeout(() => { btn.textContent = old; btn.classList.remove('ok'); }, 1400);
});

// Native share sheet (phones) for any element with data-share="url".
// Hidden on browsers without navigator.share.
function setupShare(root = document) {
  root.querySelectorAll('[data-share]').forEach((btn) => {
    if (!navigator.share) { btn.hidden = true; return; }
    btn.hidden = false;
  });
}
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('[data-share]');
  if (!btn || !navigator.share) return;
  try {
    await navigator.share({ url: btn.getAttribute('data-share'), title: btn.getAttribute('data-title') || undefined });
  } catch { /* user closed the sheet */ }
});
setupShare();
window.pasteySetupShare = setupShare;
