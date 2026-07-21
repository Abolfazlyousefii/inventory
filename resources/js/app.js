import './bootstrap';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.css';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

const initializeProductExports = () => {
    const element = document.getElementById('product-export-model-lists');

    if (element && !element.tomselect) {
        new TomSelect(element, {
            plugins: ['remove_button'],
            maxItems: null,
            closeAfterSelect: false,
            hideSelected: true,
            create: false,
            persist: false,
            placeholder: 'یک یا چند مدل را انتخاب کنید',
            searchField: ['text'],
            sortField: { field: 'text', direction: 'asc' },
        });
    }
};

document.addEventListener('DOMContentLoaded', initializeProductExports);

Alpine.start();
