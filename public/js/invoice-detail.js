(() => {
 'use strict';
 document.querySelectorAll('.invoice-detail .table').forEach(table => {
  const labels = Array.from(table.querySelectorAll('thead th'), th => th.textContent.trim());
  table.querySelectorAll('tbody tr').forEach(row => {
   const cells = Array.from(row.cells);
   if (cells.some(cell => cell.colSpan > 1)) {row.classList.add('id-empty');return;}
   cells.forEach((cell, index) => {
    cell.dataset.label = labels[index] || '';
    const value = document.createElement('span');value.className = 'id-cell-value';
    while(cell.firstChild) value.appendChild(cell.firstChild);
    cell.appendChild(value);
   });
  });
 });
})();
