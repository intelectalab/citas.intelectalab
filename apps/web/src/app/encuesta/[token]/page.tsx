import SurveyAnswerPage from '@/features/surveys/components/SurveyAnswerPage';

export default async function Page({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;

  return <SurveyAnswerPage token={token} />;
}
