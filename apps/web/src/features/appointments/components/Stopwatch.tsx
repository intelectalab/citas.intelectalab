'use client';

import { useEffect, useState } from 'react';
import { formatClock } from '../appointments';

interface StopwatchProps {
  // Segundos transcurridos según el servidor cuando entregó la cita.
  seconds: number;
  // Si el tiempo sigue corriendo. Detenido, muestra el valor tal cual.
  running: boolean;
  // Con tiempo asignado, cambia de color al pasarlo.
  limitSeconds?: number | null;
  className?: string;
}

// Cronómetro de una cita. Parte del tiempo que calculó el servidor y avanza
// solo, así no depende de que el reloj del equipo esté en hora.
export default function Stopwatch({ seconds, running, limitSeconds, className = '' }: StopwatchProps) {
  const [elapsed, setElapsed] = useState(seconds);

  useEffect(() => {
    setElapsed(seconds);
    if (!running) return;

    const startedAt = Date.now();
    const timer = setInterval(() => setElapsed(seconds + Math.floor((Date.now() - startedAt) / 1000)), 1000);

    return () => clearInterval(timer);
  }, [seconds, running]);

  const over = Boolean(limitSeconds) && elapsed > (limitSeconds as number);

  return (
    <span data-stopwatch data-over={over} className={`tabular-nums ${over ? 'text-rose-700' : ''} ${className}`}>
      {formatClock(elapsed)}
    </span>
  );
}
