/* ACCBOS app.js — small client-side helpers for the admin UI. */

(function () {
    'use strict';

    /** Recalculate a single SO line and the grand total. */
    function recalcRow(row) {
        if (!row) return 0;
        var qty      = parseFloat(row.querySelector('.item-qty')?.value      || '0');
        var price    = parseFloat(row.querySelector('.item-price')?.value    || '0');
        var discount = parseFloat(row.querySelector('.item-discount')?.value || '0');
        var amount   = (qty * price) - discount;
        if (!isFinite(amount) || isNaN(amount)) amount = 0;
        var out = row.querySelector('.item-amount');
        if (out) out.value = amount.toFixed(2);
        return amount;
    }

    function recalcAll() {
        var rows = document.querySelectorAll('#itemsTable tbody tr.item-row');
        var total = 0;
        rows.forEach(function (row) { total += recalcRow(row); });
        var grand = document.getElementById('grandTotal');
        if (grand) grand.textContent = total.toFixed(2);
    }

    function bindRow(row) {
        row.querySelectorAll('.item-qty, .item-price, .item-discount').forEach(function (input) {
            input.addEventListener('input', recalcAll);
        });
        var rm = row.querySelector('.remove-item');
        if (rm) {
            rm.addEventListener('click', function () {
                var rows = document.querySelectorAll('#itemsTable tbody tr.item-row');
                if (rows.length <= 1) {
                    // Clear instead of removing the only line.
                    row.querySelectorAll('input').forEach(function (inp) {
                        if (inp.type !== 'hidden') inp.value = inp.classList.contains('item-qty') ? '1' : '';
                    });
                } else {
                    row.parentNode.removeChild(row);
                }
                renumberRows();
                recalcAll();
            });
        }
    }

    function renumberRows() {
        var rows = document.querySelectorAll('#itemsTable tbody tr.item-row');
        rows.forEach(function (row, idx) {
            row.querySelectorAll('input[name]').forEach(function (inp) {
                inp.name = inp.name.replace(/items\[\d+\]/, 'items[' + idx + ']');
            });
        });
    }

    function buildEmptyRow(index) {
        var tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.innerHTML =
            '<td><input type="text" name="items[' + index + '][item_code]" class="form-control form-control-sm item-code"></td>' +
            '<td><input type="text" name="items[' + index + '][item_description]" class="form-control form-control-sm"></td>' +
            '<td><input type="number" step="0.0001" min="0" name="items[' + index + '][qty]" class="form-control form-control-sm item-qty" value="1"></td>' +
            '<td><input type="number" step="0.0001" min="0" name="items[' + index + '][unit_price]" class="form-control form-control-sm item-price" value="0"></td>' +
            '<td><input type="number" step="0.0001" min="0" name="items[' + index + '][discount]" class="form-control form-control-sm item-discount" value="0"></td>' +
            '<td><input type="text" name="items[' + index + '][tax_code]" class="form-control form-control-sm" value="SST"></td>' +
            '<td><input type="text" class="form-control form-control-sm item-amount" value="0.00" readonly></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger remove-item">&times;</button></td>';
        return tr;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var addBtn = document.getElementById('addItemBtn');
        var table  = document.getElementById('itemsTable');
        if (addBtn && table) {
            var tbody = table.querySelector('tbody');
            tbody.querySelectorAll('tr.item-row').forEach(bindRow);
            addBtn.addEventListener('click', function () {
                var rows = tbody.querySelectorAll('tr.item-row');
                var newRow = buildEmptyRow(rows.length);
                tbody.appendChild(newRow);
                bindRow(newRow);
                recalcAll();
            });
            recalcAll();
        }

        // --- Bulk push selection (Sales Orders list) ---
        var selectAll = document.getElementById('selectAll');
        var bulkBtn   = document.getElementById('bulkPushBtn');
        var selCount  = document.getElementById('selCount');
        if (bulkBtn) {
            var boxes = function () {
                return Array.prototype.slice.call(document.querySelectorAll('.bulk-cb'));
            };
            var refresh = function () {
                var checked = boxes().filter(function (b) { return b.checked; });
                bulkBtn.disabled = checked.length === 0;
                if (selCount) {
                    selCount.textContent = checked.length
                        ? '· ' + checked.length + ' selected' : '';
                }
                if (selectAll) {
                    var all = boxes();
                    selectAll.checked = all.length > 0 && checked.length === all.length;
                    selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
                }
            };
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    boxes().forEach(function (b) { b.checked = selectAll.checked; });
                    refresh();
                });
            }
            boxes().forEach(function (b) {
                b.addEventListener('change', refresh);
            });
            refresh();
        }
    });
})();
