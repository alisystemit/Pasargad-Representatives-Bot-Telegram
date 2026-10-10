/* ═══════════════════════════════════════════════════════════════════
   پنل نمایندگان — منطق مینی‌اپ
   ─────────────────────────────────────────────────────────────────
   ساختار فایل:
     1) پل ارتباط با تلگرام (Theme, HapticFeedback, BackButton, …)
     2) لایهٔ شبکه (fetch + initData + خطا)
     3) ابزارهای رندر (HTML escape، آیکون، نشان)
     4) وضعیت برنامه
     5) صفحه‌ها
     6) روتر + راه‌اندازی

   دو قاعده که همه‌جا رعایت شده و نباید بشکند:
     • **هر متنی که از سرور می‌آید قبل از ورود به innerHTML escape می‌شود.**
       نام پنل، متن تیکت و پیام خطا همگی ورودی کاربر/سرور هستند.
     • **سرور تصمیم می‌گیرد چه چیزی مجاز است.** کلاینت فقط همان
       `actions`/`flags` را رندر می‌کند و خودش چیزی فرض نمی‌کند.
   ═══════════════════════════════════════════════════════════════════ */

(function () {
'use strict';

/* ═══ ۱) پل ارتباط با تلگرام ═══ */

const tg = window.Telegram?.WebApp ?? null;

/** مقادیر پیش‌فرض وقتی تلگرام یا اسکریپتش نیست (مثلاً باز کردن در مرورگر). */
const TG_FALLBACK = {
    ready() {}, expand() {}, close() {}, setHeaderColor() {}, setBackgroundColor() {},
    BackButton: { show() {}, hide() {}, onClick() {}, offClick() {} },
    MainButton: {
        show() {}, hide() {}, onClick() {}, offClick() {},
        setText() {}, showProgress() {}, hideProgress() {}
    },
    HapticFeedback: { impactOccurred() {}, notificationOccurred() {}, selectionChanged() {} },
    themeParams: {}, colorScheme: 'dark', platform: 'unknown', initData: '', initDataUnsafe: {},
    isVersionAtLeast: () => true, expandTo: () => {}, disableVerticalSwipes: () => {},
    setHeaderColor: () => {}, ready: () => {}, close: () => {}
};

function t() {
    if (!tg) return TG_FALLBACK;
    for (const k of Object.keys(TG_FALLBACK)) {
        if (tg[k] === undefined) tg[k] = TG_FALLBACK[k];
    }
    return tg;
}

const haptic = {
    tap() { try { t().HapticFeedback.selectionChanged(); } catch (e) {} },
    ok() { try { t().HapticFeedback.notificationOccurred('success'); } catch (e) {} },
    warn() { try { t().HapticFeedback.notificationOccurred('warning'); } catch (e) {} },
    err() { try { t().HapticFeedback.notificationOccurred('error'); } catch (e) {} },
    impact() { try { t().HapticFeedback.impactOccurred('light'); } catch (e) {} }
};

/** اعمال رنگ‌های تم تلگرام روی متغیرهای CSS. */
function applyTheme() {
    const api = t();
    const p = api.themeParams || {};
    const root = document.documentElement;

    const map = {
        '--tg-bg': p.bg_color,
        '--tg-bg-alt': p.secondary_bg_color,
        '--tg-text': p.text_color,
        '--tg-hint': p.hint_color,
        '--tg-link': p.link_color,
        '--tg-btn': p.button_color,
        '--tg-btn-text': p.button_text_color,
        '--tg-secondary': p.secondary_bg_color,
        '--tg-sep': p.section_separator_color,
        '--tg-subtle': p.section_header_color,
        '--tg-header': p.header_bg_color
    };

    for (const [k, v] of Object.entries(map)) {
        if (typeof v === 'string' && v.trim() !== '') root.style.setProperty(k, v);
    }

    const light = api.colorScheme === 'light' || p.bg_color === '#ffffff' || p.bg_color === '#fff';
    root.classList.toggle('is-light', light);

    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta && typeof p.bg_color === 'string' && p.bg_color) meta.setAttribute('content', p.bg_color);
}

/* ═══ ۲) لایهٔ شبکه ═══ */

const BOOT = window.WEBAPP_BOOT || {};
const API_URL = BOOT.api || 'webapp.php';

/**
 * فراخوانی API.
 *
 * `initData` در هدر `X-Telegram-Init-Data` می‌رود (نه در بدنه) تا لاگ سرور
 * آن را در کنار هر درخواست ببیند و بتوان نشست را ردیابی کرد. سرور آن را با
 * HMAC بررسی می‌کند، پس دستکاری‌اش بی‌فایده است.
 */
async function api(action, params = {}) {
    let res;

    try {
        res = await fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Telegram-Init-Data': t().initData || ''
            },
            body: JSON.stringify({ action, ...params }),
            cache: 'no-store'
        });
    } catch (e) {
        throw new ApiError('ارتباط با سرور برقرار نشد. اینترنت را بررسی کنید.', 0);
    }

    let data;

    try {
        data = await res.json();
    } catch (e) {
        throw new ApiError('پاسخ سرور قابل خواندن نبود.', res.status);
    }

    if (res.status === 401) {
        // نشست منقضی شده: تنها راه، باز کردن دوبارهٔ اپ است.
        throw new ApiError(data.message || 'نشست شما منقضی شده است.', 401, true);
    }

    if (data.ok === false && typeof data.message === 'string') {
        throw new ApiError(data.message, res.status);
    }

    return data;
}

class ApiError extends Error {
    constructor(message, status = 0, fatal = false) {
        super(message);
        this.status = status;
        this.fatal = fatal;
    }
}

/** اجرای یک عملیات با مدیریت خطا و حالت «در حال اجرا» روی دکمه. */
async function run(btn, fn) {
    const busy = btn && btn.classList ? btn : null;

    if (busy) busy.classList.add('is-busy');

    try {
        return await fn();
    } finally {
        if (busy) busy.classList.remove('is-busy');
    }
}

/* ═══ ۳) ابزارهای رندر ═══ */

/** escape — تنها راه مجاز ساخت HTML از داده. */
function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/** عدد با جداکنندهٔ هزارگان فارسی. */
function fa(n) {
    const v = Number(n) || 0;
    return v.toLocaleString('fa-IR');
}

/**
 * بایت → متن خوانا، همان واحدهایی که سرور در `Str::formatBytes()` استفاده
 * می‌کند (کیلوبایت/مگابایت/گیگابایت/ترابایت با گیگابایت = ۱۰۷۳۷۴۱۸۲۴).
 *
 * چرا سمت کلاینت؟ چون سرور این مقدار را خام (int) می‌فرستد تا کلاینت بتواند
 * جمع بزند؛ تبدیل به متن در هر دو جا انجام شدن یعنی دو پیاده‌سازی که
 * به‌مرور ناهماهنگ می‌شوند (اختلاف در گرد کردن و واحد).
 */
