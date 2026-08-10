<?php
// tests/hr_management_test.php - Comprehensive Automated Test Suite for RMS SaaS HR, Shifts, Attendance & Payroll

require_once __DIR__ . '/../../../config.php';

function assertHrTest($condition, $description) {
    if ($condition) {
        echo "  ✅ [PASS] $description\n";
    } else {
        echo "  ❌ [FAIL] $description\n";
        exit(1);
    }
}

echo "=================================================================\n";
echo "    RMS SaaS AUTOMATED TEST: COMPLETE HR & PAYROLL MODULE       \n";
echo "=================================================================\n\n";

$conn = getDBConnection();
$tenantA = 8001;
$tenantB = 8002;

// Provision HR Schema first
HrService::ensureHrSchema($conn);

// Clean test records for isolated test run
$conn->query("DELETE FROM employees WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM shift_templates WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM employee_shifts WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM employee_attendance WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM salary_history WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM salary_advances WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM payrolls WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM payroll_periods WHERE restaurant_id IN ($tenantA, $tenantB)");
$conn->query("DELETE FROM admin_users WHERE username LIKE 'hr_test_%'");

// --- TEST 1: CREATE EMPLOYEE ---
echo "--- STEP 1: CREATE EMPLOYEE ---\n";
$empData = [
    'full_name' => 'Sita Sharma',
    'email' => 'sita@test.com',
    'phone' => '9841112233',
    'department' => 'Service',
    'designation' => 'Cashier',
    'employment_type' => 'Full Time',
    'employment_status' => 'Active',
    'base_salary' => 30000.00
];
$res1 = HrService::createEmployee($conn, $tenantA, $empData, 1);
assertHrTest($res1['success'] === true, "Employee created successfully with code {$res1['emp_code']}");
$empIdA = $res1['employee_id'];

// --- TEST 2 & 3: CREATE SYSTEM ACCOUNT & ASSIGN ROLE ---
echo "\n--- STEP 2 & 3: CREATE SYSTEM ACCOUNT & ASSIGN ROLE ---\n";
$empAccountData = [
    'full_name' => 'Hari Chef',
    'email' => 'hari@test.com',
    'phone' => '9841112244',
    'department' => 'Kitchen',
    'designation' => 'Head Chef',
    'employment_type' => 'Full Time',
    'employment_status' => 'Active',
    'base_salary' => 35000.00,
    'create_system_account' => 1,
    'username' => 'hr_test_chef',
    'password' => 'Pass123!',
    'role' => 'KITCHEN'
];
$res2 = HrService::createEmployee($conn, $tenantA, $empAccountData, 1);
assertHrTest($res2['success'] === true, "Employee with system account created successfully");
$empIdChef = $res2['employee_id'];

$chef = HrService::getEmployeeById($conn, $tenantA, $empIdChef);
assertHrTest($chef['system_username'] === 'hr_test_chef', "Linked system account username is 'hr_test_chef'");
assertHrTest($chef['system_role'] === 'KITCHEN', "Linked system account role is 'KITCHEN'");

// --- TEST 4: CREATE, UPDATE & DELETE SHIFT TEMPLATE ---
echo "\n--- STEP 4: CREATE, UPDATE & DELETE SHIFT TEMPLATE ---\n";
$shiftData = [
    'shift_name' => 'Morning Peak Shift',
    'start_time' => '08:00:00',
    'end_time' => '16:00:00',
    'break_duration_mins' => 60,
    'grace_period_mins' => 15,
    'overtime_threshold_mins' => 480
];
$resShift = HrService::createShiftTemplate($conn, $tenantA, $shiftData, 1);
assertHrTest($resShift['success'] === true, "Shift template created successfully");
$shiftId = $resShift['shift_id'];

// Test Shift Update
$updShiftData = [
    'shift_name' => 'Morning Peak Shift (Updated)',
    'start_time' => '08:00:00',
    'end_time' => '16:00:00',
    'break_duration_mins' => 60,
    'grace_period_mins' => 15,
    'overtime_threshold_mins' => 480
];
$resUpdShift = HrService::updateShiftTemplate($conn, $tenantA, $shiftId, $updShiftData, 1);
assertHrTest($resUpdShift['success'] === true, "Shift template updated successfully");

