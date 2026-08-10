<?php
// admin/staff.php - Complete Restaurant HR, Staff, Shifts, Attendance & Payroll Management Module
require_once __DIR__ . '/../config.php';

Auth::requireAdmin();
$tenantId = (int)TenantContext::getTenantId();
if ($tenantId <= 0) {
    die("Access denied. Invalid or missing tenant context.");
}

$conn = getDBConnection();
HrService::seedTenantHrDefaults($conn, $tenantId);

$userRole = PermissionService::normalizeRole($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'ADMIN');
$actingUserId = (int)($_SESSION['user_id'] ?? 0);

// Basic authorization check
if (!in_array($userRole, ['OWNER', 'MANAGER', 'ADMIN', 'HR_MANAGER', 'ACCOUNTANT', 'SUPER_ADMIN'], true)) {
    die("Access denied. Staff & HR Management requires Administrative privileges.");
}

// Salary privacy authorization flag
$canManageSalary = PermissionService::hasPermission($userRole, 'hr.manage_salary') || in_array($userRole, ['OWNER', 'ADMIN', 'HR_MANAGER', 'ACCOUNTANT'], true);
$canManagePayroll = PermissionService::hasPermission($userRole, 'payroll.calculate') || in_array($userRole, ['OWNER', 'ADMIN', 'HR_MANAGER', 'ACCOUNTANT'], true);

$activeTab = Security::sanitize($_GET['tab'] ?? 'employees');
if (!in_array($activeTab, ['employees', 'shifts', 'attendance', 'payroll'], true)) {
    $activeTab = 'employees';
}

