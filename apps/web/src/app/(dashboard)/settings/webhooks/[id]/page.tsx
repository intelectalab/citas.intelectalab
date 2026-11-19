'use client';

import { useParams } from 'next/navigation';
import WebhookDetail from '@/features/integrations/components/WebhookDetail';

export default function Page() {
  const { id } = useParams<{ id: string }>();
  return <WebhookDetail id={id} />;
}
