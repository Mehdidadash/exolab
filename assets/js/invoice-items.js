document.addEventListener('DOMContentLoaded', function () {
    const priceOptions = JSON.parse(document.getElementById('price-data')?.textContent || '[]');
    const itemRows = document.getElementById('invoice-items');
    const addItemBtn = document.getElementById('add-invoice-item');
    const totalAmountField = document.getElementById('total_amount');

    function createPriceOptions(selectedId = '') {
        const select = document.createElement('select');
        // the visible select is for user selection only; the actual submitted value
        // will be stored in a hidden input named `items[index][price_id]`.
        select.className = 'form-control';
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'انتخاب از لیست قیمت...';
        select.appendChild(emptyOption);
        priceOptions.forEach(price => {
            const option = document.createElement('option');
            option.value = price.id;
            option.textContent = price.title;
            if (price.id === selectedId) option.selected = true;
            option.dataset.price = price.price;
            select.appendChild(option);
        });
        return select;
    }

    function updateRowTotal(row) {
        const qty = Number(row.querySelector('.item-quantity')?.value || 1);
        const price = Number(row.querySelector('.item-unit-price')?.value || 0);
        const total = qty * price;
        row.querySelector('.item-total').textContent = total.toLocaleString('en-US');
        updateInvoiceTotal();
    }

    function updateInvoiceTotal() {
        let sum = 0;
        itemRows.querySelectorAll('tr.invoice-item-row').forEach(row => {
            const qty = Number(row.querySelector('.item-quantity')?.value || 1);
            const price = Number(row.querySelector('.item-unit-price')?.value || 0);
            sum += qty * price;
        });
        totalAmountField.value = sum.toFixed(2);
    }

    function refreshRowNames() {
        itemRows.querySelectorAll('tr.invoice-item-row').forEach((row, index) => {
            const priceSelect = row.querySelector('select');
            const descriptionInput = row.querySelector('.item-description');
            const hiddenTitle = row.querySelector('.item-title-hidden');
            const patientInput = row.querySelector('.item-patient');
            const qtyInput = row.querySelector('.item-quantity');
            const priceInput = row.querySelector('.item-unit-price');
            const hiddenPrice = row.querySelector('.item-price-hidden');

            if (priceSelect) priceSelect.removeAttribute('name');
            if (hiddenPrice) hiddenPrice.name = `items[${index}][price_id]`;
            if (descriptionInput) descriptionInput.name = `items[${index}][item_description]`;
            if (hiddenTitle) hiddenTitle.name = `items[${index}][item_title]`;
            if (patientInput) patientInput.name = `items[${index}][patient_name]`;
            if (qtyInput) qtyInput.name = `items[${index}][quantity]`;
            if (priceInput) priceInput.name = `items[${index}][unit_price]`;
        });
    }

    function addInvoiceRow(item = {}) {
        const tr = document.createElement('tr');
        tr.className = 'invoice-item-row';
        tr.innerHTML = `
            <td></td>
            <td>
                <input type="text" class="item-description" value="${item.item_description ?? ''}" placeholder="شرح خدمات">
                <input type="hidden" class="item-title-hidden" value="${item.item_title ?? item.item_description ?? ''}">
            </td>
            <td><input type="text" class="item-patient" value="${item.patient_name ?? ''}"></td>
            <td><input type="number" class="item-quantity" min="1" value="${item.quantity ?? 1}" required style="width: 80px;"></td>
            <td><input type="number" class="item-unit-price" min="0" step="0.01" value="${item.unit_price ?? 0}" required></td>
            <td class="item-total">0</td>
            <td><button type="button" class="remove-item-btn">حذف</button></td>
        `;

        const priceCell = tr.children[0];
        const priceSelect = createPriceOptions(item.price_id || '');
        priceCell.appendChild(priceSelect);
        const hiddenPrice = document.createElement('input');
        hiddenPrice.type = 'hidden';
        hiddenPrice.className = 'item-price-hidden';
        hiddenPrice.value = item.price_id ?? '';
        priceCell.appendChild(hiddenPrice);

        const descriptionInput = tr.querySelector('.item-description');
        const hiddenTitle = tr.querySelector('.item-title-hidden');
        const qtyInput = tr.querySelector('.item-quantity');
        const priceInput = tr.querySelector('.item-unit-price');

        function syncPrice() {
            const selected = priceSelect.selectedOptions[0];
            if (selected && selected.dataset.price && priceInput) {
                priceInput.value = Number(selected.dataset.price).toFixed(2);
            }
            if (hiddenPrice) hiddenPrice.value = priceSelect.value || '';
            updateRowTotal(tr);
        }

        if (descriptionInput && hiddenTitle) {
            descriptionInput.addEventListener('input', function () {
                hiddenTitle.value = this.value;
            });
        }

        priceSelect.addEventListener('change', syncPrice);
        if (qtyInput) {
            qtyInput.addEventListener('input', () => updateRowTotal(tr));
        }
        if (priceInput) {
            priceInput.addEventListener('input', () => updateRowTotal(tr));
        }

        const removeButton = tr.querySelector('.remove-item-btn');
        if (removeButton) {
            removeButton.addEventListener('click', function () {
                tr.remove();
                refreshRowNames();
                updateInvoiceTotal();
            });
        }

        itemRows.appendChild(tr);
        refreshRowNames();
        updateRowTotal(tr);
    }

    if (addItemBtn) {
        addItemBtn.addEventListener('click', function () {
            addInvoiceRow({ quantity: 1, unit_price: 0 });
        });
    }

    if (itemRows && itemRows.dataset.items) {
        const existingItems = JSON.parse(itemRows.dataset.items);
        if (existingItems.length === 0) {
            addInvoiceRow();
        } else {
            existingItems.forEach(item => addInvoiceRow(item));
        }
    }
});
