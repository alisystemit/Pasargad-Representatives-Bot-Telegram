<?php

declare(strict_types=1);

namespace Pasargad\Bot;

use Pasargad\Store\OrderRepository;
use Pasargad\Store\PanelRepository;
use Pasargad\Store\UserRepository;
use Pasargad\Support\Str;
use Pasargad\Telegram\Bot;

/**
 * Enhanced Admin Dashboard with real-time stats and analytics
 * - Revenue tracking
 * - Activity logs
 * - Order status updates
 * - Panel sync status
 * - Payment reconciliation
 */
final class AdminDashboard
{
    private Bot $bot;
    private UserRepository $users;
    private OrderRepository $orders;
    private PanelRepository $panels;

    public function __construct(
        Bot $bot,
        UserRepository $users,
        OrderRepository $orders,
        PanelRepository $panels
    ) {
        $this->bot = $bot;
        $this->users = $users;
        $this->orders = $orders;
        $this->panels = $panels;
    }

    /**
     * Show main dashboard
     */
    public function showDashboard(int $chatId): void
    {
        $stats = $this->getStats();

        $message = "📊 <b>داشبورد مدیریتی</b>\n";
        $message .= "═════════════════════════════════════\n\n";

        $message .= "👥 <b>کاربران:</b>\n";
        $message .= "  • کل: " . Str::faNumber($stats['total_users']) . "\n";
        $message .= "  • امروز: " . Str::faNumber($stats['users_today']) . "\n";
        $message .= "  • این ماه: " . Str::faNumber($stats['users_this_month']) . "\n\n";

        $message .= "🛍️ <b>سفارش‌ها:</b>\n";
        $message .= "  • کل: " . Str::faNumber($stats['total_orders']) . "\n";
        $message .= "  • امروز: " . Str::faNumber($stats['orders_today']) . "\n";
        $message .= "  • مجموع درآمد: " . Str::formatToman($stats['total_revenue']) . "\n\n";

        $message .= "💰 <b>درآمد:</b>\n";
        $message .= "  • امروز: " . Str::formatToman($stats['revenue_today']) . "\n";
        $message .= "  • این ماه: " . Str::formatToman($stats['revenue_this_month']) . "\n";
        $message .= "  • میانگین سفارش: " . Str::formatToman($stats['avg_order_value']) . "\n\n";

        $message .= "🖥️ <b>پنل‌ها:</b>\n";
        $message .= "  • کل: " . Str::faNumber($stats['total_panels']) . "\n";
        $message .= "  • فعال: " . Str::faNumber($stats['active_panels']) . "\n";
        $message .= "  • غیرفعال: " . Str::faNumber($stats['inactive_panels']) . "\n\n";

        $message .= "📈 <b>وضعیت:</b>\n";
        $message .= "  • موفق: " . Str::faNumber($stats['successful_orders']) . "\n";
        $message .= "  • ناموفق: " . Str::faNumber($stats['failed_orders']) . "\n";
        $message .= "  • درانتظار: " . Str::faNumber($stats['pending_orders']) . "\n";

        $keyboard = [
            [
                ['text' => '📈 نمودار', 'callback_data' => 'admin:chart'],
                ['text' => '📋 فعالیت', 'callback_data' => 'admin:activity'],
            ],
            [
                ['text' => '💳 تسویه', 'callback_data' => 'admin:reconcile'],
                ['text' => '🔄 همگام‌سازی', 'callback_data' => 'admin:sync_status'],
            ],
            [
                ['text' => '⬅️ بازگشت', 'callback_data' => 'main_menu'],
            ],
        ];

        $this->bot->sendMessage($chatId, $message, keyboard: $keyboard);
    }