function bytes(n) {
    const v = Number(n) || 0;

    if (v <= 0) return '۰';

    const units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    const i = Math.min(units.length - 1, Math.max(0, Math.floor(Math.log(v) / Math.log(1024))));
    const scaled = v / Math.pow(1024, i);

    const decimals = scaled >= 100 || i <= 1 ? 0 : (scaled >= 10 ? 1 : 2);

    return scaled.toLocaleString('fa-IR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + units[i];
}

/** خلاصهٔ متن برای یک خط. */
function clip(text, max = 42) {
    const s = String(text ?? '');
    return s.length > max ? s.slice(0, max - 1) + '…' : s;
}

function pill(tone, label) {
    return `<span class="pill pill-${esc(tone)}">${esc(label)}</span>`;
}

function bar(percent, tone) {
    const p = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
    const cls = tone || (p >= 90 ? 'is-bad' : p >= 70 ? 'is-warn' : 'is-ok');
    return `<div class="bar ${cls}"><i style="width:${p}%"></i></div>`;
}

function kv(label, value, opts = {}) {
    const dir = opts.ltr ? ' class="num"' : '';
    return `<div class="kv-row"><span>${esc(label)}</span><b${dir}>${value}</b></div>`;
}

/** فیلد قابل کپی با کپی خودکار هنگام لمس. */
function copyField(value, label) {
    if (!value) {
        return `<div class="kv-row"><span>${esc(label)}</span><b class="muted">ثبت نشده</b></div>`;
    }

    // مقدار در `title` هم می‌آید تا کاربر بتواند با نگه‌داشتن انگشت، نسخهٔ
    // کامل (بدون کوتاه‌شدن) را ببیند. متن داخل دکمه عمداً بریده می‌شود چون
    // یک URL کامل در عرض موبایل کل کارت را خراب می‌کند.
    return `<div class="kv-row"><span>${esc(label)}</span>`
        + `<button class="copy" data-copy="${esc(value)}" title="${esc(value)}" type="button">`
        + `<span>${esc(value)}</span> 📋</button></div>`;
}

function empty(icon, title, text, actionHtml) {
    return `<div class="empty"><div class="empty-ico">${icon}</div><b>${esc(title)}</b>`
        + `<p>${esc(text || '')}</p>${actionHtml || ''}</div>`;
}

function skeleton(count = 3) {
    let out = '';
    for (let i = 0; i < count; i++) out += '<div class="skel skel-card"></div>';
    return out;
}

function notice(tone, title, text) {
    return `<div class="notice notice-${esc(tone)}"><div><b>${esc(title)}</b>${esc(text)}</div></div>`;
}

/* ═══ توست ═══ */

function toast(message, kind = '') {
    const box = document.getElementById('toasts');
    const el = document.createElement('div');

    el.className = 'toast' + (kind ? ' toast-' + kind : '');
    el.textContent = message;
    box.appendChild(el);

    if (kind === 'ok') haptic.ok();
    else if (kind === 'bad') haptic.err();
    else haptic.impact();

    setTimeout(() => {
        el.classList.add('is-out');
        setTimeout(() => el.remove(), 260);
    }, kind === 'bad' ? 4600 : 3000);
}

/* ═══ مودال ═══ */

const sheet = {
    el: null, body: null, title: null, onClose: null,

    open(title, html, onClose) {
        this.el = document.getElementById('sheet');
        this.body = document.getElementById('sheetBody');
        this.title = document.getElementById('sheetTitle');
        this.onClose = onClose || null;

        this.title.textContent = title;
        this.body.innerHTML = html;

        document.getElementById('sheetBackdrop').hidden = false;
        this.el.hidden = false;
        haptic.tap();
    },

    close() {
        document.getElementById('sheetBackdrop').hidden = true;
        this.el.hidden = true;
        this.body.innerHTML = '';

        const fn = this.onClose;
        this.onClose = null;
        if (fn) fn();
    },

    isOpen() { return !this.el.hidden; }
};

/* ═══ ۴) وضعیت برنامه ═══ */

const state = {
    boot: null,          // پاسخ bootstrap
    route: 'home',
    args: {},
    history: [],
    gen: 0,              // شمارهٔ نسل رندر (برای لغو رندرهای کهنه)
    cache: {},           // پاسخ‌های کش‌شدهٔ صفحه‌ها
    panel: null,         // پنل انتخاب‌شده برای شارژ
    package: null,       // بستهٔ انتخاب‌شده در فروشگاه
    days: 0,
    orderFilter: ''
};

const el = {
    boot: document.getElementById('boot'),
    bootHint: document.getElementById('bootHint'),
    appbar: document.getElementById('appbar'),
    appbarTitle: document.getElementById('appbarTitle'),
    back: document.getElementById('backBtn'),
    refresh: document.getElementById('refreshBtn'),
    content: document.getElementById('content'),
    tabbar: document.getElementById('tabbar')
};

const TITLES = {
    home: 'پنل من', panels: 'پنل‌های من', panel: 'جزئیات پنل',
    shop: 'فروشگاه', package: 'جزئیات بسته', orders: 'سفارش‌ها', order: 'جزئیات سفارش',
    wallet: 'کیف پول', payments: 'تاریخچهٔ پرداخت', referral: 'دعوت دوستان',
    tests: 'کانفیگ‌های تست', support: 'پشتیبانی', ticket: 'تیکت', more: 'بیشتر',
    account: 'حساب من', link: 'افزودن پنل', rules: 'قوانین', coupon: 'کد تخفیف',
    admin: 'پنل مدیریت', adminOrders: 'سفارش‌ها', adminOrder: 'سفارش',
    adminUsers: 'کاربران', adminUser: 'کاربر', adminPanels: 'پنل‌ها',
    adminPackages: 'بسته‌ها', adminCoupons: 'کدهای تخفیف', adminTickets: 'تیکت‌ها',
    adminTicket: 'تیکت', adminFlags: 'تنظیمات و سوییچ‌ها', adminRisk: 'نمایندگان در معرض خطر',
    adminAudit: 'لاگ حسابرسی'
};

function title(name) {
    return TITLES[name] || 'پنل نمایندگان';
}

/* ═══ ناوبری ═══ */

const TABS = ['home', 'panels', 'shop', 'orders', 'more'];

/**
 * رفتن به یک تب از ناوبری پایین.
 *
 * دو تصمیم که تجربهٔ کاربری را می‌سازند:
 *   ۱) تاریخچه پاک می‌شود — تب‌ها «ریشه»‌اند، نه یک صفحهٔ معمولی. اگر
 *      نگهشان داریم، «بازگشت» کاربر را به مسیرهای عجیب می‌برد.
 *   ۲) فشار دوباره روی همان تب صفحه را تازه می‌کند (مثل اپ‌های بومی) و
 *      هیچ خطایی نمی‌دهد.
 */
function openTab(name) {
    if (state.route === name) {
        refresh();
        return;
    }

    state.history = [];
    go(name, {}, { force: true });
}

function renderTabs() {
    el.tabbar.querySelectorAll('.tab').forEach((btn) => {
        const name = btn.dataset.tab;
        btn.classList.toggle('is-active', name === state.route);
    });
}

function go(name, args = {}, opts = {}) {
    if (state.route !== name || opts.force) {
        state.history.push({ route: state.route, args: state.args });
    }

    state.route = name;
    state.args = args;

    render(name, args, opts);
}

function back() {
    const prev = state.history.pop();

    if (prev) {
        state.route = prev.route;
        state.args = prev.args;
        render(prev.route, prev.args, { fromHistory: true });
    } else {
        go('home', {}, { force: true });
    }
}

/** رفتن به یک مسیر و بازگشت به همین صفحه با دکمهٔ «بازگشت». */
function detail(name, args) {
    go(name, args);
}

/* ═══ ۵) صفحه‌ها ═══ */

const views = {};

/* ── خانه ── */
views.home = async (root) => {
    const d = state.boot;
    if (!d) return;

    const u = d.user;
    const alerts = d.panels.alerts;

    root.innerHTML = `
        <section class="hero">
            ${u.is_admin ? '<span class="hero-badge">👑 مدیر</span>' : ''}
            <div class="hero-name">سلام ${esc(u.first_name || 'دوست عزیز')} 👋</div>
            <div class="hero-sub">${u.is_representative ? 'نمایندگی شما فعال است' : 'به جمع نمایندگان خوش آمدید'}</div>
            <div class="hero-stats">
                <div class="hero-stat"><b>${fa(d.panels.count)}</b><span>پنل</span></div>
                <div class="hero-stat"><b>${fa(u.orders_count)}</b><span>سفارش</span></div>
                <div class="hero-stat"><b>${fa(u.loyalty)}</b><span>امتیاز</span></div>
            </div>
        </section>

        ${alerts > 0 ? notice('warn', '⚠️ نیاز به توجه شما',
            alerts + ' پنل در حال انقضا یا منقضی شده است. تمدید کنید تا دسترسی مشتریان قطع نشود.') : ''}

        <div class="stats">
            <div class="stat">
                <div class="stat-ico">💰</div>
                <b>${fa(u.wallet)}</b>
                <span>کیف پول (تومان)</span>
            </div>
            <div class="stat">
                <div class="stat-ico">📦</div>
                <b class="is-long">${bytes(d.panels.total_used)}</b>
                <span>مصرف کل</span>
            </div>
            <div class="stat">
                <div class="stat-ico">🎫</div>
                <b>${fa(d.tickets.open)}</b>
                <span>تیکت باز</span>
            </div>
        </div>

        ${d.panels.count > 0 ? `
            <div class="section-title"><span>📶 مصرف پنل‌ها</span><span class="tiny">${d.panels.used_percent}٪</span></div>
            ${bar(d.panels.used_percent)}
            <div class="card card-tight mb-12">
                <div class="row-between">
                    <span class="tiny">مجموع مصرف همهٔ پنل‌ها</span>
                    <span class="tiny">${bytes(d.panels.total_used)}</span>
                </div>
            </div>` : ''}

        <div class="section-title"><span>⚡ دسترسی سریع</span></div>
        <div class="grid">
            ${tile('🖥', 'پنل‌های من', `${d.panels.count} پنل`, 'panels')}
            ${tile('🛒', 'فروشگاه', 'خرید و شارژ', 'shop')}
            ${tile('📦', 'سفارش‌ها', `${fa(u.orders_count)} سفارش`, 'orders')}
            ${tile('💰', 'کیف پول', fa(u.wallet), 'wallet')}
            ${tile('🧪', 'تست کانفیگ', 'ساخت یوزر تست', 'tests')}
            ${tile('🎫', 'پشتیبانی', d.tickets.open ? d.tickets.open + ' باز' : 'تیکت جدید', 'support')}
            ${tile('🎁', 'دعوت دوستان', `${fa(d.referral.invited)} نفر`, 'referral')}
            ${tile('👤', 'حساب من', 'پروفایل و آمار', 'account')}
            ${u.is_admin ? tile('🛠', 'پنل مدیریت', 'کنترل کامل', 'admin', true) : ''}
        </div>

        ${d.recent_orders.length ? `
            <div class="section-title"><span>🧾 آخرین سفارش‌ها</span>
                <button class="btn btn-sm btn-ghost" data-go="orders" type="button">همه</button>
            </div>
            <div class="list">${d.recent_orders.map(orderRow).join('')}</div>` : ''}
    `;
};

function tile(icon, title, sub, route, admin) {
    return `<button class="tile${admin ? ' is-admin' : ''}" data-go="${esc(route)}" type="button">
        <div class="tile-ico">${icon}</div><b>${esc(title)}</b><span>${esc(sub)}</span>
    </button>`;
}

function orderRow(o) {
    return `<button class="item" data-open="order" data-id="${o.id}" type="button">
        <div class="item-ico">📦</div>
        <div class="item-main">
            <div class="item-title ellipsis">${esc(clip(o.title, 30))}</div>
            <div class="item-sub num">${esc(o.code)} • ${esc(o.price_text)}</div>
        </div>
        ${pill(o.status.tone, o.status.label)}
    </button>`;
}

/* ── پنل‌ها ── */
views.panels = async (root) => {
    const d = await api('panels.list');

    if (!d.items.length) {
        root.innerHTML = empty(
            '🖥', 'هنوز پنلی ندارید',
            'برای شروع یک پنل نمایندگی بخرید، یا اگر از قبل پنل دارید آن را وصل کنید.',
            `<div class="btn-row">
                <button class="btn btn-primary" data-go="shop" type="button">🛒 خرید پنل</button>
                <button class="btn btn-ghost" data-go="link" type="button">🔗 پنل دارم</button>
            </div>`
        );
        return;
    }

    root.innerHTML = `
        <div class="notice notice-info"><div>برای دیدن رمز پنل، روی هر پنل بزنید. همه‌چیز رمزنگاری‌شده ذخیره می‌شود.</div></div>
        <div class="list">${d.items.map(panelCard).join('')}</div>
        <button class="btn btn-ghost mt-8" data-go="link" type="button">🔌 افزودن پنل موجود</button>
    `;
};

function panelCard(p) {
    const t = p.traffic;
    const pilled = pill(p.status.tone, p.status.label);

    let expiry = '<span class="tiny">⏳ بدون انقضا</span>';

    if (p.expired) {
        expiry = '<span class="tiny" style="color:var(--bad)">⛔️ منقضی — نیازمند تمدید</span>';
    } else if (p.grace_left !== null && p.grace_left !== undefined) {
        expiry = `<span class="tiny" style="color:var(--warn)">🚨 مهلت ارفاقی: ${fa(p.grace_left)} روز</span>`;
    } else if (p.days_left !== null && p.days_left !== undefined) {
        const tone = p.days_left <= 7 ? 'var(--warn)' : 'var(--tg-hint)';
        expiry = `<span class="tiny" style="color:${tone}">📅 ${fa(Math.max(0, p.days_left))} روز اعتبار</span>`;
    }

    return `<button class="item" data-open="panel" data-id="${p.id}" type="button"
        style="flex-direction:column;align-items:stretch;gap:0">
        <div class="row-between">
            <div class="item-main">
                <div class="item-title">🖥 ${esc(p.username)}</div>
                <div class="item-sub">${esc(expiry)}</div>
            </div>
            ${pilled}
        </div>
        ${t.unlimited
            ? `<div class="tiny mt-8">💾 نامحدود ♾️ • مصرف: <span class="num">${esc(t.used_text)}</span></div>`
            : `<div class="tiny mt-8">💾 ${esc(t.limit_text)} • مصرف: <span class="num">${esc(t.used_text)}</span></div>
               ${bar(t.percent)}`}
        <div class="row-between mt-8">
            <span class="tiny">👥 ${fa(p.users.active)} فعال از ${fa(p.users.total)}</span>
            <span class="tiny">سقف کاربر: ${esc(p.users.label)}</span>
        </div>
    </button>`;
}

/* ── جزئیات پنل ── */
views.panel = async (root, args) => {
    root.innerHTML = skeleton(2);

    const d = await api('panel.detail', { id: args.id });
    const p = d.panel;
    state.panel = p;

    const t = p.traffic;
    const usable = p.usable && !p.expired;

    root.innerHTML = `
        <section class="card">
            <div class="card-head">
                <div class="card-title">🖥 ${esc(p.username)}</div>
                ${pill(p.status.tone, p.status.label)}
            </div>
            <div class="kv">
                ${copyField(p.login_url, '🌐 آدرس ورود')}
                ${copyField(p.username, '👤 نام کاربری')}
                ${copyField(p.password, '🔒 رمز عبور')}
                ${p.sub_url ? copyField(p.sub_url, '🔗 لینک اشتراک') : ''}
            </div>
        </section>

        <section class="card">
            <div class="card-head"><div class="card-title">📶 وضعیت سرویس</div>
                <button class="btn btn-sm btn-ghost" data-act="sync" type="button">🔄 سینک</button>
            </div>
            ${t.unlimited
                ? `<div class="row-between"><span class="tiny">حجم کل</span><b>نامحدود ♾️</b></div>
                   <div class="row-between mt-8"><span class="tiny">مصرف</span><b class="num">${esc(t.used_text)}</b></div>`
                : `<div class="row-between"><span class="tiny">حجم کل</span><b class="num">${esc(t.limit_text)}</b></div>
                   <div class="row-between mt-8"><span class="tiny">مصرف</span><b class="num">${esc(t.used_text)}</b></div>
                   ${bar(t.percent)}
                   <div class="row-between"><span class="tiny">باقی‌مانده</span><b class="num">${esc(t.left_text || '—')}</b></div>`}
            <div class="divider"></div>
            <div class="kv">
                ${kv('👥 کاربران کل', fa(p.users.total))}
                ${kv('🟢 فعال', fa(p.users.active))}
                ${kv('⛔️ غیرفعال', fa(p.users.disabled))}
                ${kv('🎯 سقف کاربران', esc(p.users.label))}
                ${kv('💿 سقف دیسک', esc(p.disk_text))}
                ${p.role ? kv('🎭 نقش', esc(p.role)) : ''}
            </div>
            <button class="btn btn-ghost btn-sm mt-12" data-act="stats" type="button">👥 بروزرسانی آمار کاربران</button>
        </section>

        <section class="card">
            <div class="card-head"><div class="card-title">🗓 اعتبار</div></div>
            <div class="kv">
                ${p.expire_at === null ? kv('📅 انقضا', 'بدون محدودیت') : kv('📅 انقضا', esc(p.expire_text))}
                ${p.expired ? kv('⏳ وضعیت', 'منقضی — تمدید کنید') : kv('⏳ باقی‌مانده', fa(Math.max(0, p.days_left)) + ' روز')}
                ${p.subscribed ? kv('⭐️ اشتراک', esc(p.sub_expire_at ? 'فعال' : 'فعال')) : ''}
                ${kv('🔄 آخرین سینک', p.synced_at ? esc(faDate(p.synced_at)) : 'هرگز')}
            </div>
            ${p.expired || !usable ? notice('bad', '⚠️ نیاز به تمدید',
                'پس از انقضا دسترسی مشتریان این پنل قطع می‌شود. برای جلوگیری همین حالا تمدید کنید.') : ''}
        </section>

        <div class="btn-row">
            ${state.boot.app.renewal !== false ? `<button class="btn btn-primary" data-topup="${p.id}" type="button">🛒 شارژ / تمدید</button>` : ''}
            ${state.boot.app.test_config ? `<button class="btn btn-ghost" data-act="test" type="button">🧪 تست کانفیگ</button>` : ''}
        </div>
        <div class="btn-row mt-8">
            <button class="btn btn-ghost" data-act="users" type="button">👤 کاربران پنل</button>
            ${!p.subscribed ? `<button class="btn btn-ghost" data-act="subscribe" type="button">⭐️ فعال‌سازی اشتراک</button>` : ''}
        </div>
    `;
};

function faDate(ts) {
    // تاریخ شمسی از خودِ سرور می‌آید؛ این فقط برای زمان‌هایی است که سرور
    // به‌صورت متن نداده. عمداً ساده است و از toLocaleTimeString استفاده
    // می‌کند چون خروجی صحیح شمسی نیازمند Intl با تقویم persian است که در
    // همهٔ مرورگرهای درون‌کلیکی هست.
    try {
        return new Intl.DateTimeFormat('fa-IR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(ts * 1000));
    } catch (e) {
        return new Date(ts * 1000).toLocaleString('fa-IR');
    }
}

/* ── افزودن پنل ── */
views.link = async (root) => {
    root.innerHTML = `
        <div class="notice notice-warn"><div>
            <b>🔐 اطلاعات شما رمزنگاری می‌شود</b>
            رمز پنل فقط برای مدیریت پنل خودتان استفاده می‌شود و با کلید اختصاصی
            رمزنگاری می‌گردد.
        </div></div>

        <section class="card">
            <div class="field">
                <label for="pnlUser">نام کاربری پنل</label>
                <input class="input num" id="pnlUser" placeholder="مثلاً rep_alireza" autocomplete="off" autocapitalize="off">
            </div>
            <div class="field">
                <label for="pnlPass">رمز عبور پنل</label>
                <input class="input" id="pnlPass" type="password" placeholder="••••••••" autocomplete="new-password">
            </div>
            <button class="btn btn-primary" id="pnlGo" type="button">🔗 اتصال پنل</button>
            <div class="hint mt-8">پنل باید در حالت فعال باشد و حساب ادمین (اپراتور) داشته باشد.</div>
        </section>
    `;

    const go = document.getElementById('pnlGo');

    go.addEventListener('click', () => run(go, async () => {
        const username = document.getElementById('pnlUser').value.trim();
        const password = document.getElementById('pnlPass').value;

        if (!username || !password) {
            toast('نام کاربری و رمز را وارد کنید.', 'bad');
            return;
        }

        const res = await api('panel.link', { username, password });

        document.getElementById('pnlPass').value = '';
        toast(res.message || 'پنل متصل شد.', 'ok');
        go('panels');
    }).catch((e) => toast(e.message, 'bad')));
};

/* ── فروشگاه ── */
views.shop = async (root, args) => {
    const kind = args.kind || 'agency';
    root.innerHTML = skeleton(4);

    const d = await api('shop.list', { kind });

    if (d.ok === false) {
        root.innerHTML = notice('warn', '🛒 فروشگاه', d.message || 'فروشگاه در دسترس نیست.');
        return;
    }

    if (kind === 'agency' && d.can_create && d.can_create.ok === false) {
        root.innerHTML = notice('bad', '⛔️ خرید پنل نمایندگی موقتاً ممکن نیست',
            'اکانت سازندهٔ پنل در تنظیمات سرور تنظیم نشده است. با پشتیبانی تماس بگیرید.');
        return;
    }

    const chips = `
        <div class="chips">
            <button class="chip ${kind === 'agency' ? 'is-active' : ''}" data-shop="agency" type="button">🖥 پنل نمایندگی</button>
            <button class="chip ${kind === 'topup' ? 'is-active' : ''}" data-shop="topup" type="button">⚡️ شارژ پنل</button>
        </div>`;

    if (!d.items.length) {
        root.innerHTML = chips + empty('📦', 'بسته‌ای موجود نیست', 'فعلاً بسته‌ای در این بخش تعریف نشده است.');
        return;
    }

    root.innerHTML = chips + d.items.map((p, i) => packCard(p, i)).join('');
};

function packCard(p, index) {
    // «پیشنهادی» = بستهٔ میانی فهرست (معمولاً بهترین نسبت حجم به قیمت).
    // شرط `!limit_reached` لازم است تا برچسب روی بسته‌ای نیفتد که کاربر
    // اصلاً نمی‌تواند بخرد.
    const featured = index === 1 && !p.limit_reached;

    const feats = [];
    if (p.creates_panel) feats.push('🆕 ساخت پنل نمایندگی تازه');
    if (p.volume_gb > 0) feats.push('💾 ' + esc(p.volume_text));
    if (p.bonus_text) feats.push('🎁 ' + esc(p.bonus_text));
    if (p.duration_days) feats.push('⏳ ' + fa(p.duration_days) + ' روز اعتبار');
    if (p.max_users > 0) feats.push('👥 سقف ' + esc(p.max_users_label));
    else if (p.creates_panel) feats.push('👥 سقف کاربران: نامحدود ♾️');

    return `<section class="pack${featured ? ' is-featured' : ''}">
        ${featured ? '<span class="pack-ribbon">پیشنهادی ⭐️</span>' : ''}
        <h4>${esc(p.title)}</h4>
        ${p.description ? `<div class="pack-desc">${esc(p.description)}</div>` : ''}
        <div class="pack-feats">
            ${feats.map(f => `<div class="pack-feat"><i>✔️</i><span>${f}</span></div>`).join('')}
        </div>
        <div class="pack-price">
            <b class="num">${fa(p.min_price)}</b><span class="tiny">تومان</span>
            <span class="tiny">${fa(p.duration_days)} روز</span>
        </div>
        ${p.limit_reached
            ? `<div class="notice notice-warn"><div>سقف خرید شما از این نوع پر شده (${fa(p.max_per_user)} بسته).</div></div>`
            : `<button class="btn btn-primary" data-buy="${p.id}" type="button">🛒 انتخاب و خرید</button>`}
    </section>`;
}

/* ── جزئیات بسته + پیش‌فاکتور ── */
views.package = async (root, args) => {
    root.innerHTML = skeleton(2);

    let q = await api('shop.quote', { package_id: args.id, days: args.days || 0, panel_id: args.panel_id || 0 });

    if (q.ok === false) {
        root.innerHTML = notice('warn', '🛒', q.message || 'این بسته در دسترس نیست.');
        return;
    }

    const p = q.package;

    if (state.days === 0) {
        state.days = p.periods[0]?.days || 0;
    }

    const panelPicker = p.kind === 'topup' ? `
        <div class="field">
            <label>🖥 پنل هدف شارژ</label>
            <select class="select" id="pkgPanel">
                <option value="0">پنل پیش‌فرض (${esc(q.panel?.username || '—')})</option>
            </select>
            <div class="hint">اگر چند پنل دارید، حتماً پنل درست را انتخاب کنید؛ شارژ روی همان پنل اعمال می‌شود.</div>
        </div>` : '';

    root.innerHTML = `
        <section class="pack">
            <h4>${esc(p.title)}</h4>
            ${p.description ? `<div class="pack-desc">${esc(p.description)}</div>` : ''}
            <div class="pack-feats">
                ${p.volume_gb > 0 ? `<div class="pack-feat"><i>💾</i><span>${esc(p.volume_text)}${p.bonus_text ? ' + ' + esc(p.bonus_text) : ''}</span></div>` : ''}
                <div class="pack-feat"><i>👥</i><span>سقف کاربران: ${esc(p.max_users_label)}</span></div>
                ${p.creates_panel ? '<div class="pack-feat"><i>🆕</i><span>پنل نمایندگی تازه ساخته می‌شود</span></div>' : ''}
            </div>

            ${p.periods.length > 1 ? `
                <label class="tiny">⏳ مدت</label>
                <div class="periods" id="pkgPeriods">
                    ${p.periods.map(o => `
                        <button class="period${o.days === state.days ? ' is-active' : ''}" data-days="${o.days}" type="button">
                            ${esc(o.days_text)}<small>${esc(o.price_text)}</small>
                        </button>`).join('')}
                </div>` : `<div class="row-between mb-12"><span class="tiny">⏳ مدت</span><b>${esc(p.periods[0]?.days_text || '—')}</b></div>`}

            ${panelPicker}

            <div id="pkgQuote">${quoteBlock(q.quote)}</div>

            <button class="btn btn-primary" id="pkgBuy" type="button"
                ${q.quote.below_minimum ? 'disabled' : ''}>
                🛒 پرداخت و ثبت سفارش
            </button>
            ${q.quote.below_minimum ? `<div class="hint hint-danger mt-8">مبلغ نهایی کمتر از حداقل مجاز است. کد تخفیف را حذف کنید.</div>` : ''}
        </section>

        <div class="btn-row">
            <button class="btn btn-ghost" data-go="coupon" type="button">🎟️ کد تخفیف</button>
            <button class="btn btn-ghost" data-go="referral" type="button">🎁 معرفی</button>
        </div>
    `;

    // انتخاب مدت → پیش‌فاکتور تازه (بدون ساخت سفارش)
    const periods = document.getElementById('pkgPeriods');

    if (periods) {
        periods.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('[data-days]');
            if (!btn) return;

            state.days = Number(btn.dataset.days) || 0;
            haptic.tap();

            await run(null, async () => {
                q = await api('shop.quote', {
                    package_id: args.id,
                    days: state.days,
                    panel_id: Number(document.getElementById('pkgPanel')?.value) || 0
                });

                if (q.ok === false) {
                    toast(q.message, 'bad');
                    return;
                }

                periods.querySelectorAll('.period').forEach(el2 => {
                    el2.classList.toggle('is-active', Number(el2.dataset.days) === state.days);
                });

                document.getElementById('pkgQuote').innerHTML = quoteBlock(q.quote);
                const buy = document.getElementById('pkgBuy');
                buy.disabled = q.quote.below_minimum;
            }).catch(e => toast(e.message, 'bad'));
        });
    }

    const sel = document.getElementById('pkgPanel');

    if (sel) {
        const panels = state.boot?.panels?.items || [];

        for (const item of panels) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.username + ' — ' + item.status.label;
            sel.appendChild(opt);
        }

        if (args.panel_id) sel.value = String(args.panel_id);

        sel.addEventListener('change', () => run(null, async () => {
            q = await api('shop.quote', { package_id: args.id, days: state.days, panel_id: Number(sel.value) || 0 });
            if (q.ok === false) return toast(q.message, 'bad');
            document.getElementById('pkgQuote').innerHTML = quoteBlock(q.quote);
        }).catch(e => toast(e.message, 'bad')));
    }

    document.getElementById('pkgBuy').addEventListener('click', (ev) => run(ev.currentTarget, async () => {
        const res = await api('order.create', {
            package_id: args.id,
            days: state.days,
            panel_id: Number(sel?.value) || 0
        });

        toast(res.message || 'سفارش ساخته شد.', 'ok');
        state.cache.orders = null;
        detail('order', { id: res.order.id });
    }).catch(e => toast(e.message, 'bad')));
};

