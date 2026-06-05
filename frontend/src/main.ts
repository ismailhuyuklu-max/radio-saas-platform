import { createApp } from 'vue';

import dayjs from 'dayjs';
import 'dayjs/locale/tr';
import 'ant-design-vue/dist/reset.css';
import './design/main.less';

// Turkish dates across the app (dayjs powers ant-design-vue date pickers too).
dayjs.locale('tr');

import { onUnauthorized } from '@vben/request';

import { clearAuthSession } from '#/api/modules/auth';

import App from './App.vue';
import router from './router';

// When any API call returns 401 (e.g. the HttpOnly session cookie expired),
// drop the stale local profile and send the user back to the login screen.
onUnauthorized(() => {
  clearAuthSession();
  const current = router.currentRoute.value;
  if (current.path !== '/login') {
    void router.replace({ path: '/login', query: { redirect: current.fullPath } });
  }
});

// NOTE: ant-design-vue is NOT registered globally (app.use(Antd)) on purpose —
// that pulls the entire library (~475KB gzip). Each screen imports only the
// components it uses, so Vite tree-shakes the bundle.
createApp(App).use(router).mount('#app');
