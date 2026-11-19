'use client';

import { useParams } from 'next/navigation';
import ComplaintBook from '@/features/complaints/components/ComplaintBook';

export default function CompanyComplaintBookPage() {
  const { code } = useParams<{ code: string }>();
  return <ComplaintBook code={code} />;
}
