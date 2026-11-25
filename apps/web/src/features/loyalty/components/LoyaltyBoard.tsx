'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  Award,
  Ban,
  CheckCircle2,
  Clock,
  Copy,
  CreditCard,
  Gift,
  History,
  Settings,
  Sparkles,
  UserPlus,
  Users,
} from 'lucide-react';
import {
  DataTable,
  type Column,
  type FilterConfig,
  type MetricCardItem,
} from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';
import { useAuth } from '@/hooks/useAuth';
import {
  GIFT_CARD_STATUS_BADGES,
  GIFT_CARD_TX_BADGES,
  POINT_TYPE_BADGES,
  REFERRAL_BADGES,
  TIER_BADGES,
  type ReferralRow,
  type GiftCardMovementRow,
  type GiftCardRow,
  type LoyaltyBoardData,
  type LoyaltyCustomerRow,
  type PointMovementRow,
} from '../loyalty';
import PointsAdjustDrawer from './PointsAdjustDrawer';
import GiftCardDrawer from './GiftCardDrawer';
import GiftCardActionDrawer from './GiftCardActionDrawer';
import ReferralDrawer from './ReferralDrawer';

type Section = 'points' | 'gift_cards';
type PointsFilter = 'all' | 'with_points' | 'can_redeem' | 'movements' | 'referrals';
type GiftCardsFilter = 'all' | 'active' | 'expiring' | 'closed' | 'gc_movements';

