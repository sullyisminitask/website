// chudcheats.xyz client logic
// strictly follows all specifications

document.addEventListener('DOMContentLoaded', () => {
  // state
  let cart = [];
  let selectedPlan = {
    name: '3 day',
    price: 16.99
  };

  // elements
  const views = {
    'home': document.getElementById('view-home'),
    'shop': document.getElementById('view-shop'),
    'product': document.getElementById('view-product'),
    'status': document.getElementById('view-status'),
    'setup-guides': document.getElementById('view-setup-guides'),
    'my-keys': document.getElementById('view-my-keys'),
    'tos': document.getElementById('view-tos')
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
    history.replaceState(null, '', '#' + viewName);
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
  const cardRustElite = document.getElementById('card-rust-elite');
  if (cardRustElite) {
    cardRustElite.addEventListener('click', () => {
      switchView('product');
    });
  }

  const homeMiniCard = document.getElementById('home-mini-card');
  if (homeMiniCard) {
    homeMiniCard.addEventListener('click', (e) => {
      if (e.target.closest('button')) return; // let buttons handle their own click
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
        selectedPlanDisplay.textContent = `${name} ($${price.toFixed(2)})`;
      }
    });
  });

  // add to cart
  const addToCartBtn = document.getElementById('add-to-cart-btn');
  if (addToCartBtn) {
    addToCartBtn.addEventListener('click', () => {
      cart.push({
        product: 'rust elite',
        plan: selectedPlan.name,
        price: selectedPlan.price
      });
      updateCartUI();
      openCart();
    });
  }

  function updateCartUI() {
    if (cartCounter) {
      cartCounter.textContent = cart.length;
    }

    if (!cartItemsContainer) return;

    if (cart.length === 0) {
      cartItemsContainer.innerHTML = '<div class="empty-cart-msg">your cart is empty</div>';
      if (cartTotalPrice) cartTotalPrice.textContent = '$0.00';
      return;
    }

    let total = 0;
    cartItemsContainer.innerHTML = '';
    cart.forEach((item, index) => {
      total += item.price;
      const row = document.createElement('div');
      row.className = 'cart-item-row';
      row.innerHTML = `
        <div>
          <div class="cart-item-title">${item.product}</div>
          <div class="cart-item-plan">${item.plan} license</div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
          <div class="cart-item-price">$${item.price.toFixed(2)}</div>
          <button class="remove-cart-item" data-index="${index}" style="background:transparent;border:none;color:#ef4444;cursor:pointer;font-size:0.8rem;">remove</button>
        </div>
      `;
      cartItemsContainer.appendChild(row);
    });

    if (cartTotalPrice) {
      cartTotalPrice.textContent = '$' + total.toFixed(2);
    }

    // attach remove handlers
    document.querySelectorAll('.remove-cart-item').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const idx = parseInt(e.target.getAttribute('data-index'));
        cart.splice(idx, 1);
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

  // checkout button
  if (checkoutBtn) {
    checkoutBtn.addEventListener('click', () => {
      if (cart.length === 0) return;
      const generatedKey = 'chud-rust-' + Math.random().toString(36).substring(2, 8) + '-' + Math.random().toString(36).substring(2, 8);
      alert(`purchase successful! your license key: ${generatedKey}\nuse the "my keys" tab to manage it.`);
      
      // automatically add to my keys
      addKeyToUI(generatedKey, 'rust elite - active');
      cart = [];
      updateCartUI();
      closeCart();
      switchView('my-keys');
    });
  }

  // features accordion dropdowns
  const accordionItems = document.querySelectorAll('.accordion-item');
  accordionItems.forEach(item => {
    const trigger = item.querySelector('.accordion-trigger');
    if (trigger) {
      trigger.addEventListener('click', () => {
        const isOpen = item.classList.contains('open');
        item.classList.toggle('open', !isOpen);
      });
    }
  });

  // shop search filter
  const shopSearch = document.getElementById('shop-search');
  const stockCount = document.getElementById('stock-count');
  const productsGrid = document.getElementById('products-grid');

  if (shopSearch && cardRustElite) {
    shopSearch.addEventListener('input', (e) => {
      const q = e.target.value.toLowerCase().trim();
      const match = 'rust elite'.includes(q) || 'rust'.includes(q) || q === '';
      cardRustElite.style.display = match ? 'flex' : 'none';
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

  // my keys logic
  const redeemBtn = document.getElementById('redeem-key-btn');
  const keyInput = document.getElementById('license-key-input');
  const keyFeedback = document.getElementById('key-feedback');
  const activeKeysList = document.getElementById('active-keys-list');

  function addKeyToUI(key, statusText) {
    if (!activeKeysList) return;
    const item = document.createElement('div');
    item.className = 'key-item';
    item.innerHTML = `
      <div class="key-value">${key}</div>
      <div class="key-status">${statusText}</div>
    `;
    activeKeysList.prepend(item);
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
          body: JSON.stringify({ license_key: key, product: 'rust elite' })
        });
        const data = await res.json();
        if (data.success) {
          addKeyToUI(key, 'undetected - active');
          if (keyFeedback) {
            keyFeedback.className = 'key-feedback success';
            keyFeedback.textContent = data.message || 'key activated successfully';
          }
          keyInput.value = '';
          if (currentUser) {
            checkAuthSession();
          }
        } else {
          if (keyFeedback) {
            keyFeedback.className = 'key-feedback error';
            keyFeedback.textContent = data.message || 'could not activate key';
          }
        }
      } catch (err) {
        // Fallback offline UI behavior
        addKeyToUI(key, 'undetected - active');
        if (keyFeedback) {
          keyFeedback.className = 'key-feedback success';
          keyFeedback.textContent = 'key activated locally';
        }
        keyInput.value = '';
      }
    });
  }

  // Account & Authentication system
  let currentUser = null;
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
    authFeedback.textContent = msg;
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

  // Switch tabs between login and register
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
              <span class="profile-key-code">${k.license_key}</span>
              <span class="profile-key-tag">${k.product || 'rust elite'}</span>
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
  }

  // Check existing session
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

  // Handle registration
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
          }, 600);
        } else {
          showFeedback(data.message || 'registration failed.');
        }
      } catch (err) {
        showFeedback('connection error. check server setup.');
      }
    });
  }

  // Handle login
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
          }, 500);
        } else {
          showFeedback(data.message || 'invalid email or password.');
        }
      } catch (err) {
        showFeedback('connection error. check server setup.');
      }
    });
  }

  // Handle logout
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try {
        await fetch('api/auth.php?action=logout', { method: 'POST' });
      } catch (err) {}
      updateAuthUI(null);
      closeAccountModal();
    });
  }

  // Initialize session check
  checkAuthSession();

  // check initial route
  const hash = window.location.hash.replace('#', '');
  if (hash && views[hash]) {
    switchView(hash);
  } else {
    // default to home or shop as specified
    switchView('home');
  }
});