function quoteBlock(q) {
    return `
        <div class="kv">
            ${kv('💰 قیمت پایه', '<span class="num">' + fa(q.list) + '</span>')}
            ${q.discount > 0 ? kv('🎉 تخفیف', '<span class="num" style="color:var(--ok)">−' + fa(q.discount) + '</span>') : ''}
            ${q.code ? kv('🎟️ کد', '<span class="num">' + esc(q.code) + '</span>') : ''}
            ${q.referral ? kv('🎁 پاداش معرفی', '<span class="num">' + esc(q.referral) + '</span>') : ''}
            ${q.loyalty > 0 ? kv('🏆 وفاداری', '<span class="num" style="color:var(--gold)">−' + fa(q.loyalty) + '</span>') : ''}
        </div>
        <div class="row-between mt-8" style="padding-top:10px;border-top:1px solid var(--tg-sep)">
            <span style="font-weight:700">💵 پرداختی</span>
            <b style="font-size:17px" class="num">${fa(q.final)} <span class="tiny">تومان</span></b>
        </div>
    `;
}

/* ── سفارش‌ها ── */
views.orders = async (root) => {
    root.innerHTML = skeleton(4);

    const d = await api('orders.list', { status: state.orderFilter });

    if (!d.items.length) {
        root.innerHTML = empty('📭', 'سفارشی ندارید', 'هنوز خریدی ثبت نکرده‌اید.',
            '<button class="btn btn-primary" data-go="shop" type="button">🛒 رفتن به فروشگاه</button>');
        return;
    }

    const filters = [
        ['', 'همه'],
        ['awaiting_payment', 'در انتظار پرداخت'],
        ['paid', 'پرداخت‌شده'],
        ['applied', 'اجرا شده'],
        ['failed', 'ناموفق']
    ];

    root.innerHTML = `
        <div class="chips">
            ${filters.map(([k, l]) => `<button class="chip ${state.orderFilter === k ? 'is-active' : ''}" data-filter="${k}" type="button">${esc(l)}</button>`).join('')}
        </div>
        <div class="list">${d.items.map(orderRow).join('')}</div>
    `;
};

/* ── جزئیات سفارش ── */
views.order = async (root, args) => {
    root.innerHTML = skeleton(3);

    const d = await api('order.detail', { id: args.id });
    const o = d.order;

    const gateways = (d.gateways || []).map(g =>
        `<button class="btn btn-ghost" data-pay="${esc(g.name)}" type="button">${esc(g.title)}</button>`
    ).join('');

    const actions = [];
    if (o.actions.includes('check')) actions.push('<button class="btn btn-ghost" data-act="check" type="button">🔄 بررسی وضعیت</button>');
    if (o.actions.includes('pay') && gateways) {
        actions.push('<div class="divider"></div><div class="tiny mb-8">💳 روش پرداخت:</div>'
            + `<div class="btn-row">${gateways}</div>`);
    }
    if (o.actions.includes('receipt')) actions.push('<button class="btn btn-ghost" data-act="receipt" type="button">📸 ارسال رسید</button>');
    if (o.invoice_url) actions.push(`<a class="btn btn-ghost" href="${esc(o.invoice_url)}" target="_blank" rel="noopener">🧾 فاکتور قابل چاپ</a>`);
    if (d.panel) actions.push(`<button class="btn btn-primary" data-open="panel" data-id="${d.panel.id}" type="button">🖥 مشاهدهٔ پنل</button>`);

    root.innerHTML = `
        <section class="card">
            <div class="card-head">
                <div class="card-title">${esc(clip(o.title, 28))}</div>
                ${pill(o.status.tone, o.status.label)}
            </div>
            <div class="kv">
                ${kv('🔖 کد سفارش', '<span class="num">' + esc(o.code) + '</span>')}
                ${kv('📅 تاریخ', esc(o.created_text))}
                ${kv('📦 نوع', esc(o.kind_label))}
                ${o.volume_gb > 0 ? kv('💾 حجم', '<span class="num">' + fa(o.volume_gb) + ' GB</span>') : ''}
                ${o.duration_days > 0 ? kv('⏳ اعتبار', fa(o.duration_days) + ' روز') : ''}
            </div>
        </section>

        <section class="card">
            <div class="card-head"><div class="card-title">💵 مبالغ</div></div>
            <div class="kv">
                ${o.discount > 0 ? kv('قیمت پایه', '<span class="num">' + fa(o.original) + '</span>') : ''}
                ${o.discount > 0 ? kv('تخفیف', '<span class="num" style="color:var(--ok)">−' + fa(o.discount) + '</span>') : ''}
                ${o.coupon ? kv('🎟️ کد', '<span class="num">' + esc(o.coupon) + '</span>') : ''}
                ${o.referred ? kv('🎁 معرفی', '<span class="num">' + esc(o.referred) + '</span>') : ''}
            </div>
            <div class="row-between mt-12" style="padding-top:10px;border-top:1px solid var(--tg-sep)">
                <span style="font-weight:700">پرداختی</span>
                <b class="num" style="font-size:17px">${fa(o.price)} <span class="tiny">تومان</span></b>
            </div>
            ${o.payment_method ? kv('💳 روش', esc(o.payment_method)) : ''}
            ${o.payment_ref ? kv('🧾 پیگیری', '<span class="num">' + esc(clip(o.payment_ref, 22)) + '</span>') : ''}
        </section>

        ${o.error ? notice('bad', '⚠️ خطای سفارش', o.error) : ''}

        ${o.actions.includes('receipt') ? `
            <section class="card">
                <div class="card-title mb-8">📸 رسید کارت‌به‌کارت</div>
                <div class="tiny">تصویر رسید را بفرستید. مدیر آن را بررسی و تأیید می‌کند.</div>
                <input class="input mt-8" type="file" accept="image/*" id="receiptFile">
                <button class="btn btn-primary mt-8" id="receiptGo" type="button">ارسال رسید</button>
            </section>` : ''}

        ${actions.length ? `<div class="btn-row">${actions.join('')}</div>` : ''}

        ${d.provision_logs?.length ? `
            <div class="section-title"><span>📜 تاریخچهٔ اجرا</span></div>
            <section class="card card-tight">
                ${d.provision_logs.map(l => `<div class="row-between" style="padding:6px 0;border-bottom:1px solid var(--tg-sep)">
                    <span class="tiny">${esc(l.text)}</span><span class="tiny">${esc(clip(l.message, 40))}</span>
                </div>`).join('')}
            </section>` : ''}
    `;

    root.querySelectorAll('[data-pay]').forEach(btn => {
        btn.addEventListener('click', () => run(btn, async () => {
            const res = await api('order.pay', { id: args.id, method: btn.dataset.pay });

            if (res.pay_url) {
                t().openLink(res.pay_url);
                return;
            }

            if (res.instructions) {
                sheet.open('راهنمای پرداخت',
                    `<div class="bubble bubble-admin">${esc(res.instructions)}</div>
                     <div class="tiny center">مبلغ قابل پرداخت: <b class="num">${fa(res.amount)}</b> تومان</div>`);
            }

            toast(res.message || 'پرداخت آغاز شد.', 'ok');
            render(state.route, state.args);
        }).catch(e => toast(e.message, 'bad')));
    });

    const checkBtn = root.querySelector('[data-act="check"]');

    if (checkBtn) {
        checkBtn.addEventListener('click', () => run(checkBtn, async () => {
            const res = await api('order.check', { id: args.id });
            toast(res.message || (res.paid ? 'پرداخت تأیید شد.' : 'هنوز پرداخت نشده است.'), res.paid ? 'ok' : '');
            render(state.route, state.args);
        }).catch(e => toast(e.message, 'bad')));
    }

    const file = document.getElementById('receiptFile');

    if (file) {
        const go = document.getElementById('receiptGo');

        go.addEventListener('click', () => run(go, async () => {
            const f = file.files && file.files[0];

            if (!f) return toast('ابتدا تصویر رسید را انتخاب کنید.', 'bad');

            if (f.size > 5 * 1024 * 1024) return toast('حجم تصویر بیش از ۵ مگابایت است.', 'bad');

            const base64 = await toBase64(f);
            const res = await api('order.receipt', { id: args.id, data: base64 });

            toast(res.message || 'رسید ثبت شد.', 'ok');
            render(state.route, state.args);
        }).catch(e => toast(e.message, 'bad')));
    }

    const link = root.querySelector('[data-open="panel"]');
    if (link) link.addEventListener('click', () => detail('panel', { id: link.dataset.id }));
};

function toBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(new Error('خواندن فایل ناموفق بود.'));
        reader.readAsDataURL(file);
    });
}

/* ── کیف پول ── */
views.wallet = async (root) => {
    root.innerHTML = skeleton(3);

    const d = await api('wallet');

    root.innerHTML = `
        <section class="hero">
            <div class="hero-name">موجودی کیف پول</div>
            <div class="hero-sub" style="font-size:26px;font-weight:700;opacity:1;margin-bottom:14px" class="num">${fa(d.balance)} <span class="tiny">تومان</span></div>
            <div class="btn-row">
                ${d.support_link ? `<a class="btn" style="background:rgba(255,255,255,.16);color:#fff" href="${esc(d.support_link)}" target="_blank" rel="noopener">💳 درخواست شارژ</a>` : ''}
                <button class="btn" style="background:rgba(255,255,255,.16);color:#fff" data-go="payments" type="button">🧾 پرداخت‌ها</button>
            </div>
        </section>

        ${notice('info', '💡 شارژ کیف پول چطور کار می‌کند؟',
            'کیف پول توسط مدیریت و پاداش معرفی شارژ می‌شود. برای شارژ مستقیم با پشتیبانی تماس بگیرید.')}

        <div class="section-title"><span>📜 تراکنش‌ها</span></div>
        ${d.items.length ? `<div class="list">${d.items.map(txnRow).join('')}</div>`
            : empty('💸', 'تراکنشی نیست', 'هنوز تراکنشی ثبت نشده است.')}
    `;
};

function txnRow(x) {
    return `<div class="item">
        <div class="item-ico">${x.credit ? '➕' : '➖'}</div>
        <div class="item-main">
            <div class="item-title" style="color:${x.credit ? 'var(--ok)' : 'var(--bad)'}">${esc(x.text)}</div>
            <div class="item-sub">${esc(x.kind_label)}${x.note ? ' • ' + esc(clip(x.note, 28)) : ''}</div>
        </div>
        <span class="tiny">${esc(x.date)}</span>
    </div>`;
}

/* ── پرداخت‌ها ── */
views.payments = async (root) => {
    root.innerHTML = skeleton(3);

    const d = await api('payments');

    if (!d.items.length) {
        root.innerHTML = empty('🧾', 'پرداختی نیست', 'تاریخچهٔ پرداخت شما خالی است.');
        return;
    }

    root.innerHTML = `<div class="list">${d.items.map(paymentRow).join('')}</div>`;
};

function paymentRow(p) {
    return `<div class="item">
        <div class="item-ico">💳</div>
        <div class="item-main">
            <div class="item-title num">${fa(p.amount)} تومان</div>
            <div class="item-sub">${esc(p.method_label)}${p.reference ? ' • ' + esc(clip(p.reference, 14)) : ''}</div>
        </div>
        <div class="stack" style="align-items:flex-end">
            ${pill(p.status_label.includes('✅') ? 'ok' : p.status_label.includes('❌') ? 'bad' : 'warn', p.status_label)}
            <span class="tiny">${esc(p.date)}</span>
        </div>
    </div>`;
}

/* ── کانفیگ تست ── */
views.tests = async (root) => {
    root.innerHTML = skeleton(3);

    const d = await api('tests.list');

    if (d.enabled === false) {
        root.innerHTML = notice('warn', '🧪 تست کانفیگ', 'این قابلیت موقتاً غیرفعال است.');
        return;
    }

    const panels = state.boot?.panels?.items || [];

    const head = `
        <div class="card">
            <div class="card-head"><div class="card-title">🧪 کانفیگ تست جدید</div></div>
            <div class="tiny mb-12">هر کانفیگ ${esc(d.volume_text)} حجم با ${fa(d.days)} روز اعتبار روی پنل شما ساخته می‌شود.</div>
            ${panels.length ? `
                <div class="field">
                    <label>🖥 پنل</label>
                    <select class="select" id="tstPanel">
                        ${panels.map(p => `<option value="${p.id}" ${p.usable ? '' : 'disabled'}>${esc(p.username)} — ${esc(p.status.label)}</option>`).join('')}
                    </select>
                </div>
                <button class="btn btn-primary" id="tstGo" type="button">🎁 ساخت کانفیگ تست</button>`
                : `<button class="btn btn-ghost" data-go="panels" type="button">ابتدا یک پنل فعال بسازید</button>`}
        </div>`;

    const list = d.items.length
        ? `<div class="section-title"><span>📦 کانفیگ‌های من</span></div><div class="list">${d.items.map(testRow).join('')}</div>`
        : empty('📭', 'کانفیگی ندارید', 'با دکمهٔ بالا اولین کانفیگ تست را بسازید.');

    root.innerHTML = head + list;

    const go = document.getElementById('tstGo');

    if (go) {
        go.addEventListener('click', () => run(go, async () => {
            const panelId = Number(document.getElementById('tstPanel').value) || 0;
            const res = await api('test.issue', { panel_id: panelId });

            toast(res.message || 'کانفیگ ساخته شد.', 'ok');
            render('tests');
        }).catch(e => toast(e.message, 'bad')));
    }
};

function testRow(c) {
    return `<div class="item" style="flex-direction:column;align-items:stretch;gap:0">
        <div class="row-between">
            <div class="item-main">
                <div class="item-title num">${esc(c.username)}</div>
                <div class="item-sub">${c.active ? '🟢 فعال' : '⚪️ ' + esc(c.status)} • ${fa(c.days_left)} روز مانده</div>
            </div>
            ${c.active ? '<button class="btn btn-sm btn-bad" data-tst-off="' + c.id + '" type="button">غیرفعال</button>'
                       : '<span class="tiny">' + esc(c.expire_text) + '</span>'}
        </div>
        <div class="tiny mt-8">💾 ${esc(c.limit_text)} • مصرف: <span class="num">${esc(c.used_text)}</span></div>
        ${c.sub_url ? `<div class="mt-8"><button class="copy" data-copy="${esc(c.sub_url)}" type="button"><span>${esc(c.sub_url)}</span> 📋 دریافت کانفیگ</button></div>` : ''}
        ${c.active ? `<button class="btn btn-sm btn-ghost mt-8" data-tst-auto="${c.id}" type="button">🗑️ حذف خودکار: ${c.auto_delete ? 'فعال' : 'غیرفعال'}</button>` : ''}
    </div>`;
}

/* ── دعوت دوستان ── */
views.referral = async (root) => {
    root.innerHTML = skeleton(2);

    const d = await api('referral');

    root.innerHTML = `
        <section class="hero">
            <div class="hero-name">🎁 دعوت دوستان</div>
            <div class="hero-sub">دوستان شما ${fa(d.discount_percent)}٪ تخفیف می‌گیرند، شما ${esc(d.bonus_each_text)} پاداش می‌گیرید.</div>
            <div class="hero-stats">
                <div class="hero-stat"><b>${fa(d.invited)}</b><span>دعوت‌شده</span></div>
                <div class="hero-stat"><b>${fa(d.rewarded)}</b><span>پاداش‌گرفته</span></div>
                <div class="hero-stat"><b>${fa(d.level2_bonus)}</b><span>پاداش سطح ۲</span></div>
            </div>
        </section>

        <section class="card">
            <div class="card-title mb-8">🔗 لینک اختصاصی شما</div>
            <button class="copy" style="width:100%" data-copy="${esc(d.link || d.code)}" type="button">
                <span>${esc(d.link || d.code)}</span> 📋
            </button>
            <div class="hint">با لمس، لینک کپی می‌شود.</div>
            ${d.invited ? `
                <div class="btn-row mt-12">
                    <a class="btn btn-ghost" href="https://t.me/share/url?url=${encodeURIComponent(d.link)}&text=${encodeURIComponent('با این لینک عضو شو و تخفیف بگیر!')}" target="_blank" rel="noopener">📤 اشتراک‌گذاری</a>
                </div>` : ''}
        </section>

        ${d.items.length ? `
            <div class="section-title"><span>👥 دعوت‌شده‌ها</span></div>
            <div class="list">
                ${d.items.map(i => `<div class="item">
                    <div class="item-ico">${i.rewarded ? '✅' : '⏳'}</div>
                    <div class="item-main">
                        <div class="item-title">${esc(i.name)}</div>
                        <div class="item-sub">${esc(i.rewarded_text)}${i.rewarded ? ' • ' + esc(i.bonus_text) : ''}</div>
                    </div>
                    ${pill(i.rewarded ? 'ok' : 'warn', i.rewarded ? 'پاداش داده شد' : 'در انتظار')}
                </div>`).join('')}
            </div>` : ''}

        <section class="card">
            <div class="card-title mb-8">✍️ ثبت کد معرفی</div>
            <div class="inline-form">
                <input class="input" id="refCode" placeholder="R123456" autocapitalize="characters">
                <button class="btn btn-primary" id="refGo" type="button">ثبت</button>
            </div>
            <div class="hint">اگر با لینک دعوت دوستی وارد شده‌اید و کد ثبت نشده، کد او را اینجا وارد کنید.</div>
        </section>
    `;

    const go = document.getElementById('refGo');

    go.addEventListener('click', () => run(go, async () => {
        const res = await api('referral.bind', { code: document.getElementById('refCode').value });
        toast(res.message || 'ثبت شد.', 'ok');
        refreshBoot();
        render('referral');
    }).catch(e => toast(e.message, 'bad')));
};

/* ── پشتیبانی ── */
views.support = async (root) => {
    root.innerHTML = skeleton(3);

    const d = await api('tickets.list');

    if (d.ok === false) {
        root.innerHTML = notice('warn', '🎫 پشتیبانی', d.message);
        return;
    }

    const cats = (TicketCategories || []);

    root.innerHTML = `
        <section class="card">
            <div class="card-head"><div class="card-title">📝 تیکت جدید</div></div>
            <div class="field">
                <label>دسته‌بندی</label>
                <select class="select" id="tkCat">
                    ${cats.map(c => `<option value="${esc(c.key)}">${esc(c.label)}</option>`).join('')}
                </select>
            </div>
            <div class="field">
                <label>پیام</label>
                <textarea class="textarea" id="tkBody" placeholder="مشکل یا درخواست خود را بنویسید…"></textarea>
            </div>
            <button class="btn btn-primary" id="tkGo" type="button">📨 ارسال تیکت</button>
        </section>

        <div class="section-title"><span>🎫 تیکت‌های من</span></div>
        ${d.items.length ? `<div class="list">${d.items.map(ticketRow).join('')}</div>`
            : empty('📭', 'تیکتی ندارید', 'سؤال یا مشکلی دارید؟ تیکت جدید ثبت کنید.')}
    `;

    const go = document.getElementById('tkGo');

    go.addEventListener('click', () => run(go, async () => {
        const body = document.getElementById('tkBody').value.trim();
        const category = document.getElementById('tkCat').value;

        const res = await api('ticket.create', { category, body });
        toast(res.message || 'تیکت ثبت شد.', 'ok');
        refreshBoot();
        render('support');
    }).catch(e => toast(e.message, 'bad')));
};

function ticketRow(x) {
    return `<button class="item" data-open="ticket" data-id="${x.id}" type="button">
        <div class="item-ico">🎫</div>
        <div class="item-main">
            <div class="item-title ellipsis">${esc(clip(x.subject, 34))}</div>
            <div class="item-sub">${esc(x.category_label)} • ${esc(x.created_text)}</div>
        </div>
        ${pill(x.open ? (x.status === 'answered' ? 'ok' : 'warn') : 'neutral', x.status_label)}
    </button>`;
}

views.ticket = async (root, args) => {
    root.innerHTML = skeleton(2);

    const d = await api('ticket.detail', { id: args.id });

    root.innerHTML = `
        <section class="card card-tight">
            <div class="item-title mb-8">${esc(clip(d.ticket.subject, 40))}</div>
            ${d.messages.map(m => `
                <div class="bubble ${m.side === 'admin' ? 'bubble-admin' : 'bubble-user'}">
                    ${esc(m.body)}
                    <div class="bubble-meta">${esc(m.author)} • ${esc(m.text)}</div>
                </div>`).join('')}
        </section>

        ${d.ticket.open ? `
            <section class="card">
                <div class="field">
                    <textarea class="textarea" id="tkReply" placeholder="پاسخ خود را بنویسید…"></textarea>
                </div>
                <div class="btn-row">
                    <button class="btn btn-primary" id="tkSend" type="button">📤 ارسال</button>
                    <button class="btn btn-bad" id="tkClose" type="button">🔒 بستن تیکت</button>
                </div>
            </section>` : notice('ok', '🔒 این تیکت بسته شده', 'برای ادامه یک تیکت جدید ثبت کنید.')}
    `;

    const send = document.getElementById('tkSend');

    if (send) {
        send.addEventListener('click', () => run(send, async () => {
            const res = await api('ticket.reply', { id: args.id, body: document.getElementById('tkReply').value });
            toast(res.message || 'ارسال شد.', 'ok');
            render('ticket', args);
        }).catch(e => toast(e.message, 'bad')));
    }

    const closeBtn = document.getElementById('tkClose');

    if (closeBtn) {
        closeBtn.addEventListener('click', () => run(closeBtn, async () => {
            const res = await api('ticket.close', { id: args.id });
            toast(res.message || 'بسته شد.', 'ok');
            refreshBoot();
            render('ticket', args);
        }).catch(e => toast(e.message, 'bad')));
    }
};

