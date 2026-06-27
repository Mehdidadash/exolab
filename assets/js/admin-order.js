document.addEventListener('DOMContentLoaded', function () {
    function enableSort(containerId, tableName) {
        const tbody = document.getElementById(containerId);
        if (!tbody) return;

        let dragSrcEl = null;

        function handleDragStart(e) {
            dragSrcEl = this;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.id);
            this.classList.add('dragging');
        }

        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const target = e.target.closest('tr');
            if (!target || target === dragSrcEl) return;
            const rect = target.getBoundingClientRect();
            const next = (e.clientY - rect.top) / rect.height > 0.5;
            tbody.insertBefore(dragSrcEl, next ? target.nextSibling : target);
        }

        function handleDragEnd(e) {
            this.classList.remove('dragging');
            // collect ordered ids
            const ids = Array.from(tbody.querySelectorAll('tr')).map(r => r.dataset.id);
            // post to update endpoint
            fetch('../admin/update_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ table: tableName, ids })
            }).then(r => r.json()).then(data => {
                if (!data.success) console.error('Order update failed', data);
            }).catch(err => console.error(err));
        }

        Array.from(tbody.querySelectorAll('tr')).forEach(function (row) {
            row.addEventListener('dragstart', handleDragStart, false);
            row.addEventListener('dragover', handleDragOver, false);
            row.addEventListener('dragend', handleDragEnd, false);
        });
    }

    enableSort('sortable-works', 'portfolio_works');
    enableSort('sortable-prices', 'site_prices');
});
