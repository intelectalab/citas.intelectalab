import type { ReactNode } from 'react';

export default function SectionTitle({ children }: { children: ReactNode }) {
  return <h2 className="flex items-center gap-2 text-sm font-extrabold text-brand-ink uppercase tracking-wider">{children}</h2>;
}
