const $ = selector => document.querySelector(selector);
const root = document.documentElement;
const colorModeKey = 'solqubit-color-mode';
const darkQuery = matchMedia('(prefers-color-scheme: dark)');
let toastTimer;

function toast(text) {
  const box = $('.toast');
  box.textContent = text;
  box.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => box.classList.remove('show'), 2800);
}

function storedColorMode() {
  try { return localStorage.getItem(colorModeKey); } catch { return null; }
}

function updateTheme() {
  const dark = root.dataset.theme === 'dark';
  $('meta[name="theme-color"]').content = dark ? '#18171e' : '#f7f7fb';
}

// First visit: follow the operating system until the reader picks a mode.
const initialMode = storedColorMode();
if (initialMode !== 'dark' && initialMode !== 'light') root.dataset.theme = darkQuery.matches ? 'dark' : 'light';
updateTheme();
darkQuery.addEventListener('change', event => {
  const stored = storedColorMode();
  if (stored === 'dark' || stored === 'light') return;
  root.dataset.theme = event.matches ? 'dark' : 'light';
  updateTheme();
});
$('.theme-toggle').addEventListener('click', () => {
  root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem(colorModeKey, root.dataset.theme); } catch {}
  updateTheme();
});

const menuToggle = $('.mobile-menu-toggle');
const mobileMenu = $('#mobile-nav');
function setMobileMenu(open, { returnFocus = false } = {}) {
  mobileMenu.hidden = !open;
  menuToggle.setAttribute('aria-expanded', String(open));
  if (!open && returnFocus) menuToggle.focus();
}
menuToggle.addEventListener('click', () => setMobileMenu(mobileMenu.hidden));

const moreMenu = $('.nav-more');
function closeMoreMenu({ returnFocus = false } = {}) {
  if (!moreMenu?.open) return;
  moreMenu.open = false;
  if (returnFocus) moreMenu.querySelector('summary').focus();
}

function openSearch() { $('#search-dialog').showModal(); $('#search-input').focus(); }
$('.search-trigger').addEventListener('click', openSearch);
$('.close-dialog').addEventListener('click', () => $('#search-dialog').close());
$('.detail-close').addEventListener('click', () => $('#detail-dialog').close());
$('#guide-category')?.addEventListener('change', event => event.target.form.requestSubmit());

document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && !document.querySelector('dialog[open]')) {
    if (moreMenu?.open) { event.preventDefault(); closeMoreMenu({ returnFocus: moreMenu.contains(document.activeElement) || document.activeElement === document.body }); return; }
    if (!mobileMenu.hidden) { event.preventDefault(); setMobileMenu(false, { returnFocus: true }); return; }
  }
  if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && !document.activeElement.isContentEditable && !document.querySelector('dialog[open]')) {
    event.preventDefault();
    openSearch();
  }
});

document.addEventListener('click', async event => {
  if (moreMenu?.open && !moreMenu.contains(event.target)) closeMoreMenu();
  const copy = event.target.closest('[data-copy-code]');
  const share = event.target.closest('[data-share]');
  if (copy || share) {
    try {
      await navigator.clipboard.writeText(copy ? copy.closest('.code-block').querySelector('pre code').textContent : location.href.split('#')[0]);
      toast(copy ? 'Código copiado' : 'Enlace copiado');
    } catch {
      toast('No se pudo copiar. Selecciona el texto y cópialo manualmente.');
    }
  }
  const image = event.target.closest('[data-image-src]');
  if (image) {
    const figure = document.createElement('figure');
    figure.className = 'expanded-figure';
    const img = document.createElement('img');
    img.src = image.dataset.imageSrc;
    img.alt = image.dataset.imageAlt || '';
    figure.append(img);
    if (image.dataset.imageCaption) {
      const caption = document.createElement('figcaption');
      caption.textContent = image.dataset.imageCaption;
      figure.append(caption);
    }
    $('#detail-content').replaceChildren(figure);
    $('#detail-dialog').showModal();
  }
});

if ($('.article-body')) {
  const observer = new IntersectionObserver(entries => entries.forEach(entry => {
    if (entry.isIntersecting) document.querySelectorAll('[data-scroll]').forEach(link => link.classList.toggle('active', link.dataset.scroll === entry.target.id));
  }), { rootMargin: '-100px 0px -65% 0px', threshold: 0 });
  document.querySelectorAll('.article-body h2[id]').forEach(heading => observer.observe(heading));

  // Progress is drawn with a compositor-only transform, at most once per frame.
  const progress = $('.reading-progress');
  let progressFrame = 0;
  const drawProgress = () => {
    progressFrame = 0;
    const total = root.scrollHeight - innerHeight;
    progress.style.transform = `scaleX(${total > 0 ? Math.min(1, Math.max(0, scrollY / total)) : 0})`;
  };
  const queueProgress = () => { if (!progressFrame) progressFrame = requestAnimationFrame(drawProgress); };
  addEventListener('scroll', queueProgress, { passive: true });
  addEventListener('resize', queueProgress, { passive: true });
  queueProgress();
}
