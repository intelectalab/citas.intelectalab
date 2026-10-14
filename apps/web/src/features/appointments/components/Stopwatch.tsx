'use client';

import { useEffect, useState } from 'react';
import { formatClock } from '../appointments';

interface StopwatchProps {
  seconds: number;
  running: boolean;
  limitSeconds?: number | null;
  className?: string;
}

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
