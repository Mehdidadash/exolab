<?php
// panel/aligner_cases.php
// فهرستِ جداگانهٔ کیس‌های «طرح درمان الاینر شفاف».
//
// چرا صفحهٔ جدا؟
//   کیس‌های الاینر درمان چندمرحله‌ای دارند و هر بار چند مرحله تحویل می‌شود؛ در جدولِ
//   عمومیِ کیس‌ها فقط شلوغی ایجاد می‌کردند. اینجا همان کیس‌ها با پیشرفتِ مرحله‌ها
//   (تحویل‌شده/کل برای هر فک) و مبلغِ تحویل‌ها نمایش داده می‌شوند.
//   جدولِ اصلی «کیس‌ها» این ردیف‌ها را نشان نمی‌دهد (cases_data.php → is_aligner).
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$canView = has_permission('view_all_cases') || has_permission('view_own_cases')
    || has_permission('view_assigned_cases') || has_permission('view_clinic_cases')
    || is_designer_user($user)
    || in_array($user['role'] ?? '', ['doctor', 'clinic', 'lab', 'outsource_lab', 'customer_lab', 'partner_lab'], true);
if (!$canView) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

panel_layout_start('کیس‌های الاینر شفاف');
?>

<div class="form-card" style="margin-bottom:14px;">
    <h3 style="margin:0 0 6px;">🦷 کیس‌های الاینر شفاف</h3>
    <p style="color:#525252; margin:0; line-height:1.9;">
        این صفحه فقط کیس‌های «طرح درمان الاینر» را نشان می‌دهد. برای ثبت مرحله‌های تحویل‌شده
        (چند مرحله برای فک بالا و چند مرحله برای فک پایین) روی «مشاهده» کلیک کنید و از کارت
        «مراحل الاینر شفاف» در صفحهٔ کیس استفاده کنید. مرحله‌ها با تاریخ تحویل در فاکتور همان ماه می‌آیند.
    </p>
</div>

