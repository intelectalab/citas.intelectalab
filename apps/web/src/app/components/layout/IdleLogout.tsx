'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/hooks/useAuth';
import { AppModal } from '@/app/components/ui/modals/AppModal';

export const ACTIVITY_KEY = 'last_activity';
export const LOGOUT_REASON_KEY = 'logout_reason';

const WARNING_MS = 60_000;
const EVENTS = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'wheel', 'scroll'] as const;

export function markActivity() {
  try {
    localStorage.setItem(ACTIVITY_KEY, String(Date.now()));
  } catch {}
}

export default function IdleLogout() {
  const { user, logout } = useAuth();
  const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
  const minutes = user?.idle_minutes || undefined;

  useEffect(() => {
    if (!minutes) return;

    const limit = minutes * 60_000;
    let lastMark = 0;
    let closing = false;

    const lastActivity = () => Number(localStorage.getItem(ACTIVITY_KEY) || 0);

    const onActivity = () => {
      const now = Date.now();
      if (now - lastMark < 5_000) return;
      lastMark = now;
      markActivity();
    };

    const check = () => {
      if (closing) return;
      const remaining = lastActivity() + limit - Date.now();

      if (remaining <= 0) {
        closing = true;
        localStorage.setItem(LOGOUT_REASON_KEY, `idle:${minutes}`);
        localStorage.removeItem(ACTIVITY_KEY);
        logout();
        return;
      }

      setSecondsLeft(remaining <= WARNING_MS ? Math.ceil(remaining / 1000) : null);
    };

    if (!lastActivity()) markActivity();
    check();

    EVENTS.forEach((name) => window.addEventListener(name, onActivity, { passive: true, capture: true }));
    const timer = window.setInterval(check, 1_000);

    return () => {
      EVENTS.forEach((name) => window.removeEventListener(name, onActivity, { capture: true }));
      window.clearInterval(timer);
    };
  }, [minutes]);

  const stay = () => {
    markActivity();
    setSecondsLeft(null);
  };

  return (
    <AppModal
      isOpen={secondsLeft !== null}
      type="alert"
      title="¿Sigues ahí?"
      message={`Por seguridad, tu sesión se cerrará en ${secondsLeft ?? 0} segundos porque no hubo actividad.`}
      confirmText="Seguir conectado"
      onConfirm={stay}
      onClose={stay}
    />
  );
}
