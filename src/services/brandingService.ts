import { getOption } from '../store/inMemoryStore.js';

export interface BrandingSettings {
  brand_name: string;
  menu_label: string;
  menu_icon: string;
  logo_url: string;
  brand_color: string;
  dashboard_subtitle: string;
  settings_subtitle: string;
  admin_footer_text: string;
  show_version_badge: boolean;
  show_system_info: boolean;
}

export const BRANDING_OPTION_KEY = 'neora_core_general_settings';
export const BRANDING_DEFAULT_COLOR = '#4f46e5';
export const BRANDING_DEFAULT_ICON = 'dashicons-layout';

export function getDefaultBrandingSettings(): BrandingSettings {
  return {
    brand_name: '',
    menu_label: '',
    menu_icon: BRANDING_DEFAULT_ICON,
    logo_url: '',
    brand_color: BRANDING_DEFAULT_COLOR,
    dashboard_subtitle: '',
    settings_subtitle: '',
    admin_footer_text: '',
    show_version_badge: true,
    show_system_info: true,
  };
}

export function getMenuIcons(): Record<string, string> {
  return {
    'dashicons-layout': 'Layout (Default)',
    'dashicons-admin-generic': 'Gear / Platform',
    'dashicons-shield': 'Shield',
    'dashicons-store': 'Store / Commerce',
    'dashicons-portfolio': 'Portfolio / Briefcase',
    'dashicons-building': 'Agency / Building',
    'dashicons-star-filled': 'Star',
    'dashicons-megaphone': 'Megaphone',
  };
}

export function sanitizeHexColor(color: unknown): string {
  if (typeof color !== 'string') return '';
  const trimmed = color.trim();
  const match = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.exec(trimmed);
  if (!match) return '';
  let hex = match[1].toLowerCase();
  if (hex.length === 3) {
    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
  }
  return `#${hex}`;
}

export function sanitizeMenuIcon(icon: unknown): string {
  if (typeof icon !== 'string') return BRANDING_DEFAULT_ICON;
  const trimmed = icon.trim();
  return /^dashicons-[a-z0-9-]+$/.test(trimmed) ? trimmed : BRANDING_DEFAULT_ICON;
}

export function sanitizeLogoUrl(url: unknown): string {
  if (typeof url !== 'string') return '';
  const raw = url.trim();
  if (!raw || !/^https?:\/\//i.test(raw)) return '';
  try {
    const parsed = new URL(raw);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.toString() : '';
  } catch {
    return '';
  }
}

function sanitizeText(value: unknown): string {
  if (typeof value !== 'string' && typeof value !== 'number') return '';
  return String(value).replace(/<[^>]*>/g, '').trim();
}

export function getBrandingSettings(): BrandingSettings {
  const saved = getOption<Partial<BrandingSettings>>(BRANDING_OPTION_KEY, {});
  const merged = { ...getDefaultBrandingSettings(), ...(saved && typeof saved === 'object' ? saved : {}) };
  return {
    brand_name: sanitizeText(merged.brand_name),
    menu_label: sanitizeText(merged.menu_label),
    menu_icon: sanitizeMenuIcon(merged.menu_icon),
    logo_url: sanitizeLogoUrl(merged.logo_url),
    brand_color: sanitizeHexColor(merged.brand_color) || BRANDING_DEFAULT_COLOR,
    dashboard_subtitle: sanitizeText(merged.dashboard_subtitle),
    settings_subtitle: sanitizeText(merged.settings_subtitle),
    admin_footer_text: sanitizeText(merged.admin_footer_text),
    show_version_badge: Boolean(merged.show_version_badge),
    show_system_info: Boolean(merged.show_system_info),
  };
}

export function sanitizeBrandingSettings(input: unknown): BrandingSettings {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    return getDefaultBrandingSettings();
  }
  const obj = input as Record<string, unknown>;
  return {
    brand_name: sanitizeText(obj.brand_name ?? ''),
    menu_label: sanitizeText(obj.menu_label ?? ''),
    menu_icon: sanitizeMenuIcon(obj.menu_icon ?? BRANDING_DEFAULT_ICON),
    logo_url: sanitizeLogoUrl(obj.logo_url ?? ''),
    brand_color: sanitizeHexColor(obj.brand_color ?? '') || BRANDING_DEFAULT_COLOR,
    dashboard_subtitle: sanitizeText(obj.dashboard_subtitle ?? ''),
    settings_subtitle: sanitizeText(obj.settings_subtitle ?? ''),
    admin_footer_text: sanitizeText(obj.admin_footer_text ?? ''),
    show_version_badge: Boolean(obj.show_version_badge),
    show_system_info: Boolean(obj.show_system_info),
  };
}

export function getDisplayBrandName(settings = getBrandingSettings()): string {
  return settings.brand_name || 'Neora Core';
}

export function getMenuLabel(settings = getBrandingSettings()): string {
  return settings.menu_label || 'Neora Core';
}

export function getDashboardSubtitle(settings = getBrandingSettings()): string {
  return settings.dashboard_subtitle || 'Modular WordPress platform foundation & module manager.';
}

export function getSettingsSubtitle(settings = getBrandingSettings()): string {
  return settings.settings_subtitle || 'Manage branding, SMS gateway, and active module configurations.';
}

export function getMonogram(brandName?: string): string {
  const name = (brandName ?? getDisplayBrandName()).trim();
  if (!name) return 'N';
  return Array.from(name)[0].toUpperCase();
}

function hexToRgb(hex: string): [number, number, number] {
  const clean = (sanitizeHexColor(hex) || BRANDING_DEFAULT_COLOR).slice(1);
  return [
    parseInt(clean.slice(0, 2), 16),
    parseInt(clean.slice(2, 4), 16),
    parseInt(clean.slice(4, 6), 16),
  ];
}

function mixHex(baseHex: string, targetHex: string, weight: number): string {
  const base = hexToRgb(baseHex);
  const target = hexToRgb(targetHex);
  const r = Math.max(0, Math.min(255, Math.round(base[0] * (1 - weight) + target[0] * weight)));
  const g = Math.max(0, Math.min(255, Math.round(base[1] * (1 - weight) + target[1] * weight)));
  const b = Math.max(0, Math.min(255, Math.round(base[2] * (1 - weight) + target[2] * weight)));
  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
}

function getContrastColor(hex: string): string {
  const [r, g, b] = hexToRgb(hex);
  const channels = [r, g, b].map((ch) => {
    const c = ch / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  const luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
  return luminance > 0.179 ? '#0f172a' : '#ffffff';
}

export function buildBrandingInlineCss(settings = getBrandingSettings()): string {
  const primary = sanitizeHexColor(settings.brand_color) || BRANDING_DEFAULT_COLOR;
  const vars: Record<string, string> = {
    '--neora-primary': primary,
    '--neora-primary-hover': mixHex(primary, '#000000', 0.14),
    '--neora-primary-soft': mixHex(primary, '#ffffff', 0.9),
    '--neora-primary-border': mixHex(primary, '#ffffff', 0.72),
    '--neora-primary-contrast': getContrastColor(primary),
  };
  const declarations = Object.entries(vars).map(([k, v]) => `${k}:${v}`);
  return `.neora-admin-wrap{${declarations.join(';')};}`;
}
