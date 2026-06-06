/**
 * Frontend mirror of the backend RBAC policy (RadioSaaS\Service\Rbac).
 *
 * The backend remains the source of truth and enforcement; this only drives
 * nav/route visibility so users never see controls that would 403. Keep the
 * permission map in sync with backend/src/Service/Rbac.php.
 */

export type Role = 'super' | 'radio_manager' | 'editor' | 'viewer' | 'station_user';

// Note: ANY deliberately excludes station_user — partners must not see
// admin-wide read endpoints. Tenant routes live under PARTNERS.
const ANY: Role[] = ['super', 'radio_manager', 'editor', 'viewer'];
const CONTENT_WRITERS: Role[] = ['super', 'radio_manager', 'editor'];
const MANAGERS: Role[] = ['super', 'radio_manager'];
const ADMINS: Role[] = ['super'];
const PARTNERS: Role[] = ['station_user', 'super', 'radio_manager'];

export const PERMISSIONS: Record<string, Role[]> = {
  'matrix:view': ANY,
  'plans:view': ANY,
  'stations:view': ANY,
  'sponsors:view': ANY,
  'ad:view': ANY,
  'plans:write': CONTENT_WRITERS,
  'media:write': CONTENT_WRITERS,
  'matrix:refresh': MANAGERS,
  'stations:write': MANAGERS,
  'stations:delete': MANAGERS,
  'sponsors:write': MANAGERS,
  'ad:write': MANAGERS,
  'audit:view': MANAGERS,
  'monitoring:view': MANAGERS,
  'reports:view': MANAGERS,
  'users:manage': ADMINS,
  // Partner Portal — station_user (own tenant) + admin override.
  'portal:view': PARTNERS,
  'portal:download': PARTNERS,
  'partner:provision': MANAGERS,
};

export const isPartner = (userRoles: string[] | undefined): boolean =>
  (userRoles ?? []).includes('station_user');

/** True when any of the user's roles grants the permission. */
export function allows(userRoles: string[] | undefined, permission: string): boolean {
  const allowed = PERMISSIONS[permission];
  if (!allowed) {
    // Unknown permission → fail closed (matches backend behaviour).
    return false;
  }
  const roles = userRoles ?? [];
  return allowed.some((role) => roles.includes(role));
}

/** Highest-privilege label for display. */
export function primaryRoleLabel(userRoles: string[] | undefined): string {
  const labels: Record<Role, string> = {
    super: 'Süper Yönetici',
    radio_manager: 'Radyo Yöneticisi',
    editor: 'Editör',
    viewer: 'İzleyici',
    station_user: 'Partner Radyo',
  };
  const order: Role[] = ['super', 'radio_manager', 'editor', 'viewer', 'station_user'];
  const found = order.find((r) => (userRoles ?? []).includes(r));
  return found ? labels[found] : 'Kullanıcı';
}
