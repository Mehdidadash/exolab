document.addEventListener('DOMContentLoaded', function () {
    const priceOptions = JSON.parse(document.getElementById('price-data')?.textContent || '[]');
    const itemRows = document.getElementById('invoice-items');
    const addItemBtn = document.getElementById('add-invoice-item');
    const addDiscountBtn = document.getElementById('add-discount-item');
    const addCasesBtn = document.getElementById('add-cases-from-doctor');
    const totalAmountField = document.getElementById('total_amount');

    // ─── Helper: format number with commas ───
    function fmt(n) {
        return Math.round(n).toLocaleString('en-US');
    }



    // ─── Re-index input names after row add/remove ───
    function refreshRowNames() {
        itemRows.querySelectorAll('tr.invoice-item-row').forEach(function(row, index) {
            var fields = [
                { sel: 'select', name: 'price_id' },
                { sel: '.item-price-hidden', name: 'price_id' },
                { sel: '.item-case-hidden', name: 'case_id' },
                { sel: '.item-description', name: 'item_description' },
                { sel: '.item-title-hidden', name: 'item_title' },
                { sel: '.item-patient', name: 'patient_name' },
                { sel: '.item-quantity', name: 'quantity' },
                { sel: '.item-unit-price', name: 'unit_price' },
                { sel: '.item-total', name: 'total_amount' }
            ];
            fields.forEach(function(f) {
                var el = row.querySelector(f.sel);
                if (el) {
                    el.removeAttribute('name');
                    el.name = 'items[' + index + '][' + f.name + ']';
                }
            });
        });
    }

    // ─── Add a SIMPLE item row (title + unit price) ───
    function addSimpleRow(item) {
        var tr = document.createElement('tr');
        tr.className = 'invoice-item-row';
        var desc = item.item_description || '';
        var title = item.item_title || desc || '';
        var price = Number(item.unit_price || 0);
        var caseId = item.case_id || '';
        var isNeg = item.is_discount || false;
        var patient = (item.patient_name || '').replace(/"/g, '&quot;');
        var qty = item.quantity || 1;
        var typeLabel = (item.service_title || item.item_title || (isNeg ? 'تخفیف' : '')).replace(/"/g, '&quot;');

        // Discount rows always SUBTRACT: the user types a POSITIVE amount, the
        // row total becomes negative (جمع = -فی × تعداد) so it is deducted from
        // the invoice grand total. No min="0" on the discount input so a
        // negative value never triggers a browser validation error.
        if (isNeg) { tr.setAttribute('data-discount', '1'); }

        var priceInputHtml = isNeg
            ? '<input type="number" class="item-unit-price" step="1" value="' + price + '" placeholder="مبلغ تخفیف">'
            : '<input type="number" class="item-unit-price" min="0" step="1" value="' + price + '" placeholder="فی">';

        tr.innerHTML =
            '<td style="text-align:center; vertical-align:middle;">' + (typeLabel || '—') + '</td>' +
            '<td>' +
                '<input type="text" class="item-description" value="' + desc.replace(/"/g, '&quot;') + '" placeholder="' + (isNeg ? 'عنوان تخفیف' : 'توضیحات') + '">' +
                '<input type="hidden" class="item-title-hidden" value="' + title.replace(/"/g, '&quot;') + '">' +
                '<input type="hidden" class="item-case-hidden" value="' + caseId + '">' +
                '<input type="hidden" class="item-price-hidden" value="">' +
            '</td>' +
            '<td><input type="text" class="item-patient" value="' + patient + '"></td>' +
            '<td>' + priceInputHtml + '</td>' +
            '<td><input type="number" class="item-quantity" min="1" value="' + qty + '"></td>' +
            '<td><input type="text" class="item-total" readonly value="0"></td>' +
            '<td><button type="button" class="remove-item-btn" title="حذف">🗑</button></td>';

        // Event listeners
        var descInput = tr.querySelector('.item-description');
        var hiddenTitle = tr.querySelector('.item-title-hidden');
        var qtyInput = tr.querySelector('.item-quantity');
        var priceInput = tr.querySelector('.item-unit-price');

        if (descInput && hiddenTitle) {
            descInput.addEventListener('input', function() { hiddenTitle.value = this.value; });
        }
        if (qtyInput) qtyInput.addEventListener('input', function() { updateRowTotal(tr); });
        if (priceInput) priceInput.addEventListener('input', function() { updateRowTotal(tr); });

        tr.querySelector('.remove-item-btn').addEventListener('click', function() {
            tr.remove();
            refreshRowNames();
            updateInvoiceTotal();
        });

        itemRows.appendChild(tr);
        refreshRowNames();
        updateRowTotal(tr);
    }

    // ─── Add a CASE row (with price select, unit price, patient, quantity) ───
    function addCaseRow(c) {
        var tr = document.createElement('tr');
        tr.className = 'invoice-item-row';
        var patient = (c.patient_name || '').replace(/"/g, '&quot;');
        var servTitle = (c.service_title || 'خدمت').replace(/"/g, '&quot;');
        // Use only teeth/location as description, without "کیس #" prefix
        var desc = (c.item_description || c.teeth || c.location_type || c.patient_name || '').replace(/"/g, '&quot;');
        // Strip common patterns
        desc = desc.replace(/^کیس #\d+ - /, '').replace(/^دندان /, '');
        // Use unit_price (not total_price) – جمع = تعداد × فی
        var price = Math.round(c.unit_price || c.total_price || 0);
        var qty = c.quantity || 1;

        // Build price select
        var selectHtml = '<select style="width:100%;">';
        selectHtml += '<option value="">انتخاب...</option>';
        priceOptions.forEach(function(p) {
            var sel = (p.id == c.service_id) ? ' selected' : '';
            selectHtml += '<option value="' + p.id + '" data-price="' + p.price + '"' + sel + '>' + p.title + '</option>';
        });
        selectHtml += '</select>';

        tr.innerHTML =
            '<td style="text-align:center;">' + selectHtml + '</td>' +
            '<td>' +
                '<input type="text" class="item-description" value="' + desc + '">' +
                '<input type="hidden" class="item-title-hidden" value="' + servTitle + '">' +
                '<input type="hidden" class="item-case-hidden" value="' + c.id + '">' +
                '<input type="hidden" class="item-price-hidden" value="' + (c.service_id || '') + '">' +
            '</td>' +
            '<td><input type="text" class="item-patient" value="' + patient + '"></td>' +
            '<td><input type="number" class="item-unit-price" min="0" step="1" value="' + price + '"></td>' +
            '<td><input type="number" class="item-quantity" min="1" value="' + qty + '"></td>' +
            '<td><input type="text" class="item-total" readonly value="' + Math.round(price * qty) + '"></td>' +
            '<td><button type="button" class="remove-item-btn" title="حذف">🗑</button></td>';

        // Wire events
        var selectEl = tr.querySelector('select');
        var priceHidden = tr.querySelector('.item-price-hidden');
        var unitPriceInput = tr.querySelector('.item-unit-price');
        var descInput = tr.querySelector('.item-description');
        var hiddenTitle = tr.querySelector('.item-title-hidden');
        var qtyInput = tr.querySelector('.item-quantity');

        function syncCasePrice() {
            var opt = selectEl.selectedOptions[0];
            if (opt && opt.dataset.price && unitPriceInput) {
                unitPriceInput.value = Math.round(Number(opt.dataset.price));
            }
            if (priceHidden) priceHidden.value = selectEl.value || '';
            updateRowTotal(tr);
        }

        if (descInput && hiddenTitle) {
            descInput.addEventListener('input', function() { hiddenTitle.value = this.value; });
        }
        selectEl.addEventListener('change', syncCasePrice);
        if (qtyInput) qtyInput.addEventListener('input', function() { updateRowTotal(tr); });
        if (unitPriceInput) unitPriceInput.addEventListener('input', function() { updateRowTotal(tr); });

        tr.querySelector('.remove-item-btn').addEventListener('click', function() {
            tr.remove();
            refreshRowNames();
            updateInvoiceTotal();
        });

        itemRows.appendChild(tr);
        refreshRowNames();
        updateRowTotal(tr);
    }

    // ─── Update single row total (جمع = فی × تعداد، قابل ویرایش نیست) ───
    function updateRowTotal(row) {
        var qty = Number(row.querySelector('.item-quantity')?.value || 0);
        var price = Number(row.querySelector('.item-unit-price')?.value || 0);
        // Discount rows always SUBTRACT: total = -فی × تعداد
        var total = row.getAttribute('data-discount') ? -(qty * price) : (qty * price);
        var td = row.querySelector('.item-total');
        if (td) td.value = Math.round(total);
        updateInvoiceTotal();
    }

    // ─── Update invoice grand total ───
    function updateInvoiceTotal() {
        var sum = 0;
        itemRows.querySelectorAll('tr.invoice-item-row').forEach(function(row) {
            var t = Number(row.querySelector('.item-total')?.value || 0);
            sum += t;
        });
        totalAmountField.value = Math.round(sum);
        updateServiceSummary();
    }

    // ─── Update the per-service count chips live ───
    function updateServiceSummary() {
        var wrap = document.getElementById('service-summary');
        var body = document.getElementById('service-summary-body');
        if (!wrap || !body || !itemRows) return;
        var counts = {};
        itemRows.querySelectorAll('tr.invoice-item-row').forEach(function(row) {
            if (row.getAttribute('data-discount')) return; // discounts are not a service
            var sel = row.querySelector('select');
            var title = '';
            if (sel && sel.selectedOptions[0]) title = sel.selectedOptions[0].textContent;
            if (!title) title = row.querySelector('.item-title-hidden')?.value || '';
            if (!title) title = row.querySelector('.item-description')?.value || '';
            if (!title) title = '—';
            var qty = Math.max(0, Number(row.querySelector('.item-quantity')?.value || 0));
            if (qty <= 0) return;
            counts[title] = (counts[title] || 0) + qty;
        });
        body.innerHTML = '';
        Object.keys(counts).forEach(function(title) {
            var span = document.createElement('span');
            span.style.cssText = 'background:#eef2ff; padding:3px 10px; border-radius:20px; white-space:nowrap; font-size:0.82rem;';
            var t = document.createElement('span');
            t.style.color = '#334155';
            t.textContent = title;
            span.appendChild(t);
            span.appendChild(document.createTextNode(': '));
            var b = document.createElement('b');
            b.style.color = '#1d4ed8';
            b.textContent = fmt(counts[title]);
            span.appendChild(b);
            body.appendChild(span);
        });
        wrap.style.display = (body.childNodes.length > 0) ? '' : 'none';
    }

    // ─── Manual edit of a row's جمع (total) – update grand total live ───
    if (itemRows) {
        itemRows.addEventListener('input', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('item-total')) {
                updateInvoiceTotal();
            }
        });
    }

    // ─── Button: افزودن آیتم جدید (simple, positive) ───
    if (addItemBtn) {
        addItemBtn.addEventListener('click', function () {
            addSimpleRow({ item_title: '', item_description: '', unit_price: 0 });
        });
    }

    // ─── Button: افزودن تخفیف (negative hint) ───
    if (addDiscountBtn) {
        addDiscountBtn.addEventListener('click', function () {
            addSimpleRow({ item_title: 'تخفیف', item_description: 'تخفیف', unit_price: 0, quantity: 1, is_discount: true });
        });
    }

    // ─── Button: افزودن از کیس‌های دکتر ───
    if (addCasesBtn) {
        addCasesBtn.addEventListener('click', function () {
            var doctorId = document.getElementById('doctor_id').value;
            if (!doctorId) {
                alert('لطفاً ابتدا یک دکتر انتخاب کنید.');
                return;
            }
            fetch('get_case.php?doctor_id=' + doctorId + '&uninvoiced=1')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.length) {
                        alert('هیچ کیس فاکتورنشده‌ای برای این دکتر یافت نشد.');
                        return;
                    }
                    // Build modal
                    var rowsHtml = '';
                    data.forEach(function(c, idx) {
                        var safeData = encodeURIComponent(JSON.stringify(c));
                        rowsHtml += '<tr>' +
                            '<td><input type="checkbox" class="case-cb" data-idx="' + idx + '" checked></td>' +
                            '<td>' + c.id + '</td>' +
                            '<td>' + (c.patient_name || '') + '</td>' +
                            '<td>' + (c.service_title || '') + '</td>' +
                            '<td>' + fmt(c.total_price || c.unit_price || 0) + '</td>' +
                            '</tr>';
                        rowsHtml += '<tr style="display:none;" class="case-data" data-idx="' + idx + '" data-json="' + safeData + '"></tr>';
                    });

                    var html = '<div class="case-selector-overlay" style="position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:99999;">' +
                        '<div style="background:#fff; border-radius:12px; max-width:700px; width:90%; max-height:80vh; overflow:auto; padding:24px;">' +
                        '<h3 style="margin-top:0;">انتخاب کیس‌ها</h3>' +
                        '<table style="width:100%; border-collapse:collapse;">' +
                        '<thead><tr><th><input type="checkbox" id="case-select-all" checked></th><th>#</th><th>بیمار</th><th>خدمت</th><th>قیمت</th></tr></thead><tbody>' +
                        rowsHtml +
                        '</tbody></table>' +
                        '<div style="margin-top:16px; display:flex; gap:10px;">' +
                        '<button type="button" class="case-confirm-btn" class="btn" style="background:#06B6D4; color:#fff; border:none; border-radius:8px; padding:10px 20px; font-weight:700; cursor:pointer;">افزودن کیس‌های انتخاب شده</button>' +
                        '<button type="button" class="case-cancel-btn" style="background:#E5E7EB; color:#0F172A; border:none; border-radius:8px; padding:10px 20px; font-weight:700; cursor:pointer;">انصراف</button>' +
                        '</div></div></div>';

                    var div = document.createElement('div');
                    div.innerHTML = html;
                    document.body.appendChild(div);
                    var overlay = div.querySelector('.case-selector-overlay');

                    // Select all
                    overlay.querySelector('#case-select-all').addEventListener('change', function() {
                        overlay.querySelectorAll('.case-cb').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
                    });

                    // Confirm
                    overlay.querySelector('.case-confirm-btn').addEventListener('click', function() {
                        var selected = [];
                        overlay.querySelectorAll('.case-cb:checked').forEach(function(cb) {
                            var idx = cb.dataset.idx;
                            var dataRow = overlay.querySelector('.case-data[data-idx="' + idx + '"]');
                            if (dataRow) {
                                try {
                                    var obj = JSON.parse(decodeURIComponent(dataRow.dataset.json));
                                    selected.push(obj);
                                } catch(e) { console.warn('parse error', e); }
                            }
                        });
                        selected.forEach(function(c) { addCaseRow(c); });
                        document.body.removeChild(div);
                    });

                    // Cancel
                    overlay.querySelector('.case-cancel-btn').addEventListener('click', function() {
                        document.body.removeChild(div);
                    });
                })
                .catch(function(err) {
                    alert('خطا در دریافت کیس‌ها: ' + err.message);
                });
        });
    }

    // ─── Load existing items on page load ───
    if (itemRows && itemRows.dataset.items) {
        try {
            var existingItems = JSON.parse(itemRows.dataset.items);
            if (existingItems.length === 0) {
                addSimpleRow({ unit_price: 0 });
            } else {
                existingItems.forEach(function(item) {
                    if (item.case_id) {
                        addCaseRow(item);
                    } else {
                        addSimpleRow(item);
                    }
                });
            }
        } catch(e) {
            addSimpleRow({ unit_price: 0 });
        }
        updateServiceSummary();
    }
});
