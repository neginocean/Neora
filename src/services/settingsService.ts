import crypto from 'node:crypto';
import { getOption, updateOption } from '../store/inMemoryStore.js';
import { convertDigits } from './currencyService.js';

export const SMS_OPTION_KEY = 'neora_sms_settings';
export const WC_OPTION_KEY = 'neora_wc_settings';

const SECRET_PREFIX = 'neora_enc_v1:';
const RUNTIME_SECRET_KEY = crypto
  .createHash('sha256')
  .update(`neora_secret_box|${process.env.NEORA_AUTH_SALT || 'neora-core-node-runtime'}`)
  .digest();

export function encryptSecret(plaintext: string): string {
  if (!plaintext) return '';
  if (plaintext.startsWith(SECRET_PREFIX)) return plaintext;
  const iv = crypto.randomBytes(16);
  const cipher = crypto.createCipheriv('aes-256-cbc', RUNTIME_SECRET_KEY, iv);
  const encrypted = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  const hmac = crypto.createHmac('sha256', RUNTIME_SECRET_KEY).update(Buffer.concat([iv, encrypted])).digest();
  return SECRET_PREFIX + Buffer.concat([iv, hmac, encrypted]).toString('base64');
}

export function decryptSecret(stored: string): string {
  if (!stored || !stored.startsWith(SECRET_PREFIX)) return stored;
  try {
    const decoded = Buffer.from(stored.slice(SECRET_PREFIX.length), 'base64');
    if (decoded.length <= 48) return '';
    const iv = decoded.subarray(0, 16);
    const hmac = decoded.subarray(16, 48);
    const ciphertext = decoded.subarray(48);
    const expectedHmac = crypto
      .createHmac('sha256', RUNTIME_SECRET_KEY)
      .update(Buffer.concat([iv, ciphertext]))
      .digest();
    if (!crypto.timingSafeEqual(expectedHmac, hmac)) return '';
    const decipher = crypto.createDecipheriv('aes-256-cbc', RUNTIME_SECRET_KEY, iv);
    return Buffer.concat([decipher.update(ciphertext), decipher.final()]).toString('utf8');
  } catch {
    return '';
  }
}

export interface SmsSettings {
  sandbox_mode: boolean;
  default_provider: string;
  international_provider: string;
  async_queue_enabled: boolean;
  username: string;
  password: string;
  sender_number: string;
  pattern_code: string;
  twilio_account_sid: string;
  twilio_auth_token: string;
  twilio_from: string;
  twilio_otp_template: string;
  webhook_url: string;
  webhook_secret: string;
  webhook_otp_template: string;
}

export function getDefaultSmsSettings(): SmsSettings {
  return {
    sandbox_mode: true,
    default_provider: 'melipayamak',
    international_provider: '',
    async_queue_enabled: true,
    username: '',
    password: '',
    sender_number: '',
    pattern_code: '',
    twilio_account_sid: '',
    twilio_auth_token: '',
    twilio_from: '',
    twilio_otp_template: '',
    webhook_url: '',
    webhook_secret: '',
    webhook_otp_template: '',
  };
}

export function isSecretDefinedInEnv(envName: string): boolean {
  const val = process.env[envName];
  return typeof val === 'string' && val.length > 0;
}

export function getSmsSettings(): SmsSettings {
  const saved = getOption<Partial<SmsSettings>>(SMS_OPTION_KEY, {});
  const merged: SmsSettings = {
    ...getDefaultSmsSettings(),
    ...(saved && typeof saved === 'object' ? saved : {}),
  };

  const secretEnvMap: Record<'password' | 'twilio_auth_token' | 'webhook_secret', string> = {
    password: 'NEORA_SMS_PASSWORD',
    twilio_auth_token: 'NEORA_TWILIO_AUTH_TOKEN',
    webhook_secret: 'NEORA_WEBHOOK_SECRET',
  };

  for (const [key, envName] of Object.entries(secretEnvMap) as Array<
    ['password' | 'twilio_auth_token' | 'webhook_secret', string]
  >) {
    if (isSecretDefinedInEnv(envName)) {
      merged[key] = process.env[envName] as string;
    } else if (merged[key]) {
      merged[key] = decryptSecret(merged[key]);
    }
  }

  return merged;
}

