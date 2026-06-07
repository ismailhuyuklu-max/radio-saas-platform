import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    path: '/radio-platform',
    name: 'RadioPlatform',
    meta: {
      icon: 'lucide:radio',
      order: 2100,
      title: 'AdCast Pro',
      authCode: 'radio:platform:view',
    },
    redirect: '/radio-platform/operations',
    children: [
      {
        path: '/radio-platform/operations',
        name: 'RadioPlatformOperations',
        component: () => import('#/views/radio-platform/operations/index.vue'),
        meta: {
          perm: 'matrix:view',
          icon: 'lucide:activity',
          title: 'Yayın Merkezi',
          keepAlive: true,
          authCode: 'radio:matrix:view',
        },
      },
      {
        path: '/radio-platform/media-library',
        name: 'RadioPlatformMediaLibrary',
        component: () => import('#/views/radio-platform/media-library/index.vue'),
        meta: {
          perm: 'matrix:view',
          icon: 'lucide:music',
          title: 'Medya Kütüphanesi',
          authCode: 'radio:matrix:view',
        },
      },
      {
        path: '/radio-platform/dashboard',
        name: 'RadioPlatformDashboard',
        component: () => import('#/views/radio-platform/dashboard/index.vue'),
        meta: {
          perm: 'matrix:view',
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
          perm: 'matrix:view',
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
          perm: 'sponsors:view',
          icon: 'mdi:badge-account-horizontal-outline',
          title: 'Sponsorlar',
          keepAlive: true,
          authCode: 'radio:sponsors:view',
        },
      },
      {
        path: '/radio-platform/stations',
        name: 'RadioPlatformStations',
        component: () => import('#/views/radio-platform/stations/index.vue'),
        meta: {
          perm: 'stations:view',
          icon: 'mdi:radio-tower',
          title: 'İstasyonlar',
          keepAlive: true,
          authCode: 'radio:stations:view',
        },
      },
      {
        path: '/radio-platform/traffic-center',
        name: 'RadioPlatformTrafficCenter',
        component: () => import('#/views/radio-platform/traffic-center/index.vue'),
        meta: {
          perm: 'plans:write',
          icon: 'lucide:target',
          title: 'Yayın Trafik Merkezi',
          authCode: 'radio:planning:view',
        },
      },
      {
        path: '/radio-platform/timeline',
        name: 'RadioPlatformTimeline',
        component: () => import('#/views/radio-platform/timeline/index.vue'),
        meta: {
          perm: 'plans:view',
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
          perm: 'plans:view',
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
          perm: 'plans:view',
          icon: 'lucide:calendar-range',
          title: 'Planlama',
          keepAlive: true,
          authCode: 'radio:planning:view',
        },
      },
      {
        path: '/radio-platform/ad-traffic',
        name: 'RadioPlatformAdTraffic',
        component: () => import('#/views/radio-platform/ad-traffic/index.vue'),
        meta: {
          perm: 'ad:view',
          icon: 'lucide:trending-up',
          // Revenue should be fresh on each visit, so do not keep-alive this view.
          title: 'Reklam Trafik',
          authCode: 'radio:sponsors:view',
        },
      },
      {
        path: '/radio-platform/reports',
        name: 'RadioPlatformReports',
        component: () => import('#/views/radio-platform/reports/index.vue'),
        meta: {
          perm: 'reports:view',
          icon: 'lucide:file-down',
          title: 'Raporlar',
          authCode: 'radio:sponsors:view',
        },
      },
      {
        path: '/radio-platform/noc',
        name: 'RadioPlatformNoc',
        component: () => import('#/views/radio-platform/noc/index.vue'),
        meta: {
          perm: 'monitoring:view',
          icon: 'lucide:server',
          title: 'Sistem İzleme',
          authCode: 'radio:platform:view',
        },
      },
      {
        path: '/radio-platform/security',
        name: 'RadioPlatformSecurity',
        component: () => import('#/views/radio-platform/security/index.vue'),
        meta: {
          icon: 'lucide:key-round',
          title: 'Güvenlik',
          authCode: 'radio:platform:view',
        },
      },
      {
        path: '/radio-platform/access',
        name: 'RadioPlatformAccess',
        component: () => import('#/views/radio-platform/access/index.vue'),
        meta: {
          perm: 'users:manage',
          icon: 'lucide:shield-check',
          title: 'Yetki',
          keepAlive: true,
          authCode: 'radio:access:view',
        },
      },
    ],
  },
  // AdCast Radio Partner Portal — single-page tenant view a partner radio
  // operator sees after login. Lives outside /radio-platform/* so it can run
  // without the admin chrome (sidebar, NOC, etc.) and stays mobile-friendly.
  {
    path: '/portal',
    name: 'RadioPartnerPortal',
    component: () => import('#/views/portal/index.vue'),
    meta: {
      perm: 'portal:view',
      title: 'Radyo Paneli',
      authCode: 'radio:portal:view',
    },
  },
];

export default routes;
