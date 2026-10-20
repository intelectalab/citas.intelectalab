import Link from 'next/link';
import type { ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';

export default function BackLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="inline-flex items-center gap-2 text-base font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
      <ArrowLeft size={18} /> {children}
    </Link>
  );
}
