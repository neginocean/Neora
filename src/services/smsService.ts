import crypto from 'node:crypto';
import { deleteTransient, getOption, getTransient, setTransient, updateOption } from '../store/inMemoryStore.js';
import { convertDigits } from './currencyService.js';
import { detectCountry, isIranianE164, isValidE164, normalizeE164, toIranLocal } from './phoneNumber.js';
import { getSmsSettings, SmsSettings } from './settingsService.js';

export interface SmsError {
  code: string;
  message: string;
  data?: Record<string, unknown>;
}

export type SmsResult = { ok: true } | { ok: false; error: SmsError };

export interface SmsProvider {
  getKey(): string;
  getName(): string;
  supportsInternational(): boolean;
  supportsPattern(): boolean;
  isConfigured(settings: SmsSettings): boolean;
  sendPattern(
    recipientE164: string,
    args: string[],
    patternId: string | null,
    settings: SmsSettings,
    context?: Record<string, unknown>
  ): Promise<SmsResult>;
  sendText(
    recipientE164: string,
    message: string,
    from: string | null,
    settings: SmsSettings,
    context?: Record<string, unknown>
  ): Promise<SmsResult>;
}

const MELIPAYAMAK_ERROR_CODES: Record<string, string> = {
  '-110': 'Melipayamak requires an API Key instead of your panel password.',
  '-109': 'Server IP address is not allowlisted in Melipayamak panel settings.',
  '-108': 'Server IP temporarily blocked by Melipayamak due to failed attempts.',
  '-5': 'Pattern variables do not match the approved Melipayamak pattern.',
  '-4': 'Pattern code (BodyId) is invalid or not yet approved in Melipayamak.',
  '-1': 'Melipayamak web service pattern access is disabled for this account.',
  '0': 'Invalid Melipayamak username, password, or API key.',
  '2': 'Insufficient SMS credit in your Melipayamak account.',
  '12': 'Melipayamak user account verification documents are incomplete.',
  '19': 'Hourly SMS sending limit reached in Melipayamak.',
  '35': 'The recipient mobile number is in the telecom blacklist (Code 35).',
};

export class MelipayamakProvider implements SmsProvider {
  getKey() {
    return 'melipayamak';
  }
  getName() {
    return 'Melipayamak (Iran)';
  }
  supportsInternational() {
    return false;
  }
  supportsPattern() {
    return true;
  }
  isConfigured(settings: SmsSettings) {
    return Boolean(settings.username && settings.password);
  }

