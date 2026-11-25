import SaaSPlansTable from '@/features/plans/components/SaaSPlansTable';
import SaaSAddonsTable from '@/features/plans/components/SaaSAddonsTable';

export default function SaaSPlansPage() {
  return (
    <div className="space-y-8">
      <div className="h-[calc(100vh-7rem)]">
        <SaaSPlansTable />
      </div>
      <SaaSAddonsTable />
    </div>
  );
}