    /**
     * Show revenue chart as ASCII
     */
    public function showRevenueChart(int $chatId): void
    {
        $dailyRevenue = $this->getDailyRevenue(7);

        $message = "📈 <b>درآمد ۷ روز اخیر</b>\n";
        $message .= "═════════════════════════════════════\n\n";

        $maxRevenue = max($dailyRevenue) ?: 1;

        foreach ($dailyRevenue as $date => $revenue) {
            $percent = (int) round(($revenue / $maxRevenue) * 100);
            $filled = (int) round($percent / 5);
            $bar = str_repeat('▰', $filled) . str_repeat('▱', max(0, 20 - $filled));

            $message .= sprintf(
                "%s %s %s\n",
                $date,
                $bar,
                Str::formatToman($revenue)
            );
        }

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Show activity logs
     */
    public function showActivityLog(int $chatId, int $limit = 10): void
    {
        $activities = $this->getRecentActivities($limit);

        $message = "📋 <b>فعالیت‌های اخیر</b>\n";
        $message .= "═════════════════════════════════════\n\n";

        if (empty($activities)) {
            $message .= "📭 فعالیتی در دسترس نیست";
        } else {
            foreach ($activities as $activity) {
                $time = Str::timeAgo((int) $activity['created_at']);
                $message .= "• " . $activity['action'] . " - $time\n";
            }
        }

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Show panel sync status
     */
    public function showSyncStatus(int $chatId): void
    {
        $syncStatus = $this->getPanelSyncStatus();

        $message = "🔄 <b>وضعیت همگام‌سازی پنل‌ها</b>\n";
        $message .= "═════════════════════════════════════\n\n";

        foreach ($syncStatus as $panel) {
            $status = $panel['last_sync_success'] ? '✅' : '❌';
            $lastSync = !empty($panel['last_sync_at']) 
                ? Str::timeAgo((int) $panel['last_sync_at'])
                : 'هرگز';

            $message .= "$status " . Str::escape((string) $panel['panel_username']) . "\n";
            $message .= "   └─ آخرین: $lastSync\n";
        }

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Show payment reconciliation
     */
    public function showReconciliation(int $chatId): void
    {
        $reconciliation = $this->getPaymentReconciliation();

        $message = "💳 <b>تسویه حساب</b>\n";
        $message .= "═════════════════════════════════════\n\n";

        $message .= "💰 <b>درآمد:</b>\n";
        $message .= "  • از درگاه: " . Str::formatToman($reconciliation['gateway_total']) . "\n";
        $message .= "  • ثبت‌شده: " . Str::formatToman($reconciliation['recorded_total']) . "\n";
        $message .= "  • اختلاف: " . Str::formatToman($reconciliation['difference']) . "\n\n";

        $message .= "🔍 <b>وضعیت:</b>\n";

        if (abs($reconciliation['difference']) < 1000) {
            $message .= "  ✅ متوازن - تفاوت کمتر از ۱۰۰۰ تومان\n";
        } else {
            $message .= "  ⚠️ نامتوازن - مراجعه به حسابدار\n";
        }

        $message .= "  • موفق: " . Str::faNumber($reconciliation['successful_payments']) . "\n";
        $message .= "  • ناموفق: " . Str::faNumber($reconciliation['failed_payments']) . "\n";
        $message .= "  • درانتظار: " . Str::faNumber($reconciliation['pending_payments']) . "\n";

        $this->bot->sendMessage($chatId, $message);
    }

    /**
     * Get comprehensive statistics
     */
    private function getStats(): array
    {
        $now = time();
        $todayStart = strtotime('today', $now);
        $monthStart = strtotime('first day of this month', $now);

        // Users
        $totalUsers = $this->users->count();
        $usersToday = $this->users->countCreatedAfter($todayStart);
        $usersThisMonth = $this->users->countCreatedAfter($monthStart);

        // Orders
        $totalOrders = $this->orders->count();
        $ordersToday = $this->orders->countCreatedAfter($todayStart);
        $totalRevenue = $this->orders->sumByField('price_toman');
        $revenueToday = $this->orders->sumByFieldAfter('price_toman', $todayStart);
        $revenueThisMonth = $this->orders->sumByFieldAfter('price_toman', $monthStart);

        // Order status breakdown
        $statuses = $this->orders->countByStatus();

        // Panels
        $totalPanels = $this->panels->count();
        $activePanels = $this->panels->countByStatus('active');
        $inactivePanels = $this->panels->countByStatus('inactive');

        // Calculate average
        $avgOrderValue = $totalOrders > 0 ? (int) ($totalRevenue / $totalOrders) : 0;

        return [
            'total_users' => $totalUsers,
            'users_today' => $usersToday,
            'users_this_month' => $usersThisMonth,
            'total_orders' => $totalOrders,
            'orders_today' => $ordersToday,
            'total_revenue' => $totalRevenue,
            'revenue_today' => $revenueToday,
            'revenue_this_month' => $revenueThisMonth,
            'avg_order_value' => $avgOrderValue,
            'total_panels' => $totalPanels,
            'active_panels' => $activePanels,
            'inactive_panels' => $inactivePanels,
            'successful_orders' => (int) ($statuses['applied'] ?? 0),
            'failed_orders' => (int) ($statuses['failed'] ?? 0),
            'pending_orders' => (int) ($statuses['awaiting_payment'] ?? 0),
        ];
    }

    /**
     * Get daily revenue for chart
     */
    private function getDailyRevenue(int $days = 7): array
    {
        $revenue = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dayStart = strtotime($date);
            $dayEnd = strtotime('+1 day', $dayStart);

            $total = $this->orders->sumByFieldBetween('price_toman', $dayStart, $dayEnd);
            $revenue[Str::faNumber($i) . ' روز پیش'] = $total;
        }

        return $revenue;
    }

    /**
     * Get recent activities
     */
    private function getRecentActivities(int $limit = 10): array
    {
        // This would query activity logs in a real implementation
        // For now, return order activities
        return $this->orders->fetchRecent($limit);
    }

    /**
     * Get panel sync status
     */
    private function getPanelSyncStatus(): array
    {
        return $this->panels->getAllWithSyncStatus();
    }

    /**
     * Get payment reconciliation data
     */
    private function getPaymentReconciliation(): array
    {
        $gatewayTotal = $this->orders->sumPaidByGateway();
        $recordedTotal = $this->orders->sumByStatus('applied');
        $difference = $gatewayTotal - $recordedTotal;

        $statuses = $this->orders->countByStatus();

        return [
            'gateway_total' => $gatewayTotal,
            'recorded_total' => $recordedTotal,
            'difference' => $difference,
            'successful_payments' => (int) ($statuses['paid'] ?? 0),
            'failed_payments' => (int) ($statuses['failed'] ?? 0),
            'pending_payments' => (int) ($statuses['awaiting_payment'] ?? 0),
        ];
    }

    /**
     * Export daily report
     */
    public function generateDailyReport(): string
    {
        $stats = $this->getStats();
        $now = date('Y-m-d H:i:s');

        $report = "📊 گزارش روزانه\n";
        $report .= "زمان: $now\n";
        $report .= "═════════════════════════════════════\n\n";

        $report .= "👥 کاربران: " . Str::faNumber($stats['total_users']) . "\n";
        $report .= "🛍️ سفارش‌ها: " . Str::faNumber($stats['total_orders']) . "\n";
        $report .= "💰 درآمد: " . Str::formatToman($stats['total_revenue']) . "\n";
        $report .= "🖥️ پنل‌ها: " . Str::faNumber($stats['total_panels']) . "\n";

        return $report;
    }
}
