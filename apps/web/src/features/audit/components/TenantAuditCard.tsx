'use client';

import RecentAuditCard from './RecentAuditCard';

export default function TenantAuditCard({ tenantId, className, refreshKey }: { tenantId: string; className: string; refreshKey?: unknown }) {
  return (
    <RecentAuditCard
      endpoint="/admin/audit"
      params={{ tenant_id: tenantId }}
      title="Lo que la plataforma hizo en esta empresa"
      allHref={`/admin/audit?tenant=${tenantId}`}
      empty="La plataforma todavía no hizo cambios en esta empresa."
      className={className}
      refreshKey={refreshKey}
    />
  );
}
