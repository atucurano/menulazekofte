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

  const calculateDistanceMeters = (lat1, lon1, lat2, lon2) => {
    const R = 6371e3; // meters
    const toRad = Math.PI / 180;
    const φ1 = lat1 * toRad;
    const φ2 = lat2 * toRad;
    const Δφ = (lat2 - lat1) * toRad;
    const Δλ = (lon2 - lon1) * toRad;
    const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
              Math.cos(φ1) * Math.cos(φ2) *
              Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  const getCoordinates = () => {
    return new Promise((resolve, reject) => {
      if (!('geolocation' in navigator)) {
        reject({ code: 0, message: 'GEOLOCATION_UNSUPPORTED' });
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (pos) => resolve(pos.coords),
        (err) => reject(err),
        {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 30000
        }
      );
    });
  };

  button.addEventListener('click', async () => {
    const isEn = document.documentElement.lang === 'en';

    if (!panel.dataset.table) {
      showStatus(isEn
        ? 'Please scan the QR code on your table first.'
        : 'Önce masanızdaki QR kodunu okutun; böylece hangi masada olduğunuzu bilebiliriz.', 6000);
      return;
    }

    button.disabled = true;

    let userCoords = null;
    const isLocationCheckEnabled = panel.dataset.locationCheck === '1';

    if (isLocationCheckEnabled) {
      showStatus(isEn ? 'Verifying your location…' : 'Konumunuz doğrulanıyor…');
      try {
        userCoords = await getCoordinates();
        const restLat = parseFloat(panel.dataset.restLat || '40.9252987');
        const restLng = parseFloat(panel.dataset.restLng || '29.3113258');
        const maxDist = parseFloat(panel.dataset.maxDist || '150');

        const distance = calculateDistanceMeters(userCoords.latitude, userCoords.longitude, restLat, restLng);

        if (distance > maxDist) {
          const distInt = Math.round(distance);
          showStatus(isEn
            ? `You are too far from the restaurant (${distInt}m). You must be at the restaurant to call a waiter.`
            : `Restorana olan mesafeniz çok uzak (${distInt}m). Garson çağırmak için restoranda olmalısınız.`, 7000);
          setTimeout(() => { button.disabled = false; }, 3000);
          return;
        }
      } catch (geoErr) {
        let msg;
        if (geoErr && geoErr.code === 1) { // PERMISSION_DENIED
          msg = isEn
            ? 'Location permission is required to call a waiter. Please allow location access.'
            : 'Garson çağırmak için konum izni gereklidir. Lütfen tarayıcı ayarlarından konuma izin verin.';
        } else if (geoErr && (geoErr.code === 2 || geoErr.code === 3)) { // POSITION_UNAVAILABLE or TIMEOUT
          msg = isEn
            ? 'Could not retrieve your location. Please check your GPS / Location settings.'
            : 'Konumunuz tespit edilemedi. Lütfen GPS / Konum ayarlarınızın açık olduğundan emin olun.';
        } else {
          msg = isEn
            ? 'Location verification failed. Please try again.'
            : 'Konum doğrulanamadı. Lütfen tekrar deneyin.';
        }
        showStatus(msg, 7000);
        setTimeout(() => { button.disabled = false; }, 2500);
        return;
      }
    }

    showStatus(isEn ? 'Sending call…' : 'İletiliyor…');
    try {
      const body = new URLSearchParams({
        csrf: panel.dataset.csrf,
        table: panel.dataset.table
      });
      if (userCoords) {
        body.append('lat', userCoords.latitude);
        body.append('lng', userCoords.longitude);
      }
      const response = await fetch(panel.dataset.callUrl, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || (isEn ? 'Call could not be sent.' : 'Çağrı iletilemedi.'));
      showStatus(isEn ? 'Your call was sent. Please wait.' : data.message, 4500);
      setTimeout(() => { button.disabled = false; }, 30000);
    } catch (error) {
      showStatus(error.message, 6000);
      button.disabled = false;
    }
  });
})();
