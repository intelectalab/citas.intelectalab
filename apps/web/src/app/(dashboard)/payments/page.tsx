import PaymentsTable from '@/features/sales/components/PaymentsTable';

const day = (value?: string) => (value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : undefined);

export default async function PaymentsPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const params = await searchParams;
  const from = day(params.from);
  const to = day(params.to);

  return (
    <div className="h-[calc(100vh-7rem)]">
      <PaymentsTable initial={{ method: params.method, branch_id: params.branch_id, ...(from && to ? { from, to } : {}) }} />
    </div>
  );
}
