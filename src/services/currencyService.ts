import { getOption } from '../store/inMemoryStore.js';

export const REGIONAL_OPTION_KEY = 'neora_core_regional_settings';
export const DEFAULT_CURRENCY = 'IRT';
export const DEFAULT_COUNTRY = 'IR';

export interface RegionalSettings {
  default_currency: string;
  default_country: string;
  numeral_style: 'auto' | 'persian' | 'latin';
  thousand_separator: 'comma' | 'persian_comma' | 'space' | 'dot' | 'none';
  decimal_separator: 'dot' | 'slash' | 'persian_decimal' | 'comma';
  symbol_position: 'default' | 'before' | 'before_space' | 'after' | 'after_space';
  sync_woocommerce_currency: boolean;
}

export interface CurrencyDefinition {
  code: string;
  name: string;
  symbol: string;
  decimals: number;
  symbol_position: 'before' | 'before_space' | 'after' | 'after_space';
  thousand_separator: string;
  decimal_separator: string;
}

const THOUSAND_SEPARATORS: Record<string, string> = {
  comma: ',',
  persian_comma: '٬',
  space: ' ',
  dot: '.',
  none: '',
};

const DECIMAL_SEPARATORS: Record<string, string> = {
  dot: '.',
  slash: '/',
  persian_decimal: '٫',
  comma: ',',
};

const SYMBOL_POSITIONS = ['default', 'before', 'before_space', 'after', 'after_space'] as const;

const DIGIT_TO_LATIN: Record<string, string> = {
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
  '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
  '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
};

const DIGIT_TO_PERSIAN: Record<string, string> = {
  '0': '۰', '1': '۱', '2': '۲', '3': '۳', '4': '۴',
  '5': '۵', '6': '۶', '7': '۷', '8': '۸', '9': '۹',
};

export function getCurrencies(): Record<string, CurrencyDefinition> {
  return {
    IRT: {
      code: 'IRT',
      name: 'Iranian Toman',
      symbol: 'tomans',
      decimals: 0,
      symbol_position: 'after_space',
      thousand_separator: ',',
      decimal_separator: '.',
    },
    IRR: {
      code: 'IRR',
      name: 'Iranian Rial',
      symbol: 'rials',
      decimals: 0,
      symbol_position: 'after_space',
      thousand_separator: ',',
      decimal_separator: '.',
    },
    USD: {
      code: 'USD',
      name: 'US Dollar',
      symbol: '$',
      decimals: 2,
      symbol_position: 'before',
      thousand_separator: ',',
      decimal_separator: '.',
    },
    EUR: {
      code: 'EUR',
      name: 'Euro',
      symbol: '€',
      decimals: 2,
      symbol_position: 'before',
      thousand_separator: ',',
      decimal_separator: '.',
    },
    AED: {
      code: 'AED',
      name: 'UAE Dirham',
      symbol: 'AED',
      decimals: 2,
      symbol_position: 'after_space',
      thousand_separator: ',',
      decimal_separator: '.',
    },
    TRY: {
      code: 'TRY',
      name: 'Turkish Lira',
      symbol: '₺',
      decimals: 2,
      symbol_position: 'before',
      thousand_separator: ',',
      decimal_separator: '.',
    },
  };
}

export function getSupportedCountries(): Record<string, string> {
  return {
    IR: 'Iran (IR)',
    AE: 'United Arab Emirates (AE)',
    TR: 'Turkey (TR)',
    DE: 'Germany (DE)',
    GB: 'United Kingdom (GB)',
    US: 'United States (US)',
    CA: 'Canada (CA)',
  };
}

export function getDefaultRegionalSettings(): RegionalSettings {
  return {
    default_currency: DEFAULT_CURRENCY,
    default_country: DEFAULT_COUNTRY,
    numeral_style: 'auto',
    thousand_separator: 'comma',
    decimal_separator: 'dot',
    symbol_position: 'default',
    sync_woocommerce_currency: true,
  };
}

export function getRegionalSettings(): RegionalSettings {
  const saved = getOption<Partial<RegionalSettings>>(REGIONAL_OPTION_KEY, {});
  return { ...getDefaultRegionalSettings(), ...(saved && typeof saved === 'object' ? saved : {}) };
}