  async sendPattern(
    recipientE164: string,
    args: string[],
    patternId: string | null,
    settings: SmsSettings
  ): Promise<SmsResult> {
    const local = toIranLocal(recipientE164);
    if (!local) {
      return {
        ok: false,
        error: {
          code: 'neora_sms_international_unsupported',
          message: 'Melipayamak only supports Iranian mobile numbers.',
        },
      };
    }
    const bodyId = (patternId ?? settings.pattern_code ?? '').trim();
    if (!settings.username || !settings.password || !bodyId) {
      return {
        ok: false,
        error: {
          code: 'neora_sms_missing_config',
          message: 'SMS gateway credentials or pattern code are not configured.',
        },
      };
    }

    try {
      const response = await fetch('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8', Accept: 'application/json' },
        body: JSON.stringify({
          username: settings.username,
          password: settings.password,
          text: args.map(String).join(';'),
          to: local,
          bodyId: Number(bodyId),
        }),
        signal: AbortSignal.timeout(10000),
      });
      if (response.status !== 200) {
        return {
          ok: false,
          error: { code: 'neora_sms_gateway_http_status', message: 'Unexpected response from the SMS gateway.' },
        };
      }
      const decoded = (await response.json()) as { RetStatus?: number; Value?: unknown };
      const retStatus = Number(decoded.RetStatus ?? 0);
      const value = String(decoded.Value ?? '').trim();
      if (retStatus === 1 && /^\d+$/.test(value) && value.length > 10) {
        return { ok: true };
      }
      return {
        ok: false,
        error: {
          code: 'neora_sms_gateway_rejected',
          message: MELIPAYAMAK_ERROR_CODES[value] ?? `SMS gateway rejected the request (Code: ${value || 'unknown'}).`,
        },
      };
    } catch (err) {
      return {
        ok: false,
        error: { code: 'neora_sms_http_error', message: err instanceof Error ? err.message : 'Network error' },
      };
    }
  }

  async sendText(
    recipientE164: string,
    message: string,
    from: string | null,
    settings: SmsSettings
  ): Promise<SmsResult> {
    const local = toIranLocal(recipientE164);
    if (!local) {
      return {
        ok: false,
        error: {
          code: 'neora_sms_international_unsupported',
          message: 'Melipayamak only supports Iranian mobile numbers.',
        },
      };
    }
    const sender = (from ?? settings.sender_number ?? '').trim();
    if (!settings.username || !settings.password || !sender) {
      return {
        ok: false,
        error: {
          code: 'neora_sms_missing_config',
          message: 'SMS gateway credentials or sender number are not configured.',
        },
      };
    }

    try {
      const response = await fetch('https://rest.payamak-panel.com/api/SendSMS/SendSMS', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8', Accept: 'application/json' },
        body: JSON.stringify({
          username: settings.username,
          password: settings.password,
          to: local,
          from: sender,
          text: message.trim(),
          isFlash: false,
        }),
        signal: AbortSignal.timeout(10000),
      });
      if (response.status !== 200) {
        return {
          ok: false,
          error: { code: 'neora_sms_gateway_http_status', message: 'Unexpected response from the SMS gateway.' },
        };
      }
      const decoded = (await response.json()) as { RetStatus?: number; Value?: unknown };
      const retStatus = Number(decoded.RetStatus ?? 0);
      const value = String(decoded.Value ?? '').trim();
      if (retStatus === 1 && /^\d+$/.test(value) && value.length > 10) {
        return { ok: true };
      }
      return {
        ok: false,
        error: {
          code: 'neora_sms_gateway_rejected',
          message: MELIPAYAMAK_ERROR_CODES[value] ?? `SMS gateway rejected the request (Code: ${value || 'unknown'}).`,
        },
      };
    } catch (err) {
      return {
        ok: false,
        error: { code: 'neora_sms_http_error', message: err instanceof Error ? err.message : 'Network error' },
      };
    }
  }
}

export class TwilioProvider implements SmsProvider {
  getKey() {
    return 'twilio';
  }
  getName() {
    return 'Twilio (International)';
  }
  supportsInternational() {
    return true;
  }
  supportsPattern() {
    return false;
  }
  isConfigured(settings: SmsSettings) {
    return Boolean(settings.twilio_account_sid && settings.twilio_auth_token && settings.twilio_from);
  }

  async sendPattern(
    recipientE164: string,
    args: string[],
    _patternId: string | null,
    settings: SmsSettings
  ): Promise<SmsResult> {
    if (!args.length) {
      return { ok: false, error: { code: 'neora_sms_empty_args', message: 'Pattern arguments cannot be empty.' } };
    }
    const template = (settings.twilio_otp_template || '').trim();
    const message = template
      ? args.reduce((acc, v, i) => acc.replaceAll(`{${i}}`, String(v)), template.replaceAll('{code}', String(args[0] ?? '')))
      : `Your verification code is: ${args[0] ?? ''}`;
    return this.sendText(recipientE164, message, null, settings);
  }

