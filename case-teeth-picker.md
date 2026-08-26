عالی. پس طبق طراحی تأییدشده جلو می‌رویم. ساختار فعلی `cases.php` را هم بررسی کردم؛ مودال فعلی همان `#case-modal` با عرض حداکثر 760px است و فرم با `#case-form` ارسال می‌شود، بنابراین نیازی به تغییر معماری فرم نداریم.

من دو فایل مستقل آماده کردم:

* [دانلود `case-teeth-picker.css`](sandbox:/mnt/data/case-teeth-picker.css)
* [دانلود `case-teeth-picker.js`](sandbox:/mnt/data/case-teeth-picker.js)

این نسخه هیچ کتابخانه جدیدی لازم ندارد و فقط JavaScript خام است.

## 1. فایل‌ها را در پروژه قرار بده

در پروژه:

```text
assets/
├── css/
│   └── case-teeth-picker.css
└── js/
    └── case-teeth-picker.js
```

---

## 2. در `cases.php`، CSS را فراخوانی کن

نزدیک جایی که این را داری:

```php
<link rel="stylesheet" href="../assets/css/persian-datepicker.min.css">
```

این را هم اضافه کن:

```html
<link rel="stylesheet" href="../assets/css/case-teeth-picker.css">
```

و پایین صفحه، **بعد از HTML مودال کیس**، این را اضافه کن:

```html
<script src="../assets/js/case-teeth-picker.js"></script>
```

بهتر است این script قبل از `<script>` بزرگ اصلی `cases.php` قرار بگیرد یا حداقل بعد از HTML مودال باشد.

---

# 3. قسمت فعلی دندان را عوض کن

در `cases.php` الان این قسمت را داری: 

```html
<div class="form-group">
    <label for="case-location-type">مکان</label>
    <select id="case-location-type" name="location_type">
        <option value="">انتخاب...</option>
        <option value="teeth">دندان</option>
        <option value="upper">فک بالا</option>
        <option value="lower">فک پایین</option>
        <option value="both">هر دو</option>
    </select>
</div>

<div class="form-group">
    <label for="case-teeth">دندان (مثال: 11,12)</label>
    <input id="case-teeth" name="teeth">
</div>
```

کل این قسمت را با این جایگزین کن:

```html
<div class="form-group">
    <label for="case-location-type">مکان</label>
    <select id="case-location-type" name="location_type">
        <option value="">انتخاب...</option>
        <option value="teeth">دندان</option>
        <option value="upper">فک بالا</option>
        <option value="lower">فک پایین</option>
        <option value="both">هر دو</option>
    </select>
</div>

<div
    id="case-teeth-picker"
    class="case-teeth-picker"
    aria-label="انتخاب دندان‌ها"
>
    <!--
        این بخش توسط case-teeth-picker.js ساخته می‌شود.
        مقدار واقعی برای PHP در input مخفی زیر نگهداری می‌شود.
    -->
</div>

<input
    type="hidden"
    id="case-teeth"
    name="teeth"
    value=""
>
```

یعنی `case-teeth` همچنان وجود دارد و **اسم فیلد دیتابیس/POST عوض نمی‌شود**.

این مهم است چون `save_case.php` همچنان مقدار `teeth` را دریافت می‌کند.

---

# 4. یک تغییر مهم در `populateCaseForm`

در `cases.php` فعلی این خط وجود دارد: 

```javascript
jQuery('#case-teeth').val(data.teeth || '');
```

آن را حذف کن و این را بگذار:

```javascript
if (window.CaseTeethPicker) {
    CaseTeethPicker.setValue(data.teeth || '');
} else {
    jQuery('#case-teeth').val(data.teeth || '');
}
```

بنابراین قسمت مربوط به location باید بشود:

```javascript
jQuery('#case-service-id').val(data.service_id || '');
jQuery('#case-location-type').val(data.location_type || '');

if (window.CaseTeethPicker) {
    CaseTeethPicker.setValue(data.teeth || '');
} else {
    jQuery('#case-teeth').val(data.teeth || '');
}

jQuery('#case-shade').val(data.shade || '');
jQuery('#case-quantity').val(data.quantity || 1);
```

این باعث می‌شود کیس قدیمی مثلاً:

```text
13,14
```

به صورت دو دندان مستقل نمایش داده شود.

و:

```text
13_14
```

