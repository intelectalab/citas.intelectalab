'use client';

import { useEffect } from 'react';

function countUp(el: HTMLElement) {
  const target = Number(el.dataset.count);
  if (!Number.isFinite(target) || el.dataset.counted) return;
  el.dataset.counted = '1';
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce || target <= 0) {
    el.textContent = String(target);
    return;
  }
  const start = performance.now();
  const duration = 1200;
  const tick = (now: number) => {
    const t = Math.min(1, (now - start) / duration);
    el.textContent = String(Math.round(target * (1 - Math.pow(1 - t, 3))));
    if (t < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
}

export default function SiteMotion() {
  useEffect(() => {
    const root = document.documentElement;
    const items = Array.from(document.querySelectorAll<HTMLElement>('[data-reveal]'));
    const show = (el: HTMLElement) => {
      el.setAttribute('data-shown', '');
      el.querySelectorAll<HTMLElement>('[data-count]').forEach(countUp);
    };

    items.forEach((el) => {
      if (el.getBoundingClientRect().top < window.innerHeight * 0.92) show(el);
    });
    root.classList.add('motion-ready');

    if (!('IntersectionObserver' in window)) {
      items.forEach(show);
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          show(entry.target as HTMLElement);
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

    items.filter((el) => !el.hasAttribute('data-shown')).forEach((el) => observer.observe(el));

    return () => {
      observer.disconnect();
      root.classList.remove('motion-ready');
    };
  }, []);

  return null;
}
