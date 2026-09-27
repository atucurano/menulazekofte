(() => {
  const panel = document.querySelector('.waiter-call');
  if (!panel) return;
  const button = panel.querySelector('button');
  const status = panel.querySelector('[role="status"]');
  let statusTimer;

  const showStatus = (message, duration = 0) => {
    window.clearTimeout(statusTimer);
    status.textContent = message;
    status.classList.toggle('is-visible', Boolean(message));
    if (duration) {
      statusTimer = window.setTimeout(() => {
        status.classList.remove('is-visible');
        window.setTimeout(() => { status.textContent = ''; }, 180);
      }, duration);
    }
  };

  button.addEventListener('click', async () => {
    if (!panel.dataset.table) {
      showStatus(document.documentElement.lang === 'en'
        ? 'Please scan the QR code on your table first.'
        : 'Önce masanızdaki QR kodunu okutun; böylece hangi masada olduğunuzu bilebiliriz.', 6000);
      return;
    }
    button.disabled = true;
    showStatus('İletiliyor…');
    try {
      const body = new URLSearchParams({ csrf: panel.dataset.csrf, table: panel.dataset.table });
      const response = await fetch(panel.dataset.callUrl, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Çağrı iletilemedi.');
      showStatus(document.documentElement.lang === 'en' ? 'Your call was sent. Please wait.' : data.message, 4500);
      setTimeout(() => { button.disabled = false; }, 30000);
    } catch (error) { showStatus(error.message, 6000); button.disabled = false; }
  });
})();
