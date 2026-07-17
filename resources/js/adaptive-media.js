(function () {
  function connectionTier() {
    var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (!c) return 'hd';
    if (c.saveData) return 'sd';
    if (['slow-2g', '2g', '3g'].indexOf(c.effectiveType) !== -1) return 'sd';
    return 'hd';
  }

  function hydrate(el) {
    var tier = connectionTier();

    if (el.tagName === 'VIDEO') {
      var src = (tier === 'hd' && el.dataset.hd) ? el.dataset.hd : el.dataset.sd;
      if (!src) return;
      el.src = src;
      el.poster = el.dataset.poster || '';
      el.preload = 'metadata';
      return;
    }

    if (el.tagName === 'IMG') {
      var img = tier === 'hd'
        ? (el.dataset.full || el.dataset.medium || el.dataset.thumb)
        : (el.dataset.medium || el.dataset.thumb);
      if (img) el.src = img;
    }
  }

  function hydrateAll() {
    document.querySelectorAll('[data-sd], [data-thumb]').forEach(hydrate);
  }

  document.addEventListener('DOMContentLoaded', hydrateAll);

  if (navigator.connection && navigator.connection.addEventListener) {
    navigator.connection.addEventListener('change', hydrateAll);
  }

  window.hydrateAdaptiveMedia = hydrateAll;
})();