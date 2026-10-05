export interface Plan {
  id: string;
  name: string;
  slug: string;
  price_monthly: number | string;
  price_yearly: number | string;
  max_users: number;
  max_branches: number;
  max_appointments_monthly: number | null;
  features: Record<string, boolean>;
  status: string;
}