  async sendText(
    recipientE164: string,
    message: string,
    from: string | null,
    settings: SmsSettings
  ): Promise<SmsResult> {
    if (!isValidE164(recipientE164)) {
      return { ok: false, error: { code: 'neora_sms_invalid_mobile', message: 'The provided mobile number is invalid.' } };
    }
    const sid = (settings.twilio_account_sid || '').trim();
    const token = (settings.twilio_auth_token || '').trim();
    const sender = (from || settings.twilio_from || '').trim();
    if (!sid || !token || !sender) {
      return {
        ok: false,
        error: { code: 'neora_sms_missing_config', message: 'Twilio SMS credentials or sender number are not configured.' },
      };
    }
    const params = new URLSearchParams({ To: recipientE164, Body: message.trim() });
    if (sender.startsWith('MG') && sender.length === 34) {
      params.set('MessagingServiceSid', sender);
    } else {
      params.set('From', sender);
    }
    const authHeader = 'Basic ' + Buffer.from(`${sid}:${token}`).toString('base64');
    try {
      const response = await fetch(`https://api.twilio.com/2010-04-01/Accounts/${encodeURIComponent(sid)}/Messages.json`, {
        method: 'POST',
        headers: { Authorization: authHeader, Accept: 'application/json' },
        body: params,
        signal: AbortSignal.timeout(12000),
      });
      const decoded = (await response.json().catch(() => ({}))) as { sid?: string; message?: string };
      if (response.status >= 200 && response.status < 300 && decoded.sid) {
        return { ok: true };
      }
      return {
        ok: false,
        error: {
          code: 'neora_sms_gateway_rejected',
          message: decoded.message || 'Unexpected response from the Twilio SMS gateway.',
        },
      };
    } catch (err) {
      return {
        ok: false,
        error: { code: 'neora_sms_http_error', message: err instanceof Error ? err.message : 'Network error' },
      };
    }
  }
}

export class WebhookProvider implements SmsProvider {
  getKey() {
    return 'webhook';
  }
  getName() {
    return 'Custom Webhook (HTTP JSON)';
  }
  supportsInternational() {
    return true;
  }
  supportsPattern() {
    return true;
  }
  isConfigured(settings: SmsSettings) {
    return Boolean(settings.webhook_url);
  }

  async sendPattern(
    recipientE164: string,
    args: string[],
    patternId: string | null,
    settings: SmsSettings,
    context: Record<string, unknown> = {}
  ): Promise<SmsResult> {
    const template = (settings.webhook_otp_template || '').trim();
    const message = template
      ? args.reduce((acc, v, i) => acc.replaceAll(`{${i}}`, String(v)), template.replaceAll('{code}', String(args[0] ?? '')))
      : `Your verification code is: ${args[0] ?? ''}`;

    return this.postPayload(
      recipientE164,
      {
        type: 'pattern',
        to: recipientE164,
        country: detectCountry(recipientE164) ?? '',
        pattern_id: patternId ?? settings.pattern_code ?? '',
        args: args.map(String),
        message,
        context,
      },
      settings
    );
  }

  async sendText(
    recipientE164: string,
    message: string,
    from: string | null,
    settings: SmsSettings,
    context: Record<string, unknown> = {}
  ): Promise<SmsResult> {
    return this.postPayload(
      recipientE164,
      {
        type: 'text',
        to: recipientE164,
        country: detectCountry(recipientE164) ?? '',
        from: from ?? settings.sender_number ?? '',
        message: message.trim(),
        context,
      },
      settings
    );
  }

  private async postPayload(
    recipientE164: string,
    payload: Record<string, unknown>,
    settings: SmsSettings
  ): Promise<SmsResult> {
    if (!isValidE164(recipientE164)) {
      return { ok: false, error: { code: 'neora_sms_invalid_mobile', message: 'The provided mobile number is invalid.' } };
    }
    const url = (settings.webhook_url || '').trim();
    if (!url) {
      return {
        ok: false,
        error: { code: 'neora_sms_missing_config', message: 'Webhook SMS endpoint URL is not configured or invalid.' },
      };
    }
    const headers: Record<string, string> = {
      'Content-Type': 'application/json; charset=utf-8',
      Accept: 'application/json',
    };
    if (settings.webhook_secret?.trim()) {
      headers.Authorization = `Bearer ${settings.webhook_secret.trim()}`;
    }
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers,
        body: JSON.stringify(payload),
        signal: AbortSignal.timeout(12000),
      });
      if (response.status >= 200 && response.status < 300) {
        return { ok: true };
      }
      return {
        ok: false,
        error: {
          code: 'neora_sms_gateway_rejected',
          message: `Webhook SMS endpoint returned HTTP status ${response.status}.`,
        },
      };
    } catch (err) {
      return {
        ok: false,
        error: { code: 'neora_sms_http_error', message: err instanceof Error ? err.message : 'Network error' },
      };
    }
  }
}

export class SmsManager {
  private providers = new Map<string, SmsProvider>();