/* ── کد تخفیف ── */
views.coupon = async (root) => {
    root.innerHTML = skeleton(2);

    const current = state.boot?.user?.coupon || '';

    root.innerHTML = `
        <section class="card">
            <div class="card-title mb-8">🎟️ کد تخفیف</div>
            ${current ? `<div class="notice notice-ok"><div>کد فعال شما: <b class="num">${esc(current)}</b></div></div>` : ''}
            <div class="field">
                <input class="input" id="cpCode" placeholder="کد تخفیف را وارد کنید" autocapitalize="characters"
                    value="${esc(current)}">
                <div class="hint">برای دیدن مبلغ کم‌شده، مبلغ سفارش را هم بنویسید (مثلاً <span class="num">SUMMER 500000</span>).</div>
            </div>
            <button class="btn btn-primary" id="cpGo" type="button">اعمال کد</button>
            ${current ? `<button class="btn btn-bad mt-8" id="cpClear" type="button">🗑️ حذف کد</button>` : ''}
        </section>
    `;

    const go = document.getElementById('cpGo');

    go.addEventListener('click', () => run(go, async () => {
        const raw = document.getElementById('cpCode').value.trim();
        const parts = raw.split(/[\s,،]+/);
        const code = parts[0] || '';
        const price = Number(String(parts[1] || '').replace(/\D/g, '')) || 0;

        const res = await api('coupon.apply', { code, price });
        toast(res.message || 'اعمال شد.', 'ok');
        refreshBoot();
        render('coupon');
    }).catch(e => toast(e.message, 'bad')));

    const clear = document.getElementById('cpClear');

    if (clear) {
        clear.addEventListener('click', () => run(clear, async () => {
            const res = await api('coupon.clear');
            toast(res.message, 'ok');
            refreshBoot();
            render('coupon');
        }).catch(e => toast(e.message, 'bad')));
    }
};

/* ── حساب ── */
views.account = async (root) => {
    const d = await api('bootstrap');
    state.boot = d;
    const u = d.user;

    root.innerHTML = `
        <section class="hero">
            <div class="hero-name">${esc(u.first_name || 'کاربر')}</div>
            <div class="hero-sub">${u.username ? '@' + esc(u.username) : 'بدون یوزرنیم'} • <span class="num">${fa(u.id)}</span></div>
            <div class="hero-stats">
                <div class="hero-stat"><b>${fa(u.wallet)}</b><span>کیف پول</span></div>
                <div class="hero-stat"><b>${fa(u.orders_count)}</b><span>سفارش</span></div>
                <div class="hero-stat"><b>${fa(u.loyalty)}</b><span>امتیاز</span></div>
            </div>
        </section>

        <section class="card">
            <div class="card-title mb-8">📊 آمار من</div>
            <div class="kv">
                ${kv('💰 مجموع خرید', '<span class="num">' + fa(u.total_paid) + '</span>')}
                ${kv('💳 موجودی کیف پول', '<span class="num">' + fa(u.wallet) + '</span>')}
                ${kv('🏆 امتیاز وفاداری', '<span class="num">' + fa(u.loyalty) + '</span>')}
                ${kv('🎯 امتیاز تا تخفیف بعدی', '<span class="num">' + fa(Math.max(0, d.app.loyalty_redeem - u.loyalty)) + '</span>')}
                ${kv('🖥 تعداد پنل', '<span class="num">' + fa(d.panels.count) + '</span>')}
            </div>
            <div class="hint mt-8">با هر خرید موفق ۱۰ امتیاز می‌گیرید؛ ${fa(d.app.loyalty_percent)}٪ تخفیف وفاداری روی خرید بعدی.</div>
        </section>

        <div class="grid">
            ${tile('💰', 'کیف پول', 'موجودی و تراکنش', 'wallet')}
            ${tile('🧾', 'پرداخت‌ها', 'تاریخچهٔ کامل', 'payments')}
            ${tile('🎟️', 'کد تخفیف', state.boot.user.coupon || 'بدون کد', 'coupon')}
            ${tile('📜', 'قوانین', 'شرایط نمایندگی', 'rules')}
        </div>
    `;
};

/* ── قوانین ──
   متن قوانین در سرور HTML است (همان متنی که ربات نشان می‌دهد). اینجا عمداً
   به‌صورت **متن ساده** نمایش داده می‌شود نه HTML: متن ربات برای پیام
   تلگرام نوشته شده و شامل تگ‌هایی مثل `<b>` است که در وب به‌صورت خام دیده
   می‌شد. escape هم این را ایمن می‌کند، هم خوانا. */
views.rules = async (root) => {
    root.innerHTML = skeleton(2);

    const d = await api('rules');
    const text = String(d.text || '').replace(/<\/?[a-z]+>/gi, '');

    root.innerHTML = `<section class="card"><div class="bubble bubble-admin">${esc(text)}</div></section>`;
};

/* ── بیشتر ── */
views.more = async (root) => {
    const d = state.boot;
    if (!d) return;

    const items = [
        ['wallet', '💰', 'کیف پول', 'موجودی و تراکنش‌ها'],
        ['payments', '🧾', 'تاریخچهٔ پرداخت', 'همهٔ پرداخت‌های شما'],
        ['coupon', '🎟️', 'کد تخفیف', d.user.coupon ? 'کد فعال: ' + d.user.coupon : 'ثبت کد جدید'],
        ['referral', '🎁', 'دعوت دوستان', fa(d.referral.invited) + ' نفر دعوت‌شده'],
        ['tests', '🧪', 'کانفیگ تست', 'ساخت یوزر کوتاه‌مدت'],
        ['support', '🎫', 'پشتیبانی', d.tickets.open ? fa(d.tickets.open) + ' تیکت باز' : 'تیکت جدید'],
        ['rules', '📜', 'قوانین', 'شرایط نمایندگی'],
        ['account', '👤', 'حساب من', 'پروفایل و آمار'],
        ['link', '🔌', 'افزودن پنل', 'اتصال پنل موجود']
    ];

    if (d.user.is_admin) {
        items.push(['admin', '🛠', 'پنل مدیریت', 'کنترل کامل سیستم']);
    }

    root.innerHTML = `
        <section class="card card-tight">
            <div class="row">
                <div class="item-ico" style="font-size:28px">👤</div>
                <div class="item-main">
                    <div class="item-title">${esc(d.user.first_name || 'کاربر')}</div>
                    <div class="item-sub">${d.user.username ? '@' + esc(d.user.username) : ''} ${d.user.is_admin ? '• 👑 مدیر' : ''}</div>
                </div>
            </div>
        </section>
        <div class="list">
            ${items.map(([r, ico, t, s]) => `
                <button class="item" data-go="${esc(r)}" type="button">
                    <div class="item-ico">${ico}</div>
                    <div class="item-main"><div class="item-title">${esc(t)}</div><div class="item-sub">${esc(s)}</div></div>
                    <div class="item-chev">‹</div>
                </button>`).join('')}
        </div>
        <button class="btn btn-ghost mt-12" id="closeApp" type="button">✖️ بستن اپلیکیشن</button>
    `;

    const close = document.getElementById('closeApp');

    if (close) {
        close.addEventListener('click', () => {
            haptic.warn();
            t().close();
        });
    }
};

/* ═══ صفحات مدیریت ═══ */

views.admin = async (root) => {
    const d = await api('admin.overview');
    renderTabs();
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    const s = d.orders;

    root.innerHTML = `
        <section class="hero">
            <div class="hero-name">🛠 پنل مدیریت</div>
            <div class="hero-sub">${esc(BOOT.store_name || 'مدیریت')}</div>
            <div class="hero-stats">
                <div class="hero-stat"><b>${fa(d.users)}</b><span>کاربر</span></div>
                <div class="hero-stat"><b>${fa(d.panels)}</b><span>پنل</span></div>
                <div class="hero-stat"><b>${fa(s.revenue)}</b><span>درآمد</span></div>
            </div>
        </section>

        <div class="stats">
            <div class="stat"><div class="stat-ico">📦</div><b>${fa(s.total)}</b><span>کل سفارش</span></div>
            <div class="stat"><div class="stat-ico">⏳</div><b>${fa(s.awaiting)}</b><span>در انتظار</span></div>
            <div class="stat"><div class="stat-ico">✅</div><b>${fa(s.applied)}</b><span>اجرا شده</span></div>
            <div class="stat"><div class="stat-ico">⚠️</div><b>${fa(s.failed)}</b><span>ناموفق</span></div>
            <div class="stat"><div class="stat-ico">🎫</div><b>${fa(d.tickets.open)}</b><span>تیکت باز</span></div>
            <div class="stat"><div class="stat-ico">🎟️</div><b>${fa(d.coupons.active)}</b><span>کد فعال</span></div>
        </div>

        <div class="grid">
            ${tile('📦', 'سفارش‌ها', fa(s.total), 'adminOrders')}
            ${tile('👥', 'کاربران', fa(d.users), 'adminUsers')}
            ${tile('🖥', 'پنل‌ها', fa(d.panels), 'adminPanels')}
            ${tile('🎁', 'بسته‌ها', 'مدیریت فروشگاه', 'adminPackages')}
            ${tile('🎟️', 'کدهای تخفیف', fa(d.coupons.total), 'adminCoupons')}
            ${tile('🎫', 'تیکت‌ها', fa(d.tickets.open) + ' باز', 'adminTickets')}
            ${tile('⚙️', 'سوییچ‌ها', 'روشن/خاموش', 'adminFlags')}
            ${tile('⚠️', 'در معرض خطر', fa(d.risk.length), 'adminRisk')}
            ${tile('📋', 'لاگ حسابرسی', 'سابقهٔ عملیات', 'adminAudit')}
        </div>

        ${d.risk.length ? `
            <div class="section-title"><span>⚠️ نیازمند تمدید</span>
                <button class="btn btn-sm btn-ghost" data-go="adminRisk" type="button">همه</button></div>
            <div class="list">
                ${d.risk.slice(0, 4).map(r => `<button class="item" data-go="adminUsers" type="button">
                    <div class="item-ico">👤</div>
                    <div class="item-main">
                        <div class="item-title">${esc(r.name)}</div>
                        <div class="item-sub">${fa(r.panels)} پنل • ${fa(r.expired)} منقضی</div>
                    </div>
                    <span class="tiny">${esc(r.soonest)}</span>
                </button>`).join('')}
            </div>` : ''}
    `;
};

views.adminOrders = async (root, args) => {
    root.innerHTML = skeleton(4);

    const page = args.page || 0;
    const d = await api('admin.orders', { status: args.status || '', page });

    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    const filters = [['', 'همه'], ['awaiting_payment', 'در انتظار'], ['paid', 'پرداخت‌شده'], ['failed', 'ناموفق'], ['applied', 'اجرا شده']];

    root.innerHTML = `
        <div class="chips">
            ${filters.map(([k, l]) => `<button class="chip ${(args.status || '') === k ? 'is-active' : ''}" data-adm-filter="${k}" type="button">${esc(l)}</button>`).join('')}
        </div>
        <div class="list">
            ${d.items.length ? d.items.map(o => `
                <button class="item" data-open="adminOrder" data-id="${o.id}" type="button">
                    <div class="item-ico">${o.needs_review ? '🔔' : '📦'}</div>
                    <div class="item-main">
                        <div class="item-title ellipsis">${esc(clip(o.title, 26))}</div>
                        <div class="item-sub">${esc(o.customer)} • <span class="num">${esc(o.code)}</span> • ${esc(o.price_text)}</div>
                    </div>
                    ${pill(o.status.tone, o.status.label)}
                </button>`).join('') : empty('📭', 'سفارشی نیست', 'با این فیلتر سفارشی وجود ندارد.')}
        </div>
        ${d.pages > 1 ? pager(page, d.pages, 'adminOrders', { status: args.status || '' }) : ''}
    `;

    root.querySelectorAll('[data-adm-filter]').forEach(b => b.addEventListener('click', () => {
        go('adminOrders', { status: b.dataset.admFilter, page: 0 }, { force: true });
    }));
};

function pager(page, pages, route, extra) {
    const btn = (p, label, disabled) => `<button class="btn btn-sm ${disabled ? 'btn-ghost' : ''}" data-page="${p}" type="button" ${disabled ? 'disabled' : ''}>${label}</button>`;
    return `<div class="btn-row mt-12">
        ${btn(page - 1, '‹ قبلی', page <= 0)}
        <span class="btn btn-sm btn-ghost" style="pointer-events:none">${fa(page + 1)} از ${fa(pages)}</span>
        ${btn(page + 1, 'بعدی ›', page >= pages - 1)}
    </div>`;
}

