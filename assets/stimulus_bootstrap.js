import { startStimulusApp } from '@symfony/stimulus-bundle';
import BorderPreviewController from './controllers/border_preview_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
app.register('border-preview', BorderPreviewController);
