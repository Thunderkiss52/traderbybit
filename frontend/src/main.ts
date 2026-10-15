import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { Dialog, Notify, Quasar } from 'quasar';
import 'quasar/src/css/index.sass';
import '@quasar/extras/material-icons/material-icons.css';
import './styles.scss';
import App from './App.vue';
import { router } from './router';
import { i18n } from './i18n';

const app = createApp(App);
app.config.errorHandler = (error) => console.error(error);
app.use(Quasar, { plugins: { Notify, Dialog } });
app.use(createPinia());
app.use(router);
app.use(i18n);
app.mount('#app');
