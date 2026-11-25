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

export interface Culqi3dsConfig {
  script: string;
  public_key: string;
  amount: number;
  currency: string;
  email?: string | null;
  return_url: string;
  token: string;
}

export interface Culqi3dsResult {
  parameters: Record<string, string>;
  device: string | null;
}

interface Culqi3dsApi {
  publicKey: string;
  settings: object;
  options: object;
  generateDevice: () => Promise<string>;
  initAuthentication: (tokenId: string) => void;
  reset?: () => void;
}

export async function runCulqi3ds(config: Culqi3dsConfig): Promise<Culqi3dsResult> {
  if (!config.script.startsWith('https://')) throw new Error('No se pudo cargar la verificación de tu banco.');
  await new Promise<void>((resolve, reject) => {
    if ((window as unknown as { Culqi3DS?: Culqi3dsApi }).Culqi3DS) { resolve(); return; }
    const script = document.createElement('script');
    script.src = config.script;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('No se pudo cargar la verificación de tu banco. Revisa tu conexión e inténtalo de nuevo.'));
    document.head.appendChild(script);
  });
  const three = (window as unknown as { Culqi3DS?: Culqi3dsApi }).Culqi3DS;
  if (!three) throw new Error('No se pudo abrir la verificación de tu banco.');

  return new Promise<Culqi3dsResult>((resolve, reject) => {
    let device: string | null = null;
    const listener = (event: MessageEvent) => {
      if (event.origin !== window.location.origin || !event.data || typeof event.data !== 'object') return;
      const data = event.data as { parameters3DS?: Record<string, string>; error?: string };
      if (data.parameters3DS) {
        window.removeEventListener('message', listener);
        resolve({ parameters: data.parameters3DS, device });
      } else if (data.error) {
        window.removeEventListener('message', listener);
        reject(new Error('Tu banco no confirmó el pago. Inténtalo de nuevo o usa otra tarjeta.'));
      }
    };
    window.addEventListener('message', listener);
    three.publicKey = config.public_key;
    three.settings = { charge: { totalAmount: config.amount, returnUrl: config.return_url, currency: config.currency }, card: { email: config.email ?? '' } };
    three.options = { showModal: true, showLoading: true, showIcon: true, closeModalAction: () => { window.removeEventListener('message', listener); reject(new CulqiClosed()); } };
    three.generateDevice()
      .then((id) => { device = id; three.initAuthentication(config.token); })
      .catch(() => { window.removeEventListener('message', listener); reject(new Error('No se pudo iniciar la verificación de tu banco.')); });
  });
}
