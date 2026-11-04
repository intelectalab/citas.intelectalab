import PlatformReports from '@/features/platform/components/PlatformReports';

export default async function PlatformReportsPage({ searchParams }: { searchParams: Promise<{ tab?: string }> }) {
  const { tab } = await searchParams;
  return <PlatformReports initialTab={tab} />;
}
