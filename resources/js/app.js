import './bootstrap';
import Alpine from 'alpinejs';
import ApexCharts from 'apexcharts';

import { renderDonuts, renderSparklines } from './components/charts';

window.Alpine = Alpine;
window.ApexCharts = ApexCharts;

Alpine.start();

document.addEventListener('DOMContentLoaded', () => {
    renderDonuts();
    renderSparklines();
});
