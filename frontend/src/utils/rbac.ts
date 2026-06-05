/**
 * Frontend mirror of the backend RBAC policy (RadioSaaS\Service\Rbac).
 *
 * The backend remains the source of truth and enforcement; this only drives
 * nav/route visibility so users never see controls that would 403. Keep the
 * permission map in sync with backend/src/Service/Rbac.php.
 */

export type Role = 'super' | 'radio_manager' | 'editor' | 'viewer';

const ANY: Role[] = ['super', 'radio_manager', 'editor', 'viewer'];
const CONTENT_WRITERS: Role[] = ['super', 'radio_manager', 'editor'];
const MANAGERS: Role[] = ['super', 'radio_manager'];
const ADMINS: Role[] = ['super'];

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
};

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
  };
  const order: Role[] = ['super', 'radio_manager', 'editor', 'viewer'];
  const found = order.find((r) => (userRoles ?? []).includes(r));
  return found ? labels[found] : 'Kullanıcı';
}
