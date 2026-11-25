'use client';

import { useParams } from 'next/navigation';
import PlatformErrorDetail from '@/features/platform/components/PlatformErrorDetail';

export default function Page() {
  const { id } = useParams<{ id: string }>();
  return <PlatformErrorDetail id={id} />;
}
