import type { RouteRecordRaw } from 'vue-router';

import radioRoutes from './modules/radio';

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('#/views/auth/login.vue'),
    meta: { public: true, title: 'Giriş' },
  },
  ...radioRoutes,
];

export default routes;
