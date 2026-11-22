import crypto from 'node:crypto';
import { Router, Request, Response } from 'express';
import { ModuleManager } from '../core/moduleManager.js';
import { getSupportedCountries } from '../services/currencyService.js';
import { getCountryCallingCodes, getDefaultCountry, normalizeE164, toIranLocal } from '../services/phoneNumber.js';
import { otpManager } from '../services/smsService.js';
import {
  createUserForMobile,
  findUserByMobile,
  getTransient,
  setTransient,
} from '../store/inMemoryStore.js';

const IP_RATE_LIMIT_WINDOW = 600;
const SEND_RATE_LIMIT_MAX = 10;
const VERIFY_RATE_LIMIT_MAX = 15;

interface RateLimitRecord {
  count: number;
  windowStart: number;
}

function getClientIp(req: Request): string {
  const raw = req.ip || req.socket.remoteAddress || '';
  return raw.trim();
}

function getRateLimitKey(ip: string, scope: 'send' | 'verify'): string {
  const hash = crypto.createHash('md5').update(ip).digest('hex');
  return scope === 'send' ? `neora_otp_ip_${hash}` : `neora_otp_ip_verify_${hash}`;
}

function checkIpRateLimit(ip: string, scope: 'send' | 'verify', maxRequests: number): number | null {
  if (!ip) return null;
  const record = getTransient<RateLimitRecord>(getRateLimitKey(ip, scope));
  const now = Math.floor(Date.now() / 1000);
  if (record && now - record.windowStart < IP_RATE_LIMIT_WINDOW && record.count >= maxRequests) {
    return Math.max(1, IP_RATE_LIMIT_WINDOW - (now - record.windowStart));
  }
  return null;
}

function incrementIpRateLimit(ip: string, scope: 'send' | 'verify'): void {
  if (!ip) return;
  const key = getRateLimitKey(ip, scope);
  const record = getTransient<RateLimitRecord>(key);
  const now = Math.floor(Date.now() / 1000);
  if (!record || now - record.windowStart >= IP_RATE_LIMIT_WINDOW) {
    setTransient(key, { count: 1, windowStart: now }, IP_RATE_LIMIT_WINDOW);
    return;
  }
  const remainingTtl = Math.max(1, IP_RATE_LIMIT_WINDOW - (now - record.windowStart));
  setTransient(key, { count: record.count + 1, windowStart: record.windowStart }, remainingTtl);
}

function sanitizeCountryParam(raw: unknown): string | null {
  if (typeof raw !== 'string' && typeof raw !== 'number') return null;
  const country = String(raw).trim().toUpperCase();
  return /^[A-Z]{2}$/.test(country) ? country : null;
}

export function createRestRouter(manager: ModuleManager): Router {
  const router = Router();

  const requireAuthModule = (_req: Request, res: Response, next: () => void) => {
    if (!manager.isActive('neora/auth')) {
      return res.status(404).json({ code: 'rest_no_route', message: 'OTP Authentication module is inactive.' });
    }
    next();
  };

  router.get('/auth/countries', requireAuthModule, (_req: Request, res: Response) => {
    const names = getSupportedCountries();
    const callingCodes = getCountryCallingCodes();
    const countries = Object.entries(callingCodes).map(([iso, dial]) => ({
      code: iso,
      name: names[iso] ?? iso,
      dial_code: `+${dial}`,
    }));

    res.json({
      default_country: getDefaultCountry(),
      countries,
    });
  });

  router.post('/auth/send-otp', requireAuthModule, async (req: Request, res: Response) => {
    const rawMobile = typeof req.body?.mobile === 'string' ? req.body.mobile : '';
    const country = sanitizeCountryParam(req.body?.country);
    const mobile = normalizeE164(rawMobile, country);

    if (!mobile) {
      return res.status(400).json({
        code: 'neora_otp_invalid_mobile',
        message: 'The provided mobile number is invalid.',
      });
    }

    const ip = getClientIp(req);
    const retryAfter = checkIpRateLimit(ip, 'send', SEND_RATE_LIMIT_MAX);
    if (retryAfter !== null) {
      return res.status(429).json({
        code: 'neora_auth_rate_limited',
        message: 'Too many requests from your IP address. Please try again later.',
        data: { status: 429, retry_after: retryAfter },
      });
    }

    incrementIpRateLimit(ip, 'send');
    const result = await otpManager.requestOtp(mobile, 'login', country);
    if (!result.ok) {
      const code = result.error.code;
      if (code === 'neora_otp_cooldown' || code === 'neora_otp_invalid_mobile' || code === 'neora_sms_invalid_mobile') {
        const status = code === 'neora_otp_cooldown' ? 429 : 400;
        return res.status(status).json({
          code,
          message: result.error.message,
          data: { status, ...(result.error.data ?? {}) },
        });
      }
      const status = ['neora_sms_http_error', 'neora_sms_gateway_http_status', 'neora_sms_gateway_rejected'].includes(code)
        ? 502
        : 500;
      return res.status(status).json({
        code: 'neora_otp_delivery_failed',
        message: 'Unable to send verification code at the moment. Please try again later.',
      });
    }

    return res.json({
      success: true,
      message: 'Verification code sent successfully.',
      mobile_e164: mobile,
    });
  });

  router.post('/auth/verify-otp', requireAuthModule, (req: Request, res: Response) => {
    const rawMobile = typeof req.body?.mobile === 'string' ? req.body.mobile : '';
    const rawCode = typeof req.body?.code === 'string' ? req.body.code : '';
    const country = sanitizeCountryParam(req.body?.country);
    const mobile = normalizeE164(rawMobile, country);

    if (!mobile) {
      return res.status(400).json({
        code: 'neora_otp_invalid_mobile',
        message: 'The provided mobile number is invalid.',
      });
    }

    const ip = getClientIp(req);
    const retryAfter = checkIpRateLimit(ip, 'verify', VERIFY_RATE_LIMIT_MAX);
    if (retryAfter !== null) {
      return res.status(429).json({
        code: 'neora_auth_rate_limited',
        message: 'Too many requests from your IP address. Please try again later.',
        data: { status: 429, retry_after: retryAfter },
      });
    }

    const verifyResult = otpManager.verifyOtp(mobile, rawCode, 'login', country);
    if (!verifyResult.ok) {
      incrementIpRateLimit(ip, 'verify');
      const status = verifyResult.error.code === 'neora_otp_max_attempts' ? 429 : 400;
      return res.status(status).json({
        code: verifyResult.error.code,
        message: verifyResult.error.message,
        data: { status },
      });
    }

    const candidates = [mobile];
    const iranLocal = toIranLocal(mobile);
    if (iranLocal) candidates.push(iranLocal);

    let user = findUserByMobile(candidates);
    let isNewUser = false;
    if (!user) {
      const opaqueLogin = `neora_${crypto.randomBytes(6).toString('hex')}`;
      user = createUserForMobile(mobile, opaqueLogin);
      isNewUser = true;
    }

    return res.json({
      success: true,
      message: 'Logged in successfully.',
      user_id: user.id,
      mobile_e164: mobile,
      is_new_user: isNewUser,
      redirect_url: '/',
      rest_nonce: crypto.randomBytes(12).toString('hex'),
    });
  });

  // Unmigrated WordPress REST routes return 501 per migration spec
  router.use((_req: Request, res: Response) => {
    res.status(501).json({ error: 'Not yet migrated' });
  });

  return router;
}
