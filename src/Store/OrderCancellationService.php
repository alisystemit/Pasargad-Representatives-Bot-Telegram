<?php

declare(strict_types=1);

namespace Pasargad\Store;

use Pasargad\Support\Db;

/**
 * لغو سفارش‌های پرداخت‌نشده و آزادسازی دارایی‌های رزروشده.
 *
 * سفارش در لحظهٔ ساخت، کد تخفیف و امتیاز وفاداری را «رزرو» می‌کند تا دو
 * درخواست هم‌زمان نتوانند از یک کد/امتیاز دو بار استفاده کنند. اگر سفارش
 * هرگز پرداخت نشود، این سرویس همان رزروها را برمی‌گرداند.
 */
final class OrderCancellationService
{
    private Db $db;
    private OrderRepository $orders;
    private CouponRepository $coupons;
    private UserRepository $users;

    public function __construct(
        ?Db $db = null,
        ?OrderRepository $orders = null,
        ?CouponRepository $coupons = null,
        ?UserRepository $users = null
    ) {
        $this->db      = $db ?? Db::instance();
        $this->orders  = $orders ?? new OrderRepository($this->db);
        $this->coupons = $coupons ?? new CouponRepository($this->db);
        $this->users   = $users ?? new UserRepository($this->db);
    }

    /**
     * لغو یک سفارش و برگرداندن امتیاز/کد تخفیف.
     *
     * کل عملیات در یک تراکنش است: اگر برگرداندن دارایی خطا بدهد، وضعیت
     * سفارش هم برنمی‌گردد تا وضعیت «لغو شده ولی دارایی برنگشته» باقی نماند.
     *
     * @param array<string, mixed> $order
     */
    public function cancel(array $order, string $reason = 'cancelled'): bool
    {
        $orderId = (int) ($order['id'] ?? 0);

        if ($orderId <= 0) {
            return false;
        }

        $cancelled = false;

        $this->db->transaction(function () use ($orderId, $reason, &$cancelled): void {
            // compare-and-swap: اگر هم‌زمان پرداخت ثبت شده باشد، اینجا false
            // می‌شود و هیچ دارایی‌ای آزاد نمی‌شود.
            if (!$this->orders->cancel($orderId, $reason)) {
                return;
            }

            $fresh = $this->orders->find($orderId);

            if ($fresh === null) {
                return;
            }

            $points = (int) ($fresh['loyalty_points_used'] ?? 0);

            if ($points > 0) {
                $this->users->addLoyaltyPoints((int) $fresh['user_id'], $points);
            }

            $this->coupons->releaseForOrder($orderId);
            $cancelled = true;
        });

        return $cancelled;
    }

    /**
     * لغو خودکار سفارش‌های پرداخت‌نشدهٔ قدیمی.
     *
     * @return int تعداد سفارش‌های لغوشده
     */
    public function cancelStale(int $minutes, int $limit = 50): int
    {
        $count = 0;

        foreach ($this->orders->staleUnpaid($minutes, $limit) as $order) {
            if ($this->cancel($order, 'expired')) {
                $count++;
            }
        }

        return $count;
    }
}
