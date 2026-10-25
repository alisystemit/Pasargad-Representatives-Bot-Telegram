<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Panel\PanelException;
use Pasargad\Panel\PasarGuardClient;
use Pasargad\Support\Config;
use Pasargad\Support\Logger;
use Pasargad\Support\Str;

/**
 * ساخت حساب اپراتور (ادمین پنل) برای نمایندهٔ جدید.
 *
 * وقتی کاربری بستهٔ «پنل نمایندگی» می‌خرد، ربات یک حساب ادمین تازه
 * می‌سازد و نقش آن را روی اپراتور می‌گذارد. از این لحظه نماینده از داخل
 * خودِ پنل دسترسی عملیاتی دارد (ساخت کاربر برای مشتریانش، دیدن آمار و…).
 *
 * ## ساخت روی کدام پنل؟
 *
 * اولویت با **پنلی است که خودِ کاربر وصل کرده** (`$creator`)، و اگر
 * نداشته باشد به اکانت owner سراسریِ `config.php` برمی‌گردیم.
 *
 * چ��یی که قبلاً اینجا اشتباه بود: خرید همیشه روی `panel.base_url` و با
 * اکانت owner انجام می‌شد. یعنی نماینده‌ای که پنلش را روی دامنهٔ خودش
 * نصب کرده و وصل کرده بود، بسته می‌خرید و پنلش زیر **سرور دیگری** ساخته
 * می‌شد — یعنی خریدش به کارش نمی‌آمد.
 *
 * چرا ربات این کار را می‌کند و خودِ نماینده؟
 *   • رمز باید یک‌بار تولید و به نماینده داده شود؛ ساخت دستی در پنل، رمز را
 *     در اختیار مدیر پنل می‌گذارد نه نماینده.
 *   • حجم/زمان خریداری‌شده باید همان لحظه روی همان حساب بنشیند.
 *   • ثبت آن در جدول panels لازم است تا هشدار حجم/انقضا و قطع دسترسی کاربران
 *     بعداً ممکن باشد.
 */
final class AgencyService
{
    private ?PasarGuardClient $panel;

    /**
     * آیا کلاینت از بیرون تزریق شده است؟
     *
     * کلاینت تزریق‌شده (کلاینت جعلیِ تست‌ها، یا کلاینتی که وبهوک با بودجهٔ
     * زمانی ساخته) **هرگز** نباید دور ریخته شود. قبلاً `useCreatorPanel()`
     * همیشه `$this->panel` را صفر می‌کرد و در نتیجه تست‌ها به‌جای کلاینت
     * جعلی، به پنل واقعی وصل می‌شدند و همه‌شان با «رمز اشتباه» می‌افتادند.
     */
    private bool $clientInjected = false;

    /**
     * منبع اختیار ساخت پنل: ردیف `panels` خودِ خریدار.
     *
     * @var array<string, mixed>|null
     */
    private ?array $creator = null;

    private PanelAccess $access;

    public function __construct(?PasarGuardClient $panel = null, ?PanelAccess $access = null)
    {
        $this->panel          = $panel;
        $this->clientInjected = $panel !== null;
        $this->access         = $access ?? new PanelAccess();
    }

    /**
     * تعیین می‌کند پنل نمایندگی روی **کدام** پنل ساخته شود.
     *
     * @param  array<string, mixed>|null $panel ردیف `panels` خریدار؛ null یعنی
     *                                      از اکانت owner سراسری استفاده شود.
     */
    public function useCreatorPanel(?array $panel): self
    {
        $this->creator = $panel;

        // کلاینتی که خودمان ساخته‌ایم با پنل قبلی ساخته شده و با تغییر
        // مقصد بی‌معنی می‌شود؛ ولی کلاینت تزریق‌شده دست‌نخورده می‌ماند.
        if (!$this->clientInjected) {
            $this->panel = null;
        }

        return $this;
    }

