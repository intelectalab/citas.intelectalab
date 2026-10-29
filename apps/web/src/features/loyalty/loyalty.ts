export interface LoyaltyRules {
  points_enabled: boolean;
  amount_per_point: number;
  point_value: number;
  min_redeem_points: number;
  points_validity_days: number;
  gift_cards_enabled: boolean;
  gift_card_validity_days: number;
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
  sale_code: string | null;
  created_at: string | null;
}

export interface LoyaltyBoardData {
  currency: string;
  rules: LoyaltyRules;
  can: { create: boolean; edit: boolean; delete: boolean };
  customers: LoyaltyCustomerRow[];
  point_movements: PointMovementRow[];
  gift_cards: GiftCardRow[];
  gift_card_movements: GiftCardMovementRow[];
}

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
