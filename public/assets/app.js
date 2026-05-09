/**
 * App entry point. Loads the right module based on body[data-editing].
 *
 * Modules are imported eagerly here for simplicity; if file size becomes
 * a concern this is the spot to switch to dynamic imports.
 */

import * as view     from './modules/view.js';
import * as edit     from './modules/edit.js';
import * as compact  from './modules/compact.js';
import * as launcher from './modules/launcher.js';

const editing = document.body.dataset.editing === '1';
if (editing) {
  edit.init();
} else {
  view.init();
  compact.init();
  launcher.init();
}
