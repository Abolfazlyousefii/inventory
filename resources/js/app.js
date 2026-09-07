import './bootstrap';

import Alpine from 'alpinejs';
import jQuery from 'jquery';

import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';

window.$ = window.jQuery = jQuery;

DataTable.use(jQuery);

window.DataTable = DataTable;
window.Alpine = Alpine;

Alpine.start();