    private function panelClient(): PasarGuardClient
    {
        if ($this->panel === null) {
            // اولویت با پنلی است که خریدار وصل کرده.
            if ($this->creator !== null) {
                $client = $this->access->clientFor($this->creator);

                if ($client !== null) {
                    return $this->panel = $client;
                }
            }

            $this->panel = new PasarGuardClient();
        }

        return $this->panel;
    }

    /**
     * اکانتی که با آن ادمین جدید ساخته می‌شود.
     *
     * بدون این اکانت، خرید «پنل نمایندگی» قابل اجرا نیست — ولی خرید
     * «شارژ پنل» کار می‌کند چون از اکانت خودِ نماینده استفاده می‌کند.
     *
     * فقط از **پنل‌های سوپر ادمین‌ها** برای ساخت پنل نمایندگی استفاده می‌شود.
     * اگر هیچ سوپر ادمینی پنل وصل نکرده باشد، سیستم کار نمی‌کند.
     *
     * @return array{0:string, 1:string}|null [authUsername, apiKey]
     */
    private function ownerCredentials(): ?array
    {
        // فقط سوپر ادمین‌ها می‌توانند پنل نمایندگی بسازند
        // ابتدا پنل‌های سوپر ادمین‌ها را پیدا می‌کنیم
        $superAdminPanels = $this->findSuperAdminPanels();
        
        if (empty($superAdminPanels)) {
            return null;
        }
        
        // از اولین پنل سوپر ادمین استفاده می‌کنیم
        $panel = $superAdminPanels[0];
        $credentials = $this->access->credentialsOf($panel);

        if ($credentials === null) {
            return null;
        }

        // در مسیر کلید، نام کاربری بی‌معنی است؛ خودِ کلید نقش را دارد و
        // `panelClient()` آن را به‌عنوان توکن ثابت ست می‌کند.
        return ['api', $credentials['credential']];
    }
    
    /**
     * پنل‌های سوپر ادمین‌ها را پیدا می‌کند.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findSuperAdminPanels(): array
    {
        $superAdmins = Config::arr('super_admins', []);
        
        if (empty($superAdmins)) {
            return [];
        }
        
        $db = \Pasargad\Support\Db::instance();
        $placeholders = implode(',', array_fill(0, count($superAdmins), '?'));
        
        // پیدا کردن کاربران سوپر ادمین
        $superAdminUsers = $db->all(
            "SELECT id FROM users WHERE telegram_id IN ($placeholders)",
            array_map('intval', $superAdmins)
        );
        
        if (empty($superAdminUsers)) {
            return [];
        }
        
        $userIds = array_column($superAdminUsers, 'id');
        $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
        
        // پیدا کردن پنل‌های سوپر ادمین‌ها
        return $db->all(
            "SELECT * FROM panels WHERE user_id IN ($userPlaceholders) AND api_key IS NOT NULL AND api_key != ''",
            $userIds
        );
    }

    /**
     * آیا ربات می‌تواند پنل نمایندگی بفروشد؟
     *
     * @return array{ok:bool, message:string}
     */
    public function canCreatePanels(): array
    {
        if ($this->ownerCredentials() !== null) {
            return ['ok' => true, 'message' => ''];
        }

        // بررسی می‌کنیم آیا سوپر ادمینی وجود دارد یا نه
        $superAdmins = Config::arr('super_admins', []);
        
        if (empty($superAdmins)) {
            return [
                'ok'      => false,
                'message' => 'هیچ سوپر ادمینی در تنظیمات تعریف نشده است!'
                    . "\nلطفاً در <code>config.php</code> مقدار <code>super_admins</code> را تنظیم کنید.",
            ];
        }
        
        // بررسی می‌کنیم آیا سوپر ادمین‌ها پنل وصل کرده‌اند یا نه
        $superAdminPanels = $this->findSuperAdminPanels();
        
        if (empty($superAdminPanels)) {
            return [
                'ok'      => false,
                'message' => 'هیچ سوپر ادمینی پنل خود را به ربات وصل نکرده است! ⛔️🔧'
                    . "\n\nیکی از سوپر ادمین‌ها باید:"
                    . "\n1️⃣ از منوی «⚙️ تنظیمات» گزینه «🔗 اتصال پنل جدید» را انتخاب کند"
                    . "\n2️⃣ آدرس پنل و کلید API خود را وارد کند"
                    . "\n3️⃣ پس از تأیید اتصال، سیستم آماده ساخت پنل نمایندگی خواهد بود",
            ];
        }

        return [
            'ok'      => false,
            'message' => 'پنل‌های سوپر ادمین‌ها فاقد کلید API معتبر هستند.'
                . "\nلطفاً سوپر ادمین‌ها دوباره پنل خود را با کلید API وصل کنند.",
        ];
    }

