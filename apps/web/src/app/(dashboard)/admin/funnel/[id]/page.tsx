'use client';

import { useParams } from 'next/navigation';
import LeadDetail from '@/features/web/admin/LeadDetail';

export default function Page() {
  const { id } = useParams<{ id: string }>();
  return <LeadDetail id={id} />;
}