<div class="form-card">
    <table id="aligner-table" class="display" style="width:100%;">
        <thead>
            <tr>
                <th>#</th>
                <th>بیمار</th>
                <th>پزشک</th>
                <th>فک درمانی</th>
                <th>فک بالا (تحویل / کل)</th>
                <th>فک پایین (تحویل / کل)</th>
                <th>تحویل‌ها</th>
                <th>مبلغ تحویل‌شده</th>
                <th>تاریخ طرح</th>
                <th>وضعیت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.DataTable === 'undefined') return;
    var fa = function(n){ return String(n == null ? 0 : n).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };

    function progressHtml(done, total){
        done = parseInt(done || 0, 10); total = parseInt(total || 0, 10);
        if (!total) {
            return '<span style="color:#64748b;">' + fa(done) + ' مرحله</span> <small style="color:#94a3b8;">(کل ثبت نشده)</small>';
        }
        var pct = Math.min(100, Math.round(done / total * 100));
        var rem = Math.max(0, total - done);
        var color = rem === 0 ? '#16a34a' : (done === 0 ? '#dc2626' : '#7c3aed');
        return ''
            + '<div style="display:flex; align-items:center; gap:6px;">'
            +   '<div style="flex:1; background:#e5e7eb; border-radius:6px; height:8px; overflow:hidden; min-width:60px;">'
            +     '<div style="width:' + pct + '%; height:100%; background:' + color + ';"></div>'
            +   '</div>'
            +   '<span style="white-space:nowrap; font-size:.82rem;">' + fa(done) + ' / ' + fa(total) + '</span>'
            + '</div>'
            + (rem > 0 ? '<small style="color:#92400e;">باقی‌مانده: ' + fa(rem) + '</small>' : '<small style="color:#166534;">کامل</small>');
    }

    var faMoney = function(v){ return Number(v || 0).toLocaleString('en-US').replace(/\d/g, function(x){ return '۰۱۲۳۴۵۶۷۸۹'[+x]; }); };
    var CAN_DELETE = <?= (is_admin() ? 'true' : 'false') ?>;
    var CSRF = '<?= htmlspecialchars(csrf_token()) ?>';

    jQuery('#aligner-table').DataTable({
        processing: true,
        serverSide: false,
        ajax: {
            url: 'cases_data.php?mode=all&only_aligner=1',
            dataSrc: 'data',
            data: function(d){
                d.show_delivered = 1;   // الاینرها حتی بعد از تحویل هم باید در این صفحه بمانند
            }
        },
        order: [[8, 'desc']],
        pageLength: 100,
        lengthMenu: [25, 50, 100, 250, -1],
        responsive: true,
        language: {
            search: "جستجو:",
            lengthMenu: "نمایش _MENU_ در هر صفحه",
            info: "نمایش _START_ تا _END_ از _TOTAL_ مورد",
            infoEmpty: "هیچ موردی یافت نشد",
            infoFiltered: "(فیلتر شده از _MAX_ مورد)",
            loadingRecords: "در حال بارگذاری...",
            zeroRecords: "کیسی یافت نشد",
            emptyTable: "هنوز کیس الاینری ثبت نشده است.",
            paginate: { first: "اول", previous: "قبلی", next: "بعدی", last: "آخر" }
        },
        columns: [
            { data: 0, render: function(d, t, row){
                return '<a href="view_case.php?id=' + d + '" style="font-weight:700;">#' + fa(d) + '</a>';
            } },
            { data: 2 },
            { data: 1 },
            { data: 4, render: function(d, t, row){
                // ستون «مکان/دندان» برای کیس الاینر همان فکِ درمانی است
                var v = String(row[4] || '');
                if (v.indexOf('هر دو') !== -1) return 'هر دو فک';
                if (v.indexOf('فک بالا') !== -1) return 'فک بالا';
                if (v.indexOf('فک پایین') !== -1) return 'فک پایین';
                if (v.indexOf('دندان') !== -1) return 'دندان';
                return '—';
            } },
            { data: 25, render: function(d, t, row){ return progressHtml(row[25], row[29]); } },
            { data: 26, render: function(d, t, row){ return progressHtml(row[26], row[30]); } },
            { data: 27, render: function(d, t, row){
                if (!d) return '<span style="color:#94a3b8;">—</span>';
                return fa(d) + ' بار';
            } },
            { data: 28, render: function(d){ return d ? faMoney(d) : '—'; } },
            { data: 8 },
            { data: 7 },
            { data: 11, orderable: false, searchable: false, render: function(d, t, row){
                var id = row[0];
                var wrap = '<div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">';
                wrap += '<a class="btn" href="view_case.php?id=' + id + '" style="background:#e0f2fe; color:#0369a1; padding:2px 8px; font-size:.78rem;">مشاهده</a>';
                wrap += '<a class="btn" href="view_case.php?id=' + id + '&edit=1" style="background:#fef3c7; color:#92400e; padding:2px 8px; font-size:.78rem;">ویرایش</a>';
                if (CAN_DELETE) {
                    wrap += '<button type="button" class="btn js-al-del" data-id="' + id + '" style="background:#fee2e2; color:#991b1b; padding:2px 8px; font-size:.78rem;">حذف</button>';
                }
                wrap += '</div>';
                return wrap;
            } }
        ]
    });

    // حذف کیس (فقط مدیر — مثل جدول کیس‌ها)
    jQuery('#aligner-table').on('click', '.js-al-del', function(){
        var id = this.getAttribute('data-id');
        if (!id) return;
        if (!confirm('کیس #' + fa(id) + ' حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
        var fd = new FormData();
        fd.append('id', id);
        fd.append('_csrf_token', CSRF);
        fetch('delete_case.php', { method:'POST', headers:{ 'X-CSRF-Token': CSRF, 'Accept': 'application/json' }, body: fd })
            .then(function(r){ return r.json().catch(function(){ return { success:false }; }); })
            .then(function(j){
                if (j && j.success) { jQuery('#aligner-table').DataTable().ajax.reload(null, false); }
                else { alert('حذف انجام نشد' + (j && j.error ? ' (' + j.error + ')' : '.')); }
            })
            .catch(function(){ alert('خطا در ارتباط با سرور.'); });
    });
});
</script>

<?php panel_layout_end(); ?>
