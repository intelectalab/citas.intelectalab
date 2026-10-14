'use client';

import { useEffect } from 'react';

export function useAutoRefresh(refresh: () => void, paused: boolean, intervalMs = 20000) {
  useEffect(() => {
    if (paused) return;

    const tick = () => {
      if (document.visibilityState === 'visible') refresh();
    };

    const timer = setInterval(tick, intervalMs);
    document.addEventListener('visibilitychange', tick);
    window.addEventListener('focus', tick);

    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', tick);
      window.removeEventListener('focus', tick);
    };
  }, [refresh, paused, intervalMs]);
}
