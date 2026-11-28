/* uisabor-site kit.js v1 — generic motion engine + helpers for site-specific product loops.
   Load after the page:  <script src="https://unpkg.com/lenis@1.1.13/dist/lenis.min.js"></script><script src="kit.js"></script>
   Then write the product's own loops with window.Kit (see references/motion.md). */
(() => {
const H = document.documentElement;
const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
const EASE = 'cubic-bezier(.16,1,.3,1)';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const sleep = ms => new Promise(r => setTimeout(r, ms));
const out3 = t => 1 - Math.pow(1 - t, 3);
const inOut = t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
const tween = (ms, fn, e = out3) => new Promise(res => {
  const t0 = performance.now();
  const f = now => { const t = Math.min(1, Math.max(0, (now - t0) / ms)); fn(e(t)); t < 1 ? requestAnimationFrame(f) : res(); };
  requestAnimationFrame(f);
});
const seen = (el, cb, o = { threshold: .15 }) => new IntersectionObserver(([e]) => cb(e.isIntersecting), o).observe(el);
const once = (el, cb, o) => { let d = false; seen(el, v => { if (v && !d) { d = true; cb(); } }, o); };
// run `step` over and over while `el` is on screen: the product only works where you can see it
const loop = (el, step) => { if (RM || !el) return; let on = false, busy = false;
  seen(el, async v => { on = v; if (!v || busy) return; busy = true; while (on) await step(); busy = false; }); };
// swap text with a short roll-up (counters, statuses)
const roll = (el, txt) => { el.textContent = txt; if (!RM) el.animate([{ transform: 'translateY(70%)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 520, easing: EASE }); };
// move an SVG element along a path (packets on workflow wires)
const travel = async (dot, path, ms = 850) => {
  const L = path.getTotalLength(); dot.style.opacity = 1;
  await tween(ms, t => { const p = path.getPointAtLength(L * t); dot.setAttribute('cx', p.x); dot.setAttribute('cy', p.y); }, inOut);
  dot.style.opacity = 0;
};
const flash = el => { el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash'); };
window.Kit = { $, $$, sleep, tween, out3, inOut, seen, once, loop, roll, travel, flash, EASE, RM };

/* ---- split text: h1 words, giant wordmark letters ---- */
// walk text nodes so nested markup (<span class="muted">, <br>) survives
const splitWords = root => { let wi = 0;
  const walk = node => [...node.childNodes].forEach(n => {
    if (n.nodeType === 3) {
      const frag = document.createDocumentFragment();
      n.textContent.split(/(\s+)/).forEach(part => {
        if (!part) return;
        if (/^\s+$/.test(part)) { frag.appendChild(document.createTextNode(part)); return; }
        const s = document.createElement('span'); s.className = 'w'; s.style.setProperty('--i', wi++); s.textContent = part; frag.appendChild(s);
      });
      n.replaceWith(frag);
    } else if (n.nodeType === 1 && n.tagName !== 'BR') walk(n);
  });
  walk(root); };
const h1 = $('.hero-copy h1');
if (h1) splitWords(h1);
const gw = $('.giant span');
if (gw) gw.innerHTML = [...gw.textContent].map((c, i) => `<i style="--i:${i}">${c === ' ' ? '&nbsp;' : c}</i>`).join('');

/* ---- intro ---- */
const go = () => requestAnimationFrame(() => requestAnimationFrame(() => H.classList.add('go')));
RM ? H.classList.add('go') : Promise.race([document.fonts ? document.fonts.ready : 0, sleep(900)]).then(go);

/* ---- scroll reveals (keep identical to the list in kit.css) ---- */
const REVEAL = '.split > *,.bento .c,.feat > *,.stmt,.kpi,.stats > div,.how,.steps > div,main .center > :is(.badge,.h2,.sub2,.toggle),.grid-int > div,.tgrid > *,.plan,.faq,.cta-in > *,.ftop > *,.fbottom,[data-reveal],.giant,.vbars';
const items = $$(REVEAL);
if (RM || !('IntersectionObserver' in window)) items.forEach(e => e.classList.add('in'));
else {
  const io = new IntersectionObserver(ents => {
    ents.filter(e => e.isIntersecting)
      .sort((a, b) => (a.target.compareDocumentPosition(b.target) & 4) ? -1 : 1)
      .forEach((e, k) => {
        const el = e.target, par = el.parentElement;
        const gi = par && par.classList.contains('grid-int') ? [...par.children].indexOf(el) : -1;
        const cols = gi >= 0 ? getComputedStyle(par).gridTemplateColumns.split(' ').length : 1;
        const d = gi >= 0 ? (gi % cols + Math.floor(gi / cols)) * 45 : k * 80;
        el.style.transitionDelay = d + 'ms'; el.classList.add('in'); io.unobserve(el);
        setTimeout(() => el.style.transitionDelay = '', d + 1300);
      });
  }, { rootMargin: '0px 0px -8% 0px', threshold: .08 });
  items.forEach(e => io.observe(e));
}

/* ---- smooth scroll, depth, sticky nav ---- */
const hero = $('.hero'), sky = $('.sky'), copy = $('.hero .hero-copy'), snav = $('.snav');
const pars = $$('.cta .bg, .giant .bg, [data-par]');
let lenis = null;
if (!RM && window.Lenis && !matchMedia('(pointer: coarse)').matches) lenis = new Lenis({ lerp: .1 });
let lastY = -1;
const frame = () => {
  const y = scrollY; if (y === lastY) return; lastY = y;
  const hh = hero ? hero.offsetHeight : 600;
  if (!RM && hero && y < hh + 60) {
    const p = Math.min(1, y / hh);
    // only the sky lags; the product stays planted in its scene (user rule)
    if (sky) sky.style.transform = `translate3d(0,${(y * .14).toFixed(1)}px,0)`;
    if (copy) { copy.style.transform = `translate3d(0,${(-y * .1).toFixed(1)}px,0)`; copy.style.opacity = Math.max(0, 1 - p * 1.3).toFixed(3); }
  }
  if (!RM) for (const el of pars) {
    const r = el.parentElement.getBoundingClientRect();
    if (r.bottom < -100 || r.top > innerHeight + 100) continue;
    const k = +(el.dataset.par || 70);
    el.style.transform = `translate3d(0,${(((r.top + r.height / 2) - innerHeight / 2) / innerHeight * -k).toFixed(1)}px,0)`;
  }
  if (snav) snav.classList.toggle('on', y > hh - 140);
};
const tick = t => { if (lenis) lenis.raf(t); frame(); requestAnimationFrame(tick); };
requestAnimationFrame(tick);
$$('a[href^="#"]').forEach(a => a.addEventListener('click', e => {
  const id = a.getAttribute('href'); e.preventDefault();
  const t = id === '#' ? (a.classList.contains('logo') ? 0 : null) : $(id);
  if (t === null) return;
  // absolute target from the real scrollY: Lenis' own element maths goes stale after a native scroll (keyboard focus, scrollIntoView)
  const y = t === 0 ? 0 : Math.max(0, t.getBoundingClientRect().top + scrollY - 84);
  lenis ? lenis.scrollTo(y, { duration: 1.4, force: true }) : scrollTo({ top: y, behavior: 'smooth' });
}));
Kit.lenis = lenis;

/* ---- feature tabs: <div class="seg"><button data-h="Line one<br>line two" data-p="Lead">…  + .panel per tab ---- */
$$('.feat').forEach(feat => {
  const segs = $$('.seg button', feat), panels = $$('.panel', feat), fh = $('.h2', feat), fp = $('.lead', feat);
  if (!segs.length) return;
  let cur = -1;
  const show = (i, first) => {
    if (i === cur) return; cur = i;
    segs.forEach((b, k) => b.classList.toggle('on', k === i));
    panels.forEach((p, k) => p.classList.toggle('on', k === i));
    const b = segs[i], h = b.dataset.h, p = b.dataset.p;
    if (h && fh) {
      if (first || RM) { fh.innerHTML = h; if (fp && p) fp.textContent = p; }
      else {
        [fh, fp].filter(Boolean).forEach(el => { el.getAnimations().forEach(a => a.cancel()); el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' }); });
        setTimeout(() => {
          fh.innerHTML = h; if (fp && p) fp.textContent = p;
          [fh, fp].filter(Boolean).forEach((el, k) => { el.getAnimations().forEach(a => a.cancel()); el.animate([{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 700, delay: k * 70, easing: EASE, fill: 'backwards' }); });
        }, 170);
      }
    }
    feat.dispatchEvent(new CustomEvent('tab', { detail: i }));
  };
  segs.forEach((b, k) => {
    b.addEventListener('click', () => show(k));
    b.addEventListener('animationend', () => { if (b.classList.contains('on')) show((k + 1) % segs.length); });
  });
  show(Math.max(0, segs.findIndex(b => b.classList.contains('on'))), true);
  feat.addEventListener('pointerenter', () => feat.classList.add('hold'));
  feat.addEventListener('pointerleave', () => feat.classList.remove('hold'));
  seen(feat, v => feat.classList.toggle('vis', v), { threshold: .3 });
});

/* ---- numbers count up: <b class="n">120+</b> or any [data-count] ---- */
$$('.kpi .n, .stats b, [data-count]').forEach(el => {
  const m = el.textContent.trim().match(/^([^\d]*)([\d,.]+)(.*)$/); if (!m || RM) return;
  const [, pre, num, suf] = m, dec = (num.split('.')[1] || '').length, to = parseFloat(num.replace(/,/g, ''));
  const fmt = v => pre + v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suf;
  el.textContent = fmt(0);
  once(el, () => tween(1600, t => el.textContent = fmt(to * t)), { threshold: .6 });
});

/* ---- pricing toggle: buttons [data-p="m|y"], prices <span data-m data-y> ---- */
const tg = $$('.toggle button');
tg.forEach(b => b.addEventListener('click', () => {
  tg.forEach(x => x.classList.toggle('on', x === b));
  $$('[data-m]').forEach(s => { s.textContent = s.dataset[b.dataset.p]; if (!RM) s.animate([{ opacity: 0, transform: 'translateY(.35em)' }, { opacity: 1, transform: 'none' }], { duration: 550, easing: EASE }); });
}));

/* ---- charts draw on enter: <path data-draw> (+ optional [data-after] elements shown when done) ---- */
$$('[data-draw]').forEach(p => {
  if (RM) return; const L = p.getTotalLength(), box = p.closest('[data-chart]') || p.parentElement;
  const after = $$('[data-after]', box);
  p.style.strokeDasharray = L; p.style.strokeDashoffset = L; after.forEach(a => a.style.opacity = 0);
  once(box, async () => {
    await sleep(250); await tween(1700, t => p.style.strokeDashoffset = L * (1 - t), inOut);
    after.forEach(a => a.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 600, easing: EASE, fill: 'forwards' }));
  }, { threshold: .4 });
});
})();