export function sanitizeAndSaveSmsSettings(input: unknown): SmsSettings {
  const existing = getSmsSettings();
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    return existing;
  }

  const obj = input as Record<string, unknown>;
  const rawPrimary = typeof obj.default_provider === 'string'
    ? obj.default_provider.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '')
    : '';
  const rawIntl = typeof obj.international_provider === 'string'
    ? obj.international_provider.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '')
    : '';

  const rawWebhook = typeof obj.webhook_url === 'string' ? obj.webhook_url.trim() : '';
  let webhookUrl = '';
  if (rawWebhook) {
    try {
      const parsed = new URL(rawWebhook);
      if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
        webhookUrl = parsed.toString();
      }
    } catch {
      webhookUrl = '';
    }
  }

  const rawPattern = typeof obj.pattern_code === 'string' ? obj.pattern_code.trim() : '';
  const patternCode = convertDigits(rawPattern, 'latin').replace(/[^0-9]/g, '');

  const sanitizeText = (v: unknown) =>
    typeof v === 'string' || typeof v === 'number' ? String(v).replace(/<[^>]*>/g, '').trim() : '';

  const next: SmsSettings = {
    sandbox_mode: Boolean(obj.sandbox_mode),
    async_queue_enabled: Boolean(obj.async_queue_enabled),
    default_provider: rawPrimary || 'melipayamak',
    international_provider: rawIntl,
    username: sanitizeText(obj.username),
    sender_number: sanitizeText(obj.sender_number),
    twilio_account_sid: sanitizeText(obj.twilio_account_sid),
    twilio_from: sanitizeText(obj.twilio_from),
    twilio_otp_template: sanitizeText(obj.twilio_otp_template),
    webhook_otp_template: sanitizeText(obj.webhook_otp_template),
    webhook_url: webhookUrl,
    pattern_code: patternCode,
    password: '',
    twilio_auth_token: '',
    webhook_secret: '',
  };

  const secretEnvMap: Record<'password' | 'twilio_auth_token' | 'webhook_secret', string> = {
    password: 'NEORA_SMS_PASSWORD',
    twilio_auth_token: 'NEORA_TWILIO_AUTH_TOKEN',
    webhook_secret: 'NEORA_WEBHOOK_SECRET',
  };

  const rawSaved = getOption<Partial<SmsSettings>>(SMS_OPTION_KEY, {});
  for (const [key, envName] of Object.entries(secretEnvMap) as Array<
    ['password' | 'twilio_auth_token' | 'webhook_secret', string]
  >) {
    const submitted = typeof obj[key] === 'string' ? (obj[key] as string).trim() : '';
    if (isSecretDefinedInEnv(envName)) {
      next[key] = typeof rawSaved[key] === 'string' ? (rawSaved[key] as string) : '';
    } else if (submitted) {
      next[key] = encryptSecret(sanitizeText(submitted));
    } else {
      next[key] = existing[key] ? encryptSecret(existing[key]) : '';
    }
  }

  updateOption(SMS_OPTION_KEY, next);
  return getSmsSettings();
}

export interface WcSettings {
  sync_mobile_checkout: boolean;
  enforce_iran_phone: boolean;
  enforce_international_phone: boolean;
  async_order_sms: boolean;
  allow_unverified_billing_login: boolean;
  order_created_pattern: string;
  order_completed_pattern: string;
}

export function getDefaultWcSettings(): WcSettings {
  return {
    sync_mobile_checkout: true,
    enforce_iran_phone: false,
    enforce_international_phone: false,
    async_order_sms: true,
    allow_unverified_billing_login: false,
    order_created_pattern: '',
    order_completed_pattern: '',
  };
}

export function getWcSettings(): WcSettings {
  const saved = getOption<Partial<WcSettings>>(WC_OPTION_KEY, {});
  return { ...getDefaultWcSettings(), ...(saved && typeof saved === 'object' ? saved : {}) };
}

export function sanitizeAndSaveWcSettings(input: unknown): WcSettings {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    return getDefaultWcSettings();
  }
  const obj = input as Record<string, unknown>;
  const sanitizeCode = (val: unknown) =>
    typeof val === 'string' || typeof val === 'number'
      ? convertDigits(String(val), 'latin').replace(/[^0-9]/g, '')
      : '';

  const next: WcSettings = {
    sync_mobile_checkout: Boolean(obj.sync_mobile_checkout),
    enforce_iran_phone: Boolean(obj.enforce_iran_phone),
    enforce_international_phone: Boolean(obj.enforce_international_phone),
    async_order_sms: Boolean(obj.async_order_sms),
    allow_unverified_billing_login: Boolean(obj.allow_unverified_billing_login),
    order_created_pattern: sanitizeCode(obj.order_created_pattern),
    order_completed_pattern: sanitizeCode(obj.order_completed_pattern),
  };
  updateOption(WC_OPTION_KEY, next);
  return next;
}
