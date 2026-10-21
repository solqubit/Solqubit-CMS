(() => {
  'use strict';
  const configuration = document.querySelector('script[data-solqubit-ads]');
  if (!configuration || document.getElementById('solqubit-adsense')) return;
  const publisher = configuration.dataset.publisher;
  if (!/^ca-pub-[0-9]{16}$/.test(publisher || '')) return;

  const placements = [...document.querySelectorAll('.ad-placement')];
  const slots = placements.map(placement => placement.querySelector('.adsbygoogle')).filter(Boolean);
  if (configuration.dataset.format !== 'automatic' && slots.length === 0) return;

  const script = document.createElement('script');
  script.id = 'solqubit-adsense';
  script.async = true;
  script.crossOrigin = 'anonymous';
  script.src = `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${publisher}`;
  script.addEventListener('error', () => placements.forEach(placement => { placement.hidden = true; }), { once: true });

  window.adsbygoogle = window.adsbygoogle || [];
  for (const slot of slots) {
    if (slot.dataset.solqubitQueued) continue;
    slot.dataset.solqubitQueued = 'true';
    window.adsbygoogle.push({});
  }
  document.head.append(script);
})();
