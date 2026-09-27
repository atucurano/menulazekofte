(() => {
  const root = document.querySelector('.board-shell[data-api]');
  if (!root) return;
  const newList = document.querySelector('#new-calls');
  const seenList = document.querySelector('#seen-calls');
  const status = document.querySelector('#service-status');
  let audioContext = null;
  let fallbackAudio = null;
  let lastAlertedId = 0;
  let lastAlertAt = 0;
  let latestPendingId = 0;
  let busy = false;
  let rendered = '';
  const clock = document.querySelector('#board-time');

  function updateClock() {
    if (clock) {
      clock.textContent = new Intl.DateTimeFormat('tr-TR', { timeZone: 'Europe/Istanbul', hour: '2-digit', minute: '2-digit' }).format(new Date());
    }
  }
  updateClock();
  setInterval(updateClock, 30000);

  // In-memory WAV chime generator for reliable HTML5 Audio playback across browsers
  function createChimeBlobUrl() {
    try {
      const sampleRate = 22050;
      const duration = 1.35;
      const numSamples = Math.floor(sampleRate * duration);
      const buffer = new ArrayBuffer(44 + numSamples * 2);
      const view = new DataView(buffer);

      view.setUint32(0, 0x52494646, false); // "RIFF"
      view.setUint32(4, 36 + numSamples * 2, true);
      view.setUint32(8, 0x57415645, false); // "WAVE"
      view.setUint32(12, 0x666d7420, false); // "fmt "
      view.setUint32(16, 16, true);
      view.setUint16(20, 1, true); // PCM
      view.setUint16(22, 1, true); // Mono
      view.setUint32(24, sampleRate, true);
      view.setUint32(28, sampleRate * 2, true);
      view.setUint16(32, 2, true);
      view.setUint16(34, 16, true);
      view.setUint32(36, 0x64617461, false); // "data"
      view.setUint32(40, numSamples * 2, true);

      // Pleasant 2-step restaurant chime
      for (let i = 0; i < numSamples; i++) {
        const t = i / sampleRate;
        let s = 0;
        if (t < 0.55) {
          const env = Math.exp(-t * 7);
          s = env * (Math.sin(2 * Math.PI * 784 * t) + 0.45 * Math.sin(2 * Math.PI * 1568 * t));
        } else {
          const t2 = t - 0.45;
          const env = Math.exp(-t2 * 6);
          s = env * (Math.sin(2 * Math.PI * 1046 * t2) + 0.5 * Math.sin(2 * Math.PI * 2093 * t2));
        }
        const val = Math.max(-1, Math.min(1, s)) * 0.9;
        view.setInt16(44 + i * 2, val < 0 ? val * 0x8000 : val * 0x7FFF, true);
      }

      const blob = new Blob([buffer], { type: 'audio/wav' });
      return URL.createObjectURL(blob);
    } catch (_) {
      return null;
    }
  }

  try {
    const chimeUrl = createChimeBlobUrl();
    if (chimeUrl) {
      fallbackAudio = new Audio(chimeUrl);
      fallbackAudio.preload = 'auto';
    }
  } catch (_) {}

  function getAudioContext() {
    if (!audioContext) {
      const Context = window.AudioContext || window.webkitAudioContext;
      if (Context) {
        audioContext = new Context();
      }
    }
    return audioContext;
  }

  // Auto-unlock audio on any user touch/click/tap on screen
  const unlockAudio = async () => {
    try {
      const ctx = getAudioContext();
      if (ctx && ctx.state === 'suspended') {
        await ctx.resume();
      }
      if (fallbackAudio) {
        fallbackAudio.play().then(() => {
          fallbackAudio.pause();
          fallbackAudio.currentTime = 0;
        }).catch(() => {});
      }
      // If there are pending calls that haven't sounded yet, ring immediately
      if (latestPendingId && latestPendingId > lastAlertedId) {
        alarm().then((ok) => {
          if (ok) {
            lastAlertedId = latestPendingId;
            lastAlertAt = Date.now();
          }
        });
      }
    } catch (_) {}
  };
  ['click', 'touchstart', 'touchend', 'keydown', 'pointerdown'].forEach((evt) => {
    window.addEventListener(evt, unlockAudio, { passive: true });
  });

  // Play rich synthesizer bell chime via Web Audio API
  function playSynthesizerChime(ctx) {
    const master = ctx.createDynamicsCompressor();
    master.threshold.value = -10;
    master.ratio.value = 5;
    master.connect(ctx.destination);

    // Two high-pitch pleasant chime pairs (Ding-Dong Ding-Dong)
    [
      { freq: 830, delay: 0 },
      { freq: 1108, delay: 0.22 },
      { freq: 830, delay: 0.58 },
      { freq: 1108, delay: 0.80 }
    ].forEach((toneCfg) => {
      const start = ctx.currentTime + toneCfg.delay;
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.type = 'triangle';
      osc.frequency.setValueAtTime(toneCfg.freq, start);

      gain.gain.setValueAtTime(0.001, start);
      gain.gain.exponentialRampToValueAtTime(0.9, start + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, start + 0.34);

      osc.connect(gain);
      gain.connect(master);
      osc.start(start);
      osc.stop(start + 0.35);
    });
  }

  async function alarm() {
    let played = false;
    const ctx = getAudioContext();

    if (ctx) {
      try {
        if (ctx.state === 'suspended') {
          await ctx.resume();
        }
        if (ctx.state === 'running') {
          playSynthesizerChime(ctx);
          played = true;
        }
      } catch (_) {}
    }

    if (!played && fallbackAudio) {
      try {
        fallbackAudio.currentTime = 0;
        await fallbackAudio.play();
        played = true;
      } catch (_) {}
    }

    if (navigator.vibrate) {
      try { navigator.vibrate([220, 100, 220, 100, 320]); } catch (_) {}
    }
    return played;
  }

  function render(calls) {
    const signature = JSON.stringify(calls.map((call) => [call.id, call.status, call.label, call.section, call.created_at, call.acknowledged_by]));
    if (signature === rendered) return;
    rendered = signature;
    const fresh = calls.filter((call) => call.status === 'new');
    const seen = calls.filter((call) => call.status === 'seen');
    document.querySelector('#new-count').textContent = String(fresh.length);
    document.querySelector('#seen-count').textContent = String(seen.length);
    document.querySelector('#open-count').textContent = String(calls.length);
    fillList(newList, fresh, 'Bekleyen masa yok.');
    fillList(seenList, seen, 'İlgilenilen çağrı yok.');
    updateElapsed();
  }

  function updateElapsed() {
    document.querySelectorAll('.call-elapsed').forEach((element) => {
      const seconds = Math.max(0, Number(element.dataset.baseSeconds) + Math.floor((Date.now() - Number(element.dataset.syncedAt)) / 1000));
      const minutes = Math.floor(seconds / 60);
      element.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
      element.closest('.call-card').classList.toggle('is-urgent', seconds >= 300 && element.closest('.call-card').classList.contains('is-new'));
    });
  }
  setInterval(updateElapsed, 1000);

  function fillList(container, calls, emptyText) {
    container.replaceChildren();
    if (!calls.length) {
      const empty = document.createElement('div');
      empty.className = 'call-empty';
      empty.textContent = emptyText;
      container.append(empty);
      return;
    }
    calls.forEach((call) => {
      const card = document.createElement('article');
      card.className = 'call-card ' + (call.status === 'new' ? 'is-new' : 'is-seen');
      const top = document.createElement('div');
      top.className = 'call-top';
      const metaLeft = document.createElement('div');
      metaLeft.className = 'call-top-meta';
      const badge = document.createElement('span');
      badge.className = 'call-badge';
      badge.textContent = call.status === 'new' ? 'BEKLİYOR' : 'İLGİLENİLİYOR';
      const secBadge = document.createElement('span');
      secBadge.className = 'call-section-badge';
      secBadge.textContent = call.section || 'Salon';
      metaLeft.append(badge, secBadge);
      const time = document.createElement('time');
      time.textContent = call.created_at.slice(11, 16);
      time.setAttribute('datetime', call.created_at.replace(' ', 'T'));
      top.append(metaLeft, time);
      const title = document.createElement('h3');
      title.textContent = call.label;
      const timer = document.createElement('div');
      timer.className = 'call-timer';
      const timerLabel = document.createElement('span');
      timerLabel.textContent = 'Çağrıdan beri';
      const elapsed = document.createElement('strong');
      elapsed.className = 'call-elapsed';
      elapsed.dataset.baseSeconds = String(Math.max(0, Number(call.wait_seconds) || 0));
      elapsed.dataset.syncedAt = String(Date.now());
      elapsed.textContent = '00:00';
      timer.append(timerLabel, elapsed);
      const detail = document.createElement('p');
      detail.className = 'call-detail';
      const secPrefix = call.section ? call.section + ' · ' : '';
      detail.textContent = call.status === 'new' ? secPrefix + 'Garson bekleniyor' : secPrefix + 'Gördü: ' + (call.acknowledged_by || 'Personel');
      const actions = document.createElement('div');
      actions.className = 'call-actions';
      if (call.status === 'new') actions.append(actionButton(call.id, 'seen', root.dataset.role === 'cashier' ? 'Garson atandı' : 'Gördüm'));
      actions.append(actionButton(call.id, 'done', 'Tamamlandı'));
      card.append(top, title, timer, detail, actions);
      container.append(card);
    });
  }

  function actionButton(id, action, label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.className = action === 'seen' ? 'action-primary' : 'action-secondary';
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const body = new URLSearchParams({ csrf: root.dataset.csrf, id: String(id), action });
        const response = await fetch(root.dataset.api, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) throw new Error('İşlem kaydedilemedi.');
        await poll();
      } catch (error) {
        if (status) status.textContent = error.message;
        button.textContent = 'Tekrar dene';
        button.title = error.message;
        button.disabled = false;
      }
    });
    return button;
  }

  async function poll() {
    if (busy) return;
    busy = true;
    try {
      const response = await fetch(root.dataset.api, { credentials: 'same-origin', cache: 'no-store' });
      if (response.status === 401) { window.location.href = root.dataset.loginUrl; return; }
      if (!response.ok) throw new Error('Bağlantı kurulamadı. Yeniden denenecek.');
      const data = await response.json();
      latestPendingId = Math.max(0, ...data.calls.filter((call) => call.status === 'new').map((call) => Number(call.id)));

      // Sound is ALWAYS active: rings on new call, and repeats every 10s if calls are still waiting
      if (latestPendingId && (latestPendingId > lastAlertedId || Date.now() - lastAlertAt >= 10000)) {
        alarm().then((ok) => {
          if (ok) {
            lastAlertedId = latestPendingId;
            lastAlertAt = Date.now();
          }
        });
      }

      render(data.calls);
      if (status) status.textContent = 'Çağrılar güncel';
    } catch (error) {
      if (status) status.textContent = error.message || 'Bağlantı hatası. Yeniden denenecek.';
    } finally {
      busy = false;
    }
  }

  poll();
  setInterval(poll, 4000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
