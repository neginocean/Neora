import { convertDigits, getRegionalSettings } from './currencyService.js';

export interface CountryRule {
  callingCode: string;
  trunk: string;
  nationalPattern: RegExp;
}

const COUNTRY_RULES: Record<string, CountryRule> = {
  IR: { callingCode: '98', trunk: '0', nationalPattern: /^9[0-9]{9}$/ },
  AE: { callingCode: '971', trunk: '0', nationalPattern: /^5[0-9]{8}$/ },
  TR: { callingCode: '90', trunk: '0', nationalPattern: /^5[0-9]{9}$/ },
  DE: { callingCode: '49', trunk: '0', nationalPattern: /^[1-9][0-9]{6,11}$/ },
  GB: { callingCode: '44', trunk: '0', nationalPattern: /^7[0-9]{9}$/ },
  US: { callingCode: '1', trunk: '', nationalPattern: /^[2-9][0-9]{9}$/ },
  CA: { callingCode: '1', trunk: '', nationalPattern: /^[2-9][0-9]{9}$/ },
};

export function getCountryCallingCodes(): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [iso, rule] of Object.entries(COUNTRY_RULES)) {
    result[iso] = rule.callingCode;
  }
  return result;
}

export function getDefaultCountry(): string {
  const settings = getRegionalSettings();
  const country = (settings.default_country || 'IR').trim().toUpperCase();
  return /^[A-Z]{2}$/.test(country) ? country : 'IR';
}

function validateInternationalDigits(digits: string): string | null {
  if (!/^[1-9][0-9]{6,14}$/.test(digits)) {
    return null;
  }
  if (digits.startsWith('98')) {
    let national = digits.slice(2);
    if (national.startsWith('0')) {
      national = national.slice(1);
    }
    return /^9[0-9]{9}$/.test(national) ? `+98${national}` : null;
  }
  return `+${digits}`;
}

function validateNationalForRule(rawNational: string, rule: CountryRule): string | null {
  let national = rawNational;
  if (rule.trunk && national.startsWith(rule.trunk)) {
    national = national.slice(rule.trunk.length);
  }
  return rule.nationalPattern.test(national) ? national : null;
}

export function normalizeE164(raw: string, defaultCountry?: string | null): string | null {
  const ascii = convertDigits(raw.trim(), 'latin');
  const clean = ascii.replace(/[\s\-.()\u00A0\u200E\u200F\u202A-\u202E]+/g, '');
  if (!clean) return null;

  if (clean.startsWith('+')) {
    return validateInternationalDigits(clean.slice(1));
  }
  if (clean.startsWith('00')) {
    return validateInternationalDigits(clean.slice(2));
  }
  if (!/^[0-9]+$/.test(clean)) {
    return null;
  }

  const country = defaultCountry?.trim() ? defaultCountry.trim().toUpperCase() : getDefaultCountry();
  const rule = COUNTRY_RULES[country];
  if (!rule) return null;

  if (clean.startsWith(rule.callingCode)) {
    const candidate = validateNationalForRule(clean.slice(rule.callingCode.length), rule);
    if (candidate !== null) {
      return `+${rule.callingCode}${candidate}`;
    }
  }

  const candidate = validateNationalForRule(clean, rule);
  return candidate !== null ? `+${rule.callingCode}${candidate}` : null;
}

export function isValidE164(value: string): boolean {
  return /^\+[1-9][0-9]{6,14}$/.test(value);
}

export function isIranianE164(e164: string): boolean {
  return /^\+989[0-9]{9}$/.test(e164);
}

export function toIranLocal(raw: string): string | null {
  const e164 = normalizeE164(raw, 'IR');
  if (!e164 || !isIranianE164(e164)) return null;
  return `0${e164.slice(3)}`;
}

export function detectCountry(e164: string): string | null {
  if (!isValidE164(e164)) return null;
  const digits = e164.slice(1);
  const sorted = Object.entries(getCountryCallingCodes()).sort((a, b) => b[1].length - a[1].length);
  for (const [iso, cc] of sorted) {
    if (digits.startsWith(cc)) {
      return iso;
    }
  }
  return null;
}
