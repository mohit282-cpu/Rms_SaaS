<?php
namespace App\Services;

class TableStateMachine {

    private const VALID_STATES = ['vacant', 'occupied', 'waiting_bill', 'cleaning', 'disabled'];

    private const ALLOWED_TRANSITIONS = [
        'vacant' => ['occupied', 'cleaning', 'disabled'],
        'occupied' => ['waiting_bill', 'cleaning', 'vacant'],
        'waiting_bill' => ['cleaning', 'vacant', 'occupied'],
        'cleaning' => ['vacant', 'disabled', 'occupied'],
        'disabled' => ['vacant', 'cleaning']
    ];

    /**
     * Check if a status string is valid table state.
     */
    public static function isValidState(string $status): bool {
        return in_array(strtolower(trim($status)), self::VALID_STATES, true);
    }

    /**
     * Validate whether a table status transition is legal.
     */
    public static function canTransition(string $currentStatus, string $targetStatus): bool {
        $current = strtolower(trim($currentStatus));
        $target = strtolower(trim($targetStatus));

        if ($current === $target) {
            return true;
        }

        if (!self::isValidState($target)) {
            return false;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? self::VALID_STATES;
        return in_array($target, $allowed, true);
    }

    /**
     * Assert legal table status transition or throw an Exception.
     */
    public static function assertTransition(string $currentStatus, string $targetStatus): void {
        if (!self::canTransition($currentStatus, $targetStatus)) {
            throw new \InvalidArgumentException("Invalid table status transition from '{$currentStatus}' to '{$targetStatus}'.");
        }
    }
}
