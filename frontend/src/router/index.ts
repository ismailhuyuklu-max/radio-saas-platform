import { createRouter, createWebHistory } from 'vue-router';

import { getStoredUser, isAuthenticated } from '#/api/modules/auth';
import { allows } from '#/utils/rbac';

import routes from './routes';

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', redirect: '/radio-platform/operations' },
    ...routes,
  ],
});

router.beforeEach((to) => {
  const authed = isAuthenticated();

  if (to.path === '/login') {
    return authed ? { path: '/radio-platform/operations' } : true;
  }

  if (!authed) {
    return { path: '/login', query: { redirect: to.fullPath } };
  }

  // Role gate: if a route declares a required permission and the user's roles
  // don't grant it, bounce them to the operations cockpit (everyone can see it).
  const required = to.meta?.perm as string | undefined;
  if (required && !allows(getStoredUser()?.roles, required)) {
    return { path: '/radio-platform/operations' };
  }

  return true;
});

export default router;
