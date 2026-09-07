<?php
namespace App\Services;

class OrderStateMachine {

    private const ALLOWED_TRANSITIONS = [
        'new' => ['confirmed', 'preparing', 'cancelled'],
        'confirmed' => ['preparing', 'ready', 'cancelled'],
        'preparing' => ['ready', 'completed', 'cancelled'],
        'ready' => ['completed', 'waiting_bill', 'paid'],
        'waiting_bill' => ['paid', 'completed', 'cancelled'],
        'completed' => ['waiting_bill', 'paid', 'closed'],
        'paid' => ['closed'],
        'closed' => [],
        'cancelled' => []
    ];

    /**
     * Validate whether an order state transition is legal.
     */
    public static function canTransition(string $currentStatus, string $targetStatus): bool {
        $current = strtolower(trim($currentStatus));
        $target = strtolower(trim($targetStatus));

        if ($current === $target) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [];
        return in_array($target, $allowed, true);
    }

    /**
     * Assert legal order state transition or throw an Exception.
     */
    public static function assertTransition(string $currentStatus, string $targetStatus): void {
        if (!self::canTransition($currentStatus, $targetStatus)) {
            throw new \InvalidArgumentException("Invalid order state transition from '{$currentStatus}' to '{$targetStatus}'.");
        }
    }
}