  constructor() {
    for (const p of [new MelipayamakProvider(), new TwilioProvider(), new WebhookProvider()]) {
      this.providers.set(p.getKey(), p);
    }
  }

  getProviderOptions(internationalOnly = false): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, provider] of this.providers.entries()) {
      if (internationalOnly && !provider.supportsInternational()) continue;
      out[key] = provider.getName();
    }
    return out;
  }

  resolveProvider(
    recipientE164: string,
    preferredProvider?: string | null,
    settings: SmsSettings = getSmsSettings()
  ): { ok: true; provider: SmsProvider } | { ok: false; error: SmsError } {
    const isIran = isIranianE164(recipientE164);
    const preferred = (preferredProvider ?? '').trim().toLowerCase();
    const primaryKey = (settings.default_provider || 'melipayamak').trim().toLowerCase();
    const intlKey = (settings.international_provider || '').trim().toLowerCase();

    if (preferred) {
      const provider = this.providers.get(preferred);
      if (!provider) {
        return { ok: false, error: { code: 'neora_sms_unknown_provider', message: 'The requested SMS provider is not registered.' } };
      }
      if (!isIran && !provider.supportsInternational()) {
        return {
          ok: false,
          error: {
            code: 'neora_sms_international_unsupported',
            message: 'The selected SMS provider does not support international recipients.',
          },
        };
      }
      return { ok: true, provider };
    }

    const primary = this.providers.get(primaryKey || 'melipayamak');
    if (isIran) {
      if (primary) return { ok: true, provider: primary };
    } else {
      if (primary && primary.supportsInternational()) return { ok: true, provider: primary };
      if (intlKey && intlKey !== 'none') {
        const intl = this.providers.get(intlKey);
        if (intl && intl.supportsInternational()) return { ok: true, provider: intl };
      }
      return {
        ok: false,
        error: {
          code: 'neora_sms_international_unsupported',
          message: 'No international SMS provider is configured for non-Iranian recipients.',
        },
      };
    }

    return { ok: false, error: { code: 'neora_sms_unknown_provider', message: 'No valid SMS provider is available.' } };
  }

  async sendPattern(
    recipient: string,
    args: string[],
    patternId: string | null = null,
    country: string | null = null,
    providerKey: string | null = null,
    context: Record<string, unknown> = {}
  ): Promise<SmsResult> {
    const e164 = normalizeE164(recipient, country);
    if (!e164) {
      return { ok: false, error: { code: 'neora_sms_invalid_mobile', message: 'The provided mobile number is invalid.' } };
    }
    if (!args.length) {
      return { ok: false, error: { code: 'neora_sms_empty_args', message: 'Pattern arguments cannot be empty.' } };
    }
    const settings = getSmsSettings();
    if (settings.sandbox_mode) {
      const resolved = this.resolveProvider(e164, providerKey, settings);
      const label = resolved.ok ? resolved.provider.getKey() : 'sandbox';
      console.log(
        `[Neora Core SMS Sandbox] Provider: ${label} | Type: pattern | To: ${e164} | Meta: ${patternId || 'N/A'} | Payload: ${args.join(' | ')}`
      );
      return { ok: true };
    }
    const resolved = this.resolveProvider(e164, providerKey, settings);
    if (!resolved.ok) return resolved;
    return resolved.provider.sendPattern(e164, args, patternId, settings, context);
  }

  async sendText(
    recipient: string,
    message: string,
    from: string | null = null,
    country: string | null = null,
    providerKey: string | null = null,
    context: Record<string, unknown> = {}
  ): Promise<SmsResult> {
    const e164 = normalizeE164(recipient, country);
    if (!e164) {
      return { ok: false, error: { code: 'neora_sms_invalid_mobile', message: 'The provided mobile number is invalid.' } };
    }
    const bodyText = message.trim();
    if (!bodyText) {
      return { ok: false, error: { code: 'neora_sms_empty_message', message: 'SMS message cannot be empty.' } };
    }
    const settings = getSmsSettings();
    if (settings.sandbox_mode) {
      const resolved = this.resolveProvider(e164, providerKey, settings);
      const label = resolved.ok ? resolved.provider.getKey() : 'sandbox';
      console.log(
        `[Neora Core SMS Sandbox] Provider: ${label} | Type: text | To: ${e164} | Meta: ${from || 'N/A'} | Payload: ${bodyText}`
      );
      return { ok: true };
    }
    const resolved = this.resolveProvider(e164, providerKey, settings);
    if (!resolved.ok) return resolved;
    return resolved.provider.sendText(e164, bodyText, from, settings, context);
  }
}

