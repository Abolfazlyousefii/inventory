import './bootstrap';

import Alpine from 'alpinejs';
import jQuery from 'jquery';

import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';

// Layout plugins (including Select2) are already attached to this instance.
// Replacing it with the bundled copy silently removes those plugins.
const appJQuery = window.jQuery || jQuery;
window.$ = window.jQuery = appJQuery;

DataTable.use(appJQuery);

window.DataTable = DataTable;
window.Alpine = Alpine;

Alpine.start();
