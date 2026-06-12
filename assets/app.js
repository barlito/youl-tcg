import './stimulus_bootstrap.js';
import './styles/app.css';
import { initFlowbite } from 'flowbite';

document.addEventListener('turbo:render', () => {
    initFlowbite();
});
document.addEventListener('turbo:frame-render', () => {
    initFlowbite();
});
