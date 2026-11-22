import { getOption, updateOption } from '../store/inMemoryStore.js';

export interface ModuleMetadata {
  key: string;
  name: string;
  description: string;
  isCore: boolean;
  isDefaultEnabled: boolean;
  hasRewriteRules: boolean;
  dependencies: string[];
  getMissingDependencies: () => string[];
}

export interface BootPlan {
  bootOrder: string[];
  errors: Record<string, string[]>;
  bootable: Record<string, boolean>;
}

const OPTION_KEY = 'neora_active_modules';
const LEGACY_KEY_MAP: Record<string, string> = {
  auth: 'neora/auth',
  portfolio: 'neora/portfolio',
};

export function createDefaultModules(): ModuleMetadata[] {
  return [
    {
      key: 'neora/admin-menu',
      name: 'Admin Dashboard',
      description: 'Core admin menu, system status, and module toggle panel.',
      isCore: true,
      isDefaultEnabled: true,
      hasRewriteRules: false,
      dependencies: [],
      getMissingDependencies: () => [],
    },
    {
      key: 'neora/services',
      name: 'Services',
      description: 'Registers the Service post type, categories, pricing meta, and admin columns.',
      isCore: false,
      isDefaultEnabled: true,
      hasRewriteRules: true,
      dependencies: [],
      getMissingDependencies: () => [],
    },
    {
      key: 'neora/portfolio',
      name: 'Portfolio',
      description: 'Registers the Portfolio post type, categories, and client project metadata.',
      isCore: false,
      isDefaultEnabled: true,
      hasRewriteRules: true,
      dependencies: [],
      getMissingDependencies: () => [],
    },
    {
      key: 'neora/auth',
      name: 'OTP Authentication',
      description: 'Passwordless mobile OTP login and user provisioning via REST API.',
      isCore: false,
      isDefaultEnabled: true,
      hasRewriteRules: false,
      dependencies: [],
      getMissingDependencies: () => [],
    },
    {
      key: 'neora/settings-page',
      name: 'Core & SMS Settings',
      description: 'Configures Melipayamak SMS gateway credentials, sandbox mode, and core options.',
      isCore: true,
      isDefaultEnabled: true,
      hasRewriteRules: false,
      dependencies: [],
      getMissingDependencies: () => [],
    },
    {
      key: 'neora/polylang',
      name: 'Polylang Multilingual Bridge',
      description: 'Integrates Services, Portfolio, OTP Auth REST locale, and dynamic strings with Polylang.',
      isCore: false,
      isDefaultEnabled: true,
      hasRewriteRules: false,
      dependencies: [],
      getMissingDependencies: () => [
        'Requires the Polylang plugin to be installed and active.',
      ],
    },
    {
      key: 'neora/woocommerce',
      name: 'WooCommerce Bridge',
      description: 'Connects OTP mobile identity, checkout phone validation, and transactional order SMS with WooCommerce.',
      isCore: false,
      isDefaultEnabled: true,
      hasRewriteRules: false,
      dependencies: [],
      getMissingDependencies: () => [
        'Requires the WooCommerce plugin to be installed and active.',
      ],
    },
  ];
}

export class ModuleStateStore {
  getSavedStates(): Record<string, boolean> {
    const raw = getOption<unknown>(OPTION_KEY, {});
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
      return {};
    }
    const record = raw as Record<string, unknown>;
    const normalized: Record<string, boolean> = {};
    for (const [key, enabled] of Object.entries(record)) {
      if (!key) continue;
      const canonical = LEGACY_KEY_MAP[key] ?? key;
      if (canonical !== key && Object.prototype.hasOwnProperty.call(record, canonical)) {
        continue;
      }
      normalized[canonical] = Boolean(enabled);
    }
    return normalized;
  }

  getEffectiveStates(modules: Record<string, ModuleMetadata>): Record<string, boolean> {
    const saved = this.getSavedStates();
    const map: Record<string, boolean> = {};

    for (const [key, mod] of Object.entries(modules)) {
      if (mod.isCore) {
        map[key] = true;
      } else if (Object.prototype.hasOwnProperty.call(saved, key)) {
        map[key] = Boolean(saved[key]);
      } else {
        map[key] = mod.isDefaultEnabled;
      }
    }

    return map;
  }

  saveStates(submittedStates: Record<string, unknown>, modules: Record<string, ModuleMetadata>): boolean {
    const sanitized: Record<string, boolean> = {};
    for (const [key, mod] of Object.entries(modules)) {
      if (mod.isCore) {
        sanitized[key] = true;
      } else {
        sanitized[key] = Boolean(submittedStates[key]);
      }
    }
    return updateOption(OPTION_KEY, sanitized);
  }
}