export interface SmsQueueJob {
  id: string;
  type: 'pattern' | 'text';
  recipient: string;
  args: string[];
  pattern_id: string | null;
  message: string;
  from: string | null;
  provider: string | null;
  context: Record<string, unknown>;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  attempts: number;
  max_attempts: number;
  next_attempt_at: number;
  last_error: string;
  created_at: number;
  updated_at: number;
}

const QUEUE_OPTION_KEY = 'neora_sms_queue';
const PERMANENT_ERRORS = new Set([
  'neora_sms_invalid_mobile',
  'neora_sms_empty_args',
  'neora_sms_empty_message',
  'neora_sms_missing_config',
  'neora_sms_unknown_provider',
  'neora_sms_international_unsupported',
]);

export class SmsQueue {
  constructor(private smsManager: SmsManager) {}

  private loadJobs(): Record<string, SmsQueueJob> {
    return getOption<Record<string, SmsQueueJob>>(QUEUE_OPTION_KEY, {});
  }

  private saveJobs(jobs: Record<string, SmsQueueJob>): void {
    updateOption(QUEUE_OPTION_KEY, jobs);
  }

  getStats(): { pending: number; processing: number; completed: number; failed: number; total: number } {
    const stats = { pending: 0, processing: 0, completed: 0, failed: 0, total: 0 };
    for (const job of Object.values(this.loadJobs())) {
      if (job.status in stats) {
        stats[job.status]++;
      }
      stats.total++;
    }
    return stats;
  }

  async processJob(jobId: string): Promise<boolean> {
    const jobs = this.loadJobs();
    const job = jobs[jobId];
    if (!job || job.status === 'completed' || job.status === 'failed') {
      return false;
    }
    job.status = 'processing';
    job.attempts += 1;
    const now = Math.floor(Date.now() / 1000);

    const result =
      job.type === 'text'
        ? await this.smsManager.sendText(job.recipient, job.message, job.from, null, job.provider, job.context)
        : await this.smsManager.sendPattern(job.recipient, job.args, job.pattern_id, null, job.provider, job.context);

    job.updated_at = now;
    if (result.ok) {
      job.status = 'completed';
      job.last_error = '';
      this.saveJobs(jobs);
      return true;
    }

    job.last_error = result.error.message;
    if (!PERMANENT_ERRORS.has(result.error.code) && job.attempts < job.max_attempts) {
      const delay = Math.max(10, 60 * 2 ** Math.max(0, job.attempts - 1));
      job.status = 'pending';
      job.next_attempt_at = now + delay;
      this.saveJobs(jobs);
      return false;
    }

    job.status = 'failed';
    this.saveJobs(jobs);
    return false;
  }

  async processDueJobs(limit = 10): Promise<number> {
    const now = Math.floor(Date.now() / 1000);
    const jobs = this.loadJobs();
    let processed = 0;
    for (const [id, job] of Object.entries(jobs)) {
      if (processed >= limit) break;
      if (job.status !== 'pending' || job.next_attempt_at > now) continue;
      await this.processJob(id);
      processed++;
    }
    return processed;
  }

  clearCompleted(includeFailed = false): number {
    const jobs = this.loadJobs();
    let removed = 0;
    for (const [id, job] of Object.entries(jobs)) {
      if (job.status === 'completed' || (includeFailed && job.status === 'failed')) {
        delete jobs[id];
        removed++;
      }
    }
    if (removed > 0) {
      this.saveJobs(jobs);
    }
    return removed;
  }
}

interface OtpRecord {
  codeHash: string;
  attempts: number;
  requestedAt: number;
}

export class OtpManager {
  private static EXPIRATION_SECONDS = 300;
  private static COOLDOWN_SECONDS = 120;
  private static MAX_ATTEMPTS = 5;

