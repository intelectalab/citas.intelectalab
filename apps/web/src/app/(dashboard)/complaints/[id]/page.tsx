'use client';

import { useParams } from 'next/navigation';
import ComplaintDetail from '@/features/complaints/components/ComplaintDetail';

export default function Page() {
  const { id } = useParams<{ id: string }>();
  return <ComplaintDetail id={id} />;
}
