import './bootstrap';
import './api-fetch';
import './confirm';
import './form-guard';
import './toast';
import './notification-bell';

import Alpine from 'alpinejs';
import { mobileSidebar } from './mobile-sidebar';

window.Alpine = Alpine;

Alpine.data('mobileSidebar', mobileSidebar);
Alpine.start();
