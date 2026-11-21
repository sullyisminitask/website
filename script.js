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
    redeemBtn.addEventListener('click', () => {
      const key = keyInput.value.trim().toLowerCase();
      if (!key) {
        if (keyFeedback) {
          keyFeedback.className = 'key-feedback error';
          keyFeedback.textContent = 'please enter a valid key';
        }
        return;
      }
      addKeyToUI(key, 'undetected - active');
      if (keyFeedback) {
        keyFeedback.className = 'key-feedback success';
        keyFeedback.textContent = 'key activated successfully';
      }
      keyInput.value = '';
    });
  }

  // check initial route
  const hash = window.location.hash.replace('#', '');
  if (hash && views[hash]) {
    switchView(hash);
  } else {
    // default to home or shop as specified
    switchView('home');
  }
});
