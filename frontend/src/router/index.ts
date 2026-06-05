import { createRouter, createWebHistory } from 'vue-router';

import { isAuthenticated } from '#/api/modules/auth';

import routes from './routes';

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', redirect: '/radio-platform/matrix' },
    ...routes,
  ],
});

router.beforeEach((to) => {
  const authed = isAuthenticated();

  if (to.path === '/login') {
    return authed ? { path: '/radio-platform/matrix' } : true;
  }

  if (!authed) {
    return { path: '/login', query: { redirect: to.fullPath } };
  }

  return true;
});

export default router;