export class ModuleManager {
  private modules: Record<string, ModuleMetadata> = {};
  private activeKeys = new Set<string>();
  private bootOrder: string[] = [];
  private dependencyErrors: Record<string, string[]> = {};

  constructor(initialModules: ModuleMetadata[] = createDefaultModules()) {
    for (const mod of initialModules) {
      this.modules[mod.key] = mod;
    }
  }

  refresh(stateStore: ModuleStateStore): void {
    const states = stateStore.getEffectiveStates(this.modules);
    const plan = ModuleManager.resolveModulesBootPlan(this.modules, states);
    this.bootOrder = plan.bootOrder;
    this.dependencyErrors = plan.errors;
    this.activeKeys = new Set(Object.keys(plan.bootable).filter((k) => plan.bootable[k]));
  }

  static resolveModulesBootPlan(
    modules: Record<string, ModuleMetadata>,
    effectiveStates: Record<string, boolean>
  ): BootPlan {
    const bootOrder: string[] = [];
    const errors: Record<string, string[]> = {};
    const bootable: Record<string, boolean> = {};
    const color: Record<string, number> = {};
    const canBoot: Record<string, boolean> = {};
    const cycleErrors: Record<string, string[]> = {};

    const visit = (key: string, path: string[]): boolean => {
      if (color[key] === 2) {
        return canBoot[key] ?? false;
      }
      if (color[key] === 1) {
        const cycleStart = path.indexOf(key);
        const cycleNodes = cycleStart !== -1 ? path.slice(cycleStart) : [...path];
        cycleNodes.push(key);
        const message = `Circular dependency detected: ${cycleNodes.join(' -> ')}.`;
        for (const member of new Set(cycleNodes)) {
          cycleErrors[member] = cycleErrors[member] ?? [];
          if (!cycleErrors[member].includes(message)) {
            cycleErrors[member].push(message);
          }
        }
        return false;
      }

      const mod = modules[key];
      if (!mod || !effectiveStates[key] || mod.getMissingDependencies().length > 0) {
        color[key] = 2;
        canBoot[key] = false;
        return false;
      }

      color[key] = 1;
      const nextPath = [...path, key];
      let moduleErrors: string[] = [];
      let allOk = true;

      for (const depKey of mod.dependencies) {
        if (depKey === key) {
          moduleErrors.push(`Module "${key}" cannot depend on itself.`);
          allOk = false;
          continue;
        }
        const depMod = modules[depKey];
        if (!depMod) {
          moduleErrors.push(`Requires unregistered module "${depKey}".`);
          allOk = false;
          continue;
        }
        if (!effectiveStates[depKey]) {
          moduleErrors.push(`Requires active module "${depMod.name}" (${depKey}).`);
          allOk = false;
          continue;
        }
        if (depMod.getMissingDependencies().length > 0) {
          moduleErrors.push(`Required module "${depMod.name}" (${depKey}) has unmet external dependencies.`);
          allOk = false;
          continue;
        }
        if (!visit(depKey, nextPath)) {
          allOk = false;
          if (!cycleErrors[key]?.length) {
            moduleErrors.push(`Required module "${depMod.name}" (${depKey}) could not be activated.`);
          }
        }
      }

      color[key] = 2;
      if (cycleErrors[key]?.length) {
        allOk = false;
        moduleErrors = Array.from(new Set([...cycleErrors[key], ...moduleErrors]));
      }

      if (allOk) {
        canBoot[key] = true;
        bootable[key] = true;
        bootOrder.push(key);
        return true;
      }

      canBoot[key] = false;
      if (moduleErrors.length > 0) {
        errors[key] = Array.from(new Set(moduleErrors));
      }
      return false;
    };

    for (const key of Object.keys(modules)) {
      if (effectiveStates[key]) {
        visit(key, []);
      }
    }

    return { bootOrder, errors, bootable };
  }

  all(): Record<string, ModuleMetadata> {
    return this.modules;
  }

  getActiveModules(): Record<string, ModuleMetadata> {
    const active: Record<string, ModuleMetadata> = {};
    for (const key of this.activeKeys) {
      if (this.modules[key]) {
        active[key] = this.modules[key];
      }
    }
    return active;
  }

  isActive(key: string): boolean {
    return this.activeKeys.has(key);
  }

  getDependencies(key: string): string[] {
    return this.modules[key]?.dependencies ?? [];
  }

  getDependents(key: string): string[] {
    return Object.values(this.modules)
      .filter((m) => m.key !== key && m.dependencies.includes(key))
      .map((m) => m.key);
  }

  getDependencyErrors(key: string): string[] {
    return this.dependencyErrors[key] ?? [];
  }

  getBootOrder(): string[] {
    return this.bootOrder;
  }
}