views.adminOrder = async (root, args) => {
    root.innerHTML = skeleton(3);

    const d = await api('admin.order', { id: args.id });
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    const o = d.order;

    root.innerHTML = `
        <section class="card">
            <div class="card-head">
                <div class="card-title">📦 ${esc(clip(o.title, 26))}</div>
                ${pill(o.status.tone, o.status.label)}
            </div>
            <div class="kv">
                ${kv('🔖 کد', '<span class="num">' + esc(o.code) + '</span>')}
                ${kv('👤 مشتری', esc(d.customer.name) + ' (<span class="num">' + fa(d.customer.telegram_id) + '</span>)')}
                ${kv('💵 پرداختی', '<span class="num">' + fa(o.price) + '</span>')}
                ${kv('💳 روش', esc(o.payment_method || '—'))}
                ${kv('📅 ثبت', esc(o.created_text))}
                ${o.paid_text ? kv('✅ پرداخت', esc(o.paid_text)) : ''}
            </div>
            ${o.error ? notice('bad', '⚠️ خطا', o.error) : ''}
        </section>

        ${d.can_review ? `
            <section class="card">
                <div class="card-title mb-8">🧾 بررسی پرداخت</div>
                ${o.has_receipt ? '' : notice('warn', '⚠️ رسیدی ثبت نشده', 'تأیید دستی فقط برای سفارش کارت‌به‌کارت با رسید معتبر است.')}
                <div class="field">
                    <label>یادداشت (اختیاری)</label>
                    <input class="input" id="admNote" placeholder="مثلاً: شمارهٔ پیگیری صحیح است">
                </div>
                <div class="btn-row">
                    <button class="btn btn-ok" data-rev="1" type="button">✅ تأیید و اجرا</button>
                    <button class="btn btn-bad" data-rev="0" type="button">❌ رد پرداخت</button>
                </div>
            </section>` : ''}

        ${d.can_retry ? `<button class="btn btn-primary" data-retry="1" type="button">⚙️ اجرای دستی بسته</button>` : ''}

        ${d.payments.length ? `
            <div class="section-title"><span>💳 تلاش‌های پرداخت</span></div>
            <div class="list">${d.payments.map(paymentRow).join('')}</div>` : ''}

        ${d.logs.length ? `
            <div class="section-title"><span>📜 لاگ اجرا</span></div>
            <section class="card card-tight">
                ${d.logs.map(l => `<div class="row-between" style="padding:6px 0;border-bottom:1px solid var(--tg-sep)">
                    <span class="tiny">${esc(l.text)}</span><span class="tiny">${esc(clip(l.message, 36))}</span>
                </div>`).join('')}
            </section>` : ''}
    `;

    root.querySelectorAll('[data-rev]').forEach(b => b.addEventListener('click', () => run(b, async () => {
        const approved = b.dataset.rev === '1';
        const note = document.getElementById('admNote').value;

        const res = await api('admin.order.review', { id: args.id, approved, note });
        toast(res.message || 'انجام شد.', 'ok');
        render('adminOrder', args);
    }).catch(e => toast(e.message, 'bad'))));

    const retry = root.querySelector('[data-retry]');
    if (retry) retry.addEventListener('click', () => run(retry, async () => {
        const res = await api('admin.order.retry', { id: args.id });
        toast(res.message || 'اجرا شد.', 'ok');
        render('adminOrder', args);
    }).catch(e => toast(e.message, 'bad')));
};

views.adminUsers = async (root, args) => {
    root.innerHTML = skeleton(4);

    const page = args.page || 0;
    const q = args.q || '';
    const d = await api('admin.users', { q, page });

    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = `
        <div class="field">
            <input class="input" id="admSearch" placeholder="جست‌وجوی نام، یوزر یا آیدی…" value="${esc(q)}">
        </div>
        <div class="list">
            ${d.items.length ? d.items.map(u => `
                <button class="item" data-open="adminUser" data-id="${u.id}" type="button">
                    <div class="item-ico">${u.blocked ? '⛔️' : '👤'}</div>
                    <div class="item-main">
                        <div class="item-title">${esc(u.name || 'کاربر')} ${u.username ? '<span class="tiny">@' + esc(u.username) + '</span>' : ''}</div>
                        <div class="item-sub">${fa(u.panels)} پنل • ${fa(u.orders)} سفارش • <span class="num">${fa(u.wallet)}</span> تومان</div>
                    </div>
                </button>`).join('') : empty('🔍', 'یافت نشد', 'کاربری با این مشخصات نیست.')}
        </div>
        ${d.pages > 1 ? pager(page, d.pages, 'adminUsers', { q }) : ''}
    `;

    let timer;

    document.getElementById('admSearch').addEventListener('input', (ev) => {
        clearTimeout(timer);
        const value = ev.target.value;

        timer = setTimeout(() => go('adminUsers', { q: value, page: 0 }, { force: true }), 380);
    });
};

views.adminUser = async (root, args) => {
    root.innerHTML = skeleton(3);

    const d = await api('admin.user', { id: args.id });
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    const u = d.user;

    root.innerHTML = `
        <section class="card">
            <div class="card-head">
                <div class="card-title">👤 ${esc(u.name || 'کاربر')}</div>
                ${u.blocked ? pill('bad', 'مسدود') : pill('ok', 'فعال')}
            </div>
            <div class="kv">
                ${kv('🆔 آیدی', '<span class="num">' + fa(u.telegram_id) + '</span>')}
                ${kv('@ یوزر', u.username ? '<span class="num">' + esc(u.username) + '</span>' : '—')}
                ${kv('💰 کیف پول', '<span class="num">' + fa(u.wallet) + '</span>')}
                ${kv('🏆 امتیاز', '<span class="num">' + fa(u.loyalty) + '</span>')}
                ${kv('📦 سفارش‌ها', '<span class="num">' + fa(u.orders) + '</span>')}
                ${kv('💵 مجموع خرید', '<span class="num">' + fa(u.total_paid) + '</span>')}
                ${kv('📅 عضویت', esc(u.joined))}
                ${u.reason ? kv('⛔️ دلیل مسدودی', esc(u.reason)) : ''}
            </div>
        </section>

        <section class="card">
            <div class="card-title mb-8">💰 شارژ کیف پول</div>
            <div class="inline-form mb-8">
                <input class="input num" id="admAmt" placeholder="مبلغ به تومان">
                <button class="btn btn-primary" id="admAdd" type="button">➕</button>
            </div>
            <div class="field">
                <input class="input" id="admAmtNote" placeholder="یادداشت (مثلاً: پاداش معرفی)">
            </div>
            <button class="btn btn-bad" id="admSub" type="button">➖ کسر مبلغ (وارد کنید و بزنید)</button>
            <div class="hint">مبلغ منفی هم پذیرفته می‌شود؛ برای کسر، عدد را با «−» وارد کنید.</div>
        </section>

        <div class="btn-row">
            <button class="btn ${u.blocked ? 'btn-ok' : 'btn-bad'}" data-blk="${u.blocked ? '0' : '1'}" type="button">
                ${u.blocked ? '✅ رفع مسدودی' : '⛔️ مسدود کردن'}
            </button>
        </div>

        ${d.panels.length ? `
            <div class="section-title"><span>🖥 پنل‌ها</span></div>
            <div class="list">${d.panels.map(panelCard).join('')}</div>` : ''}

        ${d.wallet_txns.length ? `
            <div class="section-title"><span>📜 تراکنش‌ها</span></div>
            <div class="list">${d.wallet_txns.map(txnRow).join('')}</div>` : ''}

        ${d.orders.length ? `
            <div class="section-title"><span>📦 سفارش‌ها</span></div>
            <div class="list">${d.orders.map(orderRow).join('')}</div>` : ''}
    `;

    const add = document.getElementById('admAdd');

    add.addEventListener('click', () => run(add, async () => {
        const amount = Number(document.getElementById('admAmt').value.replace(/\D/g, '')) || 0;
        const note = document.getElementById('admAmtNote').value;

        const res = await api('admin.user.wallet', { id: args.id, amount, note });
        toast(res.message + ' (موجودی: ' + fa(res.balance) + ')', 'ok');
        render('adminUser', args);
    }).catch(e => toast(e.message, 'bad')));

    const sub = document.getElementById('admSub');

    sub.addEventListener('click', () => run(sub, async () => {
        const raw = document.getElementById('admAmt').value.replace(/[^\d-]/g, '');
        const amount = -Math.abs(parseInt(raw, 10) || 0);

        if (!amount) return toast('مبلغ را وارد کنید.', 'bad');

        const res = await api('admin.user.wallet', { id: args.id, amount, note: document.getElementById('admAmtNote').value });
        toast(res.message + ' (موجودی: ' + fa(res.balance) + ')', 'ok');
        render('adminUser', args);
    }).catch(e => toast(e.message, 'bad')));

    root.querySelector('[data-blk]').addEventListener('click', (ev) => run(ev.currentTarget, async () => {
        const blocked = ev.currentTarget.dataset.blk === '1';
        let reason = '';

        if (blocked) {
            reason = prompt('دلیل مسدودی (اختیاری):') || '';
        }

        const res = await api('admin.user.block', { id: args.id, blocked, reason });
        toast(res.message, 'ok');
        render('adminUser', args);
    }).catch(e => toast(e.message, 'bad')));
};

views.adminPanels = async (root, args) => {
    root.innerHTML = skeleton(4);

    const page = args.page || 0;
    const d = await api('admin.panels', { page });

    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = `
        <div class="list">
            ${d.items.map(p => `
                <div class="item" style="flex-direction:column;align-items:stretch;gap:0">
                    <div class="row-between">
                        <div class="item-main">
                            <div class="item-title">${esc(p.username)}</div>
                            <div class="item-sub">مالف: <span class="num">${fa(p.owner_telegram_id)}</span> • ${esc(p.source)}</div>
                        </div>
                        ${pill(p.status.tone, p.status.label)}
                    </div>
                    <div class="tiny mt-8">💾 ${esc(p.traffic.limit_text)} • مصرف <span class="num">${esc(p.traffic.used_text)}</span></div>
                    ${p.traffic.percent !== null ? bar(p.traffic.percent) : ''}
                    <div class="btn-row mt-8">
                        <button class="btn btn-sm btn-ghost" data-psync="${p.id}" type="button">🔄 سینک</button>
                        <button class="btn btn-sm btn-bad" data-pcut="${p.id}" data-puser="${esc(p.username)}" type="button">✂️ قطع دسترسی</button>
                    </div>
                </div>`).join('')}
        </div>
        ${d.pages > 1 ? pager(page, d.pages, 'adminPanels', {}) : ''}
    `;

    root.querySelectorAll('[data-psync]').forEach(b => b.addEventListener('click', () => run(b, async () => {
        const res = await api('admin.panel.sync', { id: b.dataset.psync });
        toast(res.message, 'ok');
        render('adminPanels', args);
    }).catch(e => toast(e.message, 'bad'))));

    root.querySelectorAll('[data-pcut]').forEach(b => b.addEventListener('click', async () => {
        const id = b.dataset.pcut;
        const name = b.dataset.puser;

        try {
            const preview = await api('admin.panel.cutoff', { id });

            sheet.open('پیش‌نمایش قطع دسترسی',
                `<div class="notice notice-bad"><div><b>⚠️ عملیات برگشت‌پذیر نیست</b>
                 با تأیید شما، همهٔ کاربران فعال پنل <b>${esc(name)}</b> غیرفعال می‌شوند.</div></div>
                 <div class="tiny mb-12">${fa(preview.count)} کاربر در پیش‌نمایش:</div>
                 <div class="list">${(preview.users || []).map(u =>
                     `<div class="item"><div class="item-ico">${u.off ? '⛔️' : '🟢'}</div>
                      <div class="item-main"><div class="item-title num">${esc(u.username)}</div>
                      <div class="item-sub">${esc(u.status)}</div></div></div>`).join('')}</div>
                 <div class="divider"></div>
                 <div class="btn-row">
                    <button class="btn btn-bad" id="cutYes" type="button">✂️ تأیید و اجرا</button>
                    <button class="btn btn-ghost" id="cutNo" type="button">انصراف</button>
                 </div>`);

            document.getElementById('cutNo').addEventListener('click', () => sheet.close());

            document.getElementById('cutYes').addEventListener('click', (ev) => run(ev.currentTarget, async () => {
                const res = await api('admin.panel.cutoff', { id, confirm: true });
                sheet.close();
                toast(res.message, res.ok ? 'ok' : 'bad');
            }).catch(e => { sheet.close(); toast(e.message, 'bad'); }));
        } catch (e) {
            toast(e.message, 'bad');
        }
    }));
};

views.adminPackages = async (root) => {
    root.innerHTML = skeleton(4);

    const d = await api('admin.packages');
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = `
        <button class="btn btn-primary mb-12" id="pkgNew" type="button">➕ بستهٔ جدید</button>
        <div class="list">
            ${d.items.map(p => `
                <div class="item" style="flex-direction:column;align-items:stretch;gap:0">
                    <div class="row-between">
                        <div class="item-main">
                            <div class="item-title">${esc(p.title)}</div>
                            <div class="item-sub">${esc(p.kind_label)} • ${fa(p.min_price)} تومان • ${esc(p.volume_text)}</div>
                        </div>
                        ${p.is_active ? pill('ok', 'فعال') : pill('bad', 'غیرفعال')}
                    </div>
                    <div class="btn-row mt-8">
                        <button class="btn btn-sm btn-ghost" data-ptog="${p.id}" type="button">${p.is_active ? '⛔️ غیرفعال' : '✅ فعال'}</button>
                        <button class="btn btn-sm btn-ghost" data-pedit='${esc(JSON.stringify(p))}' type="button">✏️ ویرایش</button>
                        <button class="btn btn-sm btn-bad" data-pdel="${p.id}" type="button">🗑️</button>
                    </div>
                </div>`).join('')}
        </div>
    `;

    const editor = (p) => `
        <div class="field"><label>عنوان</label><input class="input" id="fTitle" value="${esc(p?.title || '')}"></div>
        <div class="field"><label>توضیح</label><input class="input" id="fDesc" value="${esc(p?.description || '')}"></div>
        <div class="field"><label>نوع</label>
            <select class="select" id="fKind">
                <option value="agency" ${p?.kind === 'agency' ? 'selected' : ''}>پنل نمایندگی</option>
                <option value="topup" ${p?.kind === 'topup' ? 'selected' : ''}>شارژ پنل</option>
            </select></div>
        <div class="field"><label>حجم (GB)</label><input class="input num" id="fVol" value="${p?.volume_gb ?? 0}"></div>
        <div class="field"><label>هدیه (GB)</label><input class="input num" id="fBonus" value="${p?.bonus_gb ?? 0}"></div>
        <div class="field"><label>مدت (روز)</label><input class="input num" id="fDays" value="${p?.duration_days ?? 30}"></div>
        <div class="field"><label>قیمت (تومان)</label><input class="input num" id="fPrice" value="${p?.min_price ?? 0}"></div>
        <div class="field"><label>قیمت‌های چنددوره (اختیاری)</label>
            <input class="input num" id="fPrices" placeholder="30:500000,90:1350000" value="${esc(p?.periods?.map(o => o.days + ':' + o.price).join(',') || '')}">
            <div class="hint">قالب: روز:قیمت، جدا شده با کاما.خالی یعنی فقط مدت پایه.</div></div>
        <div class="field"><label>سقف کاربران (۰ = نامحدود)</label><input class="input num" id="fUsers" value="${p?.max_users ?? 0}"></div>
        <div class="field"><label>سقف خرید هر کاربر (۰ = نامحدود)</label><input class="input num" id="fMax" value="${p?.max_per_user ?? 0}"></div>
        <div class="setting">
            <div class="setting-main"><div class="setting-label">فعال</div></div>
            <button class="switch ${p ? (p.is_active ? 'is-on' : '') : 'is-on'}" id="fActive" type="button"></button>
        </div>
        <button class="btn btn-primary" id="fSave" type="button">💾 ذخیره</button>
    `;

    const openEditor = (p) => {
        sheet.open(p ? 'ویرایش بسته' : 'بستهٔ جدید', editor(p));

        let active = p ? p.is_active : true;
        const sw = document.getElementById('fActive');

        sw.addEventListener('click', () => {
            active = !active;
            sw.classList.toggle('is-on', active);
            haptic.tap();
        });

        document.getElementById('fSave').addEventListener('click', (ev) => run(ev.currentTarget, async () => {
            const payload = {
                id: p?.id || 0,
                title: document.getElementById('fTitle').value,
                description: document.getElementById('fDesc').value,
                kind: document.getElementById('fKind').value,
                volume_gb: document.getElementById('fVol').value,
                bonus_gb: document.getElementById('fBonus').value,
                duration_days: document.getElementById('fDays').value,
                price_toman: document.getElementById('fPrice').value,
                prices: document.getElementById('fPrices').value,
                max_users: document.getElementById('fUsers').value,
                max_per_user: document.getElementById('fMax').value,
                is_active: active
            };

            await api('admin.package.save', payload);
            sheet.close();
            toast('ذخیره شد.', 'ok');
            render('adminPackages');
        }).catch(e => toast(e.message, 'bad')));
    };

    document.getElementById('pkgNew').addEventListener('click', () => openEditor(null));

    root.querySelectorAll('[data-pedit]').forEach(b => b.addEventListener('click', () => {
        try { openEditor(JSON.parse(b.dataset.pedit)); } catch (e) { toast('اطلاعات بسته ناقص است.', 'bad'); }
    }));

    root.querySelectorAll('[data-ptog]').forEach(b => b.addEventListener('click', () => run(b, async () => {
        await api('admin.package.toggle', { id: b.dataset.ptog });
        render('adminPackages');
    }).catch(e => toast(e.message, 'bad'))));

    root.querySelectorAll('[data-pdel]').forEach(b => b.addEventListener('click', () => run(b, async () => {
        if (!confirm('این بسته حذف شود؟')) return;

        const res = await api('admin.package.delete', { id: b.dataset.pdel });
        toast(res.message || 'حذف شد.', 'ok');
        render('adminPackages');
    }).catch(e => toast(e.message, 'bad'))));
};