$message = '';
$error = '';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    CSRF::requireValidToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_employee') {
        $res = HrService::createEmployee($conn, $tenantId, $_POST, $actingUserId);
        if ($res['success']) {
            $message = "Employee {$res['emp_code']} created successfully!";
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'update_employee') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $res = HrService::updateEmployee($conn, $tenantId, $empId, $_POST, $actingUserId);
        if ($res['success']) {
            $message = "Employee record updated successfully!";
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'create_shift') {
        $res = HrService::createShiftTemplate($conn, $tenantId, $_POST, $actingUserId);
        if ($res['success']) {
            $message = "Shift template created successfully!";
        } else {
            $error = $res['error'];
        }
        $activeTab = 'shifts';
    } elseif ($action === 'update_shift') {
        $shiftId = (int)($_POST['shift_template_id'] ?? 0);
        $res = HrService::updateShiftTemplate($conn, $tenantId, $shiftId, $_POST, $actingUserId);
        if ($res['success']) {
            $message = "Shift template updated successfully!";
        } else {
            $error = $res['error'];
        }
        $activeTab = 'shifts';
    } elseif ($action === 'delete_shift') {
        $shiftId = (int)($_POST['shift_template_id'] ?? 0);
        $res = HrService::deleteShiftTemplate($conn, $tenantId, $shiftId, $actingUserId);
        if ($res['success']) {
            $message = "Shift template deleted successfully!";
        } else {
            $error = $res['error'];
        }
        $activeTab = 'shifts';
    } elseif ($action === 'assign_shift') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $shiftId = (int)($_POST['shift_template_id'] ?? 0);
        $date = $_POST['assigned_date'] ?? date('Y-m-d');
        $notes = Security::sanitize($_POST['notes'] ?? '');
        $res = HrService::assignEmployeeShift($conn, $tenantId, $empId, $shiftId, $date, $actingUserId, $notes);
        if ($res['success']) {
            $message = "Shift assigned successfully!";
        } else {
            $error = $res['error'];
        }
        $activeTab = 'shifts';
    } elseif ($action === 'clock_in') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $clockInTime = !empty($_POST['clock_in_time']) ? $_POST['clock_in_time'] : null;
        $notes = Security::sanitize($_POST['notes'] ?? '');
        $res = HrService::clockIn($conn, $tenantId, $empId, $clockInTime, $notes);
        if ($res['success']) {
            $message = "Clocked in successfully at {$res['clock_in']}! Status: {$res['status']}";
        } else {
            $error = $res['error'];
        }
        $activeTab = 'attendance';
    } elseif ($action === 'clock_out') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $clockOutTime = !empty($_POST['clock_out_time']) ? $_POST['clock_out_time'] : null;
        $breakMins = (int)($_POST['break_mins'] ?? 60);
        $notes = Security::sanitize($_POST['notes'] ?? '');
        $res = HrService::clockOut($conn, $tenantId, $empId, $clockOutTime, $breakMins, $notes);
        if ($res['success']) {
            $message = "Clocked out successfully at {$res['clock_out']}! Worked: {$res['worked_hours']} hrs (OT: {$res['overtime_hours']} hrs)";
        } else {
            $error = $res['error'];
        }
        $activeTab = 'attendance';
    } elseif ($action === 'request_advance') {
        if (!$canManageSalary) {
            $error = "Unauthorized to manage salary advances.";
        } else {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $reason = Security::sanitize($_POST['reason'] ?? '');
            $method = Security::sanitize($_POST['repayment_method'] ?? 'Payroll Deduction');
            $res = HrService::requestSalaryAdvance($conn, $tenantId, $empId, $amount, $reason, $method, $actingUserId);
            if ($res['success']) {
                $message = "Salary advance recorded successfully!";
            } else {
                $error = $res['error'];
            }
        }
        $activeTab = 'payroll';
    } elseif ($action === 'create_payroll_period') {
        if (!$canManagePayroll) {
            $error = "Unauthorized to manage payroll.";
        } else {
            $pName = Security::sanitize($_POST['period_name'] ?? '');
            $sDate = $_POST['start_date'] ?? '';
            $eDate = $_POST['end_date'] ?? '';
            $res = HrService::createPayrollPeriod($conn, $tenantId, $pName, $sDate, $eDate, $actingUserId);
            if ($res['success']) {
                $message = "Payroll period '$pName' created successfully!";
            } else {
                $error = $res['error'];
            }
        }
        $activeTab = 'payroll';
    } elseif ($action === 'calculate_payroll') {
        if (!$canManagePayroll) {
            $error = "Unauthorized to calculate payroll.";
        } else {
            $periodId = (int)($_POST['payroll_period_id'] ?? 0);
            $res = HrService::calculatePayroll($conn, $tenantId, $periodId, $actingUserId);
            if ($res['success']) {
                $message = "Payroll calculated for {$res['processed']} employees! Gross: NPR " . number_format($res['total_gross'], 2) . " | Net: NPR " . number_format($res['total_net'], 2);
            } else {
                $error = $res['error'];
            }
        }
        $activeTab = 'payroll';
    } elseif ($action === 'approve_payroll') {
        if (!$canManagePayroll) {
            $error = "Unauthorized to approve payroll.";
        } else {
            $periodId = (int)($_POST['payroll_period_id'] ?? 0);
            $res = HrService::approvePayroll($conn, $tenantId, $periodId, $actingUserId);
            if ($res['success']) {
                $message = "Payroll period approved successfully!";
            } else {
                $error = $res['error'];
            }
        }
        $activeTab = 'payroll';
    } elseif ($action === 'pay_payroll') {
        if (!$canManagePayroll) {
            $error = "Unauthorized to disburse payroll.";
        } else {
            $periodId = (int)($_POST['payroll_period_id'] ?? 0);
            $payMethod = Security::sanitize($_POST['payment_method'] ?? 'Bank Transfer');
            $res = HrService::payPayroll($conn, $tenantId, $periodId, $payMethod, $actingUserId);
            if ($res['success']) {
                $message = "Payroll disbursed and marked PAID successfully!";
            } else {
                $error = $res['error'];
            }
        }
        $activeTab = 'payroll';
    } elseif ($action === 'create_staff') {
        // Preserved legacy staff account creation
        $username = Security::sanitize($_POST['username'] ?? '');
        $fullName = Security::sanitize($_POST['full_name'] ?? '');
        $role = strtoupper(Security::sanitize($_POST['role'] ?? 'CASHIER'));
        $password = $_POST['password'] ?? '';

        if ($role === 'SUPER_ADMIN') {
            $error = "Cannot assign SUPER_ADMIN role.";
        } elseif (empty($username) || empty($password)) {
            $error = "Username and password are required.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admin_users (username, password, full_name, role, restaurant_id, is_super_admin) VALUES (?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("ssssi", $username, $hash, $fullName, $role, $tenantId);
            if ($stmt->execute()) {
                $message = "Staff account '$username' created successfully!";
            } else {
                $error = "Failed to create staff account: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Fetch HR Metrics
$metrics = HrService::getHrMetrics($conn, $tenantId);

// Filters for Employees Tab
$empFilters = [
    'search' => $_GET['search'] ?? '',
    'department' => $_GET['department'] ?? '',
    'status' => $_GET['status'] ?? '',
    'employment_type' => $_GET['employment_type'] ?? ''
];
$employees = HrService::getEmployees($conn, $tenantId, $empFilters);

// Fetch Shift Templates & Shift Assignments
$shiftTemplates = HrService::getShiftTemplates($conn, $tenantId);

// Fetch Attendance Records
$attFilters = [
    'employee_id' => $_GET['att_employee_id'] ?? '',
    'date' => $_GET['att_date'] ?? date('Y-m-d'),
    'status' => $_GET['att_status'] ?? ''
];
$attendanceRecords = HrService::getAttendanceRecords($conn, $tenantId, $attFilters);

// Fetch Payroll Periods
$payrollPeriods = [];
$pStmt = $conn->prepare("SELECT * FROM payroll_periods WHERE restaurant_id = ? ORDER BY start_date DESC");
$pStmt->bind_param("i", $tenantId);
$pStmt->execute();
$payrollPeriods = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pStmt->close();

// Fetch Salary Advances
$salaryAdvances = [];
$advStmt = $conn->prepare("SELECT sa.*, e.emp_code, e.full_name FROM salary_advances sa JOIN employees e ON sa.employee_id = e.id AND sa.restaurant_id = e.restaurant_id WHERE sa.restaurant_id = ? ORDER BY sa.advance_date DESC");
$advStmt->bind_param("i", $tenantId);
$advStmt->execute();
$salaryAdvances = $advStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$advStmt->close();

// Check if viewing profile or payslip via GET
$viewProfileEmp = null;
if (!empty($_GET['view_profile_id'])) {
    $viewProfileEmp = HrService::getEmployeeById($conn, $tenantId, (int)$_GET['view_profile_id']);
}

$viewPayslip = null;
if (!empty($_GET['view_payslip_id']) && $canManageSalary) {
    $viewPayslip = HrService::getPayslip($conn, $tenantId, (int)$_GET['view_payslip_id']);
}

$currentPage = 'staff';
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 font-sans antialiased text-white selection:bg-amber-500 selection:text-zinc-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR &amp; Staff Management — RMS SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { amber: { 500: '#f59e0b', 600: '#d97706' } } } } }
    </script>
