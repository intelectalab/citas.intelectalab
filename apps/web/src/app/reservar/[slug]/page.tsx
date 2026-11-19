import PublicBookingPage from '@/features/booking/components/PublicBookingPage';

export default async function Page({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;

  return <PublicBookingPage slug={slug} />;
}
