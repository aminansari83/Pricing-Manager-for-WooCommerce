# Pricing Manager for WooCommerce

**مدیریت قیمت ووکامرس** · Developed by **Andiya / آندیا**

WooCommerce product price management with a Persian RTL administration interface, previewed bulk changes and a separate quotation workflow.

**Version: 0.2.0 · Status: Preview / Pre-release**

[Download installable ZIP](https://github.com/aminansari83/Pricing-Manager-for-WooCommerce/releases/download/v0.2.0/pricing-manager-for-woocommerce-0.2.0.zip) · [Release notes](https://github.com/aminansari83/Pricing-Manager-for-WooCommerce/releases/tag/v0.2.0) · [Report an issue](https://github.com/aminansari83/Pricing-Manager-for-WooCommerce/issues)

<div dir="rtl">

## معرفی فارسی

**مدیریت قیمت ووکامرس** به فروشنده کمک می‌کند قیمت محصولات را مدیریت کند و برای محصولاتی که قیمت معتبر ندارند، از مسیر جداگانه استعلام استفاده کند. پنل مدیریت فارسی و راست‌چین است و راهنمای فارسی نیز در بسته افزونه قرار دارد.

### امکانات نسخه ۰٫۲٫۰

- تغییر گروهی قیمت با درصد، مبلغ ثابت یا تعیین قیمت ثابت؛ همراه با پیش‌نمایش و تأیید نهایی.
- جست‌وجوی محصول با نام یا SKU و انتخاب دامنه تغییرات بر اساس دسته، برند و ویژگی.
- مدیریت محصولات ساده و متغیر؛ تعیین وضعیت خرید عادی، استعلام یا توقف فروش.
- گروه‌های قیمت‌گذاری، گردکردن قیمت‌ها، استثناها و بازگردانی تغییرات با بررسی تداخل.
- تاریخچه عملیات و گزارش CSV خصوصی.
- ورود قیمت از CSV و XLSX بر اساس SKU؛ همراه با نگاشت ستون‌ها و تبدیل صریح واحد پول.
- ثبت درخواست استعلام، پیگیری خصوصی و پیشنهاد قیمت مدت‌دار که پس از پذیرش وارد پرداخت ووکامرس می‌شود.
- اتصال اختیاری به منبع قیمت JSON از طریق HTTPS؛ با دریافت زمان‌بندی‌شده و کنترل تغییرات.

### نصب و شروع

۱. وردپرس، ووکامرس و PHP سازگار با جدول پیش‌نیازها داشته باش.

۲. از لینک دانلود بالای صفحه، فایل `pricing-manager-for-woocommerce-0.2.0.zip` را بگیر.

۳. در وردپرس به **افزونه‌ها ← افزودن افزونه ← بارگذاری افزونه** برو؛ ZIP را نصب و فعال کن.

۴. از **ووکامرس ← مدیریت قیمت ووکامرس**، تنظیمات واحد پول و رفتار غیرفعال‌سازی را بررسی کن.

۵. ابتدا چند محصول را انتخاب کن، پیش‌نمایش بگیر و سپس تغییرات را تأیید کن.

برای فهرست مستقل استعلام چندمحصولی می‌توان یک برگه با شورت‌کد `[apg_quote_list]` ساخت.

### رفتار قیمت‌ها و محدودیت‌ها

فعال‌سازی افزونه به‌تنهایی قیمت‌ها را تغییر نمی‌دهد. هنگام غیرفعال‌سازی، حالت پیش‌فرض بازگردانی فیلدهایی است که افزونه تغییر داده و هنوز با آخرین مقدار ثبت‌شده توسط آن برابرند؛ ویرایش دستی بعدی حفظ می‌شود. گزینه نگه‌داشتن قیمت‌های فعلی نیز در تنظیمات وجود دارد.

این نسخه آزمایشی است؛ ابتدا روی سایت تست استفاده شود. سازگاری با همه قالب‌ها، درگاه‌ها و افزونه‌های جانبی تأیید نشده است. افزونه موجودی را وارد نمی‌کند و نرخ زنده ارز ارائه نمی‌دهد. جزئیات پوشش بررسی‌ها و محدودیت‌ها در [TEST-REPORT.txt](TEST-REPORT.txt) آمده است.

### حریم خصوصی

استفاده از افزونه به حساب، کلید لایسنس یا سرویس اجباری نیاز ندارد و اطلاعات فروشگاه و مشتریان را به آندیا ارسال نمی‌کند. اتصال تأمین‌کننده اختیاری است و با تنظیم و رضایت مدیر فروشگاه فعال می‌شود. اطلاعات درخواست‌های استعلام در پایگاه داده همان فروشگاه ذخیره می‌شود؛ لینک پیگیری خصوصی است.

[راهنمای فارسی](GUIDE-FA.txt) · [مستندات توسعه](DEVELOPER.txt) · [نمونه CSV](sample-prices.csv)

</div>

## Requirements

| Component | Minimum version |
| --- | --- |
| WordPress | 6.8 |
| WooCommerce | 10.0 |
| PHP | 8.0 |

XLSX imports require PHP ZIP and DOM extensions. Activate separately for each store in multisite; network activation is not supported.

## Features

- Percentage, fixed-amount and fixed-price bulk edits with preview and explicit approval.
- Product name/SKU search, scope filters, named pricing groups and guarded undo.
- Simple and variable products with normal purchase, quotation and stopped-sale modes.
- Operation history and private CSV reports.
- SKU-based CSV/XLSX imports with column mapping and explicit currency conversion.
- Private quotation tracking and expiring merchant offers accepted through WooCommerce checkout.
- Optional HTTPS JSON supplier sources and scheduled fetches.
- Persian RTL administration and a Persian user guide.

## Installation

1. Install and activate a compatible WooCommerce version.
2. Download the attached plugin ZIP from [Releases](https://github.com/aminansari83/Pricing-Manager-for-WooCommerce/releases/tag/v0.2.0).
3. In WordPress, open **Plugins → Add New → Upload Plugin**, install the ZIP and activate it.
4. Open **WooCommerce → مدیریت قیمت ووکامرس** and review currency and deactivation settings.
5. Preview a small selection before confirming changes or enabling supplier schedules.

Use the attached `pricing-manager-for-woocommerce-0.2.0.zip` for installation. GitHub's automatically generated **Source code** archives are repository snapshots.

## Updating and deactivation

Upload a newer plugin ZIP through WordPress and choose **Replace current with uploaded**. Keep the plugin active until the upgrader handles replacement: manual deactivation deliberately runs the configured restoration policy.

By default, deactivation restores plugin-owned fields only when they still match the plugin's last written values. Later manual edits are preserved. The settings also allow keeping current prices. Stock and paid order totals are not changed; quotation and history data remain available after reactivation.

The internal folder and text domain remain `andiya-price-guard` for continuity with earlier installations. The approved display name is **Pricing Manager for WooCommerce**.

## Scope and privacy

This is a preview release. Test on staging before production use. Universal theme, payment gateway and extension compatibility is not claimed; see [TEST-REPORT.txt](TEST-REPORT.txt).

There is no required remote service, account or license key, and no transmission of store/customer information to Andiya. Supplier fetching is optional and requires merchant configuration and consent. Quotation contact data is stored in the store database. Private tracking links should remain private.

## Documentation and support

- [Plugin readme and changelog](readme.txt)
- [Persian user guide](GUIDE-FA.txt)
- [Developer documentation](DEVELOPER.txt)
- [Test report](TEST-REPORT.txt)
- [Sample import CSV](sample-prices.csv)

Report bugs through [GitHub Issues](https://github.com/aminansari83/Pricing-Manager-for-WooCommerce/issues). Include plugin/WordPress/WooCommerce/PHP versions and reproduction steps. Remove credentials, customer contact information and private tracking links from reports.

## License and credits

Plugin code is licensed under **GPL-2.0-or-later**, as declared in the plugin header. See [LICENSE](LICENSE).

Developed by **Amin Ansari / Andiya** · [aminansari.ir](https://aminansari.ir) · [andiyatech.org](https://andiyatech.org)
