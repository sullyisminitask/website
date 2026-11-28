// chudcheats.xyz client logic
// strictly follows all specifications

document.addEventListener('DOMContentLoaded', () => {
  // state
  let cart = [];
  try {
    const savedCart = JSON.parse(localStorage.getItem('cart') || '[]');
    if (Array.isArray(savedCart)) {
      cart = savedCart;
    }
  } catch (e) {
    cart = [];
  }

  let selectedPlan = {
    name: '3 day',
    price: 16.99
  };

  let currentUser = null;
  let squareConfigState = {
    configured: false,
    environment: 'sandbox',
    currency: 'AUD',
    usdToAud: 1.51,
    rateSource: 'loading'
  };

  // elements
  const views = {
    'home': document.getElementById('view-home'),
    'shop': document.getElementById('view-shop'),
    'product': document.getElementById('view-product'),
    'status': document.getElementById('view-status'),
    'setup-guides': document.getElementById('view-setup-guides'),
    'my-keys': document.getElementById('view-my-keys'),
    'tos': document.getElementById('view-tos'),
    '404': document.getElementById('view-404')
  };

  const navTabs = document.querySelectorAll('.nav-tab[data-tab]');
  const allTabTriggers = document.querySelectorAll('[data-tab]');
  const brandLogo = document.getElementById('brand-logo');
  const cartCounter = document.getElementById('cart-counter');
  const cartModal = document.getElementById('cart-modal');
  const openCartBtn = document.getElementById('open-cart-btn');
  const closeCartBtn = document.getElementById('close-cart-btn');
  const cartItemsContainer = document.getElementById('cart-items');
  const cartTotalPrice = document.getElementById('cart-total-price');
  const checkoutBtn = document.getElementById('checkout-btn');
  const toastContainer = document.getElementById('toast-container');

  function showToast(message) {
    if (!toastContainer) return;
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.textContent = message;
    toastContainer.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 0.25s ease';
      setTimeout(() => toast.remove(), 250);
    }, 2600);
  }

  function saveCart() {
    try {
      localStorage.setItem('cart', JSON.stringify(cart));
    } catch (e) {}
  }

  // switch view
  function switchView(viewName) {
    Object.keys(views).forEach(key => {
      if (views[key]) {
        views[key].classList.toggle('active', key === viewName);
      }
    });

    navTabs.forEach(tab => {
      tab.classList.toggle('active', tab.getAttribute('data-tab') === viewName);
    });

    window.scrollTo({ top: 0, behavior: 'smooth' });
    history.replaceState(null, '', window.location.pathname + '#' + viewName);
  }

  // nav & tab clicks
  allTabTriggers.forEach(tab => {
    tab.addEventListener('click', (e) => {
      const targetView = tab.getAttribute('data-tab');
      if (targetView && views[targetView]) {
        e.preventDefault();
        switchView(targetView);
      }
    });
  });

  if (brandLogo) {
    brandLogo.addEventListener('click', (e) => {
      e.preventDefault();
      switchView('home');
    });
  }

  // hero & mini shop action buttons
  document.querySelectorAll('[data-action="go-shop"]').forEach(btn => {
    btn.addEventListener('click', () => switchView('shop'));
  });

  document.querySelectorAll('[data-action="go-product"]').forEach(btn => {
    btn.addEventListener('click', () => switchView('product'));
  });

  // product card clicks in shop & mini shop
  const cardRustOrbit = document.getElementById('card-rust-orbit');
  if (cardRustOrbit) {
    cardRustOrbit.addEventListener('click', () => {
      switchView('product');
    });
  }

  const homeMiniCard = document.getElementById('home-mini-card');
  if (homeMiniCard) {
    homeMiniCard.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;
      switchView('product');
    });
  }

  // plan selection
  const planOptions = document.querySelectorAll('.plan-option');
  const selectedPlanDisplay = document.getElementById('selected-plan-display');

  planOptions.forEach(option => {
    option.addEventListener('click', () => {
      planOptions.forEach(opt => opt.classList.remove('selected'));
      option.classList.add('selected');
      const radio = option.querySelector('input[type="radio"]');
      if (radio) {
        radio.checked = true;
      }
      const name = option.getAttribute('data-plan');
      const price = parseFloat(option.getAttribute('data-price'));
      selectedPlan = { name, price };
      if (selectedPlanDisplay) {
        selectedPlanDisplay.textContent = `${name} ($${price.toFixed(2)} usd)`;
      }
    });
  });

  // add to cart
  const addToCartBtn = document.getElementById('add-to-cart-btn');
  if (addToCartBtn) {
    addToCartBtn.addEventListener('click', () => {
      const existing = cart.find(i => (i.product || i.name) === 'rust orbit' && i.plan === selectedPlan.name);
      if (existing) {
        existing.quantity = (existing.quantity || 1) + 1;
      } else {
        cart.push({
          name: 'rust orbit',
          product: 'rust orbit',
          plan: selectedPlan.name,
          price: selectedPlan.price,
          quantity: 1
        });
      }
      saveCart();
      updateCartUI();
      showToast(`added rust orbit (${selectedPlan.name}) to cart`);
      openCart();
    });
  }

  function updateCartUI() {
    const totalCount = cart.reduce((sum, item) => sum + (item.quantity || 1), 0);
    if (cartCounter) {
      cartCounter.textContent = totalCount;
    }

    if (!cartItemsContainer) return;

    if (cart.length === 0) {
      cartItemsContainer.innerHTML = '<div class="empty-cart-msg">your cart is empty</div>';
      if (cartTotalPrice) cartTotalPrice.textContent = '$0.00 usd';
      return;
    }

    let total = 0;
    cartItemsContainer.innerHTML = '';
    cart.forEach((item, index) => {
      const qty = item.quantity || 1;
      const lineTotal = item.price * qty;
      total += lineTotal;
      const row = document.createElement('div');
      row.className = 'cart-item-row';
      row.innerHTML = `
        <div>
          <div class="cart-item-title">${item.product || item.name || 'rust orbit'}</div>
          <div class="cart-item-plan">${item.plan} license × ${qty}</div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
          <div class="cart-item-price">$${lineTotal.toFixed(2)} usd</div>
          <button class="remove-cart-item" data-index="${index}" style="background:transparent;border:none;color:#ef4444;cursor:pointer;font-size:0.8rem;">remove</button>
        </div>
      `;
      cartItemsContainer.appendChild(row);
    });

    if (cartTotalPrice) {
      cartTotalPrice.textContent = '$' + total.toFixed(2) + ' usd';
    }

    document.querySelectorAll('.remove-cart-item').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const idx = parseInt(e.target.getAttribute('data-index'), 10);
        cart.splice(idx, 1);
        saveCart();
        updateCartUI();
      });
    });
  }

  function openCart() {
    if (cartModal) cartModal.classList.add('active');
  }

  function closeCart() {
    if (cartModal) cartModal.classList.remove('active');
  }

  if (openCartBtn) openCartBtn.addEventListener('click', openCart);
  if (closeCartBtn) closeCartBtn.addEventListener('click', closeCart);
  if (cartModal) {
    cartModal.addEventListener('click', (e) => {
      if (e.target === cartModal) closeCart();
    });
  }

  // initialize cart UI from localStorage
  updateCartUI();

  // features accordion dropdowns + toggle-all button
  const accordionItems = document.querySelectorAll('.accordion-item');
  const toggleAllFeaturesBtn = document.getElementById('toggle-all-features-btn');

  accordionItems.forEach(item => {
    const trigger = item.querySelector('.accordion-trigger');
    if (trigger) {
      trigger.addEventListener('click', () => {
        const isOpen = item.classList.contains('open');
        item.classList.toggle('open', !isOpen);
        syncToggleAllLabel();
      });
    }
  });

  function syncToggleAllLabel() {
    if (!toggleAllFeaturesBtn || accordionItems.length === 0) return;
    const anyClosed = Array.from(accordionItems).some(i => !i.classList.contains('open'));
    toggleAllFeaturesBtn.textContent = anyClosed ? 'show all features' : 'collapse all';
  }

  if (toggleAllFeaturesBtn) {
    toggleAllFeaturesBtn.addEventListener('click', () => {
      const shouldOpen = Array.from(accordionItems).some(i => !i.classList.contains('open'));
      accordionItems.forEach(item => item.classList.toggle('open', shouldOpen));
      syncToggleAllLabel();
    });
  }

  const heroScrollHint = document.getElementById('hero-scroll-hint');
  if (heroScrollHint) {
    heroScrollHint.addEventListener('click', () => {
      const target = document.getElementById('home-advantages');
      if (target) target.scrollIntoView({ behavior: 'smooth' });
    });
  }

  // rdc-style hero interactive launcher pills
  const launcherPills = document.querySelectorAll('[data-launcher-plan]');
  const launcherPricePreview = document.getElementById('launcher-price-preview');
  launcherPills.forEach(pill => {
    pill.addEventListener('click', () => {
      launcherPills.forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      const planName = pill.getAttribute('data-launcher-plan');
      const priceVal = parseFloat(pill.getAttribute('data-launcher-price') || '16.99');
      if (launcherPricePreview) {
        launcherPricePreview.textContent = `$${priceVal.toFixed(2)} usd`;
      }
      // sync selected plan in product view too
      selectedPlan = { name: planName, price: priceVal };
      planOptions.forEach(opt => {
        const match = opt.getAttribute('data-plan') === planName;
        opt.classList.toggle('selected', match);
        const r = opt.querySelector('input[type="radio"]');
        if (r) r.checked = match;
      });
      if (selectedPlanDisplay) {
        selectedPlanDisplay.textContent = `${planName} ($${priceVal.toFixed(2)} usd)`;
      }
    });
  });

  // home subscription tier quick-add buttons
  document.querySelectorAll('[data-quick-plan]').forEach(btn => {
    btn.addEventListener('click', () => {
      const planName = btn.getAttribute('data-quick-plan');
      const priceVal = parseFloat(btn.getAttribute('data-quick-price') || '16.99');
      const existing = cart.find(i => (i.product || i.name) === 'rust orbit' && i.plan === planName);
      if (existing) {
        existing.quantity = (existing.quantity || 1) + 1;
      } else {
        cart.push({
          name: 'rust orbit',
          product: 'rust orbit',
          plan: planName,
          price: priceVal,
          quantity: 1
        });
      }
      saveCart();
      updateCartUI();
      showToast(`added rust orbit (${planName}) to cart`);
      openCart();
    });
  });

  // shop search filter
  const shopSearch = document.getElementById('shop-search');
  const stockCount = document.getElementById('stock-count');

  if (shopSearch && cardRustOrbit) {
    shopSearch.addEventListener('input', (e) => {
      const q = e.target.value.toLowerCase().trim();
      const match = 'rust orbit'.includes(q) || 'rust'.includes(q) || q === '';
      cardRustOrbit.style.display = match ? 'flex' : 'none';
      if (stockCount) {
        stockCount.textContent = match ? '1' : '0';
      }
    });
  }

  // status search filter
  const statusSearch = document.getElementById('status-search');
  const statusRows = document.querySelectorAll('.status-row-card');

  if (statusSearch) {
    statusSearch.addEventListener('input', (e) => {
      const q = e.target.value.toLowerCase().trim();
      statusRows.forEach(row => {
        const title = (row.getAttribute('data-title') || '').toLowerCase();
        row.style.display = title.includes(q) || q === '' ? 'flex' : 'none';
      });
    });
  }

  // my keys logic & database synchronization
  const redeemBtn = document.getElementById('redeem-key-btn');
  const keyInput = document.getElementById('license-key-input');
  const keyFeedback = document.getElementById('key-feedback');
  const activeKeysList = document.getElementById('active-keys-list');
  const orderHistoryList = document.getElementById('order-history-list');
  const keysCountBadge = document.getElementById('keys-count-badge');
  const ordersCountBadge = document.getElementById('orders-count-badge');
  const keysAccountStatus = document.getElementById('keys-account-status');
  const keysSyncBtn = document.getElementById('keys-sync-btn');
  const statTotalKeys = document.getElementById('stat-total-keys');
  const statActiveKeys = document.getElementById('stat-active-keys');
  const statTotalOrders = document.getElementById('stat-total-orders');
  const keysSearchInput = document.getElementById('keys-search-input');
  const keysSearchWrap = document.getElementById('keys-search-wrap');
  const subtabKeysBtn = document.getElementById('subtab-keys-btn');
  const subtabOrdersBtn = document.getElementById('subtab-orders-btn');
  const panelKeys = document.getElementById('panel-keys');
  const panelOrders = document.getElementById('panel-orders');

  let guestKeys = [];
  try {
    guestKeys = JSON.parse(localStorage.getItem('guest_keys') || '[]');
  } catch (e) {
    guestKeys = [];
  }

  function maskKeyString(key) {
    if (!key || key.length <= 8) return '••••••••';
    return key.slice(0, 6) + '••••-••••-' + key.slice(-4);
  }

  function copyToClipboard(text, btnElement) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => {
        const orig = btnElement.textContent;
        btnElement.textContent = 'copied!';
        showToast('key copied to clipboard');
        setTimeout(() => { btnElement.textContent = orig; }, 1800);
      }).catch(() => fallbackCopy(text, btnElement));
    } else {
      fallbackCopy(text, btnElement);
    }
  }

  function fallbackCopy(text, btnElement) {
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      const orig = btnElement.textContent;
      btnElement.textContent = 'copied!';
      showToast('key copied to clipboard');
      setTimeout(() => { btnElement.textContent = orig; }, 1800);
    } catch (e) {}
    document.body.removeChild(ta);
  }

  // My Keys subtabs switching
  document.querySelectorAll('[data-keys-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-keys-tab');
      if (subtabKeysBtn) subtabKeysBtn.classList.toggle('active', target === 'keys');
      if (subtabOrdersBtn) subtabOrdersBtn.classList.toggle('active', target === 'orders');
      if (panelKeys) panelKeys.classList.toggle('active', target === 'keys');
      if (panelOrders) panelOrders.classList.toggle('active', target === 'orders');
      if (keysSearchWrap) keysSearchWrap.style.display = target === 'keys' ? 'flex' : 'none';
    });
  });

  if (keysSearchInput) {
    keysSearchInput.addEventListener('input', () => {
      renderMyKeys();
    });
  }

  function renderMyKeys() {
    const userKeys = (currentUser && Array.isArray(currentUser.keys)) ? currentUser.keys : [];
    const keysAll = userKeys.length > 0 ? [...userKeys] : [...guestKeys];
    const ordersAll = (currentUser && Array.isArray(currentUser.orders)) ? currentUser.orders : [];

    const activeCount = keysAll.filter(k => (k.status || 'active').toLowerCase() === 'active').length;

    if (statTotalKeys) statTotalKeys.textContent = keysAll.length;
    if (statActiveKeys) statActiveKeys.textContent = activeCount;
    if (statTotalOrders) statTotalOrders.textContent = ordersAll.length;
    if (keysCountBadge) keysCountBadge.textContent = keysAll.length;
    if (ordersCountBadge) ordersCountBadge.textContent = ordersAll.length;

    if (keysAccountStatus) {
      if (currentUser) {
        keysAccountStatus.textContent = `account: ${currentUser.email} • synced with database`;
        if (keysSyncBtn) keysSyncBtn.textContent = 'view profile';
      } else {
        keysAccountStatus.textContent = `not signed in — guest keys are saved locally. log in to sync across devices.`;
        if (keysSyncBtn) keysSyncBtn.textContent = 'sign in / register';
      }
    }

    // Filter keys by search query
    const searchQ = keysSearchInput ? keysSearchInput.value.toLowerCase().trim() : '';
    const filteredKeys = searchQ
      ? keysAll.filter(k => {
          const hay = `${k.license_key || k.key || ''} ${k.product || ''} ${k.plan || ''} ${k.order_id || ''}`.toLowerCase();
          return hay.includes(searchQ);
        })
      : keysAll;

    if (activeKeysList) {
      if (keysAll.length === 0) {
        activeKeysList.innerHTML = `
          <div class="empty-keys-placeholder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
            <p>no license keys found in this account.</p>
            <button class="btn-primary btn-sm" data-action="go-shop">browse shop to purchase</button>
          </div>
        `;
        const browseBtn = activeKeysList.querySelector('[data-action="go-shop"]');
        if (browseBtn) browseBtn.addEventListener('click', () => switchView('shop'));
      } else if (filteredKeys.length === 0) {
        activeKeysList.innerHTML = `
          <div class="empty-keys-placeholder">
            <p>no keys match "${searchQ}"</p>
          </div>
        `;
      } else {
        activeKeysList.innerHTML = '';
        filteredKeys.forEach(item => {
          const card = document.createElement('div');
          card.className = 'key-card-item';
          const keyVal = (item.license_key || item.key || '').toLowerCase();
          const prodName = (item.product || 'rust orbit').toLowerCase();
          const planName = (item.plan || '1 day').toLowerCase();
          const orderRef = item.order_id ? item.order_id.toLowerCase() : '';
          const dateStr = item.created_at ? item.created_at.split(' ')[0] : 'recent';
          const statusStr = (item.status || 'active').toLowerCase();
          const statusClass = statusStr === 'revoked' ? 'revoked' : (statusStr === 'pending' ? 'pending' : '');

          let revealed = false;

          card.innerHTML = `
            <div class="key-card-top">
              <div class="key-card-product">
                <span class="key-product-name">${prodName}</span>
                <span class="key-plan-pill">${planName}</span>
                ${orderRef ? `<span class="key-order-pill">#${orderRef}</span>` : ''}
              </div>
              <span class="key-status-badge ${statusClass}">${statusStr}</span>
            </div>
            <div class="key-card-middle">
              <span class="key-card-code">${maskKeyString(keyVal)}</span>
              <div class="key-card-actions">
                <button class="btn-icon-action" title="reveal or hide key" aria-label="reveal key">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <button class="btn-copy-key">copy key</button>
              </div>
            </div>
            <div class="key-card-footer">
              <span class="key-card-date">issued: ${dateStr}</span>
              <button class="btn-setup-link">setup guide</button>
            </div>
          `;

          const codeEl = card.querySelector('.key-card-code');
          const toggleBtn = card.querySelector('.btn-icon-action');
          const copyBtn = card.querySelector('.btn-copy-key');
          const setupBtn = card.querySelector('.btn-setup-link');

          if (toggleBtn && codeEl) {
            toggleBtn.addEventListener('click', () => {
              revealed = !revealed;
              codeEl.textContent = revealed ? keyVal : maskKeyString(keyVal);
              toggleBtn.innerHTML = revealed
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
            });
          }

          if (copyBtn) {
            copyBtn.addEventListener('click', () => copyToClipboard(keyVal, copyBtn));
          }

          if (setupBtn) {
            setupBtn.addEventListener('click', () => switchView('setup-guides'));
          }

          activeKeysList.appendChild(card);
        });
      }
    }

    // Render Order History
    if (orderHistoryList) {
      if (ordersAll.length === 0) {
        orderHistoryList.innerHTML = `
          <div class="empty-keys-placeholder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>${currentUser ? 'no orders found in this account yet.' : 'sign in to view your square order history.'}</p>
          </div>
        `;
      } else {
        orderHistoryList.innerHTML = '';
        ordersAll.forEach(ord => {
          const card = document.createElement('div');
          card.className = 'order-card-item';
          let itemsDesc = 'rust orbit';
          try {
            const parsed = JSON.parse(ord.items || '[]');
            if (Array.isArray(parsed) && parsed.length > 0) {
              itemsDesc = parsed.map(i => `${i.product || i.name || 'rust orbit'} (${i.plan || '1 day'}) × ${i.quantity || 1}`).join(', ');
            }
          } catch (e) {}

          const st = (ord.status || 'pending').toLowerCase();
          const stClass = st === 'completed' ? '' : 'pending';
          const amt = parseFloat(ord.amount || 0).toFixed(2);
          const dateStr = ord.created_at ? ord.created_at.split(' ')[0] : '';

          card.innerHTML = `
            <div class="key-card-top">
              <div class="key-card-product">
                <span class="key-product-name">#${(ord.order_id || '').toLowerCase()}</span>
                <span class="key-plan-pill">${dateStr}</span>
              </div>
              <span class="key-status-badge ${stClass}">${st}</span>
            </div>
            <div class="order-summary-line">
              <span>${itemsDesc}</span>
              <span class="order-amount">$${amt} ${(ord.currency || 'usd').toLowerCase()}</span>
            </div>
            ${st === 'pending' ? `<div class="key-card-footer"><span class="key-card-date">awaiting square confirmation</span><button class="btn-copy-key verify-order-btn" data-order-id="${ord.order_id}">verify payment</button></div>` : ''}
          `;

          const verifyBtn = card.querySelector('.verify-order-btn');
          if (verifyBtn) {
            verifyBtn.addEventListener('click', async () => {
              verifyBtn.disabled = true;
              verifyBtn.textContent = 'checking...';
              try {
                const res = await fetch(`api/square.php?action=verify_order&order_id=${encodeURIComponent(ord.order_id)}`);
                const data = await res.json();
                if (data.success) {
                  showToast('payment verified! keys added');
                  await checkAuthSession();
                } else {
                  showToast(data.message || 'payment still pending');
                  verifyBtn.disabled = false;
                  verifyBtn.textContent = 'verify payment';
                }
              } catch (err) {
                showToast('error verifying order');
                verifyBtn.disabled = false;
                verifyBtn.textContent = 'verify payment';
              }
            });
          }

          orderHistoryList.appendChild(card);
        });
      }
    }
  }

  if (keysSyncBtn) {
    keysSyncBtn.addEventListener('click', openAccountModal);
  }

  if (redeemBtn && keyInput) {
    redeemBtn.addEventListener('click', async () => {
      const key = keyInput.value.trim().toLowerCase();
      if (!key) {
        if (keyFeedback) {
          keyFeedback.className = 'key-feedback error';
          keyFeedback.textContent = 'please enter a valid key';
        }
        return;
      }

      try {
        const res = await fetch('api/auth.php?action=redeem_key', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ license_key: key, product: 'rust orbit' })
        });
        const data = await res.json();
        if (data.success) {
          if (keyFeedback) {
            keyFeedback.className = 'key-feedback success';
            keyFeedback.textContent = (data.message || 'key bound successfully!').toLowerCase();
          }
          keyInput.value = '';
          if (currentUser) {
            await checkAuthSession();
          } else {
            guestKeys.unshift({ license_key: key, product: 'rust orbit', plan: 'custom', status: 'active', created_at: new Date().toISOString().split('T')[0] });
            localStorage.setItem('guest_keys', JSON.stringify(guestKeys));
            renderMyKeys();
          }
        } else {
          if (keyFeedback) {
            keyFeedback.className = 'key-feedback error';
            keyFeedback.textContent = (data.message || 'could not activate key').toLowerCase();
          }
        }
      } catch (err) {
        guestKeys.unshift({ license_key: key, product: 'rust orbit', plan: 'custom', status: 'active', created_at: new Date().toISOString().split('T')[0] });
        localStorage.setItem('guest_keys', JSON.stringify(guestKeys));
        renderMyKeys();
        if (keyFeedback) {
          keyFeedback.className = 'key-feedback success';
          keyFeedback.textContent = 'key stored locally';
        }
        keyInput.value = '';
      }
    });
  }

  // Account & Authentication system
  const accountModal = document.getElementById('account-modal');
  const openAccountBtn = document.getElementById('open-account-btn');
  const closeAccountBtn = document.getElementById('close-account-btn');
  const accountBtnText = document.getElementById('account-btn-text');
  const authFeedback = document.getElementById('auth-feedback');

  const tabBtnLogin = document.getElementById('tab-btn-login');
  const tabBtnRegister = document.getElementById('tab-btn-register');
  const loginForm = document.getElementById('login-form');
  const registerForm = document.getElementById('register-form');
  const authFormsContainer = document.getElementById('auth-forms-container');
  const authProfileContainer = document.getElementById('auth-profile-container');
  const profileEmailDisplay = document.getElementById('profile-email-display');
  const profileCreatedDisplay = document.getElementById('profile-created-display');
  const profileKeysList = document.getElementById('profile-keys-list');
  const logoutBtn = document.getElementById('logout-btn');

  function showFeedback(msg, isError = true) {
    if (!authFeedback) return;
    authFeedback.textContent = msg.toLowerCase();
    authFeedback.className = 'auth-feedback ' + (isError ? 'error' : 'success');
  }

  function clearFeedback() {
    if (!authFeedback) return;
    authFeedback.textContent = '';
    authFeedback.className = 'auth-feedback';
  }

  function openAccountModal() {
    clearFeedback();
    if (accountModal) accountModal.classList.add('active');
  }

  function closeAccountModal() {
    clearFeedback();
    if (accountModal) accountModal.classList.remove('active');
  }

  if (openAccountBtn) openAccountBtn.addEventListener('click', openAccountModal);
  if (closeAccountBtn) closeAccountBtn.addEventListener('click', closeAccountModal);
  if (accountModal) {
    accountModal.addEventListener('click', (e) => {
      if (e.target === accountModal) closeAccountModal();
    });
  }

  if (tabBtnLogin && tabBtnRegister) {
    tabBtnLogin.addEventListener('click', () => {
      tabBtnLogin.classList.add('active');
      tabBtnRegister.classList.remove('active');
      loginForm.classList.add('active');
      registerForm.classList.remove('active');
      clearFeedback();
    });

    tabBtnRegister.addEventListener('click', () => {
      tabBtnRegister.classList.add('active');
      tabBtnLogin.classList.remove('active');
      registerForm.classList.add('active');
      loginForm.classList.remove('active');
      clearFeedback();
    });
  }

  function updateAuthUI(user) {
    currentUser = user;
    if (user) {
      if (accountBtnText) {
        accountBtnText.textContent = user.username || user.email.split('@')[0];
      }
      if (openAccountBtn) {
        openAccountBtn.classList.add('logged-in');
      }
      if (authFormsContainer) authFormsContainer.style.display = 'none';
      if (authProfileContainer) authProfileContainer.style.display = 'block';

      if (profileEmailDisplay) profileEmailDisplay.textContent = user.email;
      if (profileCreatedDisplay) profileCreatedDisplay.textContent = user.created_at ? user.created_at.split(' ')[0] : 'today';

      if (profileKeysList) {
        profileKeysList.innerHTML = '';
        if (user.keys && user.keys.length > 0) {
          user.keys.forEach(k => {
            const card = document.createElement('div');
            card.className = 'profile-key-card';
            card.innerHTML = `
              <span class="profile-key-code">${(k.license_key || '').toLowerCase()}</span>
              <span class="profile-key-tag">${k.product || 'rust orbit'} (${k.plan || '1 day'})</span>
            `;
            profileKeysList.appendChild(card);
          });
        } else {
          profileKeysList.innerHTML = '<div class="empty-keys-msg">no active license keys registered yet.</div>';
        }
      }
    } else {
      if (accountBtnText) {
        accountBtnText.textContent = 'account';
      }
      if (openAccountBtn) {
        openAccountBtn.classList.remove('logged-in');
      }
      if (authFormsContainer) authFormsContainer.style.display = 'block';
      if (authProfileContainer) authProfileContainer.style.display = 'none';
    }

    renderMyKeys();
  }

  async function checkAuthSession() {
    try {
      const res = await fetch('api/auth.php?action=me');
      const data = await res.json();
      if (data.success && data.logged_in && data.user) {
        updateAuthUI(data.user);
      } else {
        updateAuthUI(null);
      }
    } catch (err) {
      updateAuthUI(null);
    }
  }

  if (registerForm) {
    registerForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFeedback();

      const email = document.getElementById('register-email').value.trim().toLowerCase();
      const password = document.getElementById('register-password').value;
      const confirmPassword = document.getElementById('register-confirm-password').value;

      if (!email || !password) {
        showFeedback('email and password are required.');
        return;
      }

      if (password !== confirmPassword) {
        showFeedback('passwords do not match.');
        return;
      }

      if (password.length < 6) {
        showFeedback('password must be at least 6 characters.');
        return;
      }

      try {
        const res = await fetch('api/auth.php?action=register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, password, confirm_password: confirmPassword })
        });
        const data = await res.json();

        if (data.success) {
          showFeedback('account created successfully!', false);
          registerForm.reset();
          setTimeout(() => {
            updateAuthUI(data.user);
          }, 500);
        } else {
          showFeedback(data.message || 'registration failed.');
        }
      } catch (err) {
        showFeedback('connection error. check server setup.');
      }
    });
  }

  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearFeedback();

      const email = document.getElementById('login-email').value.trim().toLowerCase();
      const password = document.getElementById('login-password').value;

      if (!email || !password) {
        showFeedback('please enter both email and password.');
        return;
      }

      try {
        const res = await fetch('api/auth.php?action=login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, password })
        });
        const data = await res.json();

        if (data.success) {
          showFeedback('welcome back!', false);
          loginForm.reset();
          setTimeout(() => {
            updateAuthUI(data.user);
          }, 400);
        } else {
          showFeedback(data.message || 'invalid email or password.');
        }
      } catch (err) {
        showFeedback('connection error. check server setup.');
      }
    });
  }

  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try {
        await fetch('api/auth.php?action=logout', { method: 'POST' });
      } catch (err) {}
      updateAuthUI(null);
      closeAccountModal();
    });
  }

  // Square Checkout Modal Controller
  const checkoutModal = document.getElementById('checkout-modal');
  const closeCheckoutBtn = document.getElementById('close-checkout-btn');
  const checkoutItemsList = document.getElementById('checkout-items-list');
  const checkoutTotalPrice = document.getElementById('checkout-total-price');
  const checkoutEmail = document.getElementById('checkout-email');
  const paySquareBtn = document.getElementById('pay-square-btn');
  const checkoutFeedback = document.getElementById('checkout-feedback');
  const squareModeBadge = document.getElementById('square-mode-badge');
  const checkoutFxBox = document.getElementById('checkout-fx-box');
  const checkoutFxPair = document.getElementById('checkout-fx-pair');
  const checkoutFxRate = document.getElementById('checkout-fx-rate');
  const checkoutPromoInput = document.getElementById('checkout-promo-input');
  const applyPromoBtn = document.getElementById('apply-promo-btn');
  const checkoutPromoFeedback = document.getElementById('checkout-promo-feedback');
  const checkoutTosCheckbox = document.getElementById('checkout-tos-checkbox');
  const toggleCheckoutTosBtn = document.getElementById('toggle-checkout-tos-btn');
  const checkoutTosPreview = document.getElementById('checkout-tos-preview');
  const openFullTosBtn = document.getElementById('open-full-tos-btn');

  let appliedPromo = null;

  function setPromoFeedback(msg, isError = false) {
    if (!checkoutPromoFeedback) return;
    if (!msg) {
      checkoutPromoFeedback.textContent = '';
      checkoutPromoFeedback.className = 'promo-feedback';
      return;
    }
    checkoutPromoFeedback.textContent = msg.toLowerCase();
    checkoutPromoFeedback.className = 'promo-feedback ' + (isError ? 'error' : 'success');
  }

  function clearAppliedPromo() {
    appliedPromo = null;
    if (applyPromoBtn) applyPromoBtn.textContent = 'apply';
    setPromoFeedback('');
    updateCheckoutConversionUI();
  }

  function updateCheckoutConversionUI() {
    let totalUsd = 0;
    let totalAud = 0;
    const rate = squareConfigState.usdToAud || 1.51;
    const isAud = (squareConfigState.currency || 'AUD').toUpperCase() === 'AUD';
    const discountPct = appliedPromo ? Math.max(0, Math.min(100, Number(appliedPromo.discount_percent || 0))) : 0;

    cart.forEach(item => {
      const qty = item.quantity || 1;
      const baseUsd = Number(item.price || 0);
      const unitUsd = Math.round(baseUsd * ((100 - discountPct) / 100) * 100) / 100;
      totalUsd += unitUsd * qty;
      const unitAud = Math.round(unitUsd * rate * 100) / 100;
      totalAud += unitAud * qty;
    });

    if (checkoutTotalPrice) {
      checkoutTotalPrice.textContent = appliedPromo && totalUsd === 0
        ? `$0.00 usd (free via ${appliedPromo.code})`
        : '$' + totalUsd.toFixed(2) + ' usd';
    }

    const isFree = totalUsd <= 0;
    if (checkoutFxBox) {
      checkoutFxBox.style.display = (!isFree && isAud) ? 'flex' : 'none';
    }
    if (checkoutFxPair) {
      checkoutFxPair.textContent = `$${totalUsd.toFixed(2)} usd > $${totalAud.toFixed(2)} aud`;
    }
    if (checkoutFxRate) {
      const src = squareConfigState.rateSource === 'live' ? 'live rate' : (squareConfigState.rateSource || 'rate');
      checkoutFxRate.textContent = `1 usd = ${rate.toFixed(4)} aud (${src})`;
    }

    const btnTextEl = document.getElementById('pay-square-btn-text');
    if (btnTextEl) {
      if (isFree) {
        btnTextEl.textContent = 'complete free order';
      } else {
        btnTextEl.textContent = isAud
          ? `pay $${totalAud.toFixed(2)} aud with square`
          : `pay $${totalUsd.toFixed(2)} usd with square`;
      }
    }
  }

  async function loadSquareConfig() {
    try {
      const res = await fetch('api/square.php?action=config');
      const data = await res.json();
      if (data.success) {
        squareConfigState = {
          configured: !!data.configured,
          environment: (data.environment || 'sandbox').toLowerCase(),
          currency: (data.currency || 'AUD').toUpperCase(),
          usdToAud: Number(data.usd_to_aud) || 1.51,
          rateSource: data.rate_source || 'live'
        };
        if (squareModeBadge) {
          if (squareConfigState.configured) {
            squareModeBadge.textContent = squareConfigState.environment === 'production'
              ? 'square live checkout'
              : 'square sandbox';
            squareModeBadge.className = 'square-badge ' + (squareConfigState.environment === 'production' ? 'live' : 'sandbox');
          } else {
            squareModeBadge.textContent = 'sandbox demo (unconfigured)';
            squareModeBadge.className = 'square-badge sandbox';
          }
        }
        updateCheckoutConversionUI();
      }
    } catch (e) {}
  }

  function openCheckoutModal() {
    if (cart.length === 0) {
      showToast('your cart is empty. select a plan first.');
      return;
    }

    closeCart();

    if (checkoutFeedback) {
      checkoutFeedback.className = 'auth-feedback';
      checkoutFeedback.textContent = '';
    }

    clearAppliedPromo();
    if (checkoutPromoInput) checkoutPromoInput.value = '';
    if (checkoutTosCheckbox) checkoutTosCheckbox.checked = false;
    if (checkoutTosPreview) checkoutTosPreview.style.display = 'none';

    if (checkoutItemsList) {
      checkoutItemsList.innerHTML = '';
      cart.forEach(item => {
        const qty = item.quantity || 1;
        const prodName = item.product || item.name || 'rust orbit';
        const itemTotal = item.price * qty;
        const row = document.createElement('div');
        row.className = 'checkout-item-row';
        row.innerHTML = `
          <span class="checkout-item-title">${prodName} (${item.plan}) × ${qty}</span>
          <span class="checkout-item-price">$${itemTotal.toFixed(2)} usd</span>
        `;
        checkoutItemsList.appendChild(row);
      });
    }

    updateCheckoutConversionUI();
    loadSquareConfig();

    if (checkoutEmail && currentUser && currentUser.email) {
      checkoutEmail.value = currentUser.email;
    }

    if (checkoutModal) {
      checkoutModal.classList.add('active');
    }
  }

  function closeCheckoutModal() {
    if (checkoutModal) {
      checkoutModal.classList.remove('active');
    }
  }

  if (toggleCheckoutTosBtn && checkoutTosPreview) {
    toggleCheckoutTosBtn.addEventListener('click', () => {
      const open = checkoutTosPreview.style.display !== 'none';
      checkoutTosPreview.style.display = open ? 'none' : 'flex';
    });
  }

  if (openFullTosBtn) {
    openFullTosBtn.addEventListener('click', () => {
      closeCheckoutModal();
      switchView('tos');
    });
  }

  if (applyPromoBtn && checkoutPromoInput) {
    applyPromoBtn.addEventListener('click', async () => {
      const code = checkoutPromoInput.value.trim().toLowerCase();
      if (appliedPromo && appliedPromo.code === code) {
        checkoutPromoInput.value = '';
        clearAppliedPromo();
        return;
      }

      if (!code) {
        clearAppliedPromo();
        setPromoFeedback('enter a promo code first.', true);
        return;
      }

      applyPromoBtn.disabled = true;
      applyPromoBtn.textContent = '...';
      try {
        const res = await fetch(`api/square.php?action=check_promo&code=${encodeURIComponent(code)}`);
        const data = await res.json();
        if (data.success) {
          appliedPromo = {
            code: data.code,
            discount_percent: data.discount_percent,
            uses_remaining: data.uses_remaining
          };
          applyPromoBtn.textContent = 'remove';
          setPromoFeedback(data.message || `promo "${data.code}" applied!`, false);
          updateCheckoutConversionUI();
        } else {
          appliedPromo = null;
          applyPromoBtn.textContent = 'apply';
          setPromoFeedback(data.message || 'invalid promo code.', true);
          updateCheckoutConversionUI();
        }
      } catch (err) {
        setPromoFeedback('could not verify promo code.', true);
      } finally {
        applyPromoBtn.disabled = false;
      }
    });

    checkoutPromoInput.addEventListener('input', () => {
      if (appliedPromo && checkoutPromoInput.value.trim().toLowerCase() !== appliedPromo.code) {
        clearAppliedPromo();
      }
    });
  }

  if (checkoutBtn) {
    checkoutBtn.addEventListener('click', (e) => {
      e.preventDefault();
      openCheckoutModal();
    });
  }

  if (closeCheckoutBtn) {
    closeCheckoutBtn.addEventListener('click', closeCheckoutModal);
  }

  if (checkoutModal) {
    checkoutModal.addEventListener('click', (e) => {
      if (e.target === checkoutModal) closeCheckoutModal();
    });
  }

  // Close active modals on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeCart();
      closeAccountModal();
      closeCheckoutModal();
    }
  });

  if (paySquareBtn) {
    paySquareBtn.addEventListener('click', async () => {
      const email = (checkoutEmail ? checkoutEmail.value.trim().toLowerCase() : '');
      if (!email || !email.includes('@') || !email.includes('.')) {
        if (checkoutFeedback) {
          checkoutFeedback.className = 'auth-feedback error';
          checkoutFeedback.textContent = 'please enter a valid email address for key delivery.';
        }
        return;
      }

      if (!checkoutTosCheckbox || !checkoutTosCheckbox.checked) {
        if (checkoutFeedback) {
          checkoutFeedback.className = 'auth-feedback error';
          checkoutFeedback.textContent = 'you must accept the terms of service before continuing.';
        }
        if (checkoutTosCheckbox) checkoutTosCheckbox.focus();
        return;
      }

      if (checkoutFeedback) {
        checkoutFeedback.className = 'auth-feedback';
        checkoutFeedback.textContent = '';
      }

      const btnTextEl = document.getElementById('pay-square-btn-text');
      const prevBtnLabel = btnTextEl ? btnTextEl.textContent : 'pay with square';
      paySquareBtn.disabled = true;
      if (btnTextEl) {
        btnTextEl.textContent = appliedPromo && appliedPromo.discount_percent >= 100
          ? 'completing free order...'
          : 'connecting to square...';
      }

      try {
        const res = await fetch('api/square.php?action=create_checkout', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            items: cart,
            email: email,
            promo_code: appliedPromo ? appliedPromo.code : '',
            tos_accepted: true
          })
        });
        const data = await res.json();

        if (data.success) {
          if (data.checkout_url) {
            window.location.href = data.checkout_url;
            return;
          }

          cart = [];
          localStorage.removeItem('cart');
          updateCartUI();
          clearAppliedPromo();

          if (data.keys && data.keys.length > 0) {
            data.keys.forEach(k => {
              guestKeys.unshift(k);
            });
            localStorage.setItem('guest_keys', JSON.stringify(guestKeys));
          }

          await checkAuthSession();

          closeCheckoutModal();
          switchView('my-keys');

          if (keyFeedback) {
            keyFeedback.className = 'key-feedback success';
            keyFeedback.textContent = (data.message || 'order completed! your license keys are ready below.').toLowerCase();
          }
          showToast('keys delivered to my keys');
        } else {
          if (checkoutFeedback) {
            checkoutFeedback.className = 'auth-feedback error';
            checkoutFeedback.textContent = (data.message || 'checkout failed. please try again.').toLowerCase();
          }
        }
      } catch (err) {
        if (checkoutFeedback) {
          checkoutFeedback.className = 'auth-feedback error';
          checkoutFeedback.textContent = 'network error contacting square api. please ensure php server is running.';
        }
      } finally {
        paySquareBtn.disabled = false;
        const restoreEl = document.getElementById('pay-square-btn-text');
        if (restoreEl) restoreEl.textContent = prevBtnLabel;
        updateCheckoutConversionUI();
      }
    });
  }

  // Handle return from Square Payment Link
  const urlParams = new URLSearchParams(window.location.search);
  const returnedSuccess = urlParams.get('payment_success') === '1';
  const returnedPending = urlParams.get('payment_pending') === '1';
  const returnedOrder = urlParams.get('order') || '';

  if (returnedSuccess) {
    cart = [];
    localStorage.removeItem('cart');
    updateCartUI();
  }

  // Initialize Square config + Auth session
  loadSquareConfig();
  checkAuthSession().then(() => {
    if (returnedSuccess) {
      switchView('my-keys');
      if (keyFeedback) {
        keyFeedback.className = 'key-feedback success';
        keyFeedback.textContent = `payment confirmed by square${returnedOrder ? ' (#' + returnedOrder.toLowerCase() + ')' : ''}! your license keys are listed below.`;
      }
    } else if (returnedPending) {
      switchView('my-keys');
      if (subtabOrdersBtn) subtabOrdersBtn.click();
      if (keyFeedback) {
        keyFeedback.className = 'key-feedback error';
        keyFeedback.textContent = `payment still pending on square${returnedOrder ? ' (#' + returnedOrder.toLowerCase() + ')' : ''}. click verify payment in order history.`;
      }
    }
  });

  // check initial route and hash changes
  function handleHashRoute() {
    const rawHash = window.location.hash.replace('#', '').split('?')[0].trim();
    if (!rawHash) {
      if (!returnedSuccess && !returnedPending) {
        switchView('home');
      }
      return;
    }
    if (views[rawHash]) {
      switchView(rawHash);
    } else {
      switchView('404');
    }
  }

  window.addEventListener('hashchange', handleHashRoute);
  handleHashRoute();
});
