import { Router, Request, Response } from 'express';
import { ModuleManager, ModuleStateStore } from '../core/moduleManager.js';
import {
  BRANDING_DEFAULT_COLOR,
  BRANDING_OPTION_KEY,
  buildBrandingInlineCss,
  getBrandingSettings,
  getDashboardSubtitle,
  getDisplayBrandName,
  getMenuIcons,
  getMenuLabel,
  getMonogram,
  getSettingsSubtitle,
  sanitizeBrandingSettings,
} from '../services/brandingService.js';
import {
  formatCurrency,
  getActiveCurrency,
  getCurrencies,
  getRegionalSettings,
  getSupportedCountries,
  REGIONAL_OPTION_KEY,
  sanitizeRegionalSettings,
} from '../services/currencyService.js';
import { getCountryCallingCodes, getDefaultCountry, normalizeE164 } from '../services/phoneNumber.js';
import {
  getSmsSettings,
  getWcSettings,
  isSecretDefinedInEnv,
  sanitizeAndSaveSmsSettings,
  sanitizeAndSaveWcSettings,
} from '../services/settingsService.js';
import { smsManager, smsQueue } from '../services/smsService.js';
import { updateOption } from '../store/inMemoryStore.js';

export const CORE_VERSION = '0.1.0';

export function createAdminRouter(manager: ModuleManager, stateStore: ModuleStateStore): Router {
  const router = Router();

  router.get('/', (req: Request, res: Response) => {
    manager.refresh(stateStore);
    const modules = manager.all();
    const effectiveStates = stateStore.getEffectiveStates(modules);
    const savedStates = stateStore.getSavedStates();
    const branding = getBrandingSettings();
    const pageTitle = getDisplayBrandName(branding);
    const menuLabel = getMenuLabel(branding);

    const dependencyErrors: Record<string, string[]> = {};
    const declaredDependencies: Record<string, string[]> = {};
    const dependentModules: Record<string, string[]> = {};
    let hasInternalDepBlock = false;

    for (const [key, mod] of Object.entries(modules)) {
      const externalErrors = mod.getMissingDependencies();
      const internalErrors = manager.getDependencyErrors(key);
      if (internalErrors.length > 0) {
        hasInternalDepBlock = true;
      }
      dependencyErrors[key] = Array.from(new Set([...externalErrors, ...internalErrors]));

      const deps = manager.getDependencies(key);
      if (deps.length > 0) {
        declaredDependencies[key] = deps.map((d) => modules[d]?.name ?? d);
      }

      const revDeps = manager.getDependents(key);
      if (revDeps.length > 0) {
        dependentModules[key] = revDeps.map((d) => modules[d]?.name ?? d);
      }
    }

    res.render('admin/dashboard', {
      pageTitle,
      menuLabel,
      dashboardSubtitle: getDashboardSubtitle(branding),
      brandLogoUrl: branding.logo_url,
      brandMonogram: getMonogram(pageTitle),
      showVersionBadge: branding.show_version_badge,
      showSystemInfo: branding.show_system_info,
      adminFooterText: branding.admin_footer_text,
      inlineCss: buildBrandingInlineCss(branding),
      coreVersion: CORE_VERSION,
      nodeVersion: process.version,
      modules,
      activeCount: Object.keys(manager.getActiveModules()).length,
      totalCount: Object.keys(modules).length,
      activeKeys: new Set(Object.keys(manager.getActiveModules())),
      effectiveStates,
      savedStates,
      dependencyErrors,
      declaredDependencies,
      dependentModules,
      hasInternalDepBlock,
      modulesSaved: req.query.modules_saved === '1',
    });
  });

  router.post('/admin-post/neora_save_modules', (req: Request, res: Response) => {
    const modules = manager.all();
    const rawModules =
      req.body && typeof req.body.neora_modules === 'object' && req.body.neora_modules !== null
        ? (req.body.neora_modules as Record<string, unknown>)
        : {};

    const submitted: Record<string, boolean> = {};
    for (const key of Object.keys(modules)) {
      submitted[key] = Boolean(rawModules[key]);
    }
    stateStore.saveStates(submitted, modules);
    manager.refresh(stateStore);

    res.redirect('/?modules_saved=1');
  });

  router.get('/settings', (req: Request, res: Response) => {
    manager.refresh(stateStore);
    const tabs: Record<string, { title: string }> = {
      general: { title: 'General & Branding' },
      regional: { title: 'Currency & Regional' },
      sms: { title: 'SMS & Authentication' },
    };
    if (manager.isActive('neora/woocommerce')) {
      tabs.woocommerce = { title: 'WooCommerce' };
    }

    const rawTab = typeof req.query.tab === 'string' ? req.query.tab.toLowerCase().replace(/[^a-z0-9_-]/g, '') : '';
    const activeTab = Object.prototype.hasOwnProperty.call(tabs, rawTab) ? rawTab : 'general';

    const branding = getBrandingSettings();
    const brandName = getDisplayBrandName(branding);
    const regional = getRegionalSettings();
    const sms = getSmsSettings();
    const wc = getWcSettings();
    const activeCurrency = getActiveCurrency();

    res.render('admin/settings', {
      brandName,
      menuLabel: getMenuLabel(branding),
      settingsSubtitle: getSettingsSubtitle(branding),
      brandLogoUrl: branding.logo_url,
      brandMonogram: getMonogram(brandName),
      adminFooterText: branding.admin_footer_text,
      inlineCss: buildBrandingInlineCss(branding),
      tabs,
      activeTab,
      settingsUpdated: req.query['settings-updated'] === 'true',
      generalSettings: branding,
      menuIcons: getMenuIcons(),
      defaultColor: BRANDING_DEFAULT_COLOR,
      regionalSettings: regional,
      currencies: getCurrencies(),
      supportedCountries: getSupportedCountries(),
      activeCurrencyCode: activeCurrency.code,
      sampleFormattedAmount: formatCurrency('1500000'),
      smsSettings: sms,
      providerOptions: smsManager.getProviderOptions(false),
      intlProviderOptions: smsManager.getProviderOptions(true),
      envSecrets: {
        password: isSecretDefinedInEnv('NEORA_SMS_PASSWORD'),
        twilio_auth_token: isSecretDefinedInEnv('NEORA_TWILIO_AUTH_TOKEN'),
        webhook_secret: isSecretDefinedInEnv('NEORA_WEBHOOK_SECRET'),
      },
      wcSettings: wc,
      defaultCountry: getDefaultCountry(),
      callingCodes: getCountryCallingCodes(),
      queueStats: smsQueue.getStats(),
    });
  });

  router.post('/settings/save', (req: Request, res: Response) => {
    const tab = typeof req.body?.tab === 'string' ? req.body.tab : 'general';
    if (tab === 'general') {
      const sanitized = sanitizeBrandingSettings(req.body?.neora_core_general_settings ?? {});
      updateOption(BRANDING_OPTION_KEY, sanitized);
      return res.redirect('/settings?tab=general&settings-updated=true');
    }
    if (tab === 'regional') {
      const sanitized = sanitizeRegionalSettings(req.body?.neora_core_regional_settings ?? {});
      updateOption(REGIONAL_OPTION_KEY, sanitized);
      return res.redirect('/settings?tab=regional&settings-updated=true');
    }
    if (tab === 'sms') {
      sanitizeAndSaveSmsSettings(req.body?.neora_sms_settings ?? {});
      return res.redirect('/settings?tab=sms&settings-updated=true');
    }
    if (tab === 'woocommerce') {
      sanitizeAndSaveWcSettings(req.body?.neora_wc_settings ?? {});
      return res.redirect('/settings?tab=woocommerce&settings-updated=true');
    }
    return res.redirect('/settings');
  });

  const handleAdminAjax = async (req: Request, res: Response) => {
    const action = typeof req.body?.action === 'string' ? req.body.action : '';

    if (action === 'neora_test_sms') {
      const rawMobile = typeof req.body?.mobile === 'string' ? req.body.mobile.trim() : '';
      const rawCountry = typeof req.body?.country === 'string' ? req.body.country.trim().toUpperCase() : '';
      const country = /^[A-Z]{2}$/.test(rawCountry) ? rawCountry : null;
      const mobile = normalizeE164(rawMobile, country);

      if (!mobile) {
        return res.status(400).json({ success: false, data: { message: 'The provided mobile number is invalid.' } });
      }

      const result = await smsManager.sendPattern(mobile, ['12345'], null, country, null, {
        source: 'admin_test',
        is_otp: true,
      });

      if (!result.ok) {
        return res.status(400).json({ success: false, data: { message: result.error.message } });
      }

      const settings = getSmsSettings();
      const message = settings.sandbox_mode
        ? 'Sandbox test logged to console output successfully.'
        : 'Test SMS dispatched via active SMS gateway successfully.';

      return res.json({ success: true, data: { message } });
    }

    if (action === 'neora_sms_queue_action') {
      const queueAction = typeof req.body?.queue_action === 'string' ? req.body.queue_action : '';
      if (queueAction === 'process_due') {
        const count = await smsQueue.processDueJobs();
        return res.json({
          success: true,
          data: {
            message: `Processed ${count} due SMS queue job(s).`,
            stats: smsQueue.getStats(),
          },
        });
      }
      if (queueAction === 'clear_completed') {
        const count = smsQueue.clearCompleted(true);
        return res.json({
          success: true,
          data: {
            message: `Cleared ${count} finished SMS queue job(s).`,
            stats: smsQueue.getStats(),
          },
        });
      }
      return res.status(400).json({ success: false, data: { message: 'Invalid SMS queue action.' } });
    }

    return res.status(501).json({ success: false, error: 'Not yet migrated' });
  };

  router.post('/admin-ajax', handleAdminAjax);
  router.post('/wp-admin/admin-ajax.php', handleAdminAjax);

  return router;
}
