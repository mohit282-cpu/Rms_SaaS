<?php
// helpers/HrService.php - Central Restaurant HR, Shifts, Attendance & Payroll Manager

require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/TenantContext.php';

class HrService {

    /**
     * Auto-provision HR tables if they do not exist.
     */
    public static function ensureHrSchema(mysqli $conn): void {
        static $hrSchemaChecked = false;
        if ($hrSchemaChecked) return;
        $hrSchemaChecked = true;

        $tables = [
            "CREATE TABLE IF NOT EXISTS employees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                emp_code VARCHAR(30) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                profile_photo VARCHAR(255) DEFAULT '',
                email VARCHAR(100) DEFAULT '',
                phone VARCHAR(30) DEFAULT '',
                address VARCHAR(255) DEFAULT '',
                date_of_birth DATE DEFAULT NULL,
                gender VARCHAR(20) DEFAULT '',
                department VARCHAR(50) DEFAULT 'Operations',
                designation VARCHAR(50) DEFAULT 'Staff',
                joining_date DATE DEFAULT NULL,
                employment_type VARCHAR(30) DEFAULT 'Full Time',
                employment_status VARCHAR(30) DEFAULT 'Active',
                base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                bank_info TEXT DEFAULT NULL,
                emergency_contact VARCHAR(255) DEFAULT '',
                notes TEXT DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tenant_emp (restaurant_id, emp_code),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS shift_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                shift_name VARCHAR(50) NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                break_duration_mins INT DEFAULT 60,
                grace_period_mins INT DEFAULT 15,
                overtime_threshold_mins INT DEFAULT 480,
                status VARCHAR(20) DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_shift (restaurant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS employee_shifts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                employee_id INT NOT NULL,
                shift_template_id INT NOT NULL,
                assigned_date DATE NOT NULL,
                assigned_by INT DEFAULT NULL,
                notes VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_emp_shift_date (restaurant_id, employee_id, assigned_date),
                INDEX idx_assigned_date (assigned_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS employee_attendance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                employee_id INT NOT NULL,
                attendance_date DATE NOT NULL,
                clock_in DATETIME DEFAULT NULL,
                clock_out DATETIME DEFAULT NULL,
                break_mins INT DEFAULT 0,
                worked_hours DECIMAL(5,2) DEFAULT 0.00,
                overtime_hours DECIMAL(5,2) DEFAULT 0.00,
                late_mins INT DEFAULT 0,
                early_departure_mins INT DEFAULT 0,
                status VARCHAR(30) DEFAULT 'Present',
                notes VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_emp_att_date (restaurant_id, employee_id, attendance_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS restaurant_hr_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL UNIQUE,
                standard_hours_per_day DECIMAL(4,2) DEFAULT 8.00,
                break_duration_mins INT DEFAULT 60,
                grace_period_mins INT DEFAULT 15,
                overtime_threshold_mins INT DEFAULT 480,
                overtime_hourly_rate_multiplier DECIMAL(4,2) DEFAULT 1.50,
                late_deduction_rate_per_min DECIMAL(8,2) DEFAULT 0.00,
                payroll_currency VARCHAR(10) DEFAULT 'NPR',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS salary_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                employee_id INT NOT NULL,
                base_salary DECIMAL(12,2) NOT NULL,
                effective_date DATE NOT NULL,
                reason VARCHAR(255) DEFAULT '',
                changed_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_salary (restaurant_id, employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS salary_advances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                employee_id INT NOT NULL,
                advance_date DATE NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                reason VARCHAR(255) DEFAULT '',
                repayment_method VARCHAR(50) DEFAULT 'Payroll Deduction',
                repaid_amount DECIMAL(12,2) DEFAULT 0.00,
                status VARCHAR(30) DEFAULT 'Pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_adv (restaurant_id, employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS payroll_periods (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                period_name VARCHAR(50) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                status VARCHAR(30) DEFAULT 'Draft',
                total_gross DECIMAL(14,2) DEFAULT 0.00,
                total_net DECIMAL(14,2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tenant_period (restaurant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS payrolls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                payroll_period_id INT NOT NULL,
                employee_id INT NOT NULL,
                base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                allowances DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(30) DEFAULT 'Draft',
                payment_method VARCHAR(50) DEFAULT 'Bank Transfer',
                payment_date DATETIME DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_emp_period (restaurant_id, payroll_period_id, employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS hr_audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                action VARCHAR(50) NOT NULL,
                target_type VARCHAR(50) NOT NULL,
                target_id INT DEFAULT NULL,
                old_values TEXT DEFAULT NULL,
                new_values TEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_hr_audit (restaurant_id, action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($tables as $sql) {
            @$conn->query($sql);
        }
    }

    /**
     * Seed default shift templates and HR settings for tenant if missing.
     */
    public static function seedTenantHrDefaults(mysqli $conn, int $tenantId): void {
        if ($tenantId <= 0) return;
        self::ensureHrSchema($conn);

        // HR Settings check
        $stmt = $conn->prepare("SELECT id FROM restaurant_hr_settings WHERE restaurant_id = ? LIMIT 1");
        $stmt->bind_param("i", $tenantId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $stmt->close();
            $initSettings = $conn->prepare("INSERT INTO restaurant_hr_settings (restaurant_id) VALUES (?)");
            $initSettings->bind_param("i", $tenantId);
            $initSettings->execute();
            $initSettings->close();
        } else {
            $stmt->close();
        }

        // Shift templates check
        $stmt = $conn->prepare("SELECT id FROM shift_templates WHERE restaurant_id = ? LIMIT 1");
        $stmt->bind_param("i", $tenantId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $stmt->close();
            $defaults = [
                ['Morning Shift', '08:00:00', '16:00:00', 60, 15, 480],
                ['Evening Shift', '16:00:00', '23:00:00', 60, 15, 420],
                ['Night Shift',   '23:00:00', '07:00:00', 60, 15, 480]
            ];
            $ins = $conn->prepare("INSERT INTO shift_templates (restaurant_id, shift_name, start_time, end_time, break_duration_mins, grace_period_mins, overtime_threshold_mins) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($defaults as $d) {
                $ins->bind_param("isssiii", $tenantId, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5]);
                $ins->execute();
            }
            $ins->close();
        } else {
            $stmt->close();
        }
    }

    /**
     * Audit log helper for HR events.
     */
    public static function logAudit(mysqli $conn, int $tenantId, ?int $userId, string $action, string $targetType, ?int $targetId, $oldVals = null, $newVals = null): void {
        self::ensureHrSchema($conn);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $oldJson = is_string($oldVals) ? $oldVals : json_encode($oldVals);
        $newJson = is_string($newVals) ? $newVals : json_encode($newVals);

        $stmt = $conn->prepare("INSERT INTO hr_audit_logs (restaurant_id, user_id, action, target_type, target_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iississs", $tenantId, $userId, $action, $targetType, $targetId, $oldJson, $newJson, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Calculate dashboard metrics for HR dashboard.
     */
    public static function getHrMetrics(mysqli $conn, int $tenantId): array {
        self::seedTenantHrDefaults($conn, $tenantId);
        $today = date('Y-m-d');

        // Total & Active employees
        $totStmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN employment_status = 'Active' AND is_active = 1 THEN 1 ELSE 0 END) as active, SUM(CASE WHEN employment_status = 'On Leave' THEN 1 ELSE 0 END) as on_leave FROM employees WHERE restaurant_id = ?");
        $totStmt->bind_param("i", $tenantId);
        $totStmt->execute();
        $totRes = $totStmt->get_result()->fetch_assoc();
        $totStmt->close();

        // Today's attendance
        $attStmt = $conn->prepare("SELECT COUNT(*) as today_count, SUM(CASE WHEN status = 'Late' OR late_mins > 0 THEN 1 ELSE 0 END) as late_today, SUM(CASE WHEN clock_in IS NOT NULL AND clock_out IS NULL THEN 1 ELSE 0 END) as currently_working FROM employee_attendance WHERE restaurant_id = ? AND attendance_date = ?");
        $attStmt->bind_param("is", $tenantId, $today);
        $attStmt->execute();
        $attRes = $attStmt->get_result()->fetch_assoc();
        $attStmt->close();

        // Total Payroll This Month
        $currentMonth = date('Y-m');
        $payStmt = $conn->prepare("SELECT SUM(p.net_salary) as month_payroll FROM payrolls p JOIN payroll_periods pp ON p.payroll_period_id = pp.id WHERE p.restaurant_id = ? AND pp.start_date LIKE ? AND p.status IN ('Approved', 'Paid')");
        $monthPattern = $currentMonth . '%';
        $payStmt->bind_param("is", $tenantId, $monthPattern);
        $payStmt->execute();
        $payRes = $payStmt->get_result()->fetch_assoc();
        $payStmt->close();

        return [
            'total_employees'   => (int)($totRes['total'] ?? 0),
            'active_employees'  => (int)($totRes['active'] ?? 0),
            'on_leave_today'    => (int)($totRes['on_leave'] ?? 0),
            'today_attendance'  => (int)($attRes['today_count'] ?? 0),
            'late_today'        => (int)($attRes['late_today'] ?? 0),
            'currently_working' => (int)($attRes['currently_working'] ?? 0),
            'month_payroll'     => (float)($payRes['month_payroll'] ?? 0.00),
        ];
    }

    /**
     * Get list of employees with optional filters.
     */
    public static function getEmployees(mysqli $conn, int $tenantId, array $filters = []): array {
        self::ensureHrSchema($conn);

        $sql = "SELECT e.*, u.email as system_email, u.role as system_role, u.is_super_admin
                FROM employees e
                LEFT JOIN admin_users u ON e.user_id = u.id AND (u.restaurant_id = e.restaurant_id OR u.restaurant_id IS NULL)
                WHERE e.restaurant_id = ?";
        $types = "i";
        $params = [$tenantId];

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $sql .= " AND (e.full_name LIKE ? OR e.emp_code LIKE ? OR e.email LIKE ? OR e.phone LIKE ? OR u.email LIKE ?)";
            $types .= "sssss";
            $params[] = $search; $params[] = $search; $params[] = $search; $params[] = $search; $params[] = $search;
        }

        if (!empty($filters['department'])) {
            $sql .= " AND e.department = ?";
            $types .= "s";
            $params[] = $filters['department'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND e.employment_status = ?";
            $types .= "s";
            $params[] = $filters['status'];
        }

        if (!empty($filters['employment_type'])) {
            $sql .= " AND e.employment_type = ?";
            $types .= "s";
            $params[] = $filters['employment_type'];
        }

        $sql .= " ORDER BY e.is_active DESC, e.id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $employees = [];
        while ($row = $res->fetch_assoc()) {
            $employees[] = $row;
        }
        $stmt->close();
        return $employees;
    }

    /**
     * Get detailed single employee profile by ID.
     */
    public static function getEmployeeById(mysqli $conn, int $tenantId, int $empId): ?array {
        self::ensureHrSchema($conn);
        $stmt = $conn->prepare("SELECT e.*, u.email as system_email, u.role as system_role, u.created_at as account_created_at
                                FROM employees e
                                LEFT JOIN admin_users u ON e.user_id = u.id
                                WHERE e.restaurant_id = ? AND e.id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("ii", $tenantId, $empId);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$emp) return null;

        // Fetch salary history
        $shStmt = $conn->prepare("SELECT * FROM salary_history WHERE restaurant_id = ? AND employee_id = ? ORDER BY effective_date DESC, id DESC");
        $shStmt->bind_param("ii", $tenantId, $empId);
        $shStmt->execute();
        $emp['salary_history'] = $shStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $shStmt->close();

        // Fetch latest shift assignment
        $shiftStmt = $conn->prepare("SELECT es.*, st.shift_name, st.start_time, st.end_time
                                     FROM employee_shifts es
                                     JOIN shift_templates st ON es.shift_template_id = st.id
                                     WHERE es.restaurant_id = ? AND es.employee_id = ? AND es.assigned_date >= CURDATE()
                                     ORDER BY es.assigned_date ASC LIMIT 1");
        $shiftStmt->bind_param("ii", $tenantId, $empId);
        $shiftStmt->execute();
        $emp['current_shift'] = $shiftStmt->get_result()->fetch_assoc();
        $shiftStmt->close();

        // Fetch recent attendance summary (last 30 days)
        $attStmt = $conn->prepare("SELECT COUNT(*) as total_days,
                                          SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
                                          SUM(CASE WHEN status = 'Late' OR late_mins > 0 THEN 1 ELSE 0 END) as late_days,
                                          SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                                          SUM(worked_hours) as total_worked_hours,
                                          SUM(overtime_hours) as total_overtime_hours
                                   FROM employee_attendance
                                   WHERE restaurant_id = ? AND employee_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $attStmt->bind_param("ii", $tenantId, $empId);
        $attStmt->execute();
        $emp['attendance_summary'] = $attStmt->get_result()->fetch_assoc();
        $attStmt->close();

        // Fetch salary advances
        $advStmt = $conn->prepare("SELECT * FROM salary_advances WHERE restaurant_id = ? AND employee_id = ? ORDER BY advance_date DESC");
        $advStmt->bind_param("ii", $tenantId, $empId);
        $advStmt->execute();
        $emp['salary_advances'] = $advStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $advStmt->close();

        return $emp;
    }

    /**
     * Create Employee & optional system login account.
     */
    public static function createEmployee(mysqli $conn, int $tenantId, array $data, int $actingUserId): array {
        self::ensureHrSchema($conn);

        $fullName = Security::sanitize(trim($data['full_name'] ?? ''));
        $email = Security::sanitize(trim($data['email'] ?? ''));
        $phone = Security::sanitize(trim($data['phone'] ?? ''));
        $dept = Security::sanitize(trim($data['department'] ?? 'Operations'));
        $desig = Security::sanitize(trim($data['designation'] ?? 'Staff'));
        $empType = Security::sanitize(trim($data['employment_type'] ?? 'Full Time'));
        $empStatus = Security::sanitize(trim($data['employment_status'] ?? 'Active'));
        $baseSalary = max(0, (float)($data['base_salary'] ?? 0.00));
        $joiningDate = !empty($data['joining_date']) ? $data['joining_date'] : date('Y-m-d');
        $dob = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
        $gender = Security::sanitize(trim($data['gender'] ?? ''));
        $address = Security::sanitize(trim($data['address'] ?? ''));
        $bankInfo = Security::sanitize(trim($data['bank_info'] ?? ''));
        $emergency = Security::sanitize(trim($data['emergency_contact'] ?? ''));
        $notes = Security::sanitize(trim($data['notes'] ?? ''));

        if (empty($fullName)) {
            return ['success' => false, 'error' => "Employee full name is required."];
        }

        // System account creation if requested
        $userId = null;
        $createSystemAccount = !empty($data['create_system_account']);
        if ($createSystemAccount) {
            $accountEmail = strtolower(trim($data['account_email'] ?? $data['email'] ?? ''));
            $password = $data['password'] ?? '';
            $role = strtoupper(Security::sanitize(trim($data['role'] ?? 'CASHIER')));

            // CRITICAL: Block restaurant admins from creating SUPER_ADMIN accounts
            if ($role === 'SUPER_ADMIN') {
                return ['success' => false, 'error' => "Unauthorized: Restaurant staff accounts cannot be granted SUPER_ADMIN privileges."];
            }

            if (empty($accountEmail) || !filter_var($accountEmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'error' => "A valid email address is required for system login account creation."];
            }
            if (empty($password)) {
                return ['success' => false, 'error' => "Password is required for system account creation."];
            }

            // Check duplicate email across system
            $chk = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
            $chk->bind_param("s", $accountEmail);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                return ['success' => false, 'error' => "An account with this email address already exists."];
            }
            $chk->close();

            $hashPass = password_hash($password, PASSWORD_DEFAULT);
            $insUser = $conn->prepare("INSERT INTO admin_users (email, password, full_name, role, restaurant_id, is_super_admin) VALUES (?, ?, ?, ?, ?, 0)");
            $insUser->bind_param("ssssi", $accountEmail, $hashPass, $fullName, $role, $tenantId);
            if (!$insUser->execute()) {
                return ['success' => false, 'error' => "Failed to create user account: " . $insUser->error];
            }
            $userId = $insUser->insert_id;
            $insUser->close();
        }

        // Generate EMP code e.g. EMP-0001
        $cntStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM employees WHERE restaurant_id = ?");
        $cntStmt->bind_param("i", $tenantId);
        $cntStmt->execute();
        $cntRes = $cntStmt->get_result()->fetch_assoc();
        $cntStmt->close();
        $nextNum = ((int)$cntRes['cnt']) + 1;
        $empCode = 'EMP-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO employees
            (restaurant_id, user_id, emp_code, full_name, email, phone, address, date_of_birth, gender, department, designation, joining_date, employment_type, employment_status, base_salary, bank_info, emergency_contact, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissssssssssssdsss", $tenantId, $userId, $empCode, $fullName, $email, $phone, $address, $dob, $gender, $dept, $desig, $joiningDate, $empType, $empStatus, $baseSalary, $bankInfo, $emergency, $notes);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to create employee record: " . $err];
        }
        $empId = $stmt->insert_id;
        $stmt->close();

        // Initial salary history record
        if ($baseSalary > 0) {
            $sh = $conn->prepare("INSERT INTO salary_history (restaurant_id, employee_id, base_salary, effective_date, reason, changed_by) VALUES (?, ?, ?, ?, 'Initial Salary', ?)");
            $sh->bind_param("iidsi", $tenantId, $empId, $baseSalary, $joiningDate, $actingUserId);
            $sh->execute();
            $sh->close();
        }

        self::logAudit($conn, $tenantId, $actingUserId, 'EMPLOYEE_CREATED', 'employee', $empId, null, ['emp_code' => $empCode, 'name' => $fullName]);
        return ['success' => true, 'employee_id' => $empId, 'emp_code' => $empCode];
    }

    /**
     * Update Employee record & optional salary history. Soft deactivation if status changes.
     */
    public static function updateEmployee(mysqli $conn, int $tenantId, int $empId, array $data, int $actingUserId): array {
        self::ensureHrSchema($conn);

        $existing = self::getEmployeeById($conn, $tenantId, $empId);
        if (!$existing) {
            return ['success' => false, 'error' => "Employee not found or access denied."];
        }

        $fullName = Security::sanitize(trim($data['full_name'] ?? $existing['full_name']));
        $email = Security::sanitize(trim($data['email'] ?? $existing['email']));
        $phone = Security::sanitize(trim($data['phone'] ?? $existing['phone']));
        $dept = Security::sanitize(trim($data['department'] ?? $existing['department']));
        $desig = Security::sanitize(trim($data['designation'] ?? $existing['designation']));
        $empType = Security::sanitize(trim($data['employment_type'] ?? $existing['employment_type']));
        $empStatus = Security::sanitize(trim($data['employment_status'] ?? $existing['employment_status']));
        $baseSalary = max(0, (float)($data['base_salary'] ?? $existing['base_salary']));
        $joiningDate = !empty($data['joining_date']) ? $data['joining_date'] : $existing['joining_date'];
        $dob = !empty($data['date_of_birth']) ? $data['date_of_birth'] : $existing['date_of_birth'];
        $gender = Security::sanitize(trim($data['gender'] ?? $existing['gender']));
        $address = Security::sanitize(trim($data['address'] ?? $existing['address']));
        $bankInfo = Security::sanitize(trim($data['bank_info'] ?? $existing['bank_info']));
        $emergency = Security::sanitize(trim($data['emergency_contact'] ?? $existing['emergency_contact']));
        $notes = Security::sanitize(trim($data['notes'] ?? $existing['notes']));

        // Soft deactivation if status is Resigned or Terminated
        $isActive = in_array($empStatus, ['Resigned', 'Terminated']) ? 0 : (int)($data['is_active'] ?? $existing['is_active']);

        $stmt = $conn->prepare("UPDATE employees SET
            full_name = ?, email = ?, phone = ?, address = ?, date_of_birth = ?, gender = ?, department = ?, designation = ?, joining_date = ?, employment_type = ?, employment_status = ?, base_salary = ?, bank_info = ?, emergency_contact = ?, notes = ?, is_active = ?
            WHERE restaurant_id = ? AND id = ?");
        $stmt->bind_param("sssssssssssssssiii", $fullName, $email, $phone, $address, $dob, $gender, $dept, $desig, $joiningDate, $empType, $empStatus, $baseSalary, $bankInfo, $emergency, $notes, $isActive, $tenantId, $empId);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to update employee: " . $err];
        }
        $stmt->close();

        // Check if salary changed -> preserve salary history
        if (abs(((float)$existing['base_salary']) - $baseSalary) > 0.01) {
            $effDate = date('Y-m-d');
            $shReason = Security::sanitize(trim($data['salary_change_reason'] ?? 'Salary Revision'));
            $sh = $conn->prepare("INSERT INTO salary_history (restaurant_id, employee_id, base_salary, effective_date, reason, changed_by) VALUES (?, ?, ?, ?, ?, ?)");
            $sh->bind_param("iidssi", $tenantId, $empId, $baseSalary, $effDate, $shReason, $actingUserId);
            $sh->execute();
            $sh->close();
        }

        // Linked System Account Role update if exists
        if ($existing['user_id'] && !empty($data['role'])) {
            $newRole = strtoupper(Security::sanitize(trim($data['role'])));

            // Prevent assigning SUPER_ADMIN to tenant user
            if ($newRole !== 'SUPER_ADMIN') {
                $uUpd = $conn->prepare("UPDATE admin_users SET role = ?, full_name = ? WHERE id = ? AND (restaurant_id = ? OR restaurant_id IS NULL)");
                $uUpd->bind_param("ssii", $newRole, $fullName, $existing['user_id'], $tenantId);
                $uUpd->execute();
                $uUpd->close();
            }
        }

        self::logAudit($conn, $tenantId, $actingUserId, 'EMPLOYEE_UPDATED', 'employee', $empId, ['old_salary' => $existing['base_salary']], ['new_salary' => $baseSalary]);
        return ['success' => true];
    }

    /**
     * Get Shift Templates for Tenant.
     */
    public static function getShiftTemplates(mysqli $conn, int $tenantId): array {
        self::seedTenantHrDefaults($conn, $tenantId);
        $stmt = $conn->prepare("SELECT * FROM shift_templates WHERE restaurant_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $tenantId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    /**
     * Create custom Shift Template.
     */
    public static function createShiftTemplate(mysqli $conn, int $tenantId, array $data, int $actingUserId): array {
        self::ensureHrSchema($conn);
        $name = Security::sanitize(trim($data['shift_name'] ?? ''));
        $startTime = $data['start_time'] ?? '08:00:00';
        $endTime = $data['end_time'] ?? '16:00:00';
        $breakMins = max(0, (int)($data['break_duration_mins'] ?? 60));
        $graceMins = max(0, (int)($data['grace_period_mins'] ?? 15));
        $otThreshold = max(0, (int)($data['overtime_threshold_mins'] ?? 480));

        if (empty($name)) {
            return ['success' => false, 'error' => "Shift name is required."];
        }

        $stmt = $conn->prepare("INSERT INTO shift_templates (restaurant_id, shift_name, start_time, end_time, break_duration_mins, grace_period_mins, overtime_threshold_mins) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssiii", $tenantId, $name, $startTime, $endTime, $breakMins, $graceMins, $otThreshold);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to create shift template: " . $err];
        }
        $shiftId = $stmt->insert_id;
        $stmt->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'SHIFT_TEMPLATE_CREATED', 'shift_template', $shiftId, null, ['name' => $name]);
        return ['success' => true, 'shift_id' => $shiftId];
    }

    /**
     * Update an existing Shift Template.
     */
    public static function updateShiftTemplate(mysqli $conn, int $tenantId, int $shiftId, array $data, int $actingUserId): array {
        self::ensureHrSchema($conn);
        $name = Security::sanitize(trim($data['shift_name'] ?? ''));
        $startTime = $data['start_time'] ?? '08:00:00';
        $endTime = $data['end_time'] ?? '16:00:00';
        $breakMins = max(0, (int)($data['break_duration_mins'] ?? 60));
        $graceMins = max(0, (int)($data['grace_period_mins'] ?? 15));
        $otThreshold = max(0, (int)($data['overtime_threshold_mins'] ?? 480));

        if (empty($name)) {
            return ['success' => false, 'error' => "Shift name is required."];
        }

        $stmt = $conn->prepare("UPDATE shift_templates SET shift_name = ?, start_time = ?, end_time = ?, break_duration_mins = ?, grace_period_mins = ?, overtime_threshold_mins = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("sssiiiii", $name, $startTime, $endTime, $breakMins, $graceMins, $otThreshold, $shiftId, $tenantId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to update shift template: " . $err];
        }
        $stmt->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'SHIFT_TEMPLATE_UPDATED', 'shift_template', $shiftId, null, ['name' => $name]);
        return ['success' => true];
    }

    /**
     * Delete a Shift Template.
     */
    public static function deleteShiftTemplate(mysqli $conn, int $tenantId, int $shiftId, int $actingUserId): array {
        self::ensureHrSchema($conn);

        $stmt = $conn->prepare("DELETE FROM shift_templates WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("ii", $shiftId, $tenantId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to delete shift template: " . $err];
        }
        $stmt->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'SHIFT_TEMPLATE_DELETED', 'shift_template', $shiftId);
        return ['success' => true];
    }

    /**
     * Assign Shift to Employee for a date.
     */
    public static function assignEmployeeShift(mysqli $conn, int $tenantId, int $empId, int $shiftTemplateId, string $assignedDate, int $assignedBy, string $notes = ''): array {
        self::ensureHrSchema($conn);

        $stmt = $conn->prepare("INSERT INTO employee_shifts (restaurant_id, employee_id, shift_template_id, assigned_date, assigned_by, notes)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE shift_template_id = VALUES(shift_template_id), assigned_by = VALUES(assigned_by), notes = VALUES(notes)");
        $stmt->bind_param("iiisis", $tenantId, $empId, $shiftTemplateId, $assignedDate, $assignedBy, $notes);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to assign shift: " . $err];
        }
        $stmt->close();

        self::logAudit($conn, $tenantId, $assignedBy, 'SHIFT_ASSIGNED', 'employee_shift', $empId, null, ['date' => $assignedDate, 'shift_id' => $shiftTemplateId]);
        return ['success' => true];
    }

    /**
     * Clock In Employee with Server-side validation and Late calculation.
     */
    public static function clockIn(mysqli $conn, int $tenantId, int $empId, ?string $clockInTime = null, string $notes = ''): array {
        self::ensureHrSchema($conn);

        $now = $clockInTime ? date('Y-m-d H:i:s', strtotime($clockInTime)) : date('Y-m-d H:i:s');
        $today = date('Y-m-d', strtotime($now));

        // Check duplicate active clock-in
        $chk = $conn->prepare("SELECT id, clock_in, clock_out FROM employee_attendance WHERE restaurant_id = ? AND employee_id = ? AND attendance_date = ? LIMIT 1");
        $chk->bind_param("iis", $tenantId, $empId, $today);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing && !empty($existing['clock_in']) && empty($existing['clock_out'])) {
            return ['success' => false, 'error' => "Employee is already clocked in for today."];
        }

        // Fetch assigned shift for schedule calculation
        $shiftStmt = $conn->prepare("SELECT st.* FROM employee_shifts es JOIN shift_templates st ON es.shift_template_id = st.id WHERE es.restaurant_id = ? AND es.employee_id = ? AND es.assigned_date = ? LIMIT 1");
        $shiftStmt->bind_param("iis", $tenantId, $empId, $today);
        $shiftStmt->execute();
        $shift = $shiftStmt->get_result()->fetch_assoc();
        $shiftStmt->close();

        $lateMins = 0;
        $status = 'Present';

        if ($shift) {
            $scheduledStart = strtotime($today . ' ' . $shift['start_time']);
            $actualStart = strtotime($now);
            $graceSecs = ($shift['grace_period_mins'] ?? 15) * 60;

            if ($actualStart > ($scheduledStart + $graceSecs)) {
                $lateMins = (int)ceil(($actualStart - $scheduledStart) / 60);
                $status = 'Late';
            }
        }

        if ($existing) {
            $stmt = $conn->prepare("UPDATE employee_attendance SET clock_in = ?, late_mins = ?, status = ?, notes = ? WHERE id = ? AND restaurant_id = ?");
            $stmt->bind_param("sissii", $now, $lateMins, $status, $notes, $existing['id'], $tenantId);
            $stmt->execute();
            $stmt->close();
            $attId = $existing['id'];
        } else {
            $stmt = $conn->prepare("INSERT INTO employee_attendance (restaurant_id, employee_id, attendance_date, clock_in, late_mins, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iississ", $tenantId, $empId, $today, $now, $lateMins, $status, $notes);
            $stmt->execute();
            $attId = $stmt->insert_id;
            $stmt->close();
        }

        self::logAudit($conn, $tenantId, null, 'CLOCK_IN', 'attendance', $attId, null, ['time' => $now, 'late_mins' => $lateMins]);
        return ['success' => true, 'clock_in' => $now, 'late_mins' => $lateMins, 'status' => $status];
    }

    /**
     * Clock Out Employee & Server-side calculation of worked hours, overtime & early departure.
     */
    public static function clockOut(mysqli $conn, int $tenantId, int $empId, ?string $clockOutTime = null, int $breakMins = 60, string $notes = ''): array {
        self::ensureHrSchema($conn);

        $now = $clockOutTime ? date('Y-m-d H:i:s', strtotime($clockOutTime)) : date('Y-m-d H:i:s');
        $today = date('Y-m-d', strtotime($now));

        $chk = $conn->prepare("SELECT id, clock_in, clock_out, break_mins, status FROM employee_attendance WHERE restaurant_id = ? AND employee_id = ? AND attendance_date = ? LIMIT 1");
        $chk->bind_param("iis", $tenantId, $empId, $today);
        $chk->execute();
        $att = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$att || empty($att['clock_in'])) {
            return ['success' => false, 'error' => "Cannot clock out: No active clock-in record found for today."];
        }

        if (!empty($att['clock_out'])) {
            return ['success' => false, 'error' => "Employee has already clocked out for today."];
        }

        $clockInTs = strtotime($att['clock_in']);
        $clockOutTs = strtotime($now);

        // Server-side validation: Prevent clock-out before clock-in
        if ($clockOutTs <= $clockInTs) {
            return ['success' => false, 'error' => "Invalid timestamp: Clock-out time must be after clock-in time."];
        }

        $totalElapsedMins = (int)ceil(($clockOutTs - $clockInTs) / 60);
        $actualBreakMins = max(0, $breakMins);
        $netWorkedMins = max(0, $totalElapsedMins - $actualBreakMins);
        $workedHours = round($netWorkedMins / 60.0, 2);

        // Overtime & Shift calculation
        $shiftStmt = $conn->prepare("SELECT st.* FROM employee_shifts es JOIN shift_templates st ON es.shift_template_id = st.id WHERE es.restaurant_id = ? AND es.employee_id = ? AND es.assigned_date = ? LIMIT 1");
        $shiftStmt->bind_param("iis", $tenantId, $empId, $today);
        $shiftStmt->execute();
        $shift = $shiftStmt->get_result()->fetch_assoc();
        $shiftStmt->close();

        $otThresholdMins = $shift['overtime_threshold_mins'] ?? 480; // 8 hours default
        $overtimeHours = 0.00;
        $earlyDepartureMins = 0;

        if ($netWorkedMins > $otThresholdMins) {
            $overtimeHours = round(($netWorkedMins - $otThresholdMins) / 60.0, 2);
        }

        if ($shift) {
            $scheduledEnd = strtotime($today . ' ' . $shift['end_time']);
            if ($clockOutTs < $scheduledEnd) {
                $earlyDepartureMins = (int)ceil(($scheduledEnd - $clockOutTs) / 60);
            }
        }

        $status = $att['status'];
        if ($workedHours < 4.0 && $status !== 'Absent') {
            $status = 'Half Day';
        }

        $stmt = $conn->prepare("UPDATE employee_attendance SET clock_out = ?, break_mins = ?, worked_hours = ?, overtime_hours = ?, early_departure_mins = ?, status = ?, notes = IF(? != '', ?, notes) WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("siddisssii", $now, $actualBreakMins, $workedHours, $overtimeHours, $earlyDepartureMins, $status, $notes, $notes, $att['id'], $tenantId);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to update clock-out: " . $err];
        }
        $stmt->close();

        self::logAudit($conn, $tenantId, null, 'CLOCK_OUT', 'attendance', $att['id'], null, ['clock_out' => $now, 'worked_hours' => $workedHours, 'overtime_hours' => $overtimeHours]);
        return ['success' => true, 'clock_out' => $now, 'worked_hours' => $workedHours, 'overtime_hours' => $overtimeHours];
    }

    /**
     * Get Attendance records list.
     */
    public static function getAttendanceRecords(mysqli $conn, int $tenantId, array $filters = []): array {
        self::ensureHrSchema($conn);

        $sql = "SELECT a.*, e.emp_code, e.full_name, e.department, e.designation
                FROM employee_attendance a
                JOIN employees e ON a.employee_id = e.id AND a.restaurant_id = e.restaurant_id
                WHERE a.restaurant_id = ?";
        $types = "i";
        $params = [$tenantId];

        if (!empty($filters['employee_id'])) {
            $sql .= " AND a.employee_id = ?";
            $types .= "i";
            $params[] = (int)$filters['employee_id'];
        }

        if (!empty($filters['date'])) {
            $sql .= " AND a.attendance_date = ?";
            $types .= "s";
            $params[] = $filters['date'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND a.status = ?";
            $types .= "s";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY a.attendance_date DESC, a.id DESC LIMIT 200";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    /**
     * Create/Manage Salary Advances.
     */
    public static function requestSalaryAdvance(mysqli $conn, int $tenantId, int $empId, float $amount, string $reason, string $repaymentMethod = 'Payroll Deduction', int $actingUserId = 0): array {
        self::ensureHrSchema($conn);

        if ($amount <= 0) {
            return ['success' => false, 'error' => "Advance amount must be greater than zero."];
        }

        $advDate = date('Y-m-d');
        $stmt = $conn->prepare("INSERT INTO salary_advances (restaurant_id, employee_id, advance_date, amount, reason, repayment_method, status) VALUES (?, ?, ?, ?, ?, ?, 'Approved')");
        $stmt->bind_param("iisdss", $tenantId, $empId, $advDate, $amount, $reason, $repaymentMethod);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to record salary advance: " . $err];
        }
        $advId = $stmt->insert_id;
        $stmt->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'SALARY_ADVANCE_CREATED', 'salary_advance', $advId, null, ['amount' => $amount, 'reason' => $reason]);
        return ['success' => true, 'advance_id' => $advId];
    }

    /**
     * Create Payroll Period.
     */
    public static function createPayrollPeriod(mysqli $conn, int $tenantId, string $periodName, string $startDate, string $endDate, int $actingUserId): array {
        self::ensureHrSchema($conn);

        if (empty($periodName) || empty($startDate) || empty($endDate)) {
            return ['success' => false, 'error' => "Period name, start date, and end date are required."];
        }

        $stmt = $conn->prepare("INSERT INTO payroll_periods (restaurant_id, period_name, start_date, end_date, status) VALUES (?, ?, ?, ?, 'Draft')");
        $stmt->bind_param("isss", $tenantId, $periodName, $startDate, $endDate);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => "Failed to create payroll period: " . $err];
        }
        $periodId = $stmt->insert_id;
        $stmt->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'PAYROLL_PERIOD_CREATED', 'payroll_period', $periodId, null, ['name' => $periodName, 'dates' => "$startDate to $endDate"]);
        return ['success' => true, 'period_id' => $periodId];
    }

    /**
     * SERVER-SIDE PAYROLL CALCULATION ENGINE.
     * Computes Base Salary + Overtime Pay + Allowances + Bonus - Deductions - Salary Advance = Net Salary.
     */
    public static function calculatePayroll(mysqli $conn, int $tenantId, int $periodId, int $actingUserId): array {
        self::ensureHrSchema($conn);

        // Fetch Period
        $pStmt = $conn->prepare("SELECT * FROM payroll_periods WHERE restaurant_id = ? AND id = ? LIMIT 1");
        $pStmt->bind_param("ii", $tenantId, $periodId);
        $pStmt->execute();
        $period = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();

        if (!$period) {
            return ['success' => false, 'error' => "Payroll period not found."];
        }

        if (in_array($period['status'], ['Approved', 'Paid'])) {
            return ['success' => false, 'error' => "Cannot recalculate an Approved or Paid payroll period."];
        }

        // Fetch HR Settings
        $hrSetStmt = $conn->prepare("SELECT * FROM restaurant_hr_settings WHERE restaurant_id = ? LIMIT 1");
        $hrSetStmt->bind_param("i", $tenantId);
        $hrSetStmt->execute();
        $hrSettings = $hrSetStmt->get_result()->fetch_assoc();
        $hrSetStmt->close();

        $otMultiplier = (float)($hrSettings['overtime_hourly_rate_multiplier'] ?? 1.50);

        // Fetch active employees
        $empStmt = $conn->prepare("SELECT * FROM employees WHERE restaurant_id = ? AND is_active = 1");
        $empStmt->bind_param("i", $tenantId);
        $empStmt->execute();
        $employees = $empStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $empStmt->close();

        $totalGross = 0.00;
        $totalNet = 0.00;
        $processedCount = 0;

        foreach ($employees as $emp) {
            $empId = $emp['id'];
            $baseSalary = (float)$emp['base_salary'];

            // Calculate Overtime Pay from Attendance in Period
            $attStmt = $conn->prepare("SELECT SUM(overtime_hours) as total_ot FROM employee_attendance WHERE restaurant_id = ? AND employee_id = ? AND attendance_date BETWEEN ? AND ?");
            $attStmt->bind_param("iiss", $tenantId, $empId, $period['start_date'], $period['end_date']);
            $attStmt->execute();
            $attRes = $attStmt->get_result()->fetch_assoc();
            $attStmt->close();

            $totalOtHours = (float)($attRes['total_ot'] ?? 0.00);
            $hourlyRate = ($baseSalary > 0) ? ($baseSalary / 208.0) : 0.00; // ~26 days * 8 hrs
            $otPay = round($totalOtHours * $hourlyRate * $otMultiplier, 2);

            // Fetch active Salary Advances
            $advStmt = $conn->prepare("SELECT id, amount, repaid_amount FROM salary_advances WHERE restaurant_id = ? AND employee_id = ? AND status = 'Approved' AND repaid_amount < amount");
            $advStmt->bind_param("ii", $tenantId, $empId);
            $advStmt->execute();
            $advList = $advStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $advStmt->close();

            $advanceDeduction = 0.00;
            foreach ($advList as $adv) {
                $outstanding = ((float)$adv['amount']) - ((float)$adv['repaid_amount']);
                $advanceDeduction += $outstanding;
            }

            $allowances = 0.00;
            $bonus = 0.00;
            $deductions = 0.00;

            $grossSalary = round($baseSalary + $otPay + $allowances + $bonus, 2);
            $netSalary = max(0.00, round($grossSalary - $deductions - $advanceDeduction, 2));

            $totalGross += $grossSalary;
            $totalNet += $netSalary;

            // Insert or Update payroll item
            $insPay = $conn->prepare("INSERT INTO payrolls
                (restaurant_id, payroll_period_id, employee_id, base_salary, allowances, overtime_pay, bonus, deductions, advance_deduction, gross_salary, net_salary, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Calculated')
                ON DUPLICATE KEY UPDATE
                base_salary = VALUES(base_salary), overtime_pay = VALUES(overtime_pay), gross_salary = VALUES(gross_salary), net_salary = VALUES(net_salary), status = 'Calculated'");
            $insPay->bind_param("iiidddddddd", $tenantId, $periodId, $empId, $baseSalary, $allowances, $otPay, $bonus, $deductions, $advanceDeduction, $grossSalary, $netSalary);
            $insPay->execute();
            $insPay->close();

            $processedCount++;
        }

        // Update Payroll Period Totals
        $updPeriod = $conn->prepare("UPDATE payroll_periods SET total_gross = ?, total_net = ?, status = 'Calculated' WHERE id = ? AND restaurant_id = ?");
        $updPeriod->bind_param("ddii", $totalGross, $totalNet, $periodId, $tenantId);
        $updPeriod->execute();
        $updPeriod->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'PAYROLL_CALCULATED', 'payroll_period', $periodId, null, ['gross' => $totalGross, 'net' => $totalNet, 'processed' => $processedCount]);
        return ['success' => true, 'processed' => $processedCount, 'total_gross' => $totalGross, 'total_net' => $totalNet];
    }

    /**
     * Approve Payroll Period.
     */
    public static function approvePayroll(mysqli $conn, int $tenantId, int $periodId, int $actingUserId): array {
        self::ensureHrSchema($conn);

        $updP = $conn->prepare("UPDATE payroll_periods SET status = 'Approved' WHERE id = ? AND restaurant_id = ?");
        $updP->bind_param("ii", $periodId, $tenantId);
        if (!$updP->execute()) {
            $err = $updP->error;
            $updP->close();
            return ['success' => false, 'error' => "Failed to approve payroll period: " . $err];
        }
        $updP->close();

        $updItems = $conn->prepare("UPDATE payrolls SET status = 'Approved' WHERE payroll_period_id = ? AND restaurant_id = ?");
        $updItems->bind_param("ii", $periodId, $tenantId);
        $updItems->execute();
        $updItems->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'PAYROLL_APPROVED', 'payroll_period', $periodId);
        return ['success' => true];
    }

    /**
     * Pay Payroll Period & generate final payslips.
     */
    public static function payPayroll(mysqli $conn, int $tenantId, int $periodId, string $paymentMethod, int $actingUserId): array {
        self::ensureHrSchema($conn);
        $payDate = date('Y-m-d H:i:s');

        $updP = $conn->prepare("UPDATE payroll_periods SET status = 'Paid' WHERE id = ? AND restaurant_id = ?");
        $updP->bind_param("ii", $periodId, $tenantId);
        if (!$updP->execute()) {
            $err = $updP->error;
            $updP->close();
            return ['success' => false, 'error' => "Failed to mark payroll paid: " . $err];
        }
        $updP->close();

        $updItems = $conn->prepare("UPDATE payrolls SET status = 'Paid', payment_method = ?, payment_date = ? WHERE payroll_period_id = ? AND restaurant_id = ?");
        $updItems->bind_param("ssii", $paymentMethod, $payDate, $periodId, $tenantId);
        $updItems->execute();
        $updItems->close();

        // Mark salary advances as repaid for processed payroll items
        $advRep = $conn->prepare("UPDATE salary_advances sa
                                  JOIN payrolls p ON sa.employee_id = p.employee_id AND sa.restaurant_id = p.restaurant_id
                                  SET sa.repaid_amount = sa.amount, sa.status = 'Repaid'
                                  WHERE p.payroll_period_id = ? AND sa.restaurant_id = ? AND sa.status = 'Approved'");
        $advRep->bind_param("ii", $periodId, $tenantId);
        $advRep->execute();
        $advRep->close();

        self::logAudit($conn, $tenantId, $actingUserId, 'PAYROLL_PAID', 'payroll_period', $periodId, null, ['payment_method' => $paymentMethod, 'paid_at' => $payDate]);
        return ['success' => true];
    }

    /**
     * Get Itemized Payslip Data for printing/viewing.
     */
    public static function getPayslip(mysqli $conn, int $tenantId, int $payrollId): ?array {
        self::ensureHrSchema($conn);

        $stmt = $conn->prepare("SELECT p.*, pp.period_name, pp.start_date, pp.end_date, e.emp_code, e.full_name, e.department, e.designation, e.bank_info
                                FROM payrolls p
                                JOIN payroll_periods pp ON p.payroll_period_id = pp.id AND p.restaurant_id = pp.restaurant_id
                                JOIN employees e ON p.employee_id = e.id AND p.restaurant_id = e.restaurant_id
                                WHERE p.restaurant_id = ? AND p.id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("ii", $tenantId, $payrollId);
        $stmt->execute();
        $payslip = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $payslip;
    }
}
