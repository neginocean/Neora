/**
 * In-memory persistence store replacing WordPress options, transients, and user meta.
 * Data is ephemeral across container restarts per AI Studio Web runtime constraints.
 */

export interface StoredUser {
  id: number;
  userLogin: string;
  roles: string[];
  mobileE164: string;
  createdAt: number;
}

interface TransientEntry {
  value: unknown;
  expiresAt: number;
}

const options = new Map<string, unknown>();
const transients = new Map<string, TransientEntry>();
const usersById = new Map<number, StoredUser>();
let nextUserId = 1;

export function getOption<T>(key: string, defaultValue: T): T {
  if (!options.has(key)) {
    return defaultValue;
  }
  return options.get(key) as T;
}

export function updateOption(key: string, value: unknown): boolean {
  options.set(key, value);
  return true;
}

export function deleteOption(key: string): boolean {
  return options.delete(key);
}

export function getTransient<T = unknown>(key: string): T | null {
  const entry = transients.get(key);
  if (!entry) {
    return null;
  }
  if (entry.expiresAt > 0 && Date.now() > entry.expiresAt) {
    transients.delete(key);
    return null;
  }
  return entry.value as T;
}

export function setTransient(key: string, value: unknown, expirationSeconds: number): boolean {
  const expiresAt = expirationSeconds > 0 ? Date.now() + expirationSeconds * 1000 : 0;
  transients.set(key, { value, expiresAt });
  return true;
}

export function deleteTransient(key: string): boolean {
  return transients.delete(key);
}

export function findUserByMobile(candidates: string[]): StoredUser | null {
  for (const user of usersById.values()) {
    if (candidates.includes(user.mobileE164) || candidates.includes(user.userLogin)) {
      return user;
    }
  }
  return null;
}

export function createUserForMobile(mobileE164: string, opaqueLogin: string): StoredUser {
  const user: StoredUser = {
    id: nextUserId++,
    userLogin: opaqueLogin,
    roles: ['subscriber'],
    mobileE164,
    createdAt: Date.now(),
  };
  usersById.set(user.id, user);
  return user;
}

export function getUserById(id: number): StoredUser | null {
  return usersById.get(id) ?? null;
}
