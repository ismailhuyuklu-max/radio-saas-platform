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

  if (response?.code === 0 && response.result?.token) {
    localStorage.setItem('accessToken', response.result.token);
    localStorage.setItem('token', response.result.token);
    localStorage.setItem('userInfo', JSON.stringify(response.result));
  }

  return response;
}

export async function getCurrentUser() {
  return requestClient.get<AuthApiResponse>('/auth/user');
}

export function clearAuthSession() {
  localStorage.removeItem('accessToken');
  localStorage.removeItem('token');
  localStorage.removeItem('userInfo');
}
