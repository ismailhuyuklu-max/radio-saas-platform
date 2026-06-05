import { createApp } from 'vue';

import Antd from 'ant-design-vue';
import 'ant-design-vue/dist/reset.css';
import './design/main.less';

import App from './App.vue';
import router from './router';

createApp(App).use(router).use(Antd).mount('#app');
