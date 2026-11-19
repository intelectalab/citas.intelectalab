import AppointmentLinkPage from '@/features/booking/components/AppointmentLinkPage';

export default async function Page({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;

  return <AppointmentLinkPage token={token} />;
}
