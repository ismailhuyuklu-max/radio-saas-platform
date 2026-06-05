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

export interface AuthApiResponse {
  code: number;
  result: AuthUserResult | null;
  message: string;
}

export async function login(payload: AuthLoginPayload) {
  const normalizedPayload = {
    username: payload.username || payload.account || '',
    password: payload.password,
  };

  const response = await requestClient.post<AuthApiResponse>('/auth/login', normalizedPayload);

  if (response?.code === 0 && response.result) {
    // The session token lives ONLY in the HttpOnly cookie set by the backend
    // (not readable by JS -> XSS-resistant). Persist only non-secret profile
    // fields for the route guard and the header.
    const profile: StoredUser = {
      userId: response.result.userId,
      username: response.result.username,
      realName: response.result.realName,
      roles: response.result.roles,
    };
    localStorage.setItem('userInfo', JSON.stringify(profile));
    // Drop any legacy token persisted by older builds.
    localStorage.removeItem('accessToken');
    localStorage.removeItem('token');
  }

  return response;
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
