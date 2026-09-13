// assets/js/shade-picker.js
// انتخابِ رنگِ استاندارد سایه. هر .shade-picker یک input مخفی (شاید خارج از خودش) را پر می‌کند.
(function () {
    function inputFor(picker) {
        var sel = picker.getAttribute('data-target');
        if (sel) {
            try { return document.querySelector(sel); } catch (e) { return null; }
        }
        return picker.querySelector('input[type="hidden"]');
    }

    function sync(picker) {
        var input = inputFor(picker);
        var val = input ? String(input.value || '') : '';
        picker.querySelectorAll('.shade-swatch').forEach(function (s) {
            s.classList.toggle('is-active', (s.getAttribute('data-shade') || '') === val);
        });
    }

    function syncAll() {
        document.querySelectorAll('.shade-picker').forEach(sync);
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('.shade-picker .shade-swatch');
        if (!b) return;
        e.preventDefault();
        var picker = b.closest('.shade-picker');
        var input = inputFor(picker);
        if (input) {
            input.value = b.getAttribute('data-shade') || '';
            try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (err) {}
        }
        sync(picker);
    });

    if (document.readyState !== 'loading') syncAll();
    else document.addEventListener('DOMContentLoaded', syncAll);

    // برای فرم‌هایی که پس از بارگذاری پر می‌شوند (مودال ویرایش)
    window.ShadePickerSync = syncAll;
})();