</head>
<body class="min-h-full pb-12 font-sans antialiased">
    <?php include 'includes/sidebar.php'; ?>

    <div class="md:pl-64 min-h-screen">
        <!-- Sticky Header -->
        <header class="sticky top-0 z-40 bg-zinc-950/90 backdrop-blur-xl border-b border-zinc-800/80 px-4 md:px-8 py-3.5 flex items-center justify-between">
            <div>
                <h1 class="text-lg md:text-xl font-black text-white">HR &amp; Staff Management</h1>
                <p class="text-xs text-zinc-400">Employees &middot; Shifts &middot; Attendance &middot; Payroll &middot; Role Access</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="document.getElementById('clockModal').classList.remove('hidden')" class="px-3.5 py-2 rounded-xl bg-zinc-800 border border-zinc-700 text-zinc-200 font-bold text-xs hover:border-amber-500">
                    🕒 Clock In / Out
                </button>
                <button onclick="document.getElementById('addEmployeeModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs active:scale-95 shadow-lg shadow-amber-500/20">
                    ➕ Add Employee
                </button>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 md:px-8 pt-6 space-y-6">

            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">✅ <?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- HR Metrics Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <div class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-center">
                    <div class="text-[10px] font-extrabold uppercase tracking-wider text-zinc-500">Total Staff</div>
                    <div class="text-xl font-black text-white mt-1"><?= $metrics['total_employees'] ?></div>
                    <div class="text-[11px] text-zinc-400 font-semibold mt-0.5"><?= $metrics['active_employees'] ?> Active</div>
                </div>
                <div class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-center">
                    <div class="text-[10px] font-extrabold uppercase tracking-wider text-zinc-500">On Leave Today</div>
                    <div class="text-xl font-black text-amber-400 mt-1"><?= $metrics['on_leave_today'] ?></div>
                    <div class="text-[11px] text-zinc-400 font-semibold mt-0.5">Approved Absences</div>
                </div>
                <div class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-center">
                    <div class="text-[10px] font-extrabold uppercase tracking-wider text-zinc-500">Today Attendance</div>
                    <div class="text-xl font-black text-emerald-400 mt-1"><?= $metrics['today_attendance'] ?></div>
                    <div class="text-[11px] text-zinc-400 font-semibold mt-0.5"><?= $metrics['currently_working'] ?> Currently On Shift</div>
                </div>
                <div class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-center">
                    <div class="text-[10px] font-extrabold uppercase tracking-wider text-zinc-500">Late Today</div>
                    <div class="text-xl font-black text-rose-400 mt-1"><?= $metrics['late_today'] ?></div>
                    <div class="text-[11px] text-zinc-400 font-semibold mt-0.5">Past Grace Period</div>
                </div>
                <div class="p-4 rounded-2xl bg-zinc-900 border border-zinc-800 text-center col-span-2">
                    <div class="text-[10px] font-extrabold uppercase tracking-wider text-zinc-500">Month Payroll Expense</div>
                    <div class="text-xl font-black text-amber-400 mt-1">
                        <?= $canManageSalary ? 'NPR ' . number_format($metrics['month_payroll'], 2) : '🔒 Protected' ?>
                    </div>
                    <div class="text-[11px] text-zinc-400 font-semibold mt-0.5">Disbursed &amp; Approved</div>
                </div>
            </div>

            <!-- Tab Navigation Bar -->
            <div class="flex border-b border-zinc-800 gap-2">
                <a href="staff.php?tab=employees" class="px-5 py-3 font-extrabold text-xs transition-colors border-b-2 <?= $activeTab === 'employees' ? 'border-amber-500 text-amber-400' : 'border-transparent text-zinc-400 hover:text-white' ?>">
                    👥 Employees (<?= count($employees) ?>)
                </a>
                <a href="staff.php?tab=shifts" class="px-5 py-3 font-extrabold text-xs transition-colors border-b-2 <?= $activeTab === 'shifts' ? 'border-amber-500 text-amber-400' : 'border-transparent text-zinc-400 hover:text-white' ?>">
                    ⏰ Shifts &amp; Schedules
                </a>
                <a href="staff.php?tab=attendance" class="px-5 py-3 font-extrabold text-xs transition-colors border-b-2 <?= $activeTab === 'attendance' ? 'border-amber-500 text-amber-400' : 'border-transparent text-zinc-400 hover:text-white' ?>">
                    📅 Attendance (<?= count($attendanceRecords) ?>)
                </a>
                <a href="staff.php?tab=payroll" class="px-5 py-3 font-extrabold text-xs transition-colors border-b-2 <?= $activeTab === 'payroll' ? 'border-amber-500 text-amber-400' : 'border-transparent text-zinc-400 hover:text-white' ?>">
                    💵 Salary &amp; Payroll <?= $canManageSalary ? '' : '🔒' ?>
                </a>
            </div>

            <!-- TAB 1: EMPLOYEES -->
            <?php if ($activeTab === 'employees'): ?>
                <div class="space-y-4">
                    <!-- Filters -->
                    <form method="GET" class="bg-zinc-900 border border-zinc-800 rounded-2xl p-4 flex flex-wrap items-center gap-3 text-xs">
                        <input type="hidden" name="tab" value="employees">
                        <input type="text" name="search" value="<?= htmlspecialchars($empFilters['search']) ?>" placeholder="Search name, EMP ID, email, phone..." class="h-9 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white placeholder-zinc-500 outline-none focus:border-amber-500 flex-1 min-w-[200px]">
                        <select name="department" class="h-9 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                            <option value="">All Departments</option>
                            <option value="Operations" <?= $empFilters['department'] === 'Operations' ? 'selected' : '' ?>>Operations</option>
                            <option value="Kitchen" <?= $empFilters['department'] === 'Kitchen' ? 'selected' : '' ?>>Kitchen</option>
                            <option value="Service" <?= $empFilters['department'] === 'Service' ? 'selected' : '' ?>>Service</option>
                            <option value="Management" <?= $empFilters['department'] === 'Management' ? 'selected' : '' ?>>Management</option>
                            <option value="Accounts" <?= $empFilters['department'] === 'Accounts' ? 'selected' : '' ?>>Accounts</option>
                        </select>
                        <select name="status" class="h-9 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                            <option value="">All Statuses</option>
                            <option value="Active" <?= $empFilters['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="On Leave" <?= $empFilters['status'] === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                            <option value="Suspended" <?= $empFilters['status'] === 'Suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="Resigned" <?= $empFilters['status'] === 'Resigned' ? 'selected' : '' ?>>Resigned</option>
                            <option value="Terminated" <?= $empFilters['status'] === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                        </select>
                        <button type="submit" class="h-9 px-4 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-white font-bold">Filter</button>
                    </form>

                    <!-- Employee List Table -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                    <th class="py-2.5 px-3">EMP Code</th>
                                    <th class="py-2.5 px-3">Full Name</th>
                                    <th class="py-2.5 px-3">Department &amp; Title</th>
                                    <th class="py-2.5 px-3">Status &amp; Type</th>
                                    <th class="py-2.5 px-3">System Account</th>
                                    <th class="py-2.5 px-3">Base Salary</th>
                                    <th class="py-2.5 px-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                                <?php foreach ($employees as $emp): ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 text-amber-400 font-mono font-bold"><?= htmlspecialchars($emp['emp_code']) ?></td>
                                        <td class="py-3 px-3">
                                            <div class="font-extrabold text-white"><?= htmlspecialchars($emp['full_name']) ?></div>
                                            <div class="text-[11px] text-zinc-500"><?= htmlspecialchars($emp['phone'] ?: $emp['email']) ?></div>
                                        </td>
                                        <td class="py-3 px-3">
                                            <div class="font-semibold text-zinc-200"><?= htmlspecialchars($emp['designation']) ?></div>
                                            <div class="text-[11px] text-zinc-500"><?= htmlspecialchars($emp['department']) ?></div>
                                        </td>
                                        <td class="py-3 px-3">
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider <?= $emp['employment_status'] === 'Active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                                                <?= htmlspecialchars($emp['employment_status']) ?>
                                            </span>
                                            <div class="text-[11px] text-zinc-500 mt-0.5"><?= htmlspecialchars($emp['employment_type']) ?></div>
                                        </td>
                                        <td class="py-3 px-3">
                                            <?php if ($emp['system_username']): ?>
                                                <span class="px-2 py-0.5 rounded bg-zinc-800 border border-zinc-700 text-amber-400 font-bold text-[10px]">
                                                    👤 @<?= htmlspecialchars($emp['system_username']) ?> (<?= htmlspecialchars($emp['system_role']) ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="text-zinc-500 italic text-[11px]">No Login Account</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 font-mono font-bold text-white">
                                            <?= $canManageSalary ? 'NPR ' . number_format((float)$emp['base_salary'], 2) : '🔒 Protected' ?>
                                        </td>
                                        <td class="py-3 px-3 text-right space-x-2">
                                            <a href="staff.php?tab=employees&amp;view_profile_id=<?= $emp['id'] ?>" class="px-2.5 py-1 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 font-bold text-[11px]">👁️ Profile</a>
                                            <button onclick="editEmployee(<?= htmlspecialchars(json_encode($emp)) ?>)" class="px-2.5 py-1 rounded-lg bg-amber-500/20 text-amber-400 font-bold text-[11px]">✏️ Edit</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 2: SHIFTS -->
            <?php if ($activeTab === 'shifts'): ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Shift Templates -->
                    <div class="lg:col-span-1 bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                        <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                            <h3 class="font-extrabold text-white text-sm">Shift Templates</h3>
                            <button onclick="document.getElementById('addShiftModal').classList.remove('hidden')" class="text-xs text-amber-400 font-bold hover:underline">+ New Shift</button>
                        </div>
                        <div class="space-y-3 text-xs">
                            <?php foreach ($shiftTemplates as $st): ?>
                                <div class="p-3.5 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-2">
                                    <div class="flex justify-between items-center font-bold text-white">
                                        <span><?= htmlspecialchars($st['shift_name']) ?></span>
                                        <span class="text-amber-400 font-mono"><?= date('H:i', strtotime($st['start_time'])) ?> – <?= date('H:i', strtotime($st['end_time'])) ?></span>
                                    </div>
                                    <div class="text-zinc-500 text-[11px] flex justify-between items-center border-t border-zinc-800/60 pt-2">
                                        <span>Break: <?= $st['break_duration_mins'] ?>m &middot; Grace: <?= $st['grace_period_mins'] ?>m &middot; OT: <?= round($st['overtime_threshold_mins']/60, 1) ?>h</span>
                                        <div class="flex items-center gap-1.5">
                                            <button type="button" onclick="editShift(<?= htmlspecialchars(json_encode($st)) ?>)" class="px-2 py-0.5 rounded bg-zinc-800 hover:bg-zinc-700 text-amber-400 font-bold text-[10px]">✏️ Edit</button>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this shift template?')" class="inline">
                                                <?= CSRF::getField() ?>
                                                <input type="hidden" name="action" value="delete_shift">
                                                <input type="hidden" name="shift_template_id" value="<?= $st['id'] ?>">
                                                <button type="submit" class="px-2 py-0.5 rounded bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 font-bold text-[10px]">🗑️ Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Assign Shift Panel -->
                    <div class="lg:col-span-2 bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                        <h3 class="font-extrabold text-white text-sm border-b border-zinc-800 pb-3">Assign Employee Shift</h3>
                        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <?= CSRF::getField() ?>
                            <input type="hidden" name="action" value="assign_shift">
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">Select Employee</label>
                                <select name="employee_id" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['emp_code']) ?> &middot; <?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['department']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">Select Shift Template</label>
                                <select name="shift_template_id" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                                    <?php foreach ($shiftTemplates as $st): ?>
                                        <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['shift_name']) ?> (<?= date('H:i', strtotime($st['start_time'])) ?>–<?= date('H:i', strtotime($st['end_time'])) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">Shift Date</label>
                                <input type="date" name="assigned_date" value="<?= date('Y-m-d') ?>" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                            </div>
                            <div>
                                <label class="block font-bold text-zinc-300 mb-1">Notes / Instructions</label>
                                <input type="text" name="notes" placeholder="Optional shift notes..." class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                            </div>
                            <div class="sm:col-span-2 pt-2">
                                <button type="submit" class="w-full py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg">Assign Shift Schedule</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 3: ATTENDANCE -->
            <?php if ($activeTab === 'attendance'): ?>
                <div class="space-y-4">
                    <form method="GET" class="bg-zinc-900 border border-zinc-800 rounded-2xl p-4 flex flex-wrap items-center gap-3 text-xs">
                        <input type="hidden" name="tab" value="attendance">
                        <input type="date" name="att_date" value="<?= htmlspecialchars($attFilters['date']) ?>" class="h-9 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                        <select name="att_status" class="h-9 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                            <option value="">All Attendance Statuses</option>
                            <option value="Present" <?= $attFilters['status'] === 'Present' ? 'selected' : '' ?>>Present</option>
                            <option value="Late" <?= $attFilters['status'] === 'Late' ? 'selected' : '' ?>>Late</option>
                            <option value="Half Day" <?= $attFilters['status'] === 'Half Day' ? 'selected' : '' ?>>Half Day</option>
                            <option value="Absent" <?= $attFilters['status'] === 'Absent' ? 'selected' : '' ?>>Absent</option>
                        </select>
                        <button type="submit" class="h-9 px-4 rounded-xl bg-zinc-800 text-white font-bold">Filter</button>
                    </form>

                    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                    <th class="py-2.5 px-3">Date</th>
                                    <th class="py-2.5 px-3">Employee</th>
                                    <th class="py-2.5 px-3">Clock In</th>
                                    <th class="py-2.5 px-3">Clock Out</th>
                                    <th class="py-2.5 px-3">Worked Hrs</th>
                                    <th class="py-2.5 px-3">Overtime</th>
                                    <th class="py-2.5 px-3">Late Mins</th>
                                    <th class="py-2.5 px-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                                <?php foreach ($attendanceRecords as $att): ?>
                                    <tr class="hover:bg-zinc-800/40">
                                        <td class="py-3 px-3 font-mono text-zinc-400"><?= $att['attendance_date'] ?></td>
                                        <td class="py-3 px-3 font-bold text-white"><?= htmlspecialchars($att['full_name']) ?> <span class="text-amber-400 text-[11px] font-mono">(<?= $att['emp_code'] ?>)</span></td>
                                        <td class="py-3 px-3 font-mono text-emerald-400"><?= $att['clock_in'] ? date('H:i:s', strtotime($att['clock_in'])) : '—' ?></td>
                                        <td class="py-3 px-3 font-mono text-rose-400"><?= $att['clock_out'] ? date('H:i:s', strtotime($att['clock_out'])) : '—' ?></td>
                                        <td class="py-3 px-3 font-mono font-bold text-white"><?= number_format((float)$att['worked_hours'], 2) ?> hrs</td>
                                        <td class="py-3 px-3 font-mono text-amber-400"><?= number_format((float)$att['overtime_hours'], 2) ?> hrs</td>
                                        <td class="py-3 px-3 font-mono text-rose-400"><?= $att['late_mins'] ?> m</td>
                                        <td class="py-3 px-3">
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase <?= $att['status'] === 'Late' ? 'bg-amber-500/10 text-amber-400' : 'bg-emerald-500/10 text-emerald-400' ?>">
                                                <?= $att['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 4: SALARY / PAYROLL -->
            <?php if ($activeTab === 'payroll'): ?>
                <?php if (!$canManageSalary): ?>
                    <div class="p-6 rounded-3xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs font-bold text-center">
                        🔒 Access Denied: Salary &amp; Payroll information is restricted to authorized HR / Accountant roles.
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <!-- Action Bar for Payroll -->
                        <div class="flex flex-wrap items-center justify-between gap-3 bg-zinc-900 border border-zinc-800 rounded-2xl p-4">
                            <h3 class="font-extrabold text-white text-sm">Payroll Periods &amp; Disbursements</h3>
                            <div class="flex gap-2">
                                <button onclick="document.getElementById('requestAdvanceModal').classList.remove('hidden')" class="px-3.5 py-2 rounded-xl bg-zinc-800 text-zinc-300 font-bold text-xs hover:border-amber-500 border border-zinc-700">
                                    💵 Record Salary Advance
                                </button>
                                <button onclick="document.getElementById('createPeriodModal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs shadow-lg">
                                    🗓️ Create Payroll Period
                                </button>
                            </div>
                        </div>

                        <!-- Payroll Periods List -->
                        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                            <th class="py-2.5 px-3">Period Name</th>
                                            <th class="py-2.5 px-3">Start – End Date</th>
                                            <th class="py-2.5 px-3">Total Gross</th>
                                            <th class="py-2.5 px-3">Total Net Disbursed</th>
                                            <th class="py-2.5 px-3">Status</th>
                                            <th class="py-2.5 px-3 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                                        <?php foreach ($payrollPeriods as $pp): ?>
                                            <tr class="hover:bg-zinc-800/40">
                                                <td class="py-3 px-3 font-extrabold text-white"><?= htmlspecialchars($pp['period_name']) ?></td>
                                                <td class="py-3 px-3 font-mono text-zinc-400"><?= $pp['start_date'] ?> &rarr; <?= $pp['end_date'] ?></td>
                                                <td class="py-3 px-3 font-mono">NPR <?= number_format((float)$pp['total_gross'], 2) ?></td>
                                                <td class="py-3 px-3 font-mono font-bold text-amber-400">NPR <?= number_format((float)$pp['total_net'], 2) ?></td>
                                                <td class="py-3 px-3">
                                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase <?= $pp['status'] === 'Paid' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' ?>">
                                                        <?= $pp['status'] ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-3 text-right space-x-1.5">
                                                    <?php if ($pp['status'] === 'Draft'): ?>
                                                        <form method="POST" class="inline">
                                                            <?= CSRF::getField() ?>
                                                            <input type="hidden" name="action" value="calculate_payroll">
                                                            <input type="hidden" name="payroll_period_id" value="<?= $pp['id'] ?>">
                                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-amber-500/20 text-amber-400 font-bold text-[11px]">⚡ Calculate</button>
                                                        </form>
                                                    <?php elseif ($pp['status'] === 'Calculated'): ?>
                                                        <form method="POST" class="inline">
                                                            <?= CSRF::getField() ?>
                                                            <input type="hidden" name="action" value="approve_payroll">
                                                            <input type="hidden" name="payroll_period_id" value="<?= $pp['id'] ?>">
                                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-400 font-bold text-[11px]">✅ Approve</button>
                                                        </form>
                                                    <?php elseif ($pp['status'] === 'Approved'): ?>
                                                        <form method="POST" class="inline">
                                                            <?= CSRF::getField() ?>
                                                            <input type="hidden" name="action" value="pay_payroll">
                                                            <input type="hidden" name="payroll_period_id" value="<?= $pp['id'] ?>">
                                                            <input type="hidden" name="payment_method" value="Bank Transfer">
                                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-emerald-500 text-zinc-950 font-black text-[11px]">💵 Disburse &amp; Pay</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Salary Advances List -->
                        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-5 space-y-4">
                            <h4 class="font-extrabold text-white text-xs uppercase tracking-wider text-zinc-400">Salary Advances &amp; Recoveries</h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="border-b border-zinc-800 text-zinc-500 uppercase tracking-wider font-extrabold text-[10px]">
                                            <th class="py-2.5 px-3">Date</th>
                                            <th class="py-2.5 px-3">Employee</th>
                                            <th class="py-2.5 px-3">Advance Amount</th>
                                            <th class="py-2.5 px-3">Repaid Amount</th>
                                            <th class="py-2.5 px-3">Reason</th>
                                            <th class="py-2.5 px-3">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-800/60 font-medium text-zinc-300">
                                        <?php foreach ($salaryAdvances as $sa): ?>
                                            <tr class="hover:bg-zinc-800/40">
                                                <td class="py-3 px-3 font-mono text-zinc-400"><?= $sa['advance_date'] ?></td>
                                                <td class="py-3 px-3 font-bold text-white"><?= htmlspecialchars($sa['full_name']) ?> (<?= $sa['emp_code'] ?>)</td>
                                                <td class="py-3 px-3 font-mono text-amber-400 font-bold">NPR <?= number_format((float)$sa['amount'], 2) ?></td>
                                                <td class="py-3 px-3 font-mono text-emerald-400">NPR <?= number_format((float)$sa['repaid_amount'], 2) ?></td>
                                                <td class="py-3 px-3 text-zinc-400"><?= htmlspecialchars($sa['reason']) ?></td>
                                                <td class="py-3 px-3">
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $sa['status'] === 'Repaid' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' ?>"><?= $sa['status'] ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </main>
    </div>

    <!-- Add Employee Modal -->
    <div id="addEmployeeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-lg w-full space-y-4 max-h-[90vh] overflow-y-auto">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="create_employee">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Add New Restaurant Employee</h3>
                <button type="button" onclick="document.getElementById('addEmployeeModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div class="sm:col-span-2">
                    <label class="block font-bold text-zinc-300 mb-1">Full Name <span class="text-amber-500">*</span></label>
                    <input type="text" name="full_name" required placeholder="e.g. Hari Sharma" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Email</label>
                    <input type="email" name="email" placeholder="hari@restaurant.com" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Phone</label>
                    <input type="tel" name="phone" placeholder="98XXXXXXXX" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Department</label>
                    <select name="department" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                        <option value="Operations">Operations</option>
                        <option value="Kitchen">Kitchen</option>
                        <option value="Service">Service</option>
                        <option value="Management">Management</option>
                        <option value="Accounts">Accounts</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Designation</label>
                    <input type="text" name="designation" placeholder="e.g. Chef / Waiter / Cashier" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Employment Type</label>
                    <select name="employment_type" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                        <option value="Full Time">Full Time</option>
                        <option value="Part Time">Part Time</option>
                        <option value="Contract">Contract</option>
                        <option value="Temporary">Temporary</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Base Salary (NPR)</label>
                    <input type="number" step="0.01" name="base_salary" value="25000" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>

                <div class="sm:col-span-2 border-t border-zinc-800 pt-3 space-y-2">
                    <label class="flex items-center gap-2 font-bold text-amber-400 cursor-pointer">
                        <input type="checkbox" name="create_system_account" value="1" onchange="document.getElementById('systemAccFields').classList.toggle('hidden', !this.checked)">
                        <span>Create RMS System Login Account for this Employee</span>
                    </label>
                    <div id="systemAccFields" class="hidden grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1">
                        <div>
                            <label class="block font-semibold text-zinc-400">Username</label>
                            <input type="text" name="username" class="w-full h-9 bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 text-white">
                        </div>
                        <div>
                            <label class="block font-semibold text-zinc-400">Role</label>
                            <select name="role" class="w-full h-9 bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 text-white">
                                <option value="CASHIER">CASHIER</option>
                                <option value="WAITER">WAITER</option>
                                <option value="KITCHEN">KITCHEN</option>
                                <option value="MANAGER">MANAGER</option>
                                <option value="OWNER">OWNER</option>
                                <option value="INVENTORY_MANAGER">INVENTORY_MANAGER</option>
                                <option value="ACCOUNTANT">ACCOUNTANT</option>
                                <option value="HR_MANAGER">HR_MANAGER</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-semibold text-zinc-400">Password</label>
                            <input type="password" name="password" class="w-full h-9 bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 text-white">
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('addEmployeeModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Save Employee</button>
            </div>
        </form>
    </div>

    <!-- Clock In/Out Modal -->
    <div id="clockModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Quick Attendance Clock In / Out</h3>
                <button type="button" onclick="document.getElementById('clockModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <form method="POST" class="space-y-3 text-xs">
                <?= CSRF::getField() ?>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Select Employee</label>
                    <select name="employee_id" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['emp_code']) ?> &middot; <?= htmlspecialchars($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Timestamp (Optional - defaults to NOW)</label>
                    <input type="datetime-local" name="clock_in_time" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" name="action" value="clock_in" class="flex-1 py-2.5 rounded-xl bg-emerald-500 text-zinc-950 font-black text-xs">📥 Clock In</button>
                    <button type="submit" name="action" value="clock_out" class="flex-1 py-2.5 rounded-xl bg-rose-500 text-white font-black text-xs">📤 Clock Out</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Payroll Period Modal -->
    <div id="createPeriodModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="create_payroll_period">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Create Payroll Period</h3>
                <button type="button" onclick="document.getElementById('createPeriodModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Period Name</label>
                    <input type="text" name="period_name" required value="<?= date('F Y') ?>" placeholder="e.g. August 2026" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Start Date</label>
                    <input type="date" name="start_date" required value="<?= date('Y-m-01') ?>" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">End Date</label>
                    <input type="date" name="end_date" required value="<?= date('Y-m-t') ?>" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('createPeriodModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Create Period</button>
            </div>
        </form>
    </div>

    <!-- Request Advance Modal -->
    <div id="requestAdvanceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="request_advance">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Record Salary Advance</h3>
                <button type="button" onclick="document.getElementById('requestAdvanceModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Select Employee</label>
                    <select name="employee_id" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['emp_code']) ?> &middot; <?= htmlspecialchars($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Advance Amount (NPR)</label>
                    <input type="number" step="0.01" name="amount" required placeholder="5000.00" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Reason</label>
                    <input type="text" name="reason" placeholder="Personal emergency / Medical advance" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('requestAdvanceModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Record Advance</button>
            </div>
        </form>
    </div>

    <!-- Employee Profile View Modal -->
    <?php if ($viewProfileEmp): ?>
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4">
            <div class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-2xl w-full space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                    <div>
                        <span class="text-xs text-amber-400 font-mono font-bold"><?= htmlspecialchars($viewProfileEmp['emp_code']) ?></span>
                        <h3 class="font-black text-white text-lg"><?= htmlspecialchars($viewProfileEmp['full_name']) ?></h3>
                    </div>
                    <a href="staff.php?tab=employees" class="text-zinc-400 hover:text-white font-bold text-sm">✕ Close</a>
                </div>
                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div class="p-3.5 rounded-2xl bg-zinc-950 space-y-1">
                        <div class="font-bold text-zinc-400">Department</div>
                        <div class="text-white font-semibold"><?= htmlspecialchars($viewProfileEmp['department']) ?></div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-zinc-950 space-y-1">
                        <div class="font-bold text-zinc-400">Designation</div>
                        <div class="text-white font-semibold"><?= htmlspecialchars($viewProfileEmp['designation']) ?></div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-zinc-950 space-y-1">
                        <div class="font-bold text-zinc-400">Status &amp; Type</div>
                        <div class="text-amber-400 font-semibold"><?= htmlspecialchars($viewProfileEmp['employment_status']) ?> (<?= htmlspecialchars($viewProfileEmp['employment_type']) ?>)</div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-zinc-950 space-y-1">
                        <div class="font-bold text-zinc-400">Base Salary</div>
                        <div class="text-emerald-400 font-mono font-bold"><?= $canManageSalary ? 'NPR ' . number_format((float)$viewProfileEmp['base_salary'], 2) : '🔒 Protected' ?></div>
                    </div>
                </div>

                <!-- Salary History Timeline -->
                <?php if ($canManageSalary && !empty($viewProfileEmp['salary_history'])): ?>
                    <div class="border-t border-zinc-800 pt-3 space-y-2">
                        <h4 class="font-extrabold text-white text-xs uppercase tracking-wider text-zinc-400">Salary Revision History</h4>
                        <div class="space-y-1.5 text-xs font-mono">
                            <?php foreach ($viewProfileEmp['salary_history'] as $sh): ?>
                                <div class="p-2 rounded bg-zinc-950 flex justify-between">
                                    <span>Effective: <?= $sh['effective_date'] ?></span>
                                    <span class="text-amber-400 font-bold">NPR <?= number_format((float)$sh['base_salary'], 2) ?></span>
                                    <span class="text-zinc-500">(<?= htmlspecialchars($sh['reason']) ?>)</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Edit Shift Template Modal -->
    <div id="editShiftModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 backdrop-blur-md p-4 hidden">
        <form method="POST" class="bg-zinc-900 border border-zinc-800 rounded-3xl p-6 max-w-md w-full space-y-4">
            <?= CSRF::getField() ?>
            <input type="hidden" name="action" value="update_shift">
            <input type="hidden" name="shift_template_id" id="edit_shift_id">
            <div class="flex justify-between items-center border-b border-zinc-800 pb-3">
                <h3 class="font-black text-white text-base">Edit Shift Template</h3>
                <button type="button" onclick="document.getElementById('editShiftModal').classList.add('hidden')" class="text-zinc-400 hover:text-white">✕</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block font-bold text-zinc-300 mb-1">Shift Name</label>
                    <input type="text" name="shift_name" id="edit_shift_name" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Start Time</label>
                        <input type="time" name="start_time" id="edit_start_time" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">End Time</label>
                        <input type="time" name="end_time" id="edit_end_time" required class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Break (mins)</label>
                        <input type="number" name="break_duration_mins" id="edit_break_duration_mins" value="60" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">Grace (mins)</label>
                        <input type="number" name="grace_period_mins" id="edit_grace_period_mins" value="15" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-zinc-300 mb-1">OT Threshold (m)</label>
                        <input type="number" name="overtime_threshold_mins" id="edit_overtime_threshold_mins" value="480" class="w-full h-10 bg-zinc-950 border border-zinc-800 rounded-xl px-3 text-white outline-none">
                    </div>
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('editShiftModal').classList.add('hidden')" class="flex-1 py-2.5 rounded-xl bg-zinc-800 font-bold text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-amber-500 text-zinc-950 font-black text-xs">Update Shift</button>
            </div>
        </form>
    </div>

    <script>
    function editShift(st) {
        document.getElementById('edit_shift_id').value = st.id;
        document.getElementById('edit_shift_name').value = st.shift_name;
        document.getElementById('edit_start_time').value = st.start_time;
        document.getElementById('edit_end_time').value = st.end_time;
        document.getElementById('edit_break_duration_mins').value = st.break_duration_mins;
        document.getElementById('edit_grace_period_mins').value = st.grace_period_mins;
        document.getElementById('edit_overtime_threshold_mins').value = st.overtime_threshold_mins;
        document.getElementById('editShiftModal').classList.remove('hidden');
    }
    </script>
</body>
</html>
