// Framerate-independent smooth wheel & anchor scrolling
(() => {
  if (typeof window === 'undefined') return;
  const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (prefersReduced) {
    window.smoothScrollTo = (y) => window.scrollTo(0, Math.max(0, y));
    return;
  }

  let currentY = window.scrollY || 0;
  let targetY = currentY;
  let rafId = null;
  let lastTime = 0;

  function maxScrollY() {
    return Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
  }

  function clamp(val) {
    return Math.min(Math.max(0, val), maxScrollY());
  }

  function hasScrollableAncestor(el, deltaY) {
    let node = el;
    while (node && node !== document.body && node !== document.documentElement) {
      if (node.classList && node.classList.contains('modal-backdrop') && node.classList.contains('active')) {
        return true;
      }
      const style = window.getComputedStyle(node);
      const oy = style.overflowY;
      if ((oy === 'auto' || oy === 'scroll') && node.scrollHeight > node.clientHeight + 1) {
        const atTop = node.scrollTop <= 0;
        const atBottom = node.scrollTop + node.clientHeight >= node.scrollHeight - 1;
        if ((deltaY < 0 && !atTop) || (deltaY > 0 && !atBottom)) {
          return true;
        }
      }
      node = node.parentElement;
    }
    return false;
  }

  function step(now) {
    if (!lastTime) lastTime = now;
    const dt = Math.min(0.05, Math.max(0.001, (now - lastTime) / 1000));
    lastTime = now;

    // Exponential lerp normalized across 60Hz-240Hz displays
    const alpha = 1 - Math.exp(-8.5 * dt);
    const diff = targetY - currentY;

    if (Math.abs(diff) <= 0.4) {
      currentY = targetY;
      window.scrollTo({ top: Math.round(currentY), behavior: 'instant' });
      rafId = null;
      lastTime = 0;
      return;
    }

    currentY += diff * alpha;
    window.scrollTo({ top: currentY, behavior: 'instant' });
    rafId = requestAnimationFrame(step);
  }

  function startAnimation() {
    if (rafId === null) {
      currentY = window.scrollY || 0;
      lastTime = 0;
      rafId = requestAnimationFrame(step);
    }
  }

  window.smoothScrollTo = (y, instant = false) => {
    const dest = clamp(y);
    if (instant) {
      if (rafId !== null) {
        cancelAnimationFrame(rafId);
        rafId = null;
      }
      currentY = dest;
      targetY = dest;
      window.scrollTo({ top: dest, behavior: 'instant' });
      return;
    }
    if (rafId === null) {
      currentY = window.scrollY || 0;
    }
    targetY = dest;
    startAnimation();
  };

  window.addEventListener('wheel', (e) => {
    if (e.ctrlKey || e.metaKey || e.defaultPrevented) return;
    if (hasScrollableAncestor(e.target, e.deltaY)) return;

    let dy = e.deltaY;
    if (e.deltaMode === 1) dy *= 36;
    else if (e.deltaMode === 2) dy *= window.innerHeight * 0.85;

    if (rafId === null) {
      currentY = window.scrollY || 0;
      targetY = currentY;
    }

    e.preventDefault();
    targetY = clamp(targetY + dy * 1.15);
    startAnimation();
  }, { passive: false });

  window.addEventListener('scroll', () => {
    if (rafId === null) {
      currentY = window.scrollY || 0;
      targetY = currentY;
    }
  }, { passive: true });

  window.addEventListener('touchstart', () => {
    if (rafId !== null) {
      cancelAnimationFrame(rafId);
      rafId = null;
      lastTime = 0;
    }
  }, { passive: true });

  window.addEventListener('keydown', (e) => {
    const keys = ['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '];
    if (keys.includes(e.key) && rafId !== null) {
      cancelAnimationFrame(rafId);
      rafId = null;
      lastTime = 0;
    }
  }, { passive: true });
})();
