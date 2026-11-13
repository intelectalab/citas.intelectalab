export interface CulqiCheckoutConfig {
  public_key: string;
  script: string;
  title: string;
  description?: string;
  amount: number;
  currency: string;
  email?: string | null;
}

interface CulqiInstance {
  token?: { id: string } | null;
  error?: { user_message?: string; merchant_message?: string } | null;
  culqi?: () => void;
  closeCheckout?: () => void;
  open: () => void;
  close?: () => void;
}

type CulqiConstructor = new (publicKey: string, config: object) => CulqiInstance;

export class CulqiClosed extends Error {
  constructor() {
    super('closed');
  }
}

const loaded: Record<string, Promise<void>> = {};

function loadScript(src: string): Promise<void> {
  if (!src.startsWith('https://')) return Promise.reject(new Error('No se pudo cargar el formulario de Culqi.'));
  loaded[src] ??= new Promise<void>((resolve, reject) => {
    if ((window as unknown as { CulqiCheckout?: CulqiConstructor }).CulqiCheckout) { resolve(); return; }
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => { delete loaded[src]; reject(new Error('No se pudo cargar el formulario de Culqi. Revisa tu conexión e inténtalo de nuevo.')); };
    document.head.appendChild(script);
  });
  return loaded[src];
}

export async function openCulqi(config: CulqiCheckoutConfig): Promise<string> {
  await loadScript(config.script);
  const Checkout = (window as unknown as { CulqiCheckout?: CulqiConstructor }).CulqiCheckout;
  if (!Checkout) throw new Error('No se pudo abrir el formulario de Culqi. Inténtalo de nuevo.');

  return new Promise<string>((resolve, reject) => {
    let settled = false;
    const instance = new Checkout(config.public_key, {
      settings: { title: config.title, currency: config.currency, amount: config.amount },
      client: config.email ? { email: config.email } : undefined,
      options: { lang: 'es', installments: false, paymentMethods: { tarjeta: true, yape: false, billetera: false, bancaMovil: false, agente: false, cuotealo: false } },
      appearance: { hiddenCulqiLogo: false },
    });

    instance.culqi = () => {
      if (settled) return;
      if (instance.token?.id) {
        settled = true;
        instance.close?.();
        resolve(instance.token.id);
        return;
      }
      if (instance.error) {
        settled = true;
        instance.close?.();
        reject(new Error(instance.error.user_message || 'Culqi no aceptó la tarjeta. Prueba con otra.'));
      }
    };
    instance.closeCheckout = () => {
      if (settled) return;
      settled = true;
      reject(new CulqiClosed());
    };
    instance.open();
  });
}
