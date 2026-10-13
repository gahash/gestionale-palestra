/* Menu mobile (hamburger) */
(function () {
  'use strict';
  var toggle = document.getElementById('nav-toggle');
  var nav = document.getElementById('main-nav');
  if (!toggle || !nav) return;

  function setOpen(open) {
    nav.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Chiudi menu' : 'Apri menu');
  }

  toggle.addEventListener('click', function () {
    setOpen(!nav.classList.contains('is-open'));
  });
  nav.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') setOpen(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setOpen(false);
  });
  document.addEventListener('click', function (e) {
    if (nav.classList.contains('is-open') && !nav.contains(e.target) && !toggle.contains(e.target)) setOpen(false);
  });
})();

(function () {
  'use strict';

  var form = document.getElementById('contact-form');
  if (!form) return;

  var feedback = document.getElementById('form-feedback');

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var data = {
      nome: form.nome.value.trim(),
      telefono: form.telefono.value.trim(),
      email: form.email.value.trim(),
      servizio_interesse: form.servizio_interesse.value,
      giorno_preferito: form.giorno_preferito.value.trim(),
      fascia_oraria: form.fascia_oraria.value.trim(),
      messaggio: form.messaggio.value.trim(),
      gdpr_consenso: form.gdpr_consenso.checked ? 1 : 0,
      sito_web: form.sito_web.value // honeypot
    };

    if (!data.nome || !data.telefono) {
      showFeedback('Compila nome e telefono.', false);
      return;
    }
    if (!data.gdpr_consenso) {
      showFeedback('Devi accettare l\'informativa privacy per continuare.', false);
      return;
    }

    var submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Invio in corso...';

    fetch('api/leads', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    })
      .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
      .then(function (res) {
        if (res.ok && res.body.success) {
          showFeedback('Richiesta inviata! Ti ricontatteremo il prima possibile.', true);
          form.reset();
        } else {
          showFeedback(res.body.error || 'Si e\' verificato un errore, riprova.', false);
        }
      })
      .catch(function () {
        showFeedback('Errore di connessione. Riprova tra poco o chiamaci direttamente.', false);
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Invia richiesta';
      });
  });

  function showFeedback(message, ok) {
    feedback.textContent = message;
    feedback.className = 'form-feedback ' + (ok ? 'ok' : 'err');
  }
})();

/* Foto di sfondo dell'hero: leggero effetto "Ken Burns" (zoom in) scorrendo la pagina
   (desktop e mobile: lo scroll touch dei telefoni genera comunque l'evento "scroll"). */
(function () {
  'use strict';

  var photo = document.querySelector('.hero-training-bg img');
  var hero = document.querySelector('.hero');
  if (!photo || !hero) return;

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) return; // il CSS applica gia' uno stato statico in questo caso

  var ticking = false;

  function update() {
    ticking = false;
    var heroHeight = hero.offsetHeight || 1;
    var progress = window.scrollY / heroHeight;
    if (progress < 0) progress = 0;
    if (progress > 1) progress = 1;

    var scale = 1 + progress * 0.12; // da 1x a 1.12x, zoom leggero

    photo.style.setProperty('--muscle-scale', scale.toFixed(3));
  }

  function onScroll() {
    if (!ticking) {
      window.requestAnimationFrame(update);
      ticking = true;
    }
  }

  update();
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
})();

/* Player musicale "solo audio": usa la vera API di YouTube per la riproduzione,
   ma mostra soltanto un player stile Spotify (play/pausa, barra di avanzamento),
   senza mai mostrare il riquadro video. */
(function () {
  'use strict';

  var container = document.getElementById('audio-player');
  if (!container) return;

  var VIDEO_ID = 'AMkHbApwxrA';
  var toggleBtn = document.getElementById('player-toggle');
  var iconPlay = toggleBtn.querySelector('.icon-play');
  var iconPause = toggleBtn.querySelector('.icon-pause');
  var progressBar = document.getElementById('player-progress-bar');
  var progressTrack = document.querySelector('.player-progress');
  var timeCurrent = document.getElementById('player-time-current');
  var timeTotal = document.getElementById('player-time-total');

  var player = null;
  var progressTimer = null;

  function formatTime(seconds) {
    seconds = Math.max(0, Math.floor(seconds || 0));
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function setPlayingUI(isPlaying) {
    iconPlay.hidden = isPlaying;
    iconPause.hidden = !isPlaying;
    toggleBtn.setAttribute('aria-label', isPlaying ? 'Pausa' : 'Play');
  }

  function startProgressLoop() {
    stopProgressLoop();
    progressTimer = window.setInterval(function () {
      if (!player || typeof player.getCurrentTime !== 'function') return;
      var current = player.getCurrentTime();
      var duration = player.getDuration();
      if (duration > 0) {
        progressBar.style.width = (current / duration * 100) + '%';
        timeTotal.textContent = formatTime(duration);
      }
      timeCurrent.textContent = formatTime(current);
    }, 500);
  }

  function stopProgressLoop() {
    if (progressTimer) {
      window.clearInterval(progressTimer);
      progressTimer = null;
    }
  }

  function onPlayerStateChange(e) {
    var YT = window.YT;
    if (e.data === YT.PlayerState.PLAYING) {
      setPlayingUI(true);
      startProgressLoop();
    } else if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.ENDED) {
      setPlayingUI(false);
      stopProgressLoop();
    }
  }

  function createPlayer() {
    player = new window.YT.Player('yt-audio-target', {
      height: '1',
      width: '1',
      videoId: VIDEO_ID,
      playerVars: { controls: 0, disablekb: 1, playsinline: 1 },
      events: {
        onStateChange: onPlayerStateChange,
        onReady: function () {
          timeTotal.textContent = formatTime(player.getDuration());
        }
      }
    });
  }

  toggleBtn.addEventListener('click', function () {
    if (!player) return; // l'API non e' ancora pronta, un click troppo rapido non fa nulla
    var state = player.getPlayerState();
    if (state === window.YT.PlayerState.PLAYING) {
      player.pauseVideo();
    } else {
      player.playVideo();
    }
  });

  progressTrack.addEventListener('click', function (e) {
    if (!player || typeof player.getDuration !== 'function') return;
    var rect = progressTrack.getBoundingClientRect();
    var ratio = (e.clientX - rect.left) / rect.width;
    var duration = player.getDuration();
    if (duration > 0) {
      player.seekTo(duration * ratio, true);
    }
  });

  if (window.YT && window.YT.Player) {
    createPlayer();
  } else {
    var previousCallback = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = function () {
      if (typeof previousCallback === 'function') previousCallback();
      createPlayer();
    };
    var tag = document.createElement('script');
    tag.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(tag);
  }
})();
