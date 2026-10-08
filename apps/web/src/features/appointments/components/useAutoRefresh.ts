'use client';

import { useEffect } from 'react';

// Vuelve a pedir los datos cada cierto tiempo y al regresar a la pestaña, para
// ver lo que agendan otras personas. Se detiene mientras `paused` sea verdadero
// (un panel abierto, una cita a medio arrastrar) y con la pestaña oculta.
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