export function sanitizeRegionalSettings(input: unknown): RegionalSettings {
  const defaults = getDefaultRegionalSettings();
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    return defaults;
  }
  const obj = input as Record<string, unknown>;
  const currencies = getCurrencies();

  let currency = typeof obj.default_currency === 'string' ? obj.default_currency.trim().toUpperCase() : DEFAULT_CURRENCY;
  if (!currencies[currency]) currency = DEFAULT_CURRENCY;

  let country = typeof obj.default_country === 'string' ? obj.default_country.trim().toUpperCase() : DEFAULT_COUNTRY;
  if (!/^[A-Z]{2}$/.test(country)) country = DEFAULT_COUNTRY;

  let numeral = typeof obj.numeral_style === 'string' ? obj.numeral_style.trim().toLowerCase() : 'auto';
  if (!['auto', 'latin', 'persian'].includes(numeral)) numeral = 'auto';

  let thousand = typeof obj.thousand_separator === 'string' ? obj.thousand_separator.trim().toLowerCase() : 'comma';
  if (!Object.prototype.hasOwnProperty.call(THOUSAND_SEPARATORS, thousand)) thousand = 'comma';

  let decimal = typeof obj.decimal_separator === 'string' ? obj.decimal_separator.trim().toLowerCase() : 'dot';
  if (!Object.prototype.hasOwnProperty.call(DECIMAL_SEPARATORS, decimal)) decimal = 'dot';

  let position = typeof obj.symbol_position === 'string' ? obj.symbol_position.trim().toLowerCase() : 'default';
  if (!(SYMBOL_POSITIONS as readonly string[]).includes(position)) position = 'default';

  return {
    default_currency: currency,
    default_country: country,
    numeral_style: numeral as RegionalSettings['numeral_style'],
    thousand_separator: thousand as RegionalSettings['thousand_separator'],
    decimal_separator: decimal as RegionalSettings['decimal_separator'],
    symbol_position: position as RegionalSettings['symbol_position'],
    sync_woocommerce_currency: Boolean(obj.sync_woocommerce_currency),
  };
}

export function convertDigits(value: string, target: 'latin' | 'persian' = 'latin'): string {
  const latin = value.replace(/[۰-۹٠-٩]/g, (ch) => DIGIT_TO_LATIN[ch] ?? ch);
  if (target === 'persian') {
    return latin.replace(/[0-9]/g, (ch) => DIGIT_TO_PERSIAN[ch] ?? ch);
  }
  return latin;
}

export function normalizeAmount(value: string | number, decimals = 0, allowNegative = false): string | null {
  let raw: string;
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return null;
    raw = decimals === 0 ? String(Math.trunc(value)) : value.toFixed(Math.min(10, decimals));
  } else if (typeof value === 'string') {
    raw = value;
  } else {
    return null;
  }

  let ascii = convertDigits(raw, 'latin').trim();
  if (ascii === '') return '';
  if (decimals > 0) {
    ascii = ascii.replace(/٫/g, '.');
  }

  let negative = false;
  if (ascii.startsWith('-')) {
    if (!allowNegative) return null;
    negative = true;
    ascii = ascii.slice(1);
  }

  if (decimals === 0) {
    if (!/^[0-9]+$/.test(ascii)) return null;
    const digits = ascii.replace(/^0+/, '') || '0';
    return negative && digits !== '0' ? `-${digits}` : digits;
  }

  if (!/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/.test(ascii)) return null;
  const [rawInt, frac] = ascii.split('.');
  const intPart = (rawInt || '').replace(/^0+/, '') || '0';
  if (frac === undefined) {
    return negative && intPart !== '0' ? `-${intPart}` : intPart;
  }
  if (frac.length > decimals) return null;
  const combined = `${intPart}.${frac}`;
  const isZero = intPart === '0' && /^0*$/.test(frac);
  return negative && !isZero ? `-${combined}` : combined;
}

export function getActiveCurrency(code?: string): CurrencyDefinition {
  const currencies = getCurrencies();
  const settings = getRegionalSettings();
  const target = code?.trim().toUpperCase() || settings.default_currency || DEFAULT_CURRENCY;
  return currencies[target] ?? currencies[DEFAULT_CURRENCY];
}

export function formatCurrency(
  amount: string | number,
  currencyCode?: string,
  args: {
    withUnit?: boolean;
    numerals?: 'persian' | 'latin' | null;
    trimZeros?: boolean;
  } = {}
): string {
  const currency = getActiveCurrency(currencyCode);
  const normalized = normalizeAmount(amount, currency.decimals, true);
  if (normalized === null || normalized === '') return '';

  const settings = getRegionalSettings();
  const negative = normalized.startsWith('-');
  const unsigned = negative ? normalized.slice(1) : normalized;
  const [rawInt, frac = ''] = unsigned.split('.');

  const thousandSep = THOUSAND_SEPARATORS[settings.thousand_separator] ?? currency.thousand_separator;
  let intPart = rawInt;
  if (thousandSep !== '') {
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
  }

  let numStr = intPart;
  if (currency.decimals > 0) {
    const padded = frac.padEnd(currency.decimals, '0');
    const finalFrac = args.trimZeros ? padded.replace(/0+$/, '') : padded;
    if (finalFrac !== '') {
      const decimalSep = DECIMAL_SEPARATORS[settings.decimal_separator] ?? currency.decimal_separator;
      numStr += `${decimalSep}${finalFrac}`;
    }
  }

  const usePersian =
    args.numerals === 'persian'
      ? true
      : args.numerals === 'latin'
      ? false
      : settings.numeral_style === 'persian';

  if (usePersian) {
    numStr = convertDigits(numStr, 'persian');
  }

  const sign = negative ? '-' : '';
  if (args.withUnit === false) {
    return `${sign}${numStr}`;
  }

  const position = settings.symbol_position !== 'default' ? settings.symbol_position : currency.symbol_position;
  switch (position) {
    case 'before':
      return `${sign}${currency.symbol}${numStr}`;
    case 'before_space':
      return `${sign}${currency.symbol} ${numStr}`;
    case 'after':
      return `${sign}${numStr}${currency.symbol}`;
    default:
      return `${sign}${numStr} ${currency.symbol}`;
  }
}
