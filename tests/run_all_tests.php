<?php
// tests/run_all_tests.php - Master Verification Test Runner for RMS SaaS Extensions
echo "=================================================================\n";
echo "       RMS SAAS ENTERPRISE EXTENSIONS MASTER TEST RUNNER        \n";
echo "=================================================================\n\n";

$tests = [
    'Phase 1: Restaurant Settings & Granular RBAC' => __DIR__ . '/phase1_settings_rbac_test.php',
    'Phase 2: Modifiers, Bill Splits, Voids & Refunds' => __DIR__ . '/phase2_modifiers_split_refund_test.php',
    'Phase 3: CRM, Reservations, Expenses & Shift Reconciliation' => __DIR__ . '/phase3_crm_reservations_expenses_shifts_test.php',
    'Phase 4: Loyalty Program, Executive Analytics & Audit Logs' => __DIR__ . '/phase4_loyalty_analytics_audit_test.php'
];

foreach ($tests as $title => $script) {
    echo "Executing $title...\n";
    system("php " . escapeshellarg($script), $retval);
    if ($retval !== 0) {
        echo "\n❌ FAILED: Test suite '$title' failed with return code $retval.\n";
        exit(1);
    }
    echo "\n";
}

echo "=================================================================\n";
echo "  🎉 ALL 4 EXTENSION PHASES PASSED 100% WITH ZERO ERRORS!       \n";
echo "=================================================================\n";
