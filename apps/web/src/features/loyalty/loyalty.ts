export interface LoyaltyRules {
  points_enabled: boolean;
  amount_per_point: number;
  point_value: number;
  min_redeem_points: number;
  points_validity_days: number;
  gift_cards_enabled: boolean;
  gift_card_validity_days: number;
  vip_multiplier?: number;
  vip_spend?: number;
}

export interface LoyaltyCustomerRow {
  id: string;
  name: string;
  document_number: string | null;
  email: string | null;
  phone: string | null;
  points: number;
  earned: number;
  redeemed: number;
  movements_count: number;
  redeemable_value: number;
  can_redeem: boolean;
  tier?: { key: 'gold' | 'silver' | 'bronze'; label: string };
  vip?: boolean;
  last_movement_at: string | null;
}

export interface PointMovementRow {
  id: string;
  customer_id: string;
  customer_name: string;
  customer_document: string | null;
  type: 'earn' | 'redeem' | 'adjust' | 'expire' | 'referral' | 'reversal';
  type_label: string;
  points: number;
  balance_after: number;
  reason: string | null;
  sale_code: string | null;
  expires_at: string | null;
  expired_points?: number;
  actor: string | null;
  created_at: string | null;
}

export interface GiftCardRow {
  id: string;
  code: string;
  initial_amount: number;
  balance: number;
  used_amount: number;
  status: 'active' | 'redeemed' | 'expired' | 'cancelled';
  status_label: string;
  purchaser_customer_id: string | null;
  purchaser_name: string | null;
  recipient_customer_id: string | null;
  recipient_name: string | null;
  recipient_email: string | null;
  sale_code: string | null;
  expires_at: string | null;
  days_left: number | null;
  created_at: string | null;
}

export interface GiftCardMovementRow {
  id: string;
  gift_card_id: string;
  gift_card_code: string;
  recipient_name: string | null;
  type: 'issue' | 'redeem' | 'refund' | 'extend' | 'cancel';
  type_label: string;
  amount: number;
  reason?: string | null;
  sale_code: string | null;
  created_at: string | null;
}

export interface LoyaltySummary {
  points_in_circulation: number;
  points_debt: number;
  issued_30d: number;
  redeemed_30d: number;
  gift_cards_pending: number;
  gift_cards_sold: number;
  gift_cards_expired: number;
}

export interface ReferralRow {
  id: string;
  referrer_id: string;
  referrer_name: string;
  referred_id: string;
  referred_name: string;
  bonus_points: number;
  status: 'pending' | 'rewarded' | 'void';
  status_label: string;
  created_at: string;
  rewarded_at: string | null;
}

export interface LoyaltyBoardData {
  currency: string;
  rules: LoyaltyRules;
  can: { create: boolean; edit: boolean; delete: boolean };
  customers: LoyaltyCustomerRow[];
  point_movements: PointMovementRow[];
  gift_cards: GiftCardRow[];
  gift_card_movements: GiftCardMovementRow[];
  summary?: LoyaltySummary;
  referrals?: ReferralRow[];
  tiers?: { key: string; label: string; from: number }[];
}

export const TIER_BADGES: Record<string, string> = {
  gold: 'bg-amber-50 text-amber-800',
  silver: 'bg-slate-100 text-slate-700',
  bronze: 'bg-orange-50 text-orange-800',
};

export const REFERRAL_BADGES: Record<ReferralRow['status'], string> = {
  pending: 'bg-amber-50 text-amber-800',
  rewarded: 'bg-emerald-50 text-emerald-700',
  void: 'bg-slate-100 text-slate-500',
};

export const POINT_TYPE_BADGES: Record<PointMovementRow['type'], string> = {
  earn: 'bg-emerald-50 text-emerald-700',
  redeem: 'bg-sky-50 text-sky-700',
  adjust: 'bg-amber-50 text-amber-800',
  referral: 'bg-violet-50 text-violet-700',
  expire: 'bg-slate-100 text-slate-600',
  reversal: 'bg-rose-50 text-rose-700',
};

export const GIFT_CARD_STATUS_BADGES: Record<GiftCardRow['status'], string> = {
  active: 'bg-emerald-50 text-emerald-700',
  redeemed: 'bg-sky-50 text-sky-700',
  expired: 'bg-amber-50 text-amber-800',
  cancelled: 'bg-slate-100 text-slate-500',
};

export const GIFT_CARD_TX_BADGES: Record<GiftCardMovementRow['type'], string> = {
  issue: 'bg-emerald-50 text-emerald-700',
  redeem: 'bg-sky-50 text-sky-700',
  refund: 'bg-amber-50 text-amber-800',
  extend: 'bg-violet-50 text-violet-700',
  cancel: 'bg-rose-50 text-rose-700',
};