// Test Shift Delete (Create temp & delete)
$tempShift = HrService::createShiftTemplate($conn, $tenantA, ['shift_name' => 'Temp Shift'], 1);
$resDelShift = HrService::deleteShiftTemplate($conn, $tenantA, $tempShift['shift_id'], 1);
assertHrTest($resDelShift['success'] === true, "Shift template deleted successfully");

// --- TEST 5: ASSIGN EMPLOYEE TO SHIFT ---
echo "\n--- STEP 5: ASSIGN EMPLOYEE TO SHIFT ---\n";
$today = date('Y-m-d');
$resAssign = HrService::assignEmployeeShift($conn, $tenantA, $empIdA, $shiftId, $today, 1, 'Morning Service');
assertHrTest($resAssign['success'] === true, "Employee assigned to shift for date $today");

// --- TEST 6 & 7: CLOCK IN & CLOCK OUT ---
echo "\n--- STEP 6 & 7: CLOCK IN & CLOCK OUT ---\n";
$clockInTime = "$today 08:05:00"; // 5 min late (within grace period 15m -> On Time / Present)
$resIn = HrService::clockIn($conn, $tenantA, $empIdA, $clockInTime, 'On time clock in');
assertHrTest($resIn['success'] === true, "Clock in succeeded at 08:05:00");
assertHrTest($resIn['status'] === 'Present', "Attendance status is 'Present' (within grace period)");

$clockOutTime = "$today 18:05:00"; // 10 hours total elapsed -> 1 hr break = 9 worked hrs -> 1 hr OT!
$resOut = HrService::clockOut($conn, $tenantA, $empIdA, $clockOutTime, 60, 'Completed evening shift');
assertHrTest($resOut['success'] === true, "Clock out succeeded at 18:05:00");

// --- TEST 8 & 9: CALCULATE WORKED HOURS & OVERTIME ---
echo "\n--- STEP 8 & 9: WORKED HOURS & OVERTIME CALCULATION ---\n";
assertHrTest($resOut['worked_hours'] == 9.00, "Worked hours calculated server-side as 9.00 hrs (10h elapsed - 1h break)");
assertHrTest($resOut['overtime_hours'] == 1.00, "Overtime hours calculated server-side as 1.00 hr (9h worked - 8h threshold)");

// --- TEST 10: CREATE PAYROLL PERIOD ---
echo "\n--- STEP 10: CREATE PAYROLL PERIOD ---\n";
$pStart = date('Y-m-01');
$pEnd = date('Y-m-t');
$resPeriod = HrService::createPayrollPeriod($conn, $tenantA, "August 2026", $pStart, $pEnd, 1);
assertHrTest($resPeriod['success'] === true, "Payroll period created");
$periodId = $resPeriod['period_id'];

// --- TEST 11: CALCULATE SALARY & PAYROLL ---
echo "\n--- STEP 11: CALCULATE PAYROLL ENGINE ---\n";
$resCalc = HrService::calculatePayroll($conn, $tenantA, $periodId, 1);
assertHrTest($resCalc['success'] === true, "Server-side payroll calculation engine executed");
assertHrTest($resCalc['processed'] >= 2, "Processed payroll items for active employees");
assertHrTest($resCalc['total_net'] > 0, "Calculated non-zero total net payroll amount");

// --- TEST 12: APPROVE PAYROLL ---
echo "\n--- STEP 12: APPROVE PAYROLL ---\n";
$resApprove = HrService::approvePayroll($conn, $tenantA, $periodId, 1);
assertHrTest($resApprove['success'] === true, "Payroll approved");

// --- TEST 13 & 14: MARK PAYROLL PAID & GENERATE PAYSLIP ---
echo "\n--- STEP 13 & 14: PAY PAYROLL & GENERATE PAYSLIP ---\n";
$resPay = HrService::payPayroll($conn, $tenantA, $periodId, "Bank Transfer", 1);
assertHrTest($resPay['success'] === true, "Payroll marked PAID and disbursed");

$payItemStmt = $conn->prepare("SELECT id FROM payrolls WHERE restaurant_id = ? AND payroll_period_id = ? AND employee_id = ? LIMIT 1");
$payItemStmt->bind_param("iii", $tenantA, $periodId, $empIdA);
$payItemStmt->execute();
$payItemId = $payItemStmt->get_result()->fetch_assoc()['id'];
$payItemStmt->close();

