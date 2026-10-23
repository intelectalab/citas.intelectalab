'use client';

import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

interface FloatingProps {
  anchorRef: React.RefObject<HTMLElement | null>;
  open: boolean;
  onClose: () => void;
  children: React.ReactNode;
  align?: 'left' | 'right';
  matchWidth?: boolean;
  className?: string;
  role?: string;
}

const GAP = 4;
const MARGIN = 8;
const MIN_BELOW = 240;

export default function Floating({ anchorRef, open, onClose, children, align = 'left', matchWidth = false, className = '', role }: FloatingProps) {
  const panelRef = useRef<HTMLDivElement>(null);
  const [style, setStyle] = useState<React.CSSProperties>({ top: 0, left: 0, visibility: 'hidden' });

  const place = useCallback(() => {
    const anchor = anchorRef.current;
    const panel = panelRef.current;
    if (!anchor || !panel) return;

    const rect = anchor.getBoundingClientRect();
    const width = matchWidth ? rect.width : panel.offsetWidth;
    const height = panel.offsetHeight;
    const below = window.innerHeight - rect.bottom - MARGIN;
    const above = rect.top - MARGIN;
    const goesUp = Math.min(height, MIN_BELOW) + GAP > below && above > below;

    const left = align === 'right' ? rect.right - width : rect.left;

    setStyle({
      top: goesUp ? Math.max(MARGIN, rect.top - GAP - height) : rect.bottom + GAP,
      left: Math.max(MARGIN, Math.min(left, window.innerWidth - width - MARGIN)),
      ...(matchWidth ? { width } : {}),
      maxHeight: Math.max(160, goesUp ? above - GAP : below - GAP),
      visibility: 'visible',
    });
  }, [anchorRef, align, matchWidth]);

  useLayoutEffect(() => {
    if (open) place();
  }, [open, place]);

  useEffect(() => {
    if (!open) return;

    const onDown = (event: MouseEvent) => {
      const target = event.target as Node;
      if (panelRef.current?.contains(target) || anchorRef.current?.contains(target)) return;
      onClose();
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      event.stopPropagation();
      onClose();
    };

    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
    const observer = new ResizeObserver(place);
    if (panelRef.current) observer.observe(panelRef.current);
    const settle = setTimeout(place, 0);

    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', place, true);
      window.removeEventListener('resize', place);
      observer.disconnect();
      clearTimeout(settle);
    };
  }, [open, onClose, place, anchorRef]);

  if (!open || typeof document === 'undefined') return null;

  return createPortal(
    <div ref={panelRef} role={role} data-floating style={{ position: 'fixed', zIndex: 1000, transitionProperty: 'none', ...style }} className={className}>
      {children}
    </div>,
    document.body,
  );
}