views.adminCoupons = async (root) => {
    root.innerHTML = skeleton(4);

    const d = await api('admin.coupons');
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = `
        <button class="btn btn-primary mb-12" id="cpNew" type="button">➕ کد جدید</button>
        <div class="list">
            ${d.items.map(c => `
                <div class="item" style="flex-direction:column;align-items:stretch;gap:0">
                    <div class="row-between">
                        <div class="item-main">
                            <div class="item-title num">${esc(c.code)}</div>
                            <div class="item-sub">${esc(c.kind_label)} ${fa(c.value)}${c.kind === 'percent' ? '٪' : ' تومان'} • ${fa(c.used_count)} از ${fa(c.max_uses || '∞')}</div>
                        </div>
                        ${c.is_active ? pill('ok', 'فعال') : pill('bad', 'خاموش')}
                    </div>
                    ${c.expires_at ? `<div class="tiny mt-8">⏳ انقضا: ${esc(c.expires_text)}</div>` : ''}
                    <div class="btn-row mt-8">
                        <button class="btn btn-sm btn-bad" data-cdel="${c.id}" type="button">🗑️ حذف</button>
                    </div>
                </div>`).join('')}
        </div>
    `;

    document.getElementById('cpNew').addEventListener('click', () => {
        sheet.open('کد تخفیف جدید', `
            <div class="field"><label>کد (خالی = خودکار)</label><input class="input" id="cCode" autocapitalize="characters"></div>
            <div class="field"><label>نوع</label>
                <select class="select" id="cKind"><option value="percent">درصدی</option><option value="fixed">مبلغ ثابت</option></select></div>
            <div class="field"><label>مقدار</label><input class="input num" id="cVal" placeholder="۱۰ یا ۵۰۰۰۰"></div>
            <div class="field"><label>سقف کل استفاده (۰ = نامحدود)</label><input class="input num" id="cMax" value="0"></div>
            <div class="field"><label>سقف هر کاربر (۰ = نامحدود)</label><input class="input num" id="cPer" value="1"></div>
            <div class="field"><label>حداقل سفارش (تومان)</label><input class="input num" id="cMin" value="0"></div>
            <div class="field"><label>سقف تخفیف (۰ = نامحدود)</label><input class="input num" id="cCap" value="0"></div>
            <div class="field"><label>یادداشت</label><input class="input" id="cNote"></div>
            <button class="btn btn-primary" id="cSave" type="button">💾 ذخیره</button>
        `);

        document.getElementById('cSave').addEventListener('click', (ev) => run(ev.currentTarget, async () => {
            await api('admin.coupon.save', {
                code: document.getElementById('cCode').value,
                kind: document.getElementById('cKind').value,
                value: document.getElementById('cVal').value,
                max_uses: document.getElementById('cMax').value,
                per_user_limit: document.getElementById('cPer').value,
                min_order: document.getElementById('cMin').value,
                max_discount: document.getElementById('cCap').value,
                note: document.getElementById('cNote').value,
                is_active: true
            });

            sheet.close();
            toast('ذخیره شد.', 'ok');
            render('adminCoupons');
        }).catch(e => toast(e.message, 'bad')));
    });

    root.querySelectorAll('[data-cdel]').forEach(b => b.addEventListener('click', () => run(b, async () => {
        if (!confirm('این کد حذف شود؟')) return;

        await api('admin.coupon.delete', { id: b.dataset.cdel });
        toast('حذف شد.', 'ok');
        render('adminCoupons');
    }).catch(e => toast(e.message, 'bad'))));
};

views.adminTickets = async (root) => {
    root.innerHTML = skeleton(4);

    const d = await api('admin.tickets');
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = d.items.length
        ? `<div class="list">${d.items.map(x => `
            <button class="item" data-open="adminTicket" data-id="${x.id}" type="button">
                <div class="item-ico">🎫</div>
                <div class="item-main">
                    <div class="item-title ellipsis">${esc(clip(x.subject, 30))}</div>
                    <div class="item-sub">${esc(x.name)} • ${esc(x.category_label)} • ${esc(x.created_text)}</div>
                </div>
                ${pill(x.status === 'answered' ? 'ok' : 'warn', x.status_label)}
            </button>`).join('')}</div>`
        : empty('📭', 'تیکت بازی نیست', 'همه تیکت‌ها بسته شده‌اند.');
};

views.adminTicket = async (root, args) => {
    root.innerHTML = skeleton(2);

    const d = await api('admin.ticket', { id: args.id });
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = `
        <section class="card card-tight">
            <div class="item-title mb-8">${esc(clip(d.ticket.subject, 40))}</div>
            ${d.messages.map(m => `
                <div class="bubble ${m.side === 'admin' ? 'bubble-admin' : 'bubble-user'}">
                    ${esc(m.body)}
                    <div class="bubble-meta">${esc(m.author)} • ${esc(m.text)}</div>
                </div>`).join('')}
        </section>

        <section class="card">
            <div class="field"><label>پاسخ مدیر</label>
                <textarea class="textarea" id="adReply" placeholder="پاسخ خود را بنویسید…"></textarea></div>
            <div class="btn-row">
                <button class="btn btn-primary" id="adSend" type="button">📤 ارسال پاسخ</button>
                <a class="btn btn-ghost" href="https://t.me/${fa(d.customer.telegram_id)}" target="_blank" rel="noopener">💬 پیام مستقیم</a>
            </div>
        </section>
    `;

    const send = document.getElementById('adSend');

    send.addEventListener('click', () => run(send, async () => {
        const res = await api('admin.ticket.reply', { id: args.id, body: document.getElementById('adReply').value });
        toast(res.message || 'ارسال شد.', 'ok');
        render('adminTicket', args);
    }).catch(e => toast(e.message, 'bad')));
};

const FLAG_LABELS = {
    bot: 'ربات فعال است', card2card: 'کارت‌به‌کارت دستی', autocard: 'کارت‌به‌کارت خودکار',
    nowpayments: 'ارز دیجیتال', renewal: 'تمدید بسته', panel_sync: 'همگام‌سازی پنل‌ها',
    test_config: 'تست کانفیگ', cutoff: 'قطع دسترسی پس از انقضا', channel: 'عضویت اجباری کانال',
    coupons: 'کد تخفیف', referral: 'سیستم معرفی', tickets: 'پشتیبانی تیکتی',
    shop: 'فروشگاه باز است', auto_apply: 'اجرای خودکار بسته',
    report_daily: 'گزارش روزانه', report_weekly: 'گزارش هفتگی'
};

