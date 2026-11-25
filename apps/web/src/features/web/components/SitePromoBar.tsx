'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Check, Copy, Ticket, X } from 'lucide-react';

const KEY = 'site_promo_closed';

export default function SitePromoBar({ promo, allowSignup }: { promo: { text: string; coupon: string | null }; allowSignup: boolean }) {
  const [hidden, setHidden] = useState(false);
  const [copied, setCopied] = useState(false);
  const stamp = `${promo.text}|${promo.coupon ?? ''}`;

  useEffect(() => {
    try { if (sessionStorage.getItem(KEY) === stamp) setHidden(true); } catch {}
  }, [stamp]);

  if (hidden) return null;

  const close = () => {
    setHidden(true);
    try { sessionStorage.setItem(KEY, stamp); } catch {}
  };

  const copy = async () => {
    if (!promo.coupon) return;
    try {
      await navigator.clipboard.writeText(promo.coupon);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {}
  };

  return (
    <div className="bg-brand-ink text-white text-xs sm:text-sm" role="region" aria-label="Promoción" data-site-promo>
      <div className="max-w-6xl mx-auto px-4 sm:px-6 py-2 flex items-center gap-2 sm:gap-3">
        <Ticket size={15} className="text-brand-soft shrink-0 hidden sm:block" aria-hidden="true" />
        <p className="flex-1 min-w-0 flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="font-semibold">{promo.text}</span>
          {promo.coupon && (
            <button type="button" onClick={copy} data-promo-copy className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white/10 border border-white/25 font-mono font-bold tracking-wide hover:bg-white/20 cursor-pointer" title="Copiar cupón">
              {promo.coupon} {copied ? <Check size={12} /> : <Copy size={12} />}
              <span className="sr-only">{copied ? 'Cupón copiado' : 'Copiar cupón'}</span>
            </button>
          )}
          {allowSignup && (
            <Link href={promo.coupon ? `/register?cupon=${encodeURIComponent(promo.coupon)}` : '/register'} className="font-bold underline underline-offset-2 text-brand-soft hover:text-white">
              Usarlo ahora
            </Link>
          )}
        </p>
        <button type="button" onClick={close} aria-label="Cerrar promoción" className="p-1 rounded-md hover:bg-white/10 cursor-pointer shrink-0"><X size={15} /></button>
      </div>
    </div>
  );
}