به صورت دو دندان انتخاب‌شده **با کلید اتصال فعال** نمایش داده شود.

---

# 5. هنگام «افزودن کیس جدید» picker را هم reset کن

الان کد فعلی تو این است: 

```javascript
jQuery('#add-case-btn').on('click', function(e){
    e.preventDefault();
    jQuery('#case-form')[0].reset();
    jQuery('#case-id').val('');
    jQuery('#case-parent-id').val('');
    openCaseModal();
});
```

به این تبدیلش کن:

```javascript
jQuery('#add-case-btn').on('click', function(e){
    e.preventDefault();

    jQuery('#case-form')[0].reset();
    jQuery('#case-id').val('');
    jQuery('#case-parent-id').val('');

    if (window.CaseTeethPicker) {
        CaseTeethPicker.reset();
    }

    openCaseModal();
});
```

همین کار را برای `add_sub` هم انجام بده:

```javascript
if (addSub) {
    jQuery('#case-form')[0].reset();
    jQuery('#case-id').val('');
    jQuery('#case-parent-id').val(addSub);

    if (window.CaseTeethPicker) {
        CaseTeethPicker.reset();
    }

    openCaseModal('افزودن کیس زیرمجموعه برای کیس #' + addSub);
}
```

---

# 6. موقع بستن مودال هم reset کنیم

در `closeCaseModal()` فعلی فرم reset می‌شود. 

بعد از:

```javascript
jQuery('#case-form')[0].reset();
```

این را اضافه کن:

```javascript
if (window.CaseTeethPicker) {
    CaseTeethPicker.reset();
}
```

یعنی:

```javascript
function closeCaseModal(){
    jQuery('#case-modal').css({display: 'none'});
    jQuery('#case-form')[0].reset();

    if (window.CaseTeethPicker) {
        CaseTeethPicker.reset();
    }

    jQuery('#case-save').prop('disabled', false).text('ذخیره');

    var selSpan = document.getElementById('case-files-selection');
    if (selSpan) selSpan.style.display = 'none';

    var progWrap = document.getElementById('case-files-progress');
    if (progWrap) progWrap.style.display = 'none';

    setTimeout(function(){
        jQuery('#case-received-date').val('');
    }, 100);
}
```

---

## نتیجه‌ای که خواهی داشت

مثلاً برای یک بریج سه‌واحدی:

اول:

```text
○     ○     ○
13    14    15

▯     ▯
```

هر سه را انتخاب می‌کنی:

```text
●     ●     ●
13    14    15

▯     ▯
```

بعد کلید بین 13 و 14:

```text
●     ●     ●
13    14    15

▮     ▯
```

و بعد کلید بین 14 و 15:

```text
●     ●     ●
13    14    15

▮     ▮
```

مقدار hidden input:

```text
13_14_15
```

اگر 16 را هم انتخاب کنی ولی به آن وصل نکنی:

```text
13_14_15,16
```

و اگر فقط 13 و 14 را انتخاب کنی بدون اتصال:

```text
13,14
```

---

### نکته مهمی که در این نسخه رعایت کردم

**کلیدهای اتصال بین 11 و 21 و همچنین 41 و 31 وجود ندارند.**

یعنی این‌ها:

```text
12  11 | 21  22
          ↑
       خط وسط فک
```

و:

```text
42  41 | 31  32
          ↑
       خط وسط فک
```

به‌عنوان دندان‌های مجاور برای ساخت بریج در نظر گرفته نمی‌شوند.

این از نظر UI هم باعث می‌شود آن خط عمودی وسط فک تمیز باقی بماند.

همچنین اگر کاربر یک دندان را حذف کند، **تمام اتصال‌های مرتبط با آن دندان خودکار حذف می‌شوند**؛ بنابراین هیچ‌وقت چیزی مثل `13_14` در حالی که 14 انتخاب نیست وارد hidden input نمی‌شود.

فعلاً **`quantity` را دست نزدم**؛ چون منطق قیمت‌گذاری فعلی `cases.php` به quantity وابسته است و بهتر است آن را در مرحله بعد جداگانه تصمیم بگیریم، نه اینکه با Tooth Picker ناخواسته تغییر کند. منطق فعلی قیمت و design fee در همین فایل به quantity وابسته است. 

**فایل دیگری از پروژه برای این مرحله لازم ندارم.** `cases.php` و ساختار فعلی فرم برای پیاده‌سازی UI کافی است.
