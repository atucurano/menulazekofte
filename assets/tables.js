document.querySelectorAll('[data-qr]').forEach((element) => {
  if (typeof QRCode !== 'undefined') new QRCode(element, { text: element.dataset.qr, width: 208, height: 208, correctLevel: QRCode.CorrectLevel.M });
});
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => { if (!confirm(form.dataset.confirm)) event.preventDefault(); });
});
document.querySelectorAll('[data-copy]').forEach((button) => {
  button.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      const original = button.textContent;
      button.textContent = 'Kopyalandı';
      setTimeout(() => { button.textContent = original; }, 2000);
    } catch (_) { button.textContent = 'Bağlantıyı seçip kopyalayın'; }
  });
});