    /**
     * ساخت کلید API برای ادمینِ تازه‌ساخته.
     *
     * ⭐️ **چرا `inherit_permissions = true`**
     *
     * کلید تازه **مجوزهای کلید سازنده را به ارث می‌برد**. یعنی اگر کلیدِ ربات
     * `admins.update` نداشته باشد، کلیدِ پنل نمایندگی هم نخواهد داشت و **شارژ
     * آن پنل برای همیشه ۴۰۳ می‌دهد** — یعنی خرید موفق، سرویس ناکارآمد.
     *
     * این آگاهانه انتخاب شده: دادن مجوزِ صریحِ بیشتر از آنچه کلیدِ سازنده دارد
     * یا پنل رد می‌کند یا به ارتقای دسترسی تبدیل می‌شود که ناامن است. پس
     * درست‌تر کار این است که کلیدِ سازنده کامل باشد — و دقیقاً برای همین
     * `tools/panel_key_check.php` همهٔ مجوزهای لازم را می‌سنجد.
     *
     * نام کلید محدود و قابل‌تشخیص است تا اگر مدیر پنل وارد پنل شد، بداند این
     * کلید مال ربات است و بتواند باطلش کند.
     *
     * @return string کلید، یا رشتهٔ خالی اگر ساخته نشد
     */
    private function issueApiKeyFor(PasarGuardClient $client, int $adminId, string $username, string $parentKey): string
    {
        if ($adminId <= 0) {
            Logger::warning('Cannot issue panel api key: panel gave no admin id', ['username' => $username]);

            return '';
        }

        try {
            $response = $client->createApiKey([
                'name'                => 'pasargad-bot-' . $username,
                'admin_id'            => $adminId,
                'inherit_permissions' => true,
                'note'                => 'ساخته‌شده توسط ربات پاسارگاد برای مدیریت خودکار',
            ], $parentKey);

            $key = trim((string) ($response['api_key'] ?? ''));

            if ($key === '') {
                Logger::warning('Panel returned no api key for new admin', [
                    'username' => $username,
                    'admin_id' => $adminId,
                ]);

                return '';
            }

            Logger::info('Panel api key issued for new agency admin', [
                'admin_id' => $adminId,
                'username' => $username,
                // یادآوری: مجوزها ارثی می‌رسند. اگر کلید سازنده ناقص باشد،
                // شارژِ همین پنل بعداً ۴۰۳ می‌دهد.
                'inherited_from_parent' => true,
            ]);

            return $key;
        } catch (\Throwable $e) {
            // پنلی که مجوز ساخت کلید ندارد اینجا می‌افتد. خرید باید ناموفق
            // گزارش شود، چون پنلی که کلید ندارد برای ربات قابل مدیریت نیست.
            Logger::warning('Could not issue panel api key', [
                'username' => $username,
                'admin_id' => $adminId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * پیدا کردن خودکار نقش اپراتور از خودِ پنل.
     *
     * @return int|null شناسهٔ نقش؛ ۰ یعنی فهرست خوانده شد ولی نقش امنی نیست؛
     *                  null یعنی فهرست خوانده نشد (فراخواننده رفتار قبلی را
     *                  حفظ می‌کند و role_id نمی‌فرستد)
     */
    private function resolveOperatorRole(PasarGuardClient $client, string $ownerUser, string $ownerPassword): ?int
    {
        try {
            $response = $client->listRoles($ownerUser, $ownerPassword, ['limit' => 50]);
        } catch (\Throwable $e) {
            Logger::warning('Could not list panel roles, role_id omitted', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $roles = $response['roles'] ?? null;

        if (!is_array($roles)) {
            return null;
        }

        // فهرست خالی یعنی endpoint کار می‌کند ولی نقشی نیست — مثل حالت
        // «فقط مالک»: ادامه بی‌فایده است و باید صریح متوقف شود.
        if ($roles === []) {
            return 0;
        }

        $fallback = 0;

        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }

            $id = (int) ($role['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            // نقش مالک (owner) هرگز — حتی اگر تنها نقش موجود باشد.
            if (!empty($role['is_owner'])) {
                continue;
            }

            if ($fallback <= 0) {
                $fallback = $id;
            }

            $name = mb_strtolower(trim((string) ($role['name'] ?? '')));

            if ($name !== '' && (
                str_contains($name, 'operator')
                || str_contains($name, 'agent')
                || str_contains($name, 'نماینده')
                || str_contains($name, 'اپراتور')
            )) {
                return $id;
            }
        }

        return $fallback;
    }

    /**
     * نام کاربری یکتا و **قطعی** برای پنل یک سفارش.
     *
     * چرا قطعی و نه تصادفی؟ چون اگر ساخت پنل روی سرور موفق شود ولی ذخیرهٔ
     * محلی شکست بخورد، تلاش مجدد باید بتواند **همان** حساب را روی پنل پیدا
     * کند و به‌جایش حساب دوم نسازد. با نام قطعی، پنل خطای «تکراری» می‌دهد و
     * مسیر بازیابی روشن می‌شود.
     */
    public function generateUsername(array $order): string
    {
        $prefix = Config::str('panel.rep_username_prefix', 'rep');

        // فقط حروف و عدد انگلیسی؛ نام کاربری پنل نباید نویسهٔ خاص داشته باشد
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'rep';
        $prefix = Str::truncate($prefix, 8, '');

        $orderId = (int) ($order['id'] ?? 0);

        if ($orderId <= 0) {
            // سفارش بدون شناسه نباید رخ دهد، ولی اگر رخ داد نامِ یکتا لازم است.
            return $prefix . random_int(100000, 999999);
        }

        return $prefix . $orderId;
    }

    /**
     * تولید رمز عبور قوی و قابل تایپ.
     *
     * از کاراکترهای مبهم (0/O، 1/l/I) پرهیز می‌شود چون نماینده این رمز را
     * می‌خواند، کپی می‌کند و داخل فرم پنل دستی تایپ می‌کند.
     *
     * ⚠️ **سیاست رمز پنل رعایت می‌شود، و این تصادفی نیست.**
     *
     * نسخهٔ قبلی فقط *یک* حرف بزرگ تضمین می‌کرد (یکی از هر دسته). ولی پنل
     * با پیام «Password must contain at least 2 uppercase letters» رد می‌کرد —
     * یعنی **هر خرید پنل نمایندگی با ۴۲۲ شکست می‌خورد** و کاربر پول داده ولی
     * چیزی گیرش نمی‌آمد. این با آزمون زنده کشف شد، نه با حدس.
     *
     * پس اینجا حاشیهٔ اطمینان گذاشته شده: دو حرف بزرگ، دو رقم، و یک نویسه.
     */
    public function generatePassword(int $length = 14): string
    {
        $length = max(12, min($length, 32));

        $sets = [
            // حروف کوچک
            'abcdefghijkmnopqrstuvwxyz',
            // حروف بزرگ
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            // ارقام
            '23456789',
            // نویسه‌ها
            '!@#$%&*?-_',
        ];

        // تعداد تضمینی هر دسته — ۲ برای حروف بزرگ و ارقام چون پنل حداقل ۲
        // حرف بزرگ می‌خواهد و برای ارقام هم سخت‌گیر است.
        $minimum = [1, 2, 2, 1];

        $all  = implode('', $sets);
        $out  = '';

        foreach ($sets as $index => $set) {
            for ($i = 0; $i < $minimum[$index]; $i++) {
                $out .= $set[random_int(0, strlen($set) - 1)];
            }
        }

        while (mb_strlen($out) < $length) {
            $out .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($out);
    }

    /**
     * ساخت حساب ادمین اپراتور و ثبت آن در جدول panels.
     *
     * @param array<string, mixed> $botUser  رکورد کاربر خریدار
     * @param array<string, mixed> $order    سفارش پرداخت‌شده
     * @return array{ok:bool, message:string, details:array<string, mixed>}
     */
    public function createPanel(array $botUser, array $order, int $panelBytes, int $durationDays): array
    {
        $guard = $this->canCreatePanels();
        if (!$guard['ok']) {
            return ['ok' => false, 'message' => $guard['message'], 'details' => ['fatal' => true]];
        }

        [$ownerUser, $ownerPassword] = $this->ownerCredentials();

        $client   = $this->panelClient();
        $userId   = (int) $botUser['id'];
        $username = $this->generateUsername($order);
        $password = $this->generatePassword();

        Logger::info('Creating agency panel', [
            'user_id' => $userId,
            // نشانیِ منبع اختیار، نه خودِ راز.
            'source'  => $this->creator !== null
                ? 'user_panel#' . (int) ($this->creator['id'] ?? 0)
                : 'global_owner',
            'auth'    => 'apikey',
        ]);

        $payload = [
            'username'   => $username,
            'password'   => $password,
            'data_limit' => $panelBytes,
            'status'     => 'active',
            'note'       => 'پنل نمایندگی — خریدار #' . $userId
                . ' / سفارش ' . (string) ($order['code'] ?? ''),
        ];

        // نقش اپراتور: طبق اسپک (AdminCreate) فیلد role_id **اجباری** است و
        // بدون آن پنل ۴۲۲ می‌دهد — یعنی خرید بدون rep_role_id همیشه ناموفق
        // بود. اگر ادمین شناسه را در config گذاشته باشد همان، وگرنه از فهرست
        // نقش‌های خودِ پنل یک نقش غیرمالک (ترجیحاً اپراتور) پیدا می‌شود.
        // نقش مالک هرگز خودکار داده نمی‌شود (ارتقای دسترسی ممنوع).
        $roleId = Config::int('panel.rep_role_id', 0);

        if ($roleId <= 0) {
            $resolved = $this->resolveOperatorRole($client, $ownerUser, $ownerPassword);

            if ($resolved === 0) {
                // فهرست خوانده شد ولی نقش امنی نیست (فقط مالک) — ادامه یعنی
                // دادن دسترسی مالک به نماینده یا ۴۲۲ قطعی؛ هر دو بدتر از توقف
                // صریح‌اند. fatal تا تلاش مجدد بیهوده هم نشود.
                return [
                    'ok'      => false,
                    'message' => 'روی پنل هیچ نقش غیرمالکی (اپراتور) پیدا نشد. '
                        . 'یک نقش اپراتور در پنل بسازید یا rep_role_id را در config.php تنظیم کنید.',
                    'details' => ['fatal' => true],
                ];
            }

            // null یعنی فهرست خوانده نشد (پنل قدیمی؟) — رفتار قبلی حفظ
            // می‌شود و role_id فرستاده نمی‌شود.
            if ($resolved !== null) {
                $roleId = $resolved;
            }
        }

        if ($roleId > 0) {
            $payload['role_id'] = $roleId;
        }

        $recovered = false;

        try {
            $admin = $client->createAdmin($payload, $ownerUser, $ownerPassword);
        } catch (PanelException $e) {
            // ------------------------------------------------------------------
            // بازیابی: اگر نام کاربری از قبل وجود دارد، یعنی تلاش قبلی روی پنل
            // موفق شده بود و فقط ذخیرهٔ محلی شکست خورده.
            //
            // نام کاربری قطعی است (rep{orderId}) پس این وضعیت قابل تشخیص و
            // قابل بازیابی است. بدون این شاخه، هر تلاش مجدد یک حساب دوم روی
            // پنل می‌ساخت و نماینده دو پنل نیمه‌کاره به دست می‌آورد.
            // ------------------------------------------------------------------
            if ($e->httpStatus() === 409 || stripos($e->getMessage(), 'exist') !== false) {
                try {
                    $admin     = $client->getAdmin($username, $ownerUser, $ownerPassword);
                    $recovered = true;

                    Logger::info('Recovered existing agency panel from panel', [
                        'order_id'  => (int) $order['id'],
                        'username'  => $username,
                    ]);
                } catch (PanelException $inner) {
                    return [
                        'ok'      => false,
                        'message' => 'ساخت پنل روی سرور ناموفق بود: ' . $e->getMessage(),
                        'details' => [],
                    ];
                }
            } else {
                Logger::error('Failed to create agency panel on panel', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);

                return [
                    'ok'      => false,
                    'message' => 'ساخت پنل روی سرور ناموفق بود: ' . $e->getMessage(),
                    'details' => [],
                ];
            }
        }

        $expireAt = $durationDays > 0 ? time() + $durationDays * 86400 : null;

        // آدرسی که واقعاً رویش ساختیم — نه آنچه در config است. این مقدار باید
        // روی ردیف تازه ثبت شود تا شارژ و بروزرسانی بعدی همان‌جا بروند.
        //
        // ⚠️ اگر خریدار پنلی وصل نکرده، عمداً **خالی** می‌ماند: تهی بودن این
        // ستون یعنی «از `panel.base_url` سراسری استفاده کن» و همین قرارداد
        // را `PanelAccess` و بقیهٔ مسیرها هم می‌فهمند. نوشتن آدرس سراسری به‌دستی
        // فقط رکورد را اضافه می‌کرد و اگر بعداً config عوض شود، پنل را به
        // سرور اشتباه می‌فرستاد.
        $createdOnBaseUrl = '';

        if ($this->creator !== null) {
            $createdOnBaseUrl = (string) ($this->access->credentialsOf($this->creator)['base_url'] ?? '');
        }

        // سقف کاربران بسته (۰ = نامحدود ♾️) — جدا از حجم و مدت، روی خود
        // پنل ثبت می‌شود تا نمایش سقف و هشدار پر شدن ممکن باشد.
        $maxUsers = max(0, (int) ($order['max_users'] ?? 0));

        $panels = new PanelRepository();

        // ⚠️ در بازیابی، رمزِ این تلاش **تازه ساخته شده و روی پنل وجود ندارد**.
        //
        // رمز فقط موقع `createAdmin` ساخته می‌شود؛ اگر آن مرحله قبلاً موفق شده
        // و تلاش بعدی به ۴۰۹ خورد، حساب روی پنل رمزِ **قبلی** را دارد. نوشتن
        // رمز جدید روی ردیف یعنی: (۱) کاربر رمزی تحویل می‌گیرد که کار نمی‌کند،
        // و (۲) رمزِ درست برای همیشه از بین می‌رود.
        //
        // این مسیر با حذف رمزِ ورودی مهم‌تر شد: ساخت کلید (`POST /api/api_key`)
        // یک مرحلهٔ تازه و قابل‌شکست است، پس «ساخت شد ولی بعدش شکست خورد» به
        // مسیری تکرارشونده تبدیل شده که هر بار رمز را خراب می‌کرد.
        if ($recovered) {
            $existing = $panels->findByPanelUsername($username, $createdOnBaseUrl);
            $stored   = $existing === null ? '' : $panels->plainPassword($existing);

            if ($stored !== '') {
                $password = $stored;
            } else {
                Logger::warning('Recovered agency panel has no stored password', [
                    'order_id' => (int) $order['id'],
                    'username' => $username,
                ]);
            }
        }

        $panelId = $panels->upsertFromPanel(
            $userId,
            $username,
            $password,
            $admin,
            PanelRepository::SOURCE_BOT,
            [],
            $createdOnBaseUrl
        );

        $panels->update($panelId, [
            'granted_volume'   => $panelBytes,
            'access_expire_at' => $expireAt,
            'user_limit'       => $maxUsers,
            'order_id'         => (int) $order['id'],
            'label'            => 'پنل ' . $username,
            'login_url'        => PanelRepository::loginUrl(),

            // ⚠️ این دو ستون حیاتی‌اند و قبلاً همیشه خالی می‌ماندند.
            //
            // اگر پنل نمایندگی روی آدرس خریدار ساخته شده باشد ولی آدرسش
            // ثبت نشود، «شارژ پنل» و «بروزرسانی از پنل» بعداً به پنل
            // *پیش‌فرض سراسری* می‌روند و روی سرور اشتباه کار می‌کنند یا ۴۰۳
            // می‌دهند — یعنی کاربر پول داده و سرویسش کار نمی‌کند.
            'base_url'         => $createdOnBaseUrl,
            // حساب تازه با کلیدِ سازنده ساخته شده، پس روش احراز هویتش هم
            // کلید است.
            'auth_mode'        => PanelAccess::MODE_API_KEY,
        ]);

        // ------------------------------------------------------------------
        // کلیدِ همین حساب تازه را می‌سازیم.
        //
        // چرا حیاتی است: ربات دیگر رمز نمی‌پرسد، پس اگر این ادمین کلید
        // نداشته باشد، پنلش بعداً نه شارژ می‌شود نه بروزرسانی، نه آمار — یعنی
        // کاربر پول داده و سرویسش کار نمی‌کند.
        //
        // اگر ساخت کلید ناموفق بود، خرید **ناموفق** گزارش می‌شود (نه موفقِ
        // ناقص): سفارش بعداً تلاش مجدد می‌شود و `AgencyService` با نام کاربریِ
        // قطعی، حسابِ ازقبل‌ساخته را بازیابی می‌کند و کلید را دوباره می‌سازد.
        // ------------------------------------------------------------------
        $issuedKey = $this->issueApiKeyFor($client, (int) ($admin['id'] ?? 0), $username, $ownerPassword);

        if ($issuedKey !== '') {
            $panels->setApiKey($panelId, $issuedKey);
        }

        Logger::info('Agency panel ready', [
            'user_id'   => $userId,
            'panel_id'  => $panelId,
            'username'  => $username,
            'bytes'     => $panelBytes,
            'max_users' => $maxUsers,
            'expire_at' => $expireAt,
            'recovered' => $recovered,
        ]);

        return [
            'ok'      => true,
            'message' => $recovered
                ? 'پنل نمایندگی از اجرای قبلی بازیابی شد.'
                : 'پنل نمایندگی ساخته شد.',
            'details' => [
                'panel_id'        => $panelId,
                'panel_username'  => $username,
                'panel_password'  => $password,
                'login_url'       => PanelRepository::loginUrl(),
                'after_limit'     => $panelBytes,
                'user_limit'      => $maxUsers,
                'expire_at'       => $expireAt,
                'recovered'       => $recovered,
                'admin_response'  => $admin,
            ],
        ];
    }
}