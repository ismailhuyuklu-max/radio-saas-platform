import { requestClient } from '#/api/request';

export interface AuthLoginPayload {
  username?: string;
  account?: string;
  password: string;
}

export interface AuthUserResult {
  token: string;
  userId: string;
  username: string;
  realName: string;
  roles: string[];
}

export interface StoredUser {
  userId: string;
  username: string;
  realName: string;
  roles: string[];
}

export interface MfaChallenge {
  mfa_required: true;
  mfa_token: string;
  username: string;
}

export interface AuthApiResponse {
  code: number;
  result: AuthUserResult | MfaChallenge | null;
  message: string;
}

function isMfaChallenge(result: unknown): result is MfaChallenge {
  return typeof result === 'object' && result !== null && (result as MfaChallenge).mfa_required === true;
}

function persistProfile(result: AuthUserResult): void {
  // The session token lives ONLY in the HttpOnly cookie set by the backend
  // (not readable by JS -> XSS-resistant). Persist only non-secret profile
  // fields for the route guard and the header.
  const profile: StoredUser = {
    userId: result.userId,
    username: result.username,
    realName: result.realName,
    roles: result.roles,
  };
  localStorage.setItem('userInfo', JSON.stringify(profile));
  localStorage.removeItem('accessToken');
  localStorage.removeItem('token');
}

export async function login(payload: AuthLoginPayload) {
  const normalizedPayload = {
    username: payload.username || payload.account || '',
    password: payload.password,
  };

  const response = await requestClient.post<AuthApiResponse>('/auth/login', normalizedPayload);

  // Only a full auth result (with userId) establishes a session; an MFA
  // challenge defers it to the verify step.
  if (response?.code === 0 && response.result && !isMfaChallenge(response.result)) {
    persistProfile(response.result);
  }

  return response;
}

/** Second login step — exchange an MFA challenge token + code for a session. */
export async function verifyMfa(mfaToken: string, code: string) {
  const response = await requestClient.post<AuthApiResponse>('/auth/mfa/verify', {
    mfa_token: mfaToken,
    code,
  });
  if (response?.code === 0 && response.result && !isMfaChallenge(response.result)) {
    persistProfile(response.result);
  }
  return response;
}

export interface MfaStatus {
  enabled: boolean;
  pending: boolean;
}

export interface MfaSetupResult {
  secret: string;
  otpauth_uri: string;
}

export async function getMfaStatus() {
  return requestClient.get<{ code: number; result: MfaStatus }>('/auth/mfa/status');
}

export async function setupMfa() {
  return requestClient.post<{ code: number; result: MfaSetupResult }>('/auth/mfa/setup', {});
}

export async function enableMfa(code: string) {
  return requestClient.post<{ code: number; result: { enabled: boolean; recovery_codes: string[] } }>(
    '/auth/mfa/enable',
    { code },
  );
}

export async function disableMfa(code: string) {
  return requestClient.post<{ code: number; result: { enabled: boolean } }>('/auth/mfa/disable', {
    code,
  });
}

export async function logout() {
  try {
    await requestClient.post('/auth/logout', {});
  } catch (error) {
    console.warn('Logout request failed; clearing local session anyway.', error);
  }
  clearAuthSession();
}

export async function getCurrentUser() {
  return requestClient.get<AuthApiResponse>('/auth/user');
}

export function clearAuthSession() {
  localStorage.removeItem('accessToken');
  localStorage.removeItem('token');
  localStorage.removeItem('userInfo');
}

export function getStoredUser(): StoredUser | null {
  try {
    const raw = localStorage.getItem('userInfo');
    if (!raw) {
      return null;
    }
    const parsed = JSON.parse(raw) as Partial<StoredUser>;
    return parsed && parsed.username ? (parsed as StoredUser) : null;
  } catch {
    return null;
  }
}

export function isAuthenticated(): boolean {
  return getStoredUser() !== null;
}
