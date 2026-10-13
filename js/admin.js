(function () {
  'use strict';

  // Chiude le modali cliccando sullo sfondo o con Esc
  document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) modal.classList.remove('open');
    });
  });
  // Centro Notifiche Dropdown
  var notifBtn = document.getElementById('btn-toggle-notifiche');
  var notifFlyout = document.getElementById('notifiche-flyout');
  var notifWrap = document.getElementById('notifiche-wrap');

  if (notifBtn && notifFlyout) {
    notifBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = notifFlyout.classList.toggle('open');
      notifBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
      if (notifWrap && !notifWrap.contains(e.target)) {
        notifFlyout.classList.remove('open');
        notifBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal.open').forEach(function (m) { m.classList.remove('open'); });
      if (notifFlyout) {
        notifFlyout.classList.remove('open');
        if (notifBtn) notifBtn.setAttribute('aria-expanded', 'false');
      }
      setNav(false);
    }
  });

  // Menu laterale su mobile (hamburger nella topbar)
  var toggle = document.getElementById('topbar-toggle');
  var backdrop = document.getElementById('sidebar-backdrop');
  var close = document.getElementById('sidebar-close');

  function setNav(open) {
    document.body.classList.toggle('nav-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (toggle) toggle.addEventListener('click', function () { setNav(!document.body.classList.contains('nav-open')); });
  if (backdrop) backdrop.addEventListener('click', function () { setNav(false); });
  if (close) close.addEventListener('click', function () { setNav(false); });
  window.addEventListener('resize', function () { if (window.innerWidth > 900) setNav(false); });
})();