  constructor(private smsManager: SmsManager) {}

  private normalizePurpose(purpose: string): string {
    const clean = purpose.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
    return clean || 'login';
  }

  private getTransientKey(mobile: string, purpose: string): string {
    return 'neora_otp_' + crypto.createHash('md5').update(`${purpose}|${mobile}`).digest('hex');
  }

  private hashPayload(mobile: string, code: string, purpose: string): string {
    return crypto.createHash('sha256').update(`${purpose}|${mobile}|${code}`).digest('hex');
  }

  async requestOtp(recipient: string, purpose = 'login', country: string | null = null): Promise<SmsResult> {
    const mobile = normalizeE164(recipient, country);
    if (!mobile) {
      return { ok: false, error: { code: 'neora_otp_invalid_mobile', message: 'The provided mobile number is invalid.' } };
    }

    const normPurpose = this.normalizePurpose(purpose);
    const key = this.getTransientKey(mobile, normPurpose);
    const existing = getTransient<OtpRecord>(key);
    const now = Math.floor(Date.now() / 1000);

    if (existing && now - existing.requestedAt < OtpManager.COOLDOWN_SECONDS) {
      const remaining = OtpManager.COOLDOWN_SECONDS - (now - existing.requestedAt);
      return {
        ok: false,
        error: {
          code: 'neora_otp_cooldown',
          message: `Please wait ${remaining} seconds before requesting a new code.`,
          data: { retry_after: remaining },
        },
      };
    }

    const otpCode = String(crypto.randomInt(10000, 100000));
    const sendResult = await this.smsManager.sendPattern(mobile, [otpCode], null, country, null, {
      purpose: normPurpose,
      is_otp: true,
    });
    if (!sendResult.ok) {
      return sendResult;
    }

    setTransient(
      key,
      {
        codeHash: this.hashPayload(mobile, otpCode, normPurpose),
        attempts: 0,
        requestedAt: now,
      } satisfies OtpRecord,
      OtpManager.EXPIRATION_SECONDS
    );

    return { ok: true };
  }

  verifyOtp(recipient: string, code: string, purpose = 'login', country: string | null = null): SmsResult {
    const mobile = normalizeE164(recipient, country);
    if (!mobile) {
      return { ok: false, error: { code: 'neora_otp_invalid_mobile', message: 'The provided mobile number is invalid.' } };
    }

    const normalizedCode = convertDigits(code.trim(), 'latin');
    if (!/^[0-9]{5}$/.test(normalizedCode)) {
      return {
        ok: false,
        error: { code: 'neora_otp_invalid_format', message: 'The verification code must be a 5-digit number.' },
      };
    }

    const normPurpose = this.normalizePurpose(purpose);
    const key = this.getTransientKey(mobile, normPurpose);
    const record = getTransient<OtpRecord>(key);
    if (!record || !record.codeHash) {
      return {
        ok: false,
        error: {
          code: 'neora_otp_expired',
          message: 'Verification code has expired or does not exist. Please request a new code.',
        },
      };
    }

    const expected = this.hashPayload(mobile, normalizedCode, normPurpose);
    const matches = crypto.timingSafeEqual(Buffer.from(record.codeHash, 'utf8'), Buffer.from(expected, 'utf8'));
    if (!matches) {
      const attempts = record.attempts + 1;
      if (attempts >= OtpManager.MAX_ATTEMPTS) {
        deleteTransient(key);
        return {
          ok: false,
          error: {
            code: 'neora_otp_max_attempts',
            message: 'Too many failed attempts. Please request a new verification code.',
          },
        };
      }
      const remainingTtl = Math.max(1, OtpManager.EXPIRATION_SECONDS - (Math.floor(Date.now() / 1000) - record.requestedAt));
      setTransient(key, { ...record, attempts }, remainingTtl);
      return {
        ok: false,
        error: { code: 'neora_otp_mismatch', message: 'The verification code entered is incorrect.' },
      };
    }

    deleteTransient(key);
    return { ok: true };
  }
}

export const smsManager = new SmsManager();
export const smsQueue = new SmsQueue(smsManager);
export const otpManager = new OtpManager(smsManager);