export default function LoyaltyBoard({ section }: { section: Section }) {
  const { can } = useAuth();
  const settingsHref = '/settings#fidelizacion';
  const [data, setData] = useState<LoyaltyBoardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [pointsFilter, setPointsFilter] = useState<PointsFilter>('all');
  const [gcFilter, setGcFilter] = useState<GiftCardsFilter>('all');
  const [customerHistoryFilter, setCustomerHistoryFilter] = useState<string | null>(null);

  const [pointsSearch, setPointsSearch] = useState('');
  const [pointMovSearch, setPointMovSearch] = useState('');
  const [pointMovFilters, setPointMovFilters] = useState<Record<string, string>>({ type: '' });
  const [gcSearch, setGcSearch] = useState('');
  const [gcFilters, setGcFilters] = useState<Record<string, string>>({ status: '' });
  const [gcMovSearch, setGcMovSearch] = useState('');
  const [gcMovFilters, setGcMovFilters] = useState<Record<string, string>>({ type: '' });

  const [pointsDrawerOpen, setPointsDrawerOpen] = useState(false);
  const [selectedCustomer, setSelectedCustomer] = useState<LoyaltyCustomerRow | null>(null);
  const [initialPointType, setInitialPointType] = useState<'earn' | 'redeem' | 'adjust' | 'referral'>('earn');

  const [gcDrawerOpen, setGcDrawerOpen] = useState(false);
  const [gcActionTarget, setGcActionTarget] = useState<GiftCardRow | null>(null);
  const [gcActionMode, setGcActionMode] = useState<'redeem' | 'extend' | 'cancel' | null>(null);
  const [referralOpen, setReferralOpen] = useState(false);
  const [referralSearch, setReferralSearch] = useState('');
  const [copiedCode, setCopiedCode] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError('');
      const res = await api.get<LoyaltyBoardData>('/loyalty');
      setData(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo cargar el módulo de puntos y gift cards.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const handleSaved = (next: LoyaltyBoardData, message: string) => {
    setData(next);
    setNotice(message);
    setError('');
  };

  const copyCode = async (code: string) => {
    try {
      await navigator.clipboard.writeText(code);
      setCopiedCode(code);
      setTimeout(() => setCopiedCode(null), 1800);
    } catch {
    }
  };

  const currency = data?.currency ?? 'PEN';
  const rules = data?.rules ?? {
    points_enabled: true,
    amount_per_point: 10,
    point_value: 0.5,
    min_redeem_points: 20,
    points_validity_days: 365,
    gift_cards_enabled: true,
    gift_card_validity_days: 365,
  };

  const customers = data?.customers ?? [];
  const pointMovements = data?.point_movements ?? [];
  const giftCards = data?.gift_cards ?? [];
  const gcMovements = data?.gift_card_movements ?? [];
  const referrals = data?.referrals ?? [];
  const summary = data?.summary;
  const vipMultiplier = rules.vip_multiplier ?? 1;
  const rulesText = rules.points_enabled
    ? `1 punto por cada ${money(rules.amount_per_point, currency)} pagado${vipMultiplier > 1 ? ` (x${vipMultiplier} para clientes VIP)` : ''}. 1 punto = ${money(rules.point_value, currency)} · canje desde ${rules.min_redeem_points} puntos · ${rules.points_validity_days > 0 ? `vencen a los ${rules.points_validity_days} días` : 'no vencen'}. La propina no da puntos y las devoluciones los descuentan.`
    : 'Los puntos están apagados en las reglas: las compras no suman puntos.';

  const filteredReferrals = useMemo(() => {
    const q = referralSearch.trim().toLowerCase();
    if (!q) return referrals;
    return referrals.filter((r) => [r.referrer_name, r.referred_name, r.status_label].some((v) => v.toLowerCase().includes(q)));
  }, [referrals, referralSearch]);

  const pointsStats = useMemo(() => {
    const withPoints = customers.filter((c) => c.points > 0);
    const canRedeem = customers.filter((c) => c.can_redeem);
    const totalPoints = customers.reduce((sum, c) => sum + c.points, 0);
    const totalRedeemable = customers.reduce((sum, c) => sum + c.redeemable_value, 0);
    return {
      totalCustomers: customers.length,
      withPointsCount: withPoints.length,
      canRedeemCount: canRedeem.length,
      totalPoints,
      totalRedeemable,
      movementsCount: pointMovements.length,
    };
  }, [customers, pointMovements]);

  const gcStats = useMemo(() => {
    const active = giftCards.filter((g) => g.status === 'active' && g.balance > 0);
    const expiring = active.filter((g) => g.days_left !== null && g.days_left >= 0 && g.days_left <= 30);
    const closed = giftCards.filter((g) => g.status !== 'active' || g.balance <= 0);
    const activeBalance = active.reduce((sum, g) => sum + g.balance, 0);
    return {
      totalCount: giftCards.length,
      activeCount: active.length,
      expiringCount: expiring.length,
      closedCount: closed.length,
      activeBalance,
      movementsCount: gcMovements.length,
    };
  }, [giftCards, gcMovements]);

  const filteredCustomers = useMemo(() => {
    const q = pointsSearch.trim().toLowerCase();
    return customers.filter((c) => {
      if (pointsFilter === 'with_points' && c.points <= 0) return false;
      if (pointsFilter === 'can_redeem' && !c.can_redeem) return false;
      if (!q) return true;
      return [c.name, c.document_number, c.phone, c.email].some((v) => v?.toLowerCase().includes(q));
    });
  }, [customers, pointsFilter, pointsSearch]);

  const filteredPointMovements = useMemo(() => {
    const q = pointMovSearch.trim().toLowerCase();
    return pointMovements.filter((m) => {
      if (customerHistoryFilter && m.customer_id !== customerHistoryFilter) return false;
      if (pointMovFilters.type && m.type !== pointMovFilters.type) return false;
      if (!q) return true;
      return [m.customer_name, m.customer_document, m.reason, m.sale_code, m.type_label].some((v) =>
        v?.toLowerCase().includes(q),
      );
    });
  }, [pointMovements, customerHistoryFilter, pointMovFilters, pointMovSearch]);

  const filteredGiftCards = useMemo(() => {
    const q = gcSearch.trim().toLowerCase();
    return giftCards.filter((g) => {
      if (gcFilter === 'active' && !(g.status === 'active' && g.balance > 0)) return false;
      if (gcFilter === 'expiring' && !(g.status === 'active' && g.days_left !== null && g.days_left >= 0 && g.days_left <= 30)) return false;
      if (gcFilter === 'closed' && !(g.status !== 'active' || g.balance <= 0)) return false;
      if (gcFilters.status && g.status !== gcFilters.status) return false;
      if (!q) return true;
      return [g.code, g.recipient_name, g.recipient_email, g.purchaser_name].some((v) =>
        v?.toLowerCase().includes(q),
      );
    });
  }, [giftCards, gcFilter, gcFilters, gcSearch]);

  const filteredGcMovements = useMemo(() => {
    const q = gcMovSearch.trim().toLowerCase();
    return gcMovements.filter((m) => {
      if (gcMovFilters.type && m.type !== gcMovFilters.type) return false;
      if (!q) return true;
      return [m.gift_card_code, m.recipient_name, m.sale_code, m.type_label].some((v) => v?.toLowerCase().includes(q));
    });
  }, [gcMovements, gcMovFilters, gcMovSearch]);

  const customerColumns: Column<LoyaltyCustomerRow>[] = [
    {
      header: 'Cliente',
      cell: (row) => (
        <div className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink">
            <Sparkles size={16} />
          </span>
          <div>
            <span className={styles.customerName}>{row.name}</span>
            {row.vip && <Badge tone="bg-brand-soft/30 text-brand-ink" bold>VIP</Badge>}
            <span className="block text-xs text-slate-400">
              {[row.document_number, row.phone, row.email].filter(Boolean).join(' · ') || 'Sin documento ni contacto'}
            </span>
          </div>
        </div>
      ),
    },
    {
      header: 'Puntos disponibles',
      cell: (row) => (
        <div className="flex items-center gap-2">
          <span className="text-base font-black text-brand-ink tabular-nums">{row.points} pts</span>
          {row.can_redeem ? (
            <Badge tone="bg-emerald-50 text-emerald-700" bold>Listo para canjear</Badge>
          ) : row.points > 0 ? (
            <Badge tone="bg-amber-50 text-amber-800">Mín. {rules.min_redeem_points} pts</Badge>
          ) : null}
        </div>
      ),
    },
    {
      header: 'Nivel',
      cell: (row) => (row.tier ? <Badge tone={TIER_BADGES[row.tier.key]} bold>{row.tier.label}</Badge> : null),
    },
    {
      header: 'Valor canjeable',
      cell: (row) => (
        <span className="font-bold text-brand-accent tabular-nums">{money(row.redeemable_value, currency)}</span>
      ),
    },
    {
      header: 'Acumulados / Canjeados',
      cell: (row) => (
        <div className="text-xs text-slate-600 space-y-0.5">
          <span className="block">Ganados: <strong className="text-emerald-700">+{row.earned} pts</strong></span>
          <span className="block">Canjeados: <strong className="text-sky-700">−{row.redeemed} pts</strong></span>
        </div>
      ),
    },
    {
      header: 'Último movimiento',
      cell: (row) => (
        <span className="text-xs text-slate-500">{row.last_movement_at ? momentOf(row.last_movement_at) : 'Sin movimientos'}</span>
      ),
    },
    {
      header: 'Acciones',
      className: 'text-right',
      cell: (row) => (
        <div className="flex items-center justify-end gap-1.5">
          {data?.can.create && (
            <button
              type="button"
              onClick={() => {
                setSelectedCustomer(row);
                setInitialPointType('earn');
                setPointsDrawerOpen(true);
              }}
              className="px-2.5 py-1.5 text-xs font-bold rounded-lg bg-brand text-white hover:bg-brand-hover transition-colors"
            >
              + Puntos
            </button>
          )}
          {data?.can.edit && row.points > 0 && (
            <button
              type="button"
              onClick={() => {
                setSelectedCustomer(row);
                setInitialPointType('redeem');
                setPointsDrawerOpen(true);
              }}
              className="px-2.5 py-1.5 text-xs font-bold rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors"
            >
              Canjear
            </button>
          )}
          {row.movements_count > 0 && (
            <button
              type="button"
              onClick={() => {
                setCustomerHistoryFilter(row.id);
                setPointsFilter('movements');
              }}
              className="px-2.5 py-1.5 text-xs font-semibold rounded-lg text-brand-accent hover:bg-slate-100 transition-colors"
            >
              Historial ({row.movements_count})
            </button>
          )}
        </div>
      ),
    },
  ];

  const pointMovementColumns: Column<PointMovementRow>[] = [
    {
      header: 'Fecha',
      cell: (row) => <span className="text-xs text-slate-600">{momentOf(row.created_at)}</span>,
    },
    {
      header: 'Cliente',
      cell: (row) => (
        <div>
          <span className="block font-bold text-slate-800">{row.customer_name}</span>
          {row.customer_document && <span className="block text-xs text-slate-500">{row.customer_document}</span>}
        </div>
      ),
    },
    {
      header: 'Tipo',
      cell: (row) => (
        <Badge tone={POINT_TYPE_BADGES[row.type]} bold>
          {row.type_label}
        </Badge>
      ),
    },
    {
      header: 'Puntos',
      cell: (row) => (
        <div>
          <span className={`font-black tabular-nums ${row.points > 0 ? (row.expired_points && row.expired_points >= row.points ? 'text-slate-400 line-through' : 'text-emerald-700') : 'text-rose-700'}`}>
            {row.points > 0 ? `+${row.points}` : row.points} pts
          </span>
          {row.points > 0 && (row.expired_points ?? 0) > 0 && (
            <span data-expired-points={row.expired_points} className="block text-xs font-semibold text-rose-600">
              {row.expired_points! >= row.points ? 'Vencidos' : `Vencieron ${row.expired_points}`}
            </span>
          )}
          {row.points > 0 && !(row.expired_points ?? 0) && row.expires_at && (
            <span className="block text-xs text-slate-400">Vencen {momentOf(row.expires_at)}</span>
          )}
        </div>
      ),
    },
    {
      header: 'Saldo luego',
      cell: (row) => <span className="font-semibold text-slate-700 tabular-nums">{row.balance_after} pts</span>,
    },
    {
      header: 'Motivo / Venta',
      cell: (row) => (
        <div className="text-xs text-slate-600">
          <span className="block font-medium text-slate-800">{row.reason || '—'}</span>
          {row.sale_code && <span className="text-slate-500">Venta {row.sale_code}</span>}
        </div>
      ),
    },
    {
      header: 'Registrado por',
      cell: (row) => <span className="text-xs text-slate-500">{row.actor || 'Automático'}</span>,
    },
  ];

  const giftCardColumns: Column<GiftCardRow>[] = [
    {
      header: 'Código',
      cell: (row) => (
        <div className="flex items-center gap-3">
          <span className="p-2 rounded-lg shrink-0 bg-brand-soft/20 text-brand-ink">
            <Gift size={16} />
          </span>
          <div className="flex items-center gap-2">
            <span className={`${styles.customerName} font-mono tracking-wide`}>{row.code}</span>
            <button
              type="button"
              onClick={() => copyCode(row.code)}
              title="Copiar código"
              className="p-1 rounded-md text-slate-400 hover:text-brand-ink hover:bg-slate-100 transition-colors"
            >
              <Copy size={13} />
            </button>
            {copiedCode === row.code && <span className="text-[11px] font-bold text-emerald-600">¡Copiado!</span>}
          </div>
        </div>
      ),
    },
    {
      header: 'Destinatario / Comprador',
      cell: (row) => (
        <div className="text-xs">
          <span className="block font-bold text-slate-800 text-sm">{row.recipient_name || 'Al portador'}</span>
          {row.recipient_email && <span className="block text-slate-500">{row.recipient_email}</span>}
          {row.purchaser_name && <span className="block text-slate-400">Comprada por {row.purchaser_name}</span>}
        </div>
      ),
    },
    {
      header: 'Saldo disponible',
      cell: (row) => (
        <div>
          <span className="block text-sm font-black text-brand-ink tabular-nums">{money(row.balance, currency)}</span>
          <span className="block text-xs text-slate-500">
            Inicial: {money(row.initial_amount, currency)}
            {row.used_amount > 0 ? ` · Usado: ${money(row.used_amount, currency)}` : ''}
          </span>
        </div>
      ),
    },
    {
      header: 'Vigencia',
      cell: (row) => {
        if (!row.expires_at) {
          return <span className="text-xs font-medium text-slate-500">Sin vencimiento</span>;
        }
        const expiringSoon = row.status === 'active' && row.days_left !== null && row.days_left >= 0 && row.days_left <= 30;
        return (
          <div className="text-xs">
            <span className="block text-slate-700 font-medium">{momentOf(row.expires_at)}</span>
            {row.status === 'active' && row.days_left !== null && (
              <span className={`font-semibold ${expiringSoon ? 'text-amber-700' : 'text-slate-400'}`}>
                {row.days_left === 0 ? 'Vence hoy' : row.days_left > 0 ? `Quedan ${row.days_left} días` : 'Vencida'}
              </span>
            )}
          </div>
        );
      },
    },
    {
      header: 'Estado',
      cell: (row) => (
        <Badge tone={GIFT_CARD_STATUS_BADGES[row.status]} bold>
          {row.status_label}
        </Badge>
      ),
    },
    {
      header: 'Acciones',
      className: 'text-right',
      cell: (row) => (
        <div className="flex items-center justify-end gap-1.5">
          {data?.can.edit && row.status === 'active' && row.balance > 0 && (
            <button
              type="button"
              onClick={() => {
                setGcActionTarget(row);
                setGcActionMode('redeem');
              }}
              className="px-2.5 py-1.5 text-xs font-bold rounded-lg bg-brand text-white hover:bg-brand-hover transition-colors"
            >
              Descontar
            </button>
          )}
          {data?.can.edit && row.status !== 'cancelled' && (
            <button
              type="button"
              onClick={() => {
                setGcActionTarget(row);
                setGcActionMode('extend');
              }}
              className="px-2.5 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors"
            >
              Ampliar
            </button>
          )}
          {data?.can.delete && row.status === 'active' && (
            <button
              type="button"
              onClick={() => {
                setGcActionTarget(row);
                setGcActionMode('cancel');
              }}
              className="px-2.5 py-1.5 text-xs font-semibold rounded-lg text-rose-700 hover:bg-rose-50 transition-colors"
            >
              Anular
            </button>
          )}
        </div>
      ),
    },
  ];

  const gcMovementColumns: Column<GiftCardMovementRow>[] = [
    {
      header: 'Fecha',
      cell: (row) => <span className="text-xs text-slate-600">{momentOf(row.created_at)}</span>,
    },
    {
      header: 'Gift card',
      cell: (row) => <span className="font-mono font-bold text-brand-ink">{row.gift_card_code}</span>,
    },
    {
      header: 'Destinatario',
      cell: (row) => <span className="text-sm text-slate-700">{row.recipient_name || 'Al portador'}</span>,
    },
    {
      header: 'Movimiento',
      cell: (row) => (
        <Badge tone={GIFT_CARD_TX_BADGES[row.type]} bold>
          {row.type_label}
        </Badge>
      ),
    },
    {
      header: 'Monto',
      cell: (row) => (
        <span className="font-bold text-slate-800 tabular-nums">{money(row.amount, currency)}</span>
      ),
    },
    {
      header: 'Venta vinculada',
      cell: (row) => (
        <div>
          <span className="text-xs text-slate-500">{row.sale_code ? `Venta ${row.sale_code}` : 'Manual'}</span>
          {row.reason && <span className="block text-xs text-slate-600">Motivo: {row.reason}</span>}
        </div>
      ),
    },
  ];

  const referralColumns: Column<ReferralRow>[] = [
    { header: 'Fecha', cell: (row) => <span className="text-xs text-slate-600">{momentOf(row.created_at)}</span> },
    { header: 'Recomendó', cell: (row) => <Link href={`/customers/${row.referrer_id}`} className="font-bold text-brand-ink hover:underline">{row.referrer_name}</Link> },
    { header: 'Llegó recomendado', cell: (row) => <Link href={`/customers/${row.referred_id}`} className="font-semibold text-slate-700 hover:underline">{row.referred_name}</Link> },
    { header: 'Bono', cell: (row) => <span className="font-bold tabular-nums text-slate-800">{row.bonus_points} pts</span> },
    {
      header: 'Estado',
      cell: (row) => (
        <div>
          <Badge tone={REFERRAL_BADGES[row.status]} bold>{row.status_label}</Badge>
          {row.rewarded_at && row.status === 'rewarded' && <span className="block text-xs text-slate-500 mt-0.5">{momentOf(row.rewarded_at)}</span>}
        </div>
      ),
    },
  ];

  const pointsMetricCards: MetricCardItem[] = [
    {
      title: 'Puntos en circulación',
      value: (summary?.points_in_circulation ?? pointsStats.totalPoints).toLocaleString('es-PE'),
      hint: `Se debe ${money(summary?.points_debt ?? pointsStats.totalRedeemable, currency)} en canjes`,
      icon: <Sparkles size={18} />,
      iconColorClass: 'text-brand-ink',
      borderColorClass: pointsFilter === 'all' ? 'border-brand ring-2 ring-brand/20' : 'hover:border-slate-300',
      onClick: () => {
        setCustomerHistoryFilter(null);
        setPointsFilter('all');
      },
    },
    {
      title: 'Ganados (30 días)',
      value: (summary?.issued_30d ?? 0).toLocaleString('es-PE'),
      icon: <Award size={18} />,
      iconColorClass: 'text-brand-accent',
    },
    {
      title: 'Canjeados (30 días)',
      value: (summary?.redeemed_30d ?? 0).toLocaleString('es-PE'),
      hint: summary && summary.issued_30d > 0 ? `${Math.round((summary.redeemed_30d / summary.issued_30d) * 100)} % de lo ganado` : undefined,
      icon: <Gift size={18} />,
      iconColorClass: 'text-sky-600',
    },
    {
      title: 'Clientes con puntos',
      value: pointsStats.withPointsCount,
      icon: <Users size={18} />,
      iconColorClass: 'text-brand-accent',
      borderColorClass: pointsFilter === 'with_points' ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
      onClick: () => {
        setCustomerHistoryFilter(null);
        setPointsFilter(pointsFilter === 'with_points' ? 'all' : 'with_points');
      },
    },
    {
      title: 'Listos para canjear',
      value: pointsStats.canRedeemCount,
      icon: <CheckCircle2 size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: pointsFilter === 'can_redeem' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => {
        setCustomerHistoryFilter(null);
        setPointsFilter(pointsFilter === 'can_redeem' ? 'all' : 'can_redeem');
      },
    },
    {
      title: 'Referidos',
      value: referrals.length,
      hint: referrals.some((r) => r.status === 'pending') ? `${referrals.filter((r) => r.status === 'pending').length} esperan su primera atención` : undefined,
      icon: <UserPlus size={18} />,
      iconColorClass: 'text-violet-600',
      borderColorClass: pointsFilter === 'referrals' ? 'border-violet-500 ring-2 ring-violet-500/20' : 'hover:border-slate-300',
      onClick: () => setPointsFilter(pointsFilter === 'referrals' ? 'all' : 'referrals'),
    },
  ];

  const gcMetricCards: MetricCardItem[] = [
    {
      title: 'Todas las gift cards',
      value: gcStats.totalCount,
      icon: <Gift size={18} />,
      iconColorClass: 'text-brand-ink',
      borderColorClass: gcFilter === 'all' ? 'border-brand ring-2 ring-brand/20' : 'hover:border-slate-300',
      onClick: () => setGcFilter('all'),
    },
    {
      title: 'Vigentes con saldo',
      value: gcStats.activeCount,
      hint: `Saldo por canjear: ${money(summary?.gift_cards_pending ?? gcStats.activeBalance, currency)}`,
      icon: <CheckCircle2 size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: gcFilter === 'active' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => setGcFilter(gcFilter === 'active' ? 'all' : 'active'),
    },
    {
      title: 'Por vencer (≤ 30 días)',
      value: gcStats.expiringCount,
      icon: <Clock size={18} />,
      iconColorClass: 'text-amber-600',
      borderColorClass: gcFilter === 'expiring' ? 'border-amber-500 ring-2 ring-amber-500/20' : 'hover:border-slate-300',
      onClick: () => setGcFilter(gcFilter === 'expiring' ? 'all' : 'expiring'),
    },
    {
      title: 'Vendido (histórico)',
      value: money(summary?.gift_cards_sold ?? giftCards.filter((g) => g.status !== 'cancelled').reduce((sum, g) => sum + g.initial_amount, 0), currency),
      icon: <CreditCard size={18} />,
      iconColorClass: 'text-brand-ink',
    },
    {
      title: 'Canjeadas o cerradas',
      value: gcStats.closedCount,
      icon: <Ban size={18} />,
      iconColorClass: 'text-slate-500',
      borderColorClass: gcFilter === 'closed' ? 'border-slate-500 ring-2 ring-slate-500/20' : 'hover:border-slate-300',
      onClick: () => setGcFilter(gcFilter === 'closed' ? 'all' : 'closed'),
    },
    {
      title: 'Movimientos de gift cards',
      value: gcStats.movementsCount,
      icon: <History size={18} />,
      iconColorClass: 'text-brand-accent',
      borderColorClass: gcFilter === 'gc_movements' ? 'border-brand-accent ring-2 ring-brand-accent/20' : 'hover:border-slate-300',
      onClick: () => setGcFilter(gcFilter === 'gc_movements' ? 'all' : 'gc_movements'),
    },
  ];

  if (loading && !data) {
    return <p className="text-sm text-slate-500 p-6">Cargando puntos y gift cards...</p>;
  }

  return (
    <div className="space-y-4">
      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      {section === 'points' && (
        <p className="text-sm text-slate-600 bg-white border border-slate-100 rounded-2xl px-4 py-3" data-rules-text>{rulesText}</p>
      )}

      <div className="flex flex-wrap items-center justify-end gap-2">
        {section === 'points' && pointsFilter === 'movements' && customerHistoryFilter && (
          <button
            type="button"
            onClick={() => setCustomerHistoryFilter(null)}
            className="mr-auto px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-soft/20 text-brand-ink hover:bg-brand-soft/35 transition-colors"
          >
            Viendo 1 cliente · Ver todos
          </button>
        )}

        {section === 'points' && data?.can.create && (
          <button
            type="button"
            onClick={() => setReferralOpen(true)}
            className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 transition-colors"
          >
            <UserPlus size={14} /> Registrar referido
          </button>
        )}

        {can('settings.view') && (
          <Link
            href={settingsHref}
            className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 transition-colors"
          >
            <Settings size={14} /> {section === 'points' ? 'Reglas de los puntos' : 'Reglas de las gift cards'}
          </Link>
        )}
      </div>

      {section === 'points' ? (
        pointsFilter === 'referrals' ? (
          <DataTable<ReferralRow>
            title="Referidos"
            primaryActionLabel={data?.can.create ? 'Registrar referido' : undefined}
            onPrimaryAction={data?.can.create ? () => setReferralOpen(true) : undefined}
            tabs={[
              { id: 'all', label: 'Clientes', count: pointsStats.totalCustomers },
              { id: 'with_points', label: 'Con puntos', count: pointsStats.withPointsCount },
              { id: 'can_redeem', label: 'Listos para canjear', count: pointsStats.canRedeemCount },
              { id: 'movements', label: 'Historial de puntos', count: pointsStats.movementsCount },
              { id: 'referrals', label: 'Referidos', count: referrals.length },
            ]}
            activeTab={pointsFilter}
            onTabChange={(id) => {
              setCustomerHistoryFilter(null);
              setPointsFilter(id as PointsFilter);
            }}
            searchTerm={referralSearch}
            onSearchChange={setReferralSearch}
            columns={referralColumns}
            data={filteredReferrals}
            exportTitle="Referidos"
            exportScreen="loyalty_referrals"
            exportColumns={[
              { header: 'Fecha', type: 'datetime', value: (r) => r.created_at },
              { header: 'Recomendó', value: (r) => r.referrer_name },
              { header: 'Llegó recomendado', value: (r) => r.referred_name },
              { header: 'Bono', type: 'number', value: (r) => r.bonus_points },
              { header: 'Estado', value: (r) => r.status_label },
            ]}
            getItemKey={(r) => r.id}
            entityLabel={filteredReferrals.length === 1 ? 'referido' : 'referidos'}
            metricCards={pointsMetricCards}
          />
        ) : pointsFilter === 'movements' ? (
          <DataTable<PointMovementRow>
            title="Historial de puntos"
            primaryActionLabel={data?.can.create ? 'Movimiento de puntos' : undefined}
            onPrimaryAction={
              data?.can.create
                ? () => {
                    setSelectedCustomer(null);
                    setInitialPointType('earn');
                    setPointsDrawerOpen(true);
                  }
                : undefined
            }
            tabs={[
              { id: 'all', label: 'Clientes', count: pointsStats.totalCustomers },
              { id: 'with_points', label: 'Con puntos', count: pointsStats.withPointsCount },
              { id: 'can_redeem', label: 'Listos para canjear', count: pointsStats.canRedeemCount },
              { id: 'movements', label: 'Historial de puntos', count: pointsStats.movementsCount },
              { id: 'referrals', label: 'Referidos', count: referrals.length },
            ]}
            activeTab={pointsFilter}
            onTabChange={(id) => {
              setCustomerHistoryFilter(null);
              setPointsFilter(id as PointsFilter);
            }}
            searchTerm={pointMovSearch}
            onSearchChange={setPointMovSearch}
            filterConfigs={[
              {
                id: 'type',
                label: 'Tipo',
                type: 'select',
                options: [
                  { value: 'earn', label: 'Puntos ganados' },
                  { value: 'redeem', label: 'Canje de puntos' },
                  { value: 'referral', label: 'Premio por referido' },
                  { value: 'adjust', label: 'Ajuste manual' },
                  { value: 'expire', label: 'Vencimiento' },
                  { value: 'reversal', label: 'Reversión por devolución' },
                ],
              },
            ]}
            filterValues={pointMovFilters}
            onFilterChange={(id, value) => setPointMovFilters((curr) => ({ ...curr, [id]: value }))}
            dateFilters={[{ id: 'created_at', label: 'Fecha', value: (m) => m.created_at }]}
            sortOptions={[
              { id: 'created_at', label: 'Fecha', type: 'date', value: (m) => m.created_at },
              { id: 'points', label: 'Puntos', type: 'number', value: (m) => m.points },
              { id: 'customer', label: 'Cliente', value: (m) => m.customer_name },
            ]}
            columns={pointMovementColumns}
            data={filteredPointMovements}
            exportTitle="Historial_Puntos"
            exportScreen="loyalty_point_movements"
            exportColumns={[
              { header: 'Fecha', type: 'datetime', value: (m) => m.created_at },
              { header: 'Cliente', value: (m) => m.customer_name },
              { header: 'Tipo', value: (m) => m.type_label },
              { header: 'Puntos', type: 'number', value: (m) => m.points },
              { header: 'Saldo luego', type: 'number', value: (m) => m.balance_after },
              { header: 'Motivo', value: (m) => m.reason },
              { header: 'Venta', value: (m) => m.sale_code },
            ]}
            getItemKey={(m) => m.id}
            entityLabel={filteredPointMovements.length === 1 ? 'movimiento' : 'movimientos'}
            metricCards={pointsMetricCards}
          />
        ) : (
          <DataTable<LoyaltyCustomerRow>
            title="Puntos"
            primaryActionLabel={data?.can.create ? 'Movimiento de puntos' : undefined}
            onPrimaryAction={
              data?.can.create
                ? () => {
                    setSelectedCustomer(null);
                    setInitialPointType('earn');
                    setPointsDrawerOpen(true);
                  }
                : undefined
            }
            tabs={[
              { id: 'all', label: 'Clientes', count: pointsStats.totalCustomers },
              { id: 'with_points', label: 'Con puntos', count: pointsStats.withPointsCount },
              { id: 'can_redeem', label: 'Listos para canjear', count: pointsStats.canRedeemCount },
              { id: 'movements', label: 'Historial de puntos', count: pointsStats.movementsCount },
              { id: 'referrals', label: 'Referidos', count: referrals.length },
            ]}
            activeTab={pointsFilter}
            onTabChange={(id) => {
              setCustomerHistoryFilter(null);
              setPointsFilter(id as PointsFilter);
            }}
            searchTerm={pointsSearch}
            onSearchChange={setPointsSearch}
            sortOptions={[
              { id: 'points', label: 'Puntos disponibles', type: 'number', value: (c) => c.points },
              { id: 'value', label: 'Valor canjeable', type: 'number', value: (c) => c.redeemable_value },
              { id: 'earned', label: 'Puntos ganados', type: 'number', value: (c) => c.earned },
              { id: 'name', label: 'Nombre', value: (c) => c.name },
            ]}
            columns={customerColumns}
            data={filteredCustomers}
            exportTitle="Puntos_Clientes"
            exportScreen="loyalty_customers"
            exportColumns={[
              { header: 'Cliente', value: (c) => c.name },
              { header: 'Documento', value: (c) => c.document_number },
              { header: 'Puntos disponibles', type: 'number', value: (c) => c.points },
              { header: 'Valor canjeable', type: 'money', total: true, value: (c) => c.redeemable_value },
              { header: 'Ganados', type: 'number', value: (c) => c.earned },
              { header: 'Canjeados', type: 'number', value: (c) => c.redeemed },
              { header: 'Nivel', value: (c) => c.tier?.label ?? '' },
            ]}
            getItemKey={(c) => c.id}
            entityLabel={filteredCustomers.length === 1 ? 'cliente' : 'clientes'}
            metricCards={pointsMetricCards}
          />
        )
      ) : gcFilter === 'gc_movements' ? (
        <DataTable<GiftCardMovementRow>
          title="Movimientos de gift cards"
          primaryActionLabel={data?.can.create ? 'Emitir gift card' : undefined}
          onPrimaryAction={data?.can.create ? () => setGcDrawerOpen(true) : undefined}
          tabs={[
            { id: 'all', label: 'Todas', count: gcStats.totalCount },
            { id: 'active', label: 'Vigentes', count: gcStats.activeCount },
            { id: 'expiring', label: 'Por vencer', count: gcStats.expiringCount },
            { id: 'closed', label: 'Cerradas', count: gcStats.closedCount },
            { id: 'gc_movements', label: 'Movimientos', count: gcStats.movementsCount },
          ]}
          activeTab={gcFilter}
          onTabChange={(id) => setGcFilter(id as GiftCardsFilter)}
          searchTerm={gcMovSearch}
          onSearchChange={setGcMovSearch}
          filterConfigs={[
            {
              id: 'type',
              label: 'Movimiento',
              type: 'select',
              options: [
                { value: 'issue', label: 'Emisión' },
                { value: 'redeem', label: 'Consumo' },
                { value: 'refund', label: 'Devolución' },
                { value: 'extend', label: 'Ampliación' },
                { value: 'cancel', label: 'Anulación' },
              ],
            },
          ]}
          filterValues={gcMovFilters}
          onFilterChange={(id, value) => setGcMovFilters((curr) => ({ ...curr, [id]: value }))}
          dateFilters={[{ id: 'created_at', label: 'Fecha', value: (m) => m.created_at }]}
          sortOptions={[
            { id: 'created_at', label: 'Fecha', type: 'date', value: (m) => m.created_at },
            { id: 'amount', label: 'Monto', type: 'number', value: (m) => m.amount },
            { id: 'code', label: 'Código', value: (m) => m.gift_card_code },
          ]}
          columns={gcMovementColumns}
          data={filteredGcMovements}
          exportTitle="Movimientos_Gift_Cards"
          exportScreen="loyalty_gift_card_movements"
          exportColumns={[
            { header: 'Fecha', type: 'datetime', value: (m) => m.created_at },
            { header: 'Gift card', value: (m) => m.gift_card_code },
            { header: 'Destinatario', value: (m) => m.recipient_name },
            { header: 'Movimiento', value: (m) => m.type_label },
            { header: 'Monto', type: 'money', total: true, value: (m) => m.amount },
            { header: 'Venta', value: (m) => m.sale_code },
          ]}
          getItemKey={(m) => m.id}
          entityLabel={filteredGcMovements.length === 1 ? 'movimiento' : 'movimientos'}
          metricCards={gcMetricCards}
        />
      ) : (
        <DataTable<GiftCardRow>
          title="Gift cards"
          primaryActionLabel={data?.can.create ? 'Emitir gift card' : undefined}
          onPrimaryAction={data?.can.create ? () => setGcDrawerOpen(true) : undefined}
          tabs={[
            { id: 'all', label: 'Todas', count: gcStats.totalCount },
            { id: 'active', label: 'Vigentes', count: gcStats.activeCount },
            { id: 'expiring', label: 'Por vencer', count: gcStats.expiringCount },
            { id: 'closed', label: 'Cerradas', count: gcStats.closedCount },
            { id: 'gc_movements', label: 'Movimientos', count: gcStats.movementsCount },
          ]}
          activeTab={gcFilter}
          onTabChange={(id) => setGcFilter(id as GiftCardsFilter)}
          searchTerm={gcSearch}
          onSearchChange={setGcSearch}
          filterConfigs={[
            {
              id: 'status',
              label: 'Estado',
              type: 'select',
              options: [
                { value: 'active', label: 'Vigente' },
                { value: 'redeemed', label: 'Canjeada' },
                { value: 'expired', label: 'Vencida' },
                { value: 'cancelled', label: 'Anulada' },
              ],
            },
          ]}
          filterValues={gcFilters}
          onFilterChange={(id, value) => setGcFilters((curr) => ({ ...curr, [id]: value }))}
          dateFilters={[{ id: 'expires_at', label: 'Vencimiento', future: true, value: (g) => g.expires_at }]}
          sortOptions={[
            { id: 'created_at', label: 'Fecha de emisión', type: 'date', value: (g) => g.created_at },
            { id: 'balance', label: 'Saldo disponible', type: 'number', value: (g) => g.balance },
            { id: 'initial_amount', label: 'Monto inicial', type: 'number', value: (g) => g.initial_amount },
            { id: 'code', label: 'Código', value: (g) => g.code },
          ]}
          columns={giftCardColumns}
          data={filteredGiftCards}
          exportTitle="Gift_Cards"
          exportScreen="loyalty_gift_cards"
          exportColumns={[
            { header: 'Código', value: (g) => g.code },
            { header: 'Destinatario', value: (g) => g.recipient_name },
            { header: 'Comprador', value: (g) => g.purchaser_name },
            { header: 'Monto inicial', type: 'money', total: true, value: (g) => g.initial_amount },
            { header: 'Saldo disponible', type: 'money', total: true, value: (g) => g.balance },
            { header: 'Estado', value: (g) => g.status_label },
            { header: 'Vence', type: 'datetime', value: (g) => g.expires_at },
          ]}
          getItemKey={(g) => g.id}
          entityLabel={filteredGiftCards.length === 1 ? 'gift card' : 'gift cards'}
          metricCards={gcMetricCards}
        />
      )}

      <PointsAdjustDrawer
        isOpen={pointsDrawerOpen}
        onClose={() => setPointsDrawerOpen(false)}
        customers={customers}
        initialCustomer={selectedCustomer}
        initialType={initialPointType}
        rules={rules}
        currency={currency}
        onSaved={handleSaved}
      />

      <GiftCardDrawer
        isOpen={gcDrawerOpen}
        onClose={() => setGcDrawerOpen(false)}
        customers={customers}
        rules={rules}
        onSaved={handleSaved}
      />

      <GiftCardActionDrawer
        giftCard={gcActionTarget}
        mode={gcActionMode}
        currency={currency}
        onClose={() => {
          setGcActionTarget(null);
          setGcActionMode(null);
        }}
        onSaved={handleSaved}
      />

      <ReferralDrawer
        isOpen={referralOpen}
        onClose={() => setReferralOpen(false)}
        customers={customers}
        rules={rules}
        currency={currency}
        onSaved={handleSaved}
      />
    </div>
  );
}
