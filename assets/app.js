/**
 * LAZE Köfte & Çorba - QR Menü Etkileşim Scripti
 * Modern, akıcı ve mobil öncelikli kullanıcı deneyimi
 */
(() => {
  'use strict';
  const isEnglish = document.documentElement.lang === 'en';
  const pageLoader = document.querySelector('#page-loader');

  if (pageLoader) {
    const startedAt = performance.now();
    const minimumDuration = 300;
    let loaderHidden = false;
    const hideLoader = () => {
      if (loaderHidden) return;
      loaderHidden = true;
      window.setTimeout(() => pageLoader.classList.add('is-hidden'), Math.max(0, minimumDuration - (performance.now() - startedAt)));
    };

    if (document.readyState === 'complete') hideLoader();
    else {
      window.addEventListener('load', hideLoader, { once: true });
      window.setTimeout(hideLoader, 1500);
    }

    window.addEventListener('pageshow', () => pageLoader.classList.add('is-hidden'));

    document.addEventListener('click', (event) => {
      const link = event.target.closest('a');
      if (!link || event.defaultPrevented) return;
      const href = link.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || link.target === '_blank' || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      try {
        const destination = new URL(href, window.location.href);
        if (destination.origin !== window.location.origin || destination.href === window.location.href || (destination.pathname === window.location.pathname && destination.search === window.location.search && destination.hash)) return;
        event.preventDefault();
        pageLoader.classList.remove('is-hidden');
        window.setTimeout(() => { window.location.href = destination.href; }, 180);
      } catch (_) { /* Invalid URL: leave browser navigation untouched. */ }
    });
  }

  document.documentElement.classList.add('category-mode');

  // DOM Elemanları
  const searchInput = document.querySelector('#menu-search');
  const globalSearchInput = document.querySelector('.global-search-input');
  const cards = [...document.querySelectorAll('[data-product]')];
  const sections = [...document.querySelectorAll('[data-category]')];
  const categoryLinks = [...document.querySelectorAll('.category-nav-scroll a')];
  const emptyState = document.querySelector('#empty-state');
  const clearBtn = document.querySelector('#clear-search-btn');

  // Türkçe karakter duyarlı arama normalizasyonu
  const normalize = (val) => {
    if (!val) return '';
    return val
      .toLocaleLowerCase('tr-TR')
      .replace(/ı/g, 'i')
      .replace(/ğ/g, 'g')
      .replace(/ü/g, 'u')
      .replace(/ş/g, 's')
      .replace(/ö/g, 'o')
      .replace(/ç/g, 'c')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  };

  let activeSection = null;
  let isGlobalSearchMode = false;
  const selectedAllergens = new Set();

  // Kategori Gösterme / Gizleme Fonksiyonu
  const showCategory = () => {
    const currentHash = window.location.hash;
    
    // Eğer hash #category-ID formatındaysa o kategoriyi aç
    if (currentHash && currentHash.startsWith('#category-')) {
      activeSection = sections.find((sec) => `#${sec.id}` === currentHash) || null;
    } else {
      activeSection = null;
    }

    const hasSelection = Boolean(activeSection) || isGlobalSearchMode;
    document.documentElement.classList.toggle('has-selection', hasSelection);

    // Bölümleri göster/gizle
    sections.forEach((section) => {
      if (isGlobalSearchMode) {
        section.hidden = false;
      } else {
        section.hidden = section !== activeSection;
      }
    });

    // Kategori gezinti bağlantılarını güncelle
    categoryLinks.forEach((link) => {
      const selected = activeSection && link.hash === `#${activeSection.id}`;
      link.classList.toggle('active', Boolean(selected));
      if (selected) {
        link.setAttribute('aria-current', 'page');
        const navScroll = link.closest('.category-nav-scroll');
        if (navScroll) {
          const scrollToActive = (behavior = 'smooth') => {
            const isFirst = link === navScroll.querySelector('a:first-of-type');
            if (isFirst) {
              navScroll.scrollTo({ left: 0, behavior });
              return;
            }
            const maxScroll = Math.max(0, navScroll.scrollWidth - navScroll.clientWidth);
            const isLast = link === navScroll.querySelector('a:last-of-type');
            if (isLast) {
              navScroll.scrollTo({ left: maxScroll, behavior });
              return;
            }
            const navRect = navScroll.getBoundingClientRect();
            const linkRect = link.getBoundingClientRect();
            if (navRect.width > 0 && linkRect.width > 0) {
              const linkLeft = (linkRect.left - navRect.left) + navScroll.scrollLeft;
              const targetLeft = linkLeft - (navScroll.clientWidth / 2) + (link.clientWidth / 2);
              const clampedLeft = Math.max(0, Math.min(targetLeft, maxScroll));
              navScroll.scrollTo({ left: clampedLeft, behavior });
            }
          };

          scrollToActive('smooth');
          requestAnimationFrame(() => scrollToActive('smooth'));
        }
      } else {
        link.removeAttribute('aria-current');
      }
    });

    // Kartları sıfırla / filtreleri uygula
    if (!isGlobalSearchMode) {
      if (searchInput) searchInput.value = '';
      toggleClearButton(searchInput);
      performSearch('', Boolean(activeSection));
    }

    // Sayfayı kategori başına yumuşak kaydır
    if (hasSelection && !isGlobalSearchMode) {
      window.scrollTo({ top: 0, behavior: 'instant' });
    }
  };

  // Kategori bağlantılarına tıklama dinleyicileri
  document.querySelectorAll('[data-category-link]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      const targetHash = link.getAttribute('href');
      isGlobalSearchMode = false;
      history.pushState(null, '', targetHash);
      showCategory();
    });
  });

  // "Kategoriler" geri dön bağlantıları
  document.querySelectorAll('[data-category-back], .back-to-categories').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      isGlobalSearchMode = false;
      history.pushState(null, '', '#categories');
      showCategory();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  window.addEventListener('popstate', () => {
    isGlobalSearchMode = false;
    showCategory();
  });
  window.addEventListener('hashchange', () => {
    showCategory();
  });

  // Arama Temizleme Butonu Kontrolü
  const toggleClearButton = (input) => {
    if (!input) return;
    const parentBox = input.closest('.search-box');
    if (!parentBox) return;
    const clear = parentBox.querySelector('.search-clear');
    if (clear) {
      clear.hidden = !input.value;
    }
  };

  // Canlı Arama ve Alerjen Filtreleme Mantığı
  const performSearch = (query = '', inActiveSectionOnly = true) => {
    const cleanQuery = normalize(query.trim());
    let matchCount = 0;

    const targetCards = inActiveSectionOnly && activeSection 
      ? activeSection.querySelectorAll('[data-product]') 
      : cards;

    // Eğer genel arama yapılıyorsa veya alerjen filtresi varsa ve kategori seçili değilse tüm bölümleri aç
    if (!activeSection && (cleanQuery.length > 0 || selectedAllergens.size > 0)) {
      isGlobalSearchMode = true;
      document.documentElement.classList.add('has-selection');
      sections.forEach(s => s.hidden = false);
    }

    targetCards.forEach((card) => {
      const searchTarget = normalize(card.dataset.search || '');
      const isSearchMatch = cleanQuery === '' || searchTarget.includes(cleanQuery);

      let isAllergenExcluded = false;
      if (selectedAllergens.size > 0) {
        const raw = (card.dataset.allergens || '')
          .split(',')
          .map(s => s.trim().toLowerCase())
          .filter(Boolean);
        isAllergenExcluded = raw.some(a => selectedAllergens.has(a));
      }

      const isVisible = isSearchMatch && !isAllergenExcluded;
      card.hidden = !isVisible;
      if (isVisible) matchCount++;
    });

    // Bölümlerin görünürlüğünü güncelle
    sections.forEach((sec) => {
      if (isGlobalSearchMode) {
        const visibleInSec = sec.querySelectorAll('[data-product]:not([hidden])').length;
        sec.hidden = visibleInSec === 0;
      } else if (activeSection) {
        const visibleInSec = sec.querySelectorAll('[data-product]:not([hidden])').length;
        sec.hidden = (sec !== activeSection) || (visibleInSec === 0);
      }
    });

    if (emptyState) {
      emptyState.hidden = matchCount > 0;
    }
  };

  // Kategori içi Arama Kutusu
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      toggleClearButton(searchInput);
      performSearch(searchInput.value, Boolean(activeSection));
    });

    const clear = searchInput.closest('.search-box')?.querySelector('.search-clear');
    if (clear) {
      clear.addEventListener('click', () => {
        searchInput.value = '';
        toggleClearButton(searchInput);
        performSearch('', Boolean(activeSection));
        searchInput.focus();
      });
    }
  }

  // Ana Sayfa Genel Arama Kutusu
  if (globalSearchInput) {
    globalSearchInput.addEventListener('input', () => {
      toggleClearButton(globalSearchInput);
      const val = globalSearchInput.value.trim();
      if (val.length > 0) {
        if (searchInput) {
          searchInput.value = globalSearchInput.value;
          toggleClearButton(searchInput);
        }
        performSearch(val, false);
      }
    });

    const clear = globalSearchInput.closest('.search-box')?.querySelector('.search-clear');
    if (clear) {
      clear.addEventListener('click', () => {
        globalSearchInput.value = '';
        toggleClearButton(globalSearchInput);
        if (searchInput) searchInput.value = '';
        performSearch('', false);
        globalSearchInput.focus();
      });
    }
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (searchInput) searchInput.value = '';
      if (globalSearchInput) globalSearchInput.value = '';
      toggleClearButton(searchInput);
      toggleClearButton(globalSearchInput);
      performSearch('', false);
    });
  }

  // ------------------------------------------------------------------------
  // Ürün Detay Dialog Mantığı (Modal / Bottom Sheet)
  // ------------------------------------------------------------------------
  const productDialog = document.querySelector('#product-dialog');
  if (productDialog && typeof productDialog.showModal === 'function') {
    const dialogImg = productDialog.querySelector('.dialog-image');
    const dialogName = productDialog.querySelector('#dialog-name');
    const dialogTag = productDialog.querySelector('#dialog-tag');
    const dialogDesc = productDialog.querySelector('#dialog-description');
    const dialogPrice = productDialog.querySelector('#dialog-price');
    const dialogOldPrice = productDialog.querySelector('#dialog-old-price');
    const dialogPriceBox = productDialog.querySelector('#dialog-price-box');
    const dialogMetaChips = productDialog.querySelector('#dialog-meta-chips');
    const dialogPrepChip = productDialog.querySelector('#dialog-prep-chip');
    const dialogPrepVal = productDialog.querySelector('#dialog-prep-val');
    const dialogCaloriesChip = productDialog.querySelector('#dialog-calories-chip');
    const dialogCaloriesVal = productDialog.querySelector('#dialog-calories-val');
    const dialogWeightChip = productDialog.querySelector('#dialog-weight-chip');
    const dialogWeightVal = productDialog.querySelector('#dialog-weight-val');
    const dialogAllergenTags = productDialog.querySelector('#dialog-allergen-tags');
    const drawerHandle = productDialog.querySelector('.drawer-handle');
    const dialogContent = productDialog.querySelector('.dialog-content');

    const isMobile = () => window.innerWidth <= 640;

    // --- Drawer Aç (Anında Görsel Yükleme & Kademeli Netleşme) ---
    const openModal = (data, cardBtn) => {
      dialogName.textContent = data.name || '';

      if (dialogTag) {
        if (data.tag && data.tag.trim() !== '') {
          dialogTag.textContent = data.tag.trim();
          dialogTag.hidden = false;
        } else {
          dialogTag.textContent = '';
          dialogTag.hidden = true;
        }
      }

      // Hazırlık & Besin Bilgileri (Pills)
      let hasMeta = false;
      if (dialogPrepChip && dialogPrepVal) {
        if (data.prep && data.prep.trim() !== '') {
          dialogPrepVal.textContent = data.prep.trim();
          dialogPrepChip.hidden = false;
          hasMeta = true;
        } else {
          dialogPrepChip.hidden = true;
        }
      }

      if (dialogCaloriesChip && dialogCaloriesVal) {
        const cal = (data.calories || '').trim().toLowerCase();
        const hasCal = cal !== '' && cal !== '0' && cal !== '0 kcal' && cal !== '0kcal';
        if (hasCal) {
          dialogCaloriesVal.textContent = data.calories.trim();
          dialogCaloriesChip.hidden = false;
          hasMeta = true;
        } else {
          dialogCaloriesVal.textContent = '';
          dialogCaloriesChip.hidden = true;
        }
      }

      if (dialogWeightChip && dialogWeightVal) {
        if (data.weight && data.weight.trim() !== '') {
          dialogWeightVal.textContent = data.weight.trim();
          dialogWeightChip.hidden = false;
          hasMeta = true;
        } else {
          dialogWeightChip.hidden = true;
        }
      }

      if (dialogMetaChips) {
        dialogMetaChips.hidden = !hasMeta;
      }

      if (data.description && data.description.trim() !== '') {
        dialogDesc.textContent = data.description.trim();
        dialogDesc.hidden = false;
      } else {
        dialogDesc.textContent = '';
        dialogDesc.hidden = true;
      }

      if (data.price && data.price.trim() !== '') {
        const hasDiscount = (data.hasDiscount === '1' || data.hasDiscount === 'true') && data.discountPrice && data.discountPrice.trim() !== '';
        if (hasDiscount) {
          if (dialogOldPrice) {
            dialogOldPrice.textContent = data.price;
            dialogOldPrice.hidden = false;
          }
          dialogPrice.textContent = data.discountPrice;
          dialogPriceBox.classList.add('has-discount');
        } else {
          if (dialogOldPrice) {
            dialogOldPrice.textContent = '';
            dialogOldPrice.hidden = true;
          }
          dialogPrice.textContent = data.price;
          dialogPriceBox.classList.remove('has-discount');
        }
        dialogPriceBox.hidden = false;
      } else {
        dialogPriceBox.hidden = true;
      }

      // Alerjen Etiketleri (Fiyat alanının hemen altında, yalın etiketler)
      if (dialogAllergenTags) {
        const rawAllergens = (data.allergens || '')
          .split(',')
          .map(s => s.trim().toLowerCase())
          .filter(Boolean);

        if (rawAllergens.length > 0 && window.QR_ALLERGENS) {
          dialogAllergenTags.innerHTML = '';
          let count = 0;
          rawAllergens.forEach((aId) => {
            const info = window.QR_ALLERGENS[aId];
            if (info) {
              const tag = document.createElement('span');
              tag.className = 'dialog-allergen-tag';
              tag.innerHTML = `<span class="dialog-allergen-icon">${info.icon || '⚠️'}</span><span>${info.name || aId}</span>`;
              dialogAllergenTags.appendChild(tag);
              count++;
            }
          });
          dialogAllergenTags.hidden = (count === 0);
        } else {
          dialogAllergenTags.innerHTML = '';
          dialogAllergenTags.hidden = true;
        }
      }

      dialogImg.alt = data.name || '';

      // 1. Karttaki mevcut (zaten indirilmiş) görseli 0ms beklemeden hemen göster
      const cardImg = cardBtn ? cardBtn.querySelector('.product-photo img') : null;
      const initialSrc = cardImg?.currentSrc || cardImg?.src || data.thumb || data.image || '';
      if (initialSrc) {
        dialogImg.src = initialSrc;
      }

      // 2. Büyük yüksek çözünürlüklü görseli arka planda yükle ve pürüzsüzce güncelle
      if (data.image && data.image !== initialSrc) {
        const full = new Image();
        full.onload = () => {
          if (productDialog.open && dialogName.textContent === (data.name || '')) {
            dialogImg.src = full.src;
          }
        };
        full.src = data.image;
      }

      // Scroll'u sıfırla
      if (dialogContent) dialogContent.scrollTop = 0;

      // Önceki kapanış kalıntılarını temizle
      productDialog.classList.remove('is-closing', 'is-dragging');
      productDialog.style.transform = '';

      // Zaten açıksa önce kapat
      if (productDialog.open) productDialog.close();

      document.body.classList.add('modal-open');
      productDialog.showModal();
    };

    // --- Drawer Kapat (animasyonlu) ---
    const closeModal = () => {
      productDialog.classList.add('is-closing');

      const onEnd = () => {
        productDialog.classList.remove('is-closing');
        productDialog.close();
        document.body.classList.remove('modal-open');
        productDialog.removeEventListener('transitionend', onEnd);
      };

      productDialog.addEventListener('transitionend', onEnd);
      // Fallback: animasyon olmazsa 400ms sonra kapat
      setTimeout(() => {
        if (productDialog.open) {
          productDialog.classList.remove('is-closing');
          productDialog.close();
          document.body.classList.remove('modal-open');
        }
      }, 400);
    };

    // Ürün kartlarına tıklama
    document.querySelectorAll('.open-product').forEach((cardBtn) => {
      cardBtn.addEventListener('click', () => openModal(cardBtn.dataset, cardBtn));
    });

    // Boşta kalınca ürün büyük görsellerini arka planda sessizce önbelleğe al
    const prefetchModalImages = () => {
      document.querySelectorAll('.open-product[data-image]').forEach((btn) => {
        const url = btn.dataset.image;
        if (url) {
          const pre = new Image();
          pre.src = url;
        }
      });
    };
    if (typeof requestIdleCallback === 'function') {
      requestIdleCallback(prefetchModalImages, { timeout: 2500 });
    } else {
      setTimeout(prefetchModalImages, 1500);
    }

    // Kapatma butonları
    productDialog.querySelectorAll('.dialog-close, .dialog-close-btn').forEach((btn) => {
      btn.addEventListener('click', closeModal);
    });

    // Backdrop tıklaması
    productDialog.addEventListener('click', (event) => {
      if (event.target === productDialog) closeModal();
    });

    // ESC tuşu (cancel olayı)
    productDialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      closeModal();
    });

    // --- Mobil Swipe-to-Dismiss (Touch Drawer) ---
    let startY = 0;
    let currentY = 0;
    let isDragging = false;

    const onTouchStart = (e) => {
      if (!isMobile() || !productDialog.open) return;
      // Sadece drawer handle veya scroll en üstteyse sürüklemeye izin ver
      const isHandle = e.target.closest('.drawer-handle');
      const isScrolledToTop = dialogContent && dialogContent.scrollTop <= 0;
      if (!isHandle && !isScrolledToTop) return;

      startY = e.touches[0].clientY;
      currentY = startY;
      isDragging = true;
      productDialog.classList.add('is-dragging');
    };

    const onTouchMove = (e) => {
      if (!isDragging) return;
      currentY = e.touches[0].clientY;
      const deltaY = currentY - startY;

      // Sadece aşağı sürükleme
      if (deltaY < 0) {
        productDialog.style.transform = '';
        return;
      }

      // Yukarı kaydırmayı engelle, drawer'ı hareket ettir
      e.preventDefault();
      productDialog.style.transform = `translateY(${deltaY}px)`;
    };

    const onTouchEnd = () => {
      if (!isDragging) return;
      isDragging = false;
      productDialog.classList.remove('is-dragging');

      const deltaY = currentY - startY;
      const threshold = 100; // px — bu kadar sürüklerse kapat

      if (deltaY > threshold) {
        closeModal();
      } else {
        // Geri çek
        productDialog.style.transform = '';
      }
    };

    productDialog.addEventListener('touchstart', onTouchStart, { passive: true });
    productDialog.addEventListener('touchmove', onTouchMove, { passive: false });
    productDialog.addEventListener('touchend', onTouchEnd, { passive: true });
  }

  // ------------------------------------------------------------------------
  // Geri Bildirim Dialog Mantığı & İnteraktif Yıldız Puanlama
  // ------------------------------------------------------------------------
  const feedbackDialog = document.querySelector('#feedback-dialog');
  if (feedbackDialog && typeof feedbackDialog.showModal === 'function') {
    const openButtons = [...document.querySelectorAll('[data-open-feedback]')];
    const closeBtn = feedbackDialog.querySelector('[data-close-feedback]');
    const starLabels = [...feedbackDialog.querySelectorAll('.rating-star-btn')];
    const statusText = feedbackDialog.querySelector('#rating-status');
    const commentField = feedbackDialog.querySelector('#feedback-comment');
    const commentCount = feedbackDialog.querySelector('#feedback-count');

    const updateCommentCount = () => {
      if (commentField && commentCount) commentCount.textContent = `${commentField.value.length} / 1000`;
    };

    const openFeedback = () => {
      document.body.classList.add('modal-open');
      feedbackDialog.showModal();
    };

    const closeFeedback = () => {
      document.body.classList.remove('modal-open');
      feedbackDialog.close();
    };

    openButtons.forEach((button) => button.addEventListener('click', openFeedback));
    if (closeBtn) closeBtn.addEventListener('click', closeFeedback);
    if (commentField) commentField.addEventListener('input', updateCommentCount);

    feedbackDialog.addEventListener('click', (event) => {
      if (event.target === feedbackDialog) closeFeedback();
    });

    feedbackDialog.addEventListener('cancel', () => {
      document.body.classList.remove('modal-open');
    });

    // Yıldız Hover & Seçim Efektleri
    const updateStarDisplay = (score) => {
      starLabels.forEach((label, idx) => {
        const starIndex = idx + 1;
        const isActive = starIndex <= score;
        label.classList.toggle('is-active', isActive);
      });
    };

    let selectedRating = 0;

    starLabels.forEach((label, idx) => {
      const starIndex = idx + 1;
      const radioInput = label.querySelector('input');
      const ratingWord = isEnglish ? 'stars' : 'Yıldız';
      const labelText = label.dataset.ratingLabel || `${starIndex} ${ratingWord}`;

      // Tıklama ile kalıcı seçim
      label.addEventListener('click', () => {
        selectedRating = starIndex;
        radioInput.checked = true;
        updateStarDisplay(selectedRating);
        if (statusText) {
          statusText.textContent = `${starIndex} / 5 ${ratingWord} - ${labelText}`;
        }
      });

      // Hover ile önizleme
      label.addEventListener('mouseenter', () => {
        updateStarDisplay(starIndex);
        if (statusText) {
          statusText.textContent = `${starIndex} / 5 ${ratingWord} - ${labelText}`;
        }
      });
    });

    // Yıldız alanından fare çıkınca seçili puana dön
    const ratingGroup = feedbackDialog.querySelector('.rating-group');
    if (ratingGroup) {
      ratingGroup.addEventListener('mouseleave', () => {
        updateStarDisplay(selectedRating);
        if (statusText) {
          statusText.textContent = selectedRating > 0 
            ? `${selectedRating} / 5 ${isEnglish ? 'stars' : 'Yıldız'}` 
            : (isEnglish ? 'Choose your rating' : 'Puanınızı seçin');
        }
      });
    }
  }

  // ------------------------------------------------------------------------
  // Alerjen Filtresi Mantığı (TGK 14 Alerjen)
  // ------------------------------------------------------------------------
  const allergenDialog = document.querySelector('#allergen-filter-dialog');
  const openAllergenBtns = document.querySelectorAll('[data-open-allergen-filter]');
  const activeAllergenBar = document.querySelector('#active-allergen-bar');
  const activeAllergenChips = document.querySelector('#active-allergen-chips');
  const activeAllergenClear = document.querySelector('#active-allergen-clear');
  const allergenBadgeCounts = document.querySelectorAll('.allergen-badge-count');

  if (allergenDialog && typeof allergenDialog.showModal === 'function') {
    const closeBtn = allergenDialog.querySelector('[data-close-allergen]');
    const allergenSelectChips = allergenDialog.querySelectorAll('.allergen-select-chip');
    const btnReset = allergenDialog.querySelector('#btn-reset-allergens');
    const btnApply = allergenDialog.querySelector('#btn-apply-allergens');
    const applyCountEl = allergenDialog.querySelector('#allergen-apply-count');
    const allergenContent = allergenDialog.querySelector('.allergen-dialog-content');

    let tempSelected = new Set(selectedAllergens);

    const updateDialogChipsUI = () => {
      allergenSelectChips.forEach((chip) => {
        const id = chip.dataset.allergenId;
        const isSel = tempSelected.has(id);
        chip.classList.toggle('is-selected', isSel);
      });
      if (applyCountEl) applyCountEl.textContent = tempSelected.size;
    };

    const updateFilterDisplay = () => {
      allergenBadgeCounts.forEach((badge) => {
        badge.textContent = selectedAllergens.size;
        badge.hidden = selectedAllergens.size === 0;
      });

      openAllergenBtns.forEach((btn) => {
        btn.classList.toggle('has-filter', selectedAllergens.size > 0);
      });

      if (activeAllergenBar && activeAllergenChips) {
        if (selectedAllergens.size > 0) {
          activeAllergenChips.innerHTML = '';
          selectedAllergens.forEach((aId) => {
            const info = (window.QR_ALLERGENS && window.QR_ALLERGENS[aId]) || { name: aId, icon: '⚠️' };
            const tag = document.createElement('button');
            tag.type = 'button';
            tag.className = 'active-pill-tag';
            tag.title = info.name + (isEnglish ? ' remove filter' : ' filtresini kaldır');
            tag.innerHTML = `<span>${info.icon}</span> <span>${info.name}</span> <span aria-hidden="true">×</span>`;
            tag.addEventListener('click', () => {
              selectedAllergens.delete(aId);
              tempSelected.delete(aId);
              updateFilterDisplay();
              performSearch(searchInput?.value || '', Boolean(activeSection));
            });
            activeAllergenChips.appendChild(tag);
          });
          activeAllergenBar.hidden = false;
        } else {
          activeAllergenBar.hidden = true;
          activeAllergenChips.innerHTML = '';
        }
      }
    };

    const openAllergenDialog = () => {
      tempSelected = new Set(selectedAllergens);
      updateDialogChipsUI();
      if (allergenContent) allergenContent.scrollTop = 0;
      allergenDialog.classList.remove('is-closing', 'is-dragging');
      allergenDialog.style.transform = '';
      if (allergenDialog.open) allergenDialog.close();
      document.body.classList.add('modal-open');
      allergenDialog.showModal();
    };

    const closeAllergenDialog = () => {
      allergenDialog.classList.add('is-closing');
      const onEnd = () => {
        allergenDialog.classList.remove('is-closing');
        allergenDialog.close();
        document.body.classList.remove('modal-open');
        allergenDialog.removeEventListener('transitionend', onEnd);
      };
      allergenDialog.addEventListener('transitionend', onEnd);
      setTimeout(() => {
        if (allergenDialog.open) {
          allergenDialog.classList.remove('is-closing');
          allergenDialog.close();
          document.body.classList.remove('modal-open');
        }
      }, 350);
    };

    openAllergenBtns.forEach((btn) => btn.addEventListener('click', openAllergenDialog));
    if (closeBtn) closeBtn.addEventListener('click', closeAllergenDialog);

    allergenSelectChips.forEach((chip) => {
      chip.addEventListener('click', () => {
        const id = chip.dataset.allergenId;
        if (tempSelected.has(id)) {
          tempSelected.delete(id);
        } else {
          tempSelected.add(id);
        }
        updateDialogChipsUI();
      });
    });

    if (btnReset) {
      btnReset.addEventListener('click', () => {
        tempSelected.clear();
        updateDialogChipsUI();
      });
    }

    if (btnApply) {
      btnApply.addEventListener('click', () => {
        selectedAllergens.clear();
        tempSelected.forEach(id => selectedAllergens.add(id));
        updateFilterDisplay();
        closeAllergenDialog();
        performSearch(searchInput?.value || '', Boolean(activeSection));
      });
    }

    if (activeAllergenClear) {
      activeAllergenClear.addEventListener('click', () => {
        selectedAllergens.clear();
        tempSelected.clear();
        updateFilterDisplay();
        performSearch(searchInput?.value || '', Boolean(activeSection));
      });
    }

    allergenDialog.addEventListener('click', (e) => {
      if (e.target === allergenDialog) closeAllergenDialog();
    });

    allergenDialog.addEventListener('cancel', (e) => {
      e.preventDefault();
      closeAllergenDialog();
    });

    // Touch Swipe-to-dismiss on mobile
    let aStartY = 0;
    let aCurrentY = 0;
    let aDragging = false;
    const isMobile = () => window.innerWidth <= 640;

    allergenDialog.addEventListener('touchstart', (e) => {
      if (!isMobile() || !allergenDialog.open) return;
      const isHandle = e.target.closest('.drawer-handle');
      const isScrolledToTop = allergenContent && allergenContent.scrollTop <= 0;
      if (!isHandle && !isScrolledToTop) return;
      aStartY = e.touches[0].clientY;
      aCurrentY = aStartY;
      aDragging = true;
      allergenDialog.classList.add('is-dragging');
    }, { passive: true });

    allergenDialog.addEventListener('touchmove', (e) => {
      if (!aDragging) return;
      aCurrentY = e.touches[0].clientY;
      const deltaY = aCurrentY - aStartY;
      if (deltaY < 0) {
        allergenDialog.style.transform = '';
        return;
      }
      e.preventDefault();
      allergenDialog.style.transform = `translateY(${deltaY}px)`;
    }, { passive: false });

    allergenDialog.addEventListener('touchend', () => {
      if (!aDragging) return;
      aDragging = false;
      allergenDialog.classList.remove('is-dragging');
      const deltaY = aCurrentY - aStartY;
      if (deltaY > 100) {
        closeAllergenDialog();
      } else {
        allergenDialog.style.transform = '';
      }
    }, { passive: true });
  }

  // İlk yüklemede URL hash kontrolü
  showCategory();
})();
