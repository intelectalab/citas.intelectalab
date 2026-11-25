export interface Tracking {
  ga4_id: string | null;
  meta_pixel_id: string | null;
}

export const CONSENT_KEY = 'cookie_consent';

export function readConsent(): 'all' | 'necessary' | null {
  try {
    const value = localStorage.getItem(CONSENT_KEY);
    return value === 'all' || value === 'necessary' ? value : null;
  } catch {
    return null;
  }
}

export function saveConsent(value: 'all' | 'necessary') {
  try {
    localStorage.setItem(CONSENT_KEY, value);
  } catch {}
}

export function loadTrackers(tracking: Tracking) {
  const w = window as unknown as Record<string, any>;
  if (tracking.ga4_id && !w.__bookingGa4) {
    w.__bookingGa4 = true;
    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(tracking.ga4_id)}`;
    document.head.appendChild(script);
    w.dataLayer = w.dataLayer || [];
    w.gtag = function gtag() { w.dataLayer.push(arguments); };
    w.gtag('js', new Date());
    w.gtag('config', tracking.ga4_id);
  }
  if (tracking.meta_pixel_id && !w.__bookingPixel) {
    w.__bookingPixel = true;
    const fbq: any = function (...args: unknown[]) { fbq.callMethod ? fbq.callMethod(...args) : fbq.queue.push(args); };
    fbq.queue = [];
    fbq.loaded = true;
    fbq.version = '2.0';
    w.fbq = w.fbq || fbq;
    w._fbq = w._fbq || fbq;
    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://connect.facebook.net/en_US/fbevents.js';
    document.head.appendChild(script);
    w.fbq('init', tracking.meta_pixel_id);
    w.fbq('track', 'PageView');
  }
}

export function trackBooking(event: 'start' | 'complete', detail: { value?: number | null; currency?: string | null; service?: string | null } = {}) {
  const w = window as unknown as Record<string, any>;
  const value = detail.value ?? undefined;
  const currency = detail.currency ?? undefined;
  if (typeof w.gtag === 'function') {
    w.gtag('event', event === 'start' ? 'begin_checkout' : 'purchase', { value, currency, items: detail.service ? [{ item_name: detail.service }] : undefined });
  }
  if (typeof w.fbq === 'function') {
    w.fbq('track', event === 'start' ? 'InitiateCheckout' : 'Schedule', { value, currency, content_name: detail.service ?? undefined });
  }
}

export function channelFromUrl(): string | null {
  try {
    const params = new URLSearchParams(window.location.search);
    const value = params.get('origen') ?? params.get('utm_source');
    return value ? value.toLowerCase().slice(0, 30) : null;
  } catch {
    return null;
  }
}

export interface DepositConversion {
  tracking: Tracking;
  transaction_id: string;
  value: number | null;
  deposit: number;
  currency: string;
  service: string | null;
  sent: boolean;
}

const purchaseKey = (id: string) => `booking_purchase:${id}`;

export function purchaseAlreadySent(id: string): boolean {
  try {
    return localStorage.getItem(purchaseKey(id)) === '1';
  } catch {
    return false;
  }
}

export function trackDepositPurchase(conversion: DepositConversion) {
  const w = window as unknown as Record<string, any>;
  const value = conversion.value ?? conversion.deposit;
  if (typeof w.gtag === 'function') {
    w.gtag('event', 'purchase', { transaction_id: conversion.transaction_id, value, currency: conversion.currency, items: conversion.service ? [{ item_name: conversion.service, price: value, quantity: 1 }] : undefined });
  }
  if (typeof w.fbq === 'function') {
    w.fbq('track', 'Purchase', { value, currency: conversion.currency, content_name: conversion.service ?? undefined }, { eventID: conversion.transaction_id });
  }
  try {
    localStorage.setItem(purchaseKey(conversion.transaction_id), '1');
  } catch {}
}
