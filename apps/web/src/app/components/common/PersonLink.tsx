import Link from 'next/link';
import type { ReactNode } from 'react';

interface PersonLinkProps {
  // Sin id (dato que no llegó) se muestra el nombre como texto.
  id?: string | null;
  children: ReactNode;
  className?: string;
}

const hover = 'hover:text-[#3F768C] hover:underline underline-offset-2 transition-colors';

const personLink = (base: string) =>
  function PersonLink({ id, children, className = '' }: PersonLinkProps) {
    if (!id) return <span className={className}>{children}</span>;

    return (
      <Link href={`${base}/${id}`} data-person-link={base.slice(1)} onClick={(e) => e.stopPropagation()} className={`${className} ${hover}`}>
        {children}
      </Link>
    );
  };

/** El nombre de un profesional, que lleva a su página. */
export const ProfessionalLink = personLink('/professionals');

/** El nombre de alguien del personal, que lleva a su página de accesos. */
export const UserLink = personLink('/users');

/** El nombre de un cliente, que lleva a su página. */
export const CustomerLink = personLink('/customers');
