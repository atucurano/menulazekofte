(() => {
  const root = document.querySelector('.board-shell[data-api]');
  if (!root) return;
  const newList = document.querySelector('#new-calls');
  const seenList = document.querySelector('#seen-calls');
  const status = document.querySelector('#service-status');
  const soundStatus = document.querySelector('#sound-status');
  const soundButton = document.querySelector('#sound-enable');
  let audio = null;
  let soundEnabled = false;
  let lastAlertedId = 0;
  let lastAlertAt = 0;
  let latestPendingId = 0;
  let busy = false;
  let rendered = '';
  const clock = document.querySelector('#board-time');
  function updateClock() { clock.textContent = new Intl.DateTimeFormat('tr-TR', { timeZone: 'Europe/Istanbul', hour: '2-digit', minute: '2-digit' }).format(new Date()); }
  updateClock(); setInterval(updateClock, 30000);

  soundButton.addEventListener('click', async () => {
    try {
      const Context = window.AudioContext || window.webkitAudioContext;
      if (!Context) throw new Error('Bu tarayıcı sesli uyarıyı desteklemiyor.');
      audio = audio || new Context();
      await audio.resume();
      if (!(await alarm())) throw new Error('Ses açılamadı. Simgeye yeniden dokunun.');
      soundEnabled = true;
      lastAlertedId = latestPendingId;
      lastAlertAt = Date.now();
      soundStatus.textContent = 'Sesli uyarı açık.';
      soundButton.classList.add('sound-on');
      soundButton.classList.remove('sound-error');
      soundButton.title = 'Ses açık · tekrar test et';
      soundButton.setAttribute('aria-label', 'Ses açık · tekrar test et');
    } catch (error) { soundStatus.textContent = error.message || 'Ses açılamadı.'; soundButton.classList.add('sound-error'); soundButton.title = soundStatus.textContent; soundButton.setAttribute('aria-label', soundStatus.textContent); }
  });
  async function alarm() {
    if (!audio) return false;
    try { if (audio.state !== 'running') await audio.resume(); }
    catch (_) { soundStatus.textContent = 'Tarayıcı sesi durdurdu. Sesi test et düğmesine tekrar dokunun.'; return false; }
    if (audio.state !== 'running') { soundStatus.textContent = 'Ses duraklatıldı. Sesli uyarıyı aç düğmesine tekrar dokunun.'; return false; }
    const master = audio.createDynamicsCompressor();
    master.threshold.value = -12;
    master.ratio.value = 4;
    master.connect(audio.destination);
    [0, .24, .48, .85, 1.09, 1.33].forEach((delay, index) => {
      const start = audio.currentTime + delay;
      const tone = audio.createOscillator();
      const gain = audio.createGain();
      tone.type = 'sawtooth';
      tone.frequency.setValueAtTime(index % 2 ? 1050 : 780, start);
      gain.gain.setValueAtTime(.001, start);
      gain.gain.exponentialRampToValueAtTime(.55, start + .025);
      gain.gain.exponentialRampToValueAtTime(.001, start + .19);
      tone.connect(gain); gain.connect(master);
      tone.start(start); tone.stop(start + .2);
    });
    if (navigator.vibrate) navigator.vibrate([180, 100, 180, 100, 180]);
    return true;
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
    if (!calls.length) { const empty = document.createElement('div'); empty.className = 'call-empty'; empty.textContent = emptyText; container.append(empty); return; }
    calls.forEach((call) => {
      const card = document.createElement('article'); card.className = 'call-card ' + (call.status === 'new' ? 'is-new' : 'is-seen');
      const top = document.createElement('div'); top.className = 'call-top';
      const metaLeft = document.createElement('div'); metaLeft.className = 'call-top-meta';
      const badge = document.createElement('span'); badge.className = 'call-badge'; badge.textContent = call.status === 'new' ? 'BEKLİYOR' : 'İLGİLENİLİYOR';
      const secBadge = document.createElement('span'); secBadge.className = 'call-section-badge'; secBadge.textContent = call.section || 'Salon';
      metaLeft.append(badge, secBadge);
      const time = document.createElement('time'); time.textContent = call.created_at.slice(11, 16); time.setAttribute('datetime', call.created_at.replace(' ', 'T'));
      top.append(metaLeft, time);
      const title = document.createElement('h3'); title.textContent = call.label;
      const timer = document.createElement('div'); timer.className = 'call-timer';
      const timerLabel = document.createElement('span'); timerLabel.textContent = 'Çağrıdan beri';
      const elapsed = document.createElement('strong'); elapsed.className = 'call-elapsed'; elapsed.dataset.baseSeconds = String(Math.max(0, Number(call.wait_seconds) || 0)); elapsed.dataset.syncedAt = String(Date.now()); elapsed.textContent = '00:00';
      timer.append(timerLabel, elapsed);
      const detail = document.createElement('p'); detail.className = 'call-detail';
      const secPrefix = call.section ? call.section + ' · ' : '';
      detail.textContent = call.status === 'new' ? secPrefix + 'Garson bekleniyor' : secPrefix + 'Gördü: ' + (call.acknowledged_by || 'Personel');
      const actions = document.createElement('div'); actions.className = 'call-actions';
      if (call.status === 'new') actions.append(actionButton(call.id, 'seen', root.dataset.role === 'cashier' ? 'Garson atandı' : 'Gördüm'));
      actions.append(actionButton(call.id, 'done', 'Tamamlandı'));
      card.append(top, title, timer, detail, actions); container.append(card);
    });
  }
  function actionButton(id, action, label) {
    const button = document.createElement('button'); button.type = 'button'; button.textContent = label;
    button.className = action === 'seen' ? 'action-primary' : 'action-secondary';
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const body = new URLSearchParams({ csrf: root.dataset.csrf, id: String(id), action });
        const response = await fetch(root.dataset.api, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) throw new Error('İşlem kaydedilemedi.');
        await poll();
      } catch (error) { status.textContent = error.message; button.textContent = 'Tekrar dene'; button.title = error.message; button.disabled = false; }
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
      if (soundEnabled && latestPendingId && (latestPendingId > lastAlertedId || Date.now() - lastAlertAt >= 12000)) {
        if (await alarm()) { lastAlertedId = latestPendingId; lastAlertAt = Date.now(); }
      }
      render(data.calls);
      status.textContent = 'Çağrılar güncel';
    } catch (error) { status.textContent = error.message || 'Bağlantı hatası. Yeniden denenecek.'; }
    finally { busy = false; }
  }
  poll(); setInterval(poll, 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
