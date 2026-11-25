'use client';

import { useParams } from 'next/navigation';
import SurveyDetail from '@/features/surveys/components/SurveyDetail';

export default function Page() {
  const { id } = useParams<{ id: string }>();
  return <SurveyDetail id={id} />;
}