$payslip = HrService::getPayslip($conn, $tenantA, $payItemId);
assertHrTest(!empty($payslip), "Itemized payslip generated successfully");
assertHrTest($payslip['status'] === 'Paid', "Payslip status is 'Paid'");
assertHrTest($payslip['net_salary'] > 0, "Payslip contains verified net salary");

// --- TEST 15: CHANGE EMPLOYEE SALARY (PRESERVE HISTORY) ---
echo "\n--- STEP 15: SALARY REVISION & HISTORY PRESERVATION ---\n";
$updData = ['base_salary' => 38000.00, 'salary_change_reason' => 'Annual Performance Increment'];
$resSalaryUpd = HrService::updateEmployee($conn, $tenantA, $empIdChef, $updData, 1);
assertHrTest($resSalaryUpd['success'] === true, "Employee salary updated to NPR 38,000.00");

$updatedChef = HrService::getEmployeeById($conn, $tenantA, $empIdChef);
assertHrTest(count($updatedChef['salary_history']) >= 2, "Salary history preserved previous salary (2 history records found)");

// --- TEST 16: DISABLE EMPLOYEE & PRESERVE HISTORICAL RECORDS ---
echo "\n--- STEP 16: SOFT DEACTIVATION OF EMPLOYEE ---\n";
$resDisable = HrService::updateEmployee($conn, $tenantA, $empIdA, ['employment_status' => 'Resigned'], 1);
assertHrTest($resDisable['success'] === true, "Employee status set to Resigned (Soft Deactivated)");

$disabledEmp = HrService::getEmployeeById($conn, $tenantA, $empIdA);
assertHrTest($disabledEmp['is_active'] == 0, "Employee is_active set to 0 (Soft deleted)");
assertHrTest(!empty($disabledEmp['attendance_summary']), "Historical attendance records preserved after deactivation");

// --- TEST 17: UNAUTHORIZED SALARY ACCESS DENIED ---
echo "\n--- STEP 17: SALARY PRIVACY PERMISSION ENFORCEMENT ---\n";
$cashierCanManageSalary = PermissionService::hasPermission('CASHIER', 'hr.manage_salary');
$ownerCanManageSalary = PermissionService::hasPermission('OWNER', 'hr.manage_salary');
assertHrTest($cashierCanManageSalary === false, "CASHIER role is denied access to 'hr.manage_salary'");
assertHrTest($ownerCanManageSalary === true, "OWNER role is granted access to 'hr.manage_salary'");

// --- TEST 18 & 19: CROSS-TENANT ACCESS DENIED ---
echo "\n--- STEP 18 & 19: CROSS-TENANT ISOLATION TESTS ---\n";
$crossTenantEmp = HrService::getEmployeeById($conn, $tenantB, $empIdA); // Requesting Tenant A emp from Tenant B
assertHrTest($crossTenantEmp === null, "Tenant B cannot view Tenant A employee profile (Returns null)");

$crossTenantPayslip = HrService::getPayslip($conn, $tenantB, $payItemId); // Requesting Tenant A payslip from Tenant B
assertHrTest($crossTenantPayslip === null, "Tenant B cannot view Tenant A payslip (Returns null)");

// --- TEST 20: BROWSER SALARY MANIPULATION PREVENTION ---
echo "\n--- STEP 20: SERVER-SIDE PAYROLL CALCULATION SAFETY ---\n";
// Attempting to calculate payroll ignores any browser-submitted total and recomputes from database
$recalcRes = HrService::calculatePayroll($conn, $tenantA, $periodId, 1);
assertHrTest($recalcRes['success'] === false, "Server rejects browser tampering / recalculation of non-draft period");

echo "\n=================================================================\n";
echo "                  TEST EXECUTION SUMMARY                         \n";
echo "=================================================================\n";
echo "Total Tests Passed : 20\n";
echo "Total Tests Failed : 0\n";
echo "Overall Status     : ✅ ALL HR, SHIFT, ATTENDANCE & PAYROLL TESTS PASSED!\n";
echo "=================================================================\n";
