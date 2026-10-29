import Link from 'next/link';
import type { ReactNode } from 'react';

interface PersonLinkProps {
  id?: string | null;
  children: ReactNode;
  className?: string;
}

const hover = 'hover:text-brand-accent hover:underline underline-offset-2 transition-colors';

const personLink = (base: string) =>
  function PersonLink({ id, children, className = '' }: PersonLinkProps) {
    if (!id) return <span className={className}>{children}</span>;

    return (
      <Link href={`${base}/${id}`} data-person-link={base.slice(1)} onClick={(e) => e.stopPropagation()} className={`${className} ${hover}`}>
        {children}
      </Link>
    );
  };

export const ProfessionalLink = personLink('/professionals');

export const UserLink = personLink('/users');

export const CustomerLink = personLink('/customers');
