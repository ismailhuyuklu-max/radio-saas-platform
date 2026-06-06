import { createRouter, createWebHistory } from 'vue-router';

import { getStoredUser, isAuthenticated } from '#/api/modules/auth';
import { allows, isPartner } from '#/utils/rbac';

import routes from './routes';

/** Default landing depends on the user: partner radios → /portal, admins → cockpit. */
function homeFor(roles: string[] | undefined): string {
  return isPartner(roles) ? '/portal' : '/radio-platform/operations';
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', redirect: () => homeFor(getStoredUser()?.roles) },
    ...routes,
  ],
});

router.beforeEach((to) => {
  const authed = isAuthenticated();
  const roles = getStoredUser()?.roles;

  if (to.path === '/login') {
    return authed ? { path: homeFor(roles) } : true;
  }

  if (!authed) {
    return { path: '/login', query: { redirect: to.fullPath } };
  }

  // Partner-radio users are locked to the portal. They never see the admin
  // cockpit, NOC, planning, etc. — even if they typed the URL.
  if (isPartner(roles) && !to.path.startsWith('/portal')) {
    return { path: '/portal' };
  }

  // Role gate: if a route declares a required permission and the user's roles
  // don't grant it, bounce them to their natural landing page.
  const required = to.meta?.perm as string | undefined;
  if (required && !allows(roles, required)) {
    return { path: homeFor(roles) };
  }

  return true;
});

export default router;