views.adminFlags = async (root) => {
    root.innerHTML = skeleton(4);

    const d = await api('admin.flags');
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    const set = await api('admin.settings');
    if (set.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', set.message));

    root.innerHTML = `
        <div class="section-title"><span>⚙️ سوییچ‌ها</span></div>
        <section class="card">
            ${Object.entries(d.flags).map(([k, v]) => `
                <div class="setting">
                    <div class="setting-main">
                        <div class="setting-label">${esc(FLAG_LABELS[k] || k)}</div>
                        ${d.gateways[k] ? `<div class="setting-hint">${d.gateways[k].configured ? 'پیکربندی‌شده' : '⚠️ در کانفیگ تنظیم نشده'}</div>` : ''}
                    </div>
                    <button class="switch ${v ? 'is-on' : ''}" data-flag="${esc(k)}" type="button"></button>
                </div>`).join('')}
        </section>

        <div class="section-title"><span>🔢 تنظیمات عددی</span></div>
        <section class="card">
            ${set.items.map(s => `
                <div class="setting">
                    <div class="setting-main">
                        <div class="setting-label">${esc(s.label)}</div>
                        <div class="setting-hint">محدوده: ${fa(s.min)} تا ${fa(s.max)}</div>
                    </div>
                    <input class="input num" type="number" value="${s.value}" min="${s.min}" max="${s.max}" data-set="${esc(s.key)}">
                    <button class="btn btn-sm btn-ghost" data-ssave="${esc(s.key)}" type="button">💾</button>
                </div>`).join('')}
        </section>
    `;

    root.querySelectorAll('[data-flag]').forEach(sw => sw.addEventListener('click', () => run(sw, async () => {
        const res = await api('admin.flag.toggle', { key: sw.dataset.flag });
        toast(res.message || 'تغییر کرد.', res.ok ? 'ok' : 'bad');
        sw.classList.toggle('is-on', sw.classList.contains('is-on') ? false : true);
    }).catch(e => { toast(e.message, 'bad'); render('adminFlags'); })));

    root.querySelectorAll('[data-ssave]').forEach(b => b.addEventListener('click', () => run(b, async () => {
        const key = b.dataset.ssave;
        const input = root.querySelector(`[data-set="${CSS.escape(key)}"]`);
        const res = await api('admin.setting.save', { key, value: input.value });
        toast(res.message, 'ok');
    }).catch(e => toast(e.message, 'bad'))));
};

views.adminRisk = async (root) => {
    root.innerHTML = skeleton(3);

    const d = await api('admin.risk');
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = d.items.length ? `
        <div class="notice notice-warn"><div>این نمایندگان پنل منقضی یا رو به انقضا دارند. تمدید نکردن یعنی قطع دسترسی مشتریانشان.</div></div>
        <div class="list">
            ${d.items.map(r => `
                <button class="item" data-go="adminUsers" type="button">
                    <div class="item-ico">${r.expired > 0 ? '⛔️' : '⏳'}</div>
                    <div class="item-main">
                        <div class="item-title">${esc(r.name)}</div>
                        <div class="item-sub">${fa(r.panels)} پنل • ${fa(r.expired)} منقضی • <span class="num">${fa(r.telegram_id)}</span></div>
                    </div>
                    <span class="tiny">${esc(r.soonest)}</span>
                </button>`).join('')}
        </div>` : empty('✅', 'خبری نیست', 'هیچ نماینده‌ای پنل رو به اتمام ندارد.');
};

views.adminAudit = async (root) => {
    root.innerHTML = skeleton(4);

    const d = await api('admin.audit');
    if (d.forbidden) return (root.innerHTML = notice('bad', '⛔️ دسترسی', d.message));

    root.innerHTML = d.items.length ? `
        <section class="card">
            <table class="table">
                <thead><tr><th>زمان</th><th>عملیات</th><th>هدف</th><th>جزئیات</th></tr></thead>
                <tbody>${d.items.map(x => `<tr>
                    <td class="tiny">${esc(x.text)}</td>
                    <td class="tiny">${esc(x.action)}</td>
                    <td class="tiny num">${esc(x.target)}</td>
                    <td class="tiny">${esc(clip(x.details, 22))}</td>
                </tr>`).join('')}</tbody>
            </table>
        </section>` : empty('📋', 'لاگ خالی است', 'هنوز عملیات مدیریتی ثبت نشده است.');
};

/* ═══ ۶) روتر ═══ */

async function render(name, args = {}, opts = {}) {
    const view = views[name];

    if (!view) return go('home', {}, { force: true });

    // نسل رندر. هر رندر یک شماره می‌گیرد و بعد از `await` بررسی می‌کند که هنوز
    // تازه‌ترین است. بدون این، دو ناوبریِ همزمان (مثلاً bootstrap خودکار خانه
    // که دیر جواب می‌دهد، بعد از اینکه کاربر «فروشگاه» را زده) هرکدام دیرتر
    // تمام می‌شود و **نتیجهٔ قدیمی‌تر روی صفحه می‌نشیند** — کاربر فروشگاه را
    // می‌زند و ناگهان دوباره خانه را می‌بیند.
    const gen = ++state.gen;

    const isStale = () => gen !== state.gen;

    // مدیریت: تب‌بار در بخش مدیریت مخفی می‌شود چون ناوبری کاربر معنی ندارد.
    const isAdminZone = name.startsWith('admin');
    el.tabbar.hidden = isAdminZone;

    el.appbarTitle.textContent = title(name);
    el.back.style.visibility = (state.history.length > 0 || !TABS.includes(name)) ? 'visible' : 'hidden';
    el.appbar.hidden = false;
    el.content.hidden = false;

    renderTabs();

    try {
        await view(el.content, args);

        // رندرِ کهنه شده: نتیجه‌اش را دور می‌ریزیم و اجازه می‌دهیم رندرِ
        // تازه‌تر صفحه را بگذارد.
        if (isStale()) return;

        // اسکرول **بعد** از رندر: اگر قبلش صدا زده شود، مرورگر ارتفاع محتوای
        // تازه را هنوز حساب نکرده و کاربر وسط صفحه با نوار بالای بریده می‌ماند.
        requestAnimationFrame(() => window.scrollTo(0, 0));
    } catch (e) {
        if (isStale()) return;

        if (e && e.fatal) {
            fatal(e.message);
            return;
        }

        el.content.innerHTML = notice('bad', '⚠️ خطا', e.message || 'بارگذاری این بخش ممکن نشد.')
            + '<button class="btn btn-ghost" id="retry">🔄 تلاش دوباره</button>';

        const retry = document.getElementById('retry');
        if (retry) retry.addEventListener('click', () => render(name, args, opts));
    }
}

/** خطای مرگبار: فقط یک بار، با راه بازگشت. */
function fatal(message) {
    el.boot.classList.remove('is-gone');
    el.bootHint.textContent = message;
    el.bootHint.classList.add('is-error');
    el.appbar.hidden = true;
    el.content.hidden = true;
    el.tabbar.hidden = true;
}

/** بارگذاری دوبارهٔ bootstrap (بعد از تغییر کد تخفیف/معرفی). */
async function refreshBoot() {
    try {
        state.boot = await api('bootstrap');
    } catch (e) {
        /* bootstrap دوباره فقط برای به‌روزرسانی شمارنده‌هاست؛ خطایش نباید UX را خراب کند. */
    }
}

/**
 * دکمهٔ بروزرسانی نوار بالا.
 *
 * هر دو لایه را تازه می‌کند: `bootstrap` (شمارنده‌های خانه و کیف پول) و
 * صفحهٔ فعلی. فقط صفحه را تازه کردن کافی نیست — مثلاً بعد از پرداخت، هم
 * کیف پول و هم فهرست سفارش‌ها باید درست شوند ولی صفحهٔ فعلی یکی از آن‌هاست.
 */
async function refresh() {
    el.refresh.classList.add('is-spinning');
    haptic.impact();

    try {
        state.cache = {};

        if (!sheet.isOpen()) {
            await refreshBoot();
        }

        await render(state.route, state.args);
    } catch (e) {
        toast(e.message || 'بروزرسانی نشد.', 'bad');
    } finally {
        el.refresh.classList.remove('is-spinning');
    }
}

/* ── Delegation: یک شنونده برای همهٔ کلیک‌ها ── */
document.addEventListener('click', (ev) => {
    // ناوبری پایین. اول بررسی می‌شود چون دکمه‌های تب هم `data-tab` دارند
    // و نباید با هیچ‌کدام از قواعد پایین‌تر اشتباه شوند.
    const tabBtn = ev.target.closest('.tab[data-tab]');

    if (tabBtn) {
        haptic.tap();
        openTab(tabBtn.dataset.tab);
        return;
    }

    // کپی به کلیپ‌بورد
    const copy = ev.target.closest('[data-copy]');
    if (copy) {
        const value = copy.dataset.copy;
        copyText(value);
        return;
    }

    // لینک‌های خارجی (فاکتور، کانال، شارژ کیف پول)
    const linkBtn = ev.target.closest('a[href]');
    if (linkBtn && linkBtn.href) {
        ev.preventDefault();
        try {
            t().openLink(linkBtn.href);
        } catch (e) {
            // اگر در Telegram نبود، لینک را در مرورگر باز کن
            window.open(linkBtn.href, '_blank');
        }
        haptic.tap();
        return;
    }

    const goBtn = ev.target.closest('[data-go]');
    if (goBtn) {
        haptic.tap();
        go(goBtn.dataset.go);
        return;
    }

    const openBtn = ev.target.closest('[data-open]');
    if (openBtn) {
        haptic.tap();
        detail(openBtn.dataset.open, { id: Number(openBtn.dataset.id) });
        return;
    }

    const shopBtn = ev.target.closest('[data-shop]');
    if (shopBtn) {
        go('shop', { kind: shopBtn.dataset.shop }, { force: true });
        return;
    }

    const buyBtn = ev.target.closest('[data-buy]');
    if (buyBtn) {
        state.days = 0;
        detail('package', { id: Number(buyBtn.dataset.buy) });
        return;
    }

    const topup = ev.target.closest('[data-topup]');
    if (topup) {
        // شارژ: کاربر مستقیم به بسته‌های شارژ می‌رود و پنل را با خود می‌برد.
        go('shop', { kind: 'topup' });
        return;
    }

    const filter = ev.target.closest('[data-filter]');
    if (filter) {
        state.orderFilter = filter.dataset.filter;
        render('orders');
        return;
    }

    const pageBtn = ev.target.closest('[data-page]');
    if (pageBtn && !pageBtn.disabled) {
        const p = Number(pageBtn.dataset.page);
        const extra = {};

        if (state.args.status !== undefined) extra.status = state.args.status;
        if (state.args.q !== undefined) extra.q = state.args.q;

        go(state.route, { ...state.args, ...extra, page: p }, { force: true });
        return;
    }

    const testOff = ev.target.closest('[data-tst-off]');
    if (testOff) {
        run(testOff, async () => {
            const res = await api('test.disable', { id: Number(testOff.dataset.tstOff) });
            toast(res.message, 'ok');
            render('tests');
        }).catch(e => toast(e.message, 'bad'));
        return;
    }

    const testAuto = ev.target.closest('[data-tst-auto]');
    if (testAuto) {
        run(testAuto, async () => {
            const res = await api('test.autodelete', {
                id: Number(testAuto.dataset.tstAuto),
                op: testAuto.textContent.includes('فعال') ? 'off' : 'on'
            });
            toast(res.message, 'ok');
            render('tests');
        }).catch(e => toast(e.message, 'bad'));
        return;
    }

    // اکشن‌های داخلی صفحهٔ پنل/سفارش
    const act = ev.target.closest('[data-act]');

    if (act) {
        const btn = act;

        run(btn, async () => {
            const what = btn.dataset.act;
            const id = Number(state.args.id || 0);

            if (what === 'sync') {
                const res = await api('panel.sync', { id });
                toast(res.message, 'ok');
                render('panel', state.args);
            } else if (what === 'stats') {
                const res = await api('panel.stats', { id, force: true });

                sheet.open('آمار کاربران پنل',
                    `<div class="stats">
                        <div class="stat"><div class="stat-ico">👥</div><b>${fa(res.stats.total)}</b><span>کل</span></div>
                        <div class="stat"><div class="stat-ico">🟢</div><b>${fa(res.stats.active)}</b><span>فعال</span></div>
                        <div class="stat"><div class="stat-ico">⛔️</div><b>${fa(res.stats.disabled)}</b><span>غیرفعال</span></div>
                     </div>
                     ${bar(res.stats.percent)}
                     <div class="tiny center">${fa(res.stats.percent)}٪ کاربران فعال هستند</div>`);

                render('panel', state.args);
            } else if (what === 'users') {
                const res = await api('panel.users', { id, limit: 12 });

                sheet.open('کاربران پنل',
                    (res.users || []).length
                        ? `<div class="list">${res.users.map(u => `<div class="item">
                            <div class="item-ico">${u.off ? '⛔️' : '🟢'}</div>
                            <div class="item-main">
                                <div class="item-title num">${esc(u.username)}</div>
                                <div class="item-sub">${esc(u.limit_text)} • مصرف ${esc(u.used_text)}</div>
                            </div>
                            <span class="tiny">${esc(u.expire_text)}</span>
                          </div>`).join('')}</div>`
                        : empty('👥', 'کاربری نیست', 'این پنل هنوز کاربری ندارد.'));
            } else if (what === 'test') {
                const res = await api('test.issue', { panel_id: id });
                toast(res.message, 'ok');
                render('panel', state.args);
            } else if (what === 'subscribe') {
                const res = await api('panel.subscribe', { id });
                toast(res.message, 'ok');
                render('panel', state.args);
            } else if (what === 'receipt') {
                render('order', { id });
            }
        }).catch(e => toast(e.message, 'bad'));
    }
});

async function copyText(value) {
    try {
        await navigator.clipboard.writeText(value);
        toast('کپی شد ✅', 'ok');
    } catch (e) {
        // مرورگرهای درون‌کلیکی تلگرام گاهی Clipboard API را نمی‌دهند.
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();

        try { document.execCommand('copy'); toast('کپی شد ✅', 'ok'); }
        catch (e2) { toast('کپی نشد؛ دستی انتخاب کنید.', 'bad'); }

        ta.remove();
    }
}

/* ── دسته‌بندی تیکت (کش می‌شود بعد از اولین bootstrap) ── */
let TicketCategories = [];

/* ═══ راه‌اندازی ═══ */

async function boot() {
    applyTheme();

    const api2 = t();

    api2.ready();
    api2.expand();
    api2.disableVerticalSwipes();

    try { api2.setHeaderColor(api2.colorScheme === 'light' ? '#f2f4f9' : '#0b1020'); } catch (e) {}
    try { api2.setBackgroundColor(api2.colorScheme === 'light' ? '#f2f4f9' : '#0b1020'); } catch (e) {}

    api2.onEvent?.('themeChanged', applyTheme);

    // دکمهٔ بازگشت تلگرام با تاریخچهٔ داخلی همگام می‌شود تا یک رفتار واحد
    // در همهٔ صفحه‌ها دیده شود.
    api2.BackButton.onClick(back);

    el.back.addEventListener('click', back);
    el.refresh.addEventListener('click', () => refresh());

    document.getElementById('sheetClose').addEventListener('click', () => sheet.close());
    document.getElementById('sheetBackdrop').addEventListener('click', () => sheet.close());

    // اگر اسکریپت تلگرام لود نشده یا initData خالی است، یعنی صفحه در مرورگر
    // عادی (یا مرورگر درون‌برنامه‌ای بدون WebApp) باز شده — نه از دکمهٔ
    // web_app داخل ربات. در این حالت initData نداریم و همهٔ درخواست‌های API
    // با 401 رد می‌شوند؛ پس زود و با پیام واضح می‌ایستیم.
    if (!tg || !t().initData) {
        fatal('این برنامه فقط داخل تلگرام کار می‌کند. لطفاً از دکمهٔ «📱 اپلیکیشن وب» داخل همین ربات بازش کنید و لینک را در مرورگر کپی نکنید.');
        return;
    }

    el.bootHint.textContent = 'در حال دریافت اطلاعات…';

    try {
        state.boot = await api('bootstrap');
    } catch (e) {
        fatal(e.message || 'اتصال برقرار نشد.');
        return;
    }

    const d = state.boot;

    // پاسخ bootstrap شامل دسته‌بندی تیکت نیست؛ از endpoint پشتیبانی
    // می‌گیریمش تا فرم تیکت بدون درخواست اضافه هم کار کند.
    try {
        const sup = await api('support');
        TicketCategories = sup.categories || [];
    } catch (e) {
        TicketCategories = [
            { key: 'other', label: '📩 سایر موارد' },
            { key: 'panel', label: '🖥 مشکل پنل یا ورود' },
            { key: 'payment', label: '💳 مشکل پرداخت' }
        ];
    }

    // دروازهٔ کانال: تا عضو نشده، فقط همین صفحه.
    if (d.channel?.required && !d.channel.member) {
        renderChannelGate();
    } else if (!d.app.bot_enabled) {
        renderNotice(d.app.disabled_notice, 'ربات موقتاً غیرفعال است');
    } else if (d.needs_referral && d.ref_code) {
        // پارامتر شروع = کد معرفی (لینک دعوت با `?startapp=R123`). خودکار
        // ثبتش می‌کنیم تا کاربر لازم نباشد چیزی تایپ کند — دعوت باید یک
        // کلیک باشد، نه یک فرم.
        try {
            const res = await api('referral.bind', { code: d.ref_code });
            toast(res.message || 'کد معرفی ثبت شد.', 'ok');
            await refreshBoot();
        } catch (e) {
            toast(e.message, 'bad');
        }
    }

    el.boot.classList.add('is-gone');
    el.appbar.hidden = false;
    el.content.hidden = false;
    el.tabbar.hidden = false;

    await render('home');
}

/* مسیریاب برنامه هنگام اجرا (console و پیش‌نمایش). */
globalThis.__webapp = { state, views, go, back, refresh, openTab, render, api, sheet, toast, applyTheme };

function renderChannelGate() {
    const d = state.boot;

    el.appbar.hidden = true;
    el.tabbar.hidden = true;
    el.content.hidden = false;

    el.content.innerHTML = `
        <div class="card" style="margin-top:40px;text-align:center">
            <div style="font-size:46px;margin-bottom:10px">📢</div>
            <h3 style="font-size:17px;margin-bottom:8px">عضویت در کانال الزامی است</h3>
            <p class="muted mb-12">برای استفاده از خدمات باید در کانال <b>${esc(d.channel.title)}</b> عضو باشید.</p>
            ${d.channel.invite_link ? `<a class="btn btn-primary" href="${esc(d.channel.invite_link)}" target="_blank" rel="noopener">عضویت در کانال</a>` : ''}
            <button class="btn btn-ghost mt-8" id="recheck" type="button">✅ بررسی مجدد</button>
        </div>
    `;

    document.getElementById('recheck').addEventListener('click', () => run(null, async () => {
        state.boot = await api('bootstrap');

        if (state.boot.channel?.member) {
            el.appbar.hidden = false;
            el.tabbar.hidden = false;
            toast('عضویت تأیید شد ✅', 'ok');
            render('home');
        } else {
            toast('هنوز عضو نشده‌اید.', 'bad');
        }
    }).catch(e => toast(e.message, 'bad')));
}

function renderNotice(text, title) {
    el.appbar.hidden = true;
    el.tabbar.hidden = true;
    el.content.hidden = false;

    el.content.innerHTML = `<div class="card" style="margin-top:40px">
        <div class="card-title mb-8">${esc(title)}</div>
        <div class="muted">${esc(text)}</div>
    </div>`;
}

/* شروع */
boot().catch((e) => fatal(e && e.message ? e.message : 'خطای ناشناخته'));

})();
