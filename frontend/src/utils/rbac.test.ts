import { describe, expect, it } from 'vitest';

import { allows, primaryRoleLabel } from './rbac';

describe('allows', () => {
  it('super can do everything', () => {
    expect(allows(['super'], 'users:manage')).toBe(true);
    expect(allows(['super'], 'matrix:view')).toBe(true);
    expect(allows(['super'], 'reports:view')).toBe(true);
  });
  it('viewer is read-only', () => {
    expect(allows(['viewer'], 'matrix:view')).toBe(true);
    expect(allows(['viewer'], 'plans:write')).toBe(false);
    expect(allows(['viewer'], 'reports:view')).toBe(false);
    expect(allows(['viewer'], 'monitoring:view')).toBe(false);
    expect(allows(['viewer'], 'users:manage')).toBe(false);
  });
  it('editor writes content but not infra/admin', () => {
    expect(allows(['editor'], 'plans:write')).toBe(true);
    expect(allows(['editor'], 'stations:write')).toBe(false);
    expect(allows(['editor'], 'reports:view')).toBe(false);
  });
  it('manager gets reports + monitoring but not user admin', () => {
    expect(allows(['radio_manager'], 'reports:view')).toBe(true);
    expect(allows(['radio_manager'], 'monitoring:view')).toBe(true);
    expect(allows(['radio_manager'], 'users:manage')).toBe(false);
  });
  it('fails closed for unknown permission and empty roles', () => {
    expect(allows(['super'], 'nope:nope')).toBe(false);
    expect(allows([], 'matrix:view')).toBe(false);
    expect(allows(undefined, 'matrix:view')).toBe(false);
  });
});

describe('primaryRoleLabel', () => {
  it('picks the highest-privilege role', () => {
    expect(primaryRoleLabel(['viewer', 'super'])).toBe('Süper Yönetici');
    expect(primaryRoleLabel(['editor', 'viewer'])).toBe('Editör');
    expect(primaryRoleLabel(['viewer'])).toBe('İzleyici');
    expect(primaryRoleLabel([])).toBe('Kullanıcı');
  });
});
