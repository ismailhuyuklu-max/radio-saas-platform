import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    path: '/radio-platform',
    name: 'RadioPlatform',
    meta: {
      icon: 'lucide:radio',
      order: 2100,
      title: 'Aircast Pro',
      authCode: 'radio:platform:view',
    },
    redirect: '/radio-platform/operations',
    children: [
      {
        path: '/radio-platform/operations',
        name: 'RadioPlatformOperations',
        component: () => import('#/views/radio-platform/operations/index.vue'),
        meta: {
          icon: 'lucide:activity',
          title: 'Yayın Merkezi',
          keepAlive: true,
          authCode: 'radio:matrix:view',
        },
      },
      {
        path: '/radio-platform/dashboard',
        name: 'RadioPlatformDashboard',
        component: () => import('#/views/radio-platform/dashboard/index.vue'),
        meta: {
          icon: 'lucide:layout-dashboard',
          title: 'Genel Bakış',
          keepAlive: true,
          authCode: 'radio:matrix:view',
        },
      },
      {
        path: '/radio-platform/matrix',
        name: 'RadioPlatformMatrix',
        component: () => import('#/views/radio-platform/matrix/index.vue'),
        meta: {
          icon: 'lucide:grid-2x2',
          title: 'Bölgesel Durum',
          keepAlive: true,
          authCode: 'radio:matrix:view',
        },
      },
      {
        path: '/radio-platform/sponsors',
        name: 'RadioPlatformSponsors',
        component: () => import('#/views/radio-platform/sponsors/index.vue'),
        meta: {
          icon: 'mdi:badge-account-horizontal-outline',
          title: 'Sponsors',
          keepAlive: true,
          authCode: 'radio:sponsors:view',
        },
      },
      {
        path: '/radio-platform/stations',
        name: 'RadioPlatformStations',
        component: () => import('#/views/radio-platform/stations/index.vue'),
        meta: {
          icon: 'mdi:radio-tower',
          title: 'Stations',
          keepAlive: true,
          authCode: 'radio:stations:view',
        },
      },
      {
        path: '/radio-platform/timeline',
        name: 'RadioPlatformTimeline',
        component: () => import('#/views/radio-platform/timeline/index.vue'),
        meta: {
          icon: 'lucide:gantt-chart',
          title: 'Zaman Çizelgesi',
          keepAlive: true,
          authCode: 'radio:planning:view',
        },
      },
      {
        path: '/radio-platform/kanban',
        name: 'RadioPlatformKanban',
        component: () => import('#/views/radio-platform/kanban/index.vue'),
        meta: {
          icon: 'lucide:kanban',
          title: 'Haber Akışı',
          keepAlive: true,
          authCode: 'radio:planning:view',
        },
      },
      {
        path: '/radio-platform/planning',
        name: 'RadioPlatformPlanning',
        component: () => import('#/views/radio-platform/planning/index.vue'),
        meta: {
          icon: 'lucide:calendar-range',
          title: 'Planlama',
          keepAlive: true,
          authCode: 'radio:planning:view',
        },
      },
      {
        path: '/radio-platform/access',
        name: 'RadioPlatformAccess',
        component: () => import('#/views/radio-platform/access/index.vue'),
        meta: {
          icon: 'lucide:shield-check',
          title: 'Yetki',
          keepAlive: true,
          authCode: 'radio:access:view',
        },
      },
    ],
  },
];

export default routes;
