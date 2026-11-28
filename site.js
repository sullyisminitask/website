/* chudcheats shared front-end state & real PHP backend client */
(() => {
const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
const store = {
  get: (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d } catch { return d } },
  set: (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch {} },
  del: k => { try { localStorage.removeItem(k) } catch {} } };

const TIERS = {
  '1d': { name: 'orbit', len: '1 day', days: 1, price: 7.99 },
  '7d': { name: 'orbit', len: '7 days', days: 7, price: 19.99 },
  '30d': { name: 'orbit', len: '30 days', days: 30, price: 39.99 } };

const planTier = plan => {
  const p = String(plan || '').toLowerCase().trim();
  if (p === '1d' || p === '1 day') return '1d';
  if (p === '3d' || p === '3 day' || p === '3 days') return '7d';
  if (p === '7d' || p === '7 day' || p === '7 days' || p === 'week') return '7d';
  if (p === '30d' || p === '30 day' || p === '30 days' || p === 'month') return '30d';
  return '7d';
};

const planDays = plan => {
  const p = String(plan || '').toLowerCase().trim();
  if (p === '1d' || p === '1 day') return 1;
  if (p === '3d' || p === '3 day' || p === '3 days') return 3;
  if (p === '7d' || p === '7 day' || p === '7 days' || p === 'week') return 7;
  if (p === '30d' || p === '30 day' || p === '30 days' || p === 'month') return 30;
  if (p === '3 month') return 90;
  return 7;
};

const parseUtc = str => {
  if (!str) return Date.now();
  if (typeof str === 'number') return str;
  const iso = String(str).includes('T') ? str : String(str).replace(' ', 'T') + 'Z';
  const t = Date.parse(iso);
  return Number.isNaN(t) ? Date.now() : t;
};

const cart = () => store.get('cc_cart', []).filter(i => TIERS[i.tier]);
const paintCount = () => { const n = cart().reduce((a, i) => a + i.qty, 0); $$('[data-cart-count]').forEach(e => e.textContent = n); };
const saveCart = c => { store.set('cc_cart', c); paintCount(); };
const toast = msg => {
  let t = $('.toast'); if (!t) { t = document.createElement('div'); t.className = 'toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
  t.textContent = String(msg || '').toLowerCase(); t.classList.add('show'); clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2600); };
const add = (tier, qty = 1) => {
  if (!TIERS[tier]) return;
  const c = cart(), f = c.find(i => i.tier === tier); f ? f.qty += qty : c.push({ tier, qty });
  saveCart(c); toast(`added ${TIERS[tier].name} · ${TIERS[tier].len} to your cart`); };
const money = n => '$' + Number(n || 0).toFixed(2);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const api = async (url, opts = {}) => {
  try {
    const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
    const text = await res.text();
    try { return JSON.parse(text); } catch { return { success: false, message: 'invalid server response.' }; }
  } catch {
    return { success: false, message: 'network error contacting server.' };
  }
};

let currentUser = null;
const paintUser = u => {
  currentUser = u || null;
  document.documentElement.classList.toggle('authed', !!currentUser);
  if (currentUser) {
    const label = (currentUser.username || currentUser.email.split('@')[0] || 'user').toLowerCase();
    $$('[data-user-name]').forEach(e => e.textContent = label);
    $$('[data-user-email]').forEach(e => e.textContent = currentUser.email || '');
    $$('[data-user-init]').forEach(e => e.textContent = label.slice(0, 2));
    try { sessionStorage.setItem('cc_user_hint', JSON.stringify({ username: label, email: currentUser.email || '' })); } catch {}
  } else {
    try { sessionStorage.removeItem('cc_user_hint'); } catch {}
  }
  $$('[data-account]').forEach(a => a.href = currentUser ? 'dashboard.html' : 'login.html');
};

try {
  const hint = JSON.parse(sessionStorage.getItem('cc_user_hint') || 'null');
  if (hint && hint.email) paintUser(hint);
} catch {}

let configPromise = null;
const getConfig = (force = false) => {
  if (!configPromise || force) {
    configPromise = api('api/square.php?action=config').then(d => d.success ? d : {
      configured: false, environment: 'sandbox', currency: 'AUD', usd_to_aud: 1.51,
      rate_source: 'fallback', turnstile_enabled: false, turnstile_site_key: '' });
  }
  return configPromise;
};

const turnstileState = {
  enabled: false, siteKey: '', scriptPromise: null,
  widgets: {}, tokens: {}
};

const ensureTurnstileScript = () => {
  if (window.turnstile) return Promise.resolve(true);
  if (!turnstileState.scriptPromise) {
    turnstileState.scriptPromise = new Promise(resolve => {
      let existing = $('script[src*="challenges.cloudflare.com/turnstile"]');
      if (!existing) {
        existing = document.createElement('script');
        existing.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        existing.async = true; existing.defer = true;
        document.head.appendChild(existing);
      }
      let tries = 0;
      const poll = () => {
        if (window.turnstile) return resolve(true);
        if (++tries >= 50) return resolve(false);
        setTimeout(poll, 140);
      };
      poll();
    });
  }
  return turnstileState.scriptPromise;
};

const turnstile = {
  isActive: () => Boolean(turnstileState.enabled && turnstileState.siteKey),
  mount: async (slotKey, elementId) => {
    const cfg = await getConfig();
    turnstileState.enabled = !!cfg.turnstile_enabled;
    turnstileState.siteKey = cfg.turnstile_site_key || '';
    const el = document.getElementById(elementId);
    if (!el) return;
    if (!turnstile.isActive()) { el.classList.remove('visible'); return; }
    el.classList.add('visible');
    const ready = await ensureTurnstileScript();
    if (!ready || !window.turnstile) return;
    const prev = turnstileState.widgets[slotKey];
    if (prev !== undefined && prev !== null) {
      turnstileState.tokens[slotKey] = '';
      try { window.turnstile.reset(prev); } catch {}
      return;
    }
    el.innerHTML = '';
    turnstileState.tokens[slotKey] = '';
    turnstileState.widgets[slotKey] = window.turnstile.render(el, {
      sitekey: turnstileState.siteKey,
      theme: 'dark',
      callback: tok => { turnstileState.tokens[slotKey] = tok || ''; },
      'expired-callback': () => { turnstileState.tokens[slotKey] = ''; },
      'error-callback': () => { turnstileState.tokens[slotKey] = ''; }
    });
  },
  reset: slotKey => {
    turnstileState.tokens[slotKey] = '';
    const id = turnstileState.widgets[slotKey];
    if (id !== undefined && id !== null && window.turnstile) {
      try { window.turnstile.reset(id); } catch {}
    }
  },
  token: slotKey => {
    if (!turnstile.isActive()) return '';
    if (turnstileState.tokens[slotKey]) return turnstileState.tokens[slotKey];
    const id = turnstileState.widgets[slotKey];
    if (id !== undefined && id !== null && window.turnstile) {
      try { return window.turnstile.getResponse(id) || ''; } catch {}
    }
    return '';
  }
};

const refreshSession = async () => {
  const data = await api('api/auth.php?action=me');
  if (data && data.success && data.logged_in && data.user) {
    paintUser(data.user);
    return data.user;
  }
  paintUser(null);
  return null;
};

const ready = refreshSession().then(u => {
  if (document.body.dataset.auth === 'required' && !u) {
    const file = location.pathname.split('/').pop() || 'dashboard.html';
    location.replace('login.html?next=' + encodeURIComponent(file));
  }
  return u;
});

paintCount();
document.addEventListener('click', e => {
  const b = e.target.closest('[data-add]'); if (!b) return;
  e.preventDefault(); add(b.dataset.add);
});
$$('[data-logout]').forEach(b => b.addEventListener('click', async e => {
  e.preventDefault();
  await api('api/auth.php?action=logout', { method: 'POST' });
  paintUser(null);
  location.href = 'index.html';
}));

window.CC = {
  $, $$, store, TIERS, cart, saveCart, add, user: () => currentUser,
  toast, money, sleep, paintCount, api, ready, refreshSession,
  getConfig, turnstile, parseUtc, planTier, planDays, paintUser
};
})();
