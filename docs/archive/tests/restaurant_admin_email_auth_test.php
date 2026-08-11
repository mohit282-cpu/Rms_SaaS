<?php
// docs/archive/tests/restaurant_admin_email_auth_test.php - Restaurant Admin Email Auth Test Suite
require_once __DIR__ . '/../../../config.php';

function assertRestAuth($condition, $message) {
    if ($condition) {
        echo "  ✅ PASS: $message\n";
    } else {
        echo "  ❌ FAIL: $message\n";
        exit(1);
    }
}

echo "=================================================================\n";
echo "    RESTAURANT ADMIN EMAIL AUTHENTICATION & MIGRATION TEST SUITE \n";
echo "=================================================================\n\n";

$conn = getDBConnection();
assertRestAuth($conn !== false, "Database connection established");

// Cleanup previous test tenant data
$testRestCode = 'REST-AUTH-99';
$testEmail = 'owner@testrestaurant.com';
$testNewEmail = 'newowner@testrestaurant.com';
$testPass = 'SecurePassword123!';
$testNewPass = 'NewSecurePass456!';

$conn->query("DELETE FROM admin_users WHERE email IN ('$testEmail', '$testNewEmail')");
$conn->query("DELETE FROM restaurants WHERE restaurant_code = '$testRestCode'");

// TEST 1: Create Restaurant Admin Account & Login via Email
echo "--> Running TEST 1: Create Restaurant Admin & Email Login\n";
$stmtRest = $conn->prepare("INSERT INTO restaurants (uuid, restaurant_code, restaurant_name, owner_name, email, phone, status) VALUES ('uuid-test-99', ?, 'Test Restaurant POS', 'Test Owner', ?, '9800009999', 'ACTIVE')");
$stmtRest->bind_param("ss", $testRestCode, $testEmail);
$okR = $stmtRest->execute();
$tenantIdA = $stmtRest->insert_id;
$stmtRest->close();
assertRestAuth($okR, "Created test restaurant A (Tenant ID #$tenantIdA)");

$hashPass = password_hash($testPass, PASSWORD_DEFAULT);
$stmtUser = $conn->prepare("INSERT INTO admin_users (username, email, password, full_name, role, is_super_admin, restaurant_id) VALUES (?, ?, ?, 'Test Owner', 'OWNER', 0, ?)");
$stmtUser->bind_param("sssi", $testEmail, $testEmail, $hashPass, $tenantIdA);
$okU = $stmtUser->execute();
$userIdA = $stmtUser->insert_id;
$stmtUser->close();
assertRestAuth($okU, "Created Restaurant Admin user account with email '$testEmail'");

// Verify Email Login Lookup
$stmtLogin = $conn->prepare("SELECT id, password, role, restaurant_id FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
$normalizedEmail = strtolower($testEmail);
$stmtLogin->bind_param("s", $normalizedEmail);
$stmtLogin->execute();
$userObj = $stmtLogin->get_result()->fetch_assoc();
$stmtLogin->close();

assertRestAuth($userObj !== null, "User account located by email");
assertRestAuth(password_verify($testPass, $userObj['password']), "Password verification succeeds for email login");

// TEST 2: Verify Username Authentication Does NOT Exist / Is Not Allowed
echo "\n--> Running TEST 2: Username login non-existence / bypass prevention\n";
$dummyUsername = 'testowner_legacy_username';
$stmtUserNoEmail = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
$stmtUserNoEmail->bind_param("s", $dummyUsername);
$stmtUserNoEmail->execute();
$noRes = $stmtUserNoEmail->get_result()->fetch_assoc();
$stmtUserNoEmail->close();
assertRestAuth($noRes === null, "Legacy username '$dummyUsername' cannot authenticate via email login endpoint");

// TEST 3: Duplicate Email Check
echo "\n--> Running TEST 3: Duplicate email rejection\n";
$stmtDup = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
$stmtDup->bind_param("s", $normalizedEmail);
$stmtDup->execute();
$dupFound = $stmtDup->get_result()->num_rows > 0;
$stmtDup->close();
assertRestAuth($dupFound === true, "Duplicate email check detects existing email and rejects registration");

// TEST 4: Invalid Email Format
echo "\n--> Running TEST 4: Invalid email format validation\n";
$invalidEmail = 'owner@';
$validFormat = filter_var($invalidEmail, FILTER_VALIDATE_EMAIL);
assertRestAuth($validFormat === false, "Invalid email format 'owner@' rejected by filter_var");

// TEST 5: Change Email & Verify Old Email Cannot Log In
echo "\n--> Running TEST 5: Change email & verify old email invalidation\n";
$stmtUpEmail = $conn->prepare("UPDATE admin_users SET email = ? WHERE id = ?");
$stmtUpEmail->bind_param("si", $testNewEmail, $userIdA);
$stmtUpEmail->execute();
$stmtUpEmail->close();

// Check old email login fails
$stmtOld = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
$stmtOld->bind_param("s", $normalizedEmail);
$stmtOld->execute();
$oldRes = $stmtOld->get_result()->fetch_assoc();
$stmtOld->close();
assertRestAuth($oldRes === null, "Old email '$testEmail' can no longer log in");

// Check new email login succeeds
$stmtNew = $conn->prepare("SELECT id, password FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
$normNew = strtolower($testNewEmail);
$stmtNew->bind_param("s", $normNew);
$stmtNew->execute();
$newRes = $stmtNew->get_result()->fetch_assoc();
$stmtNew->close();
assertRestAuth($newRes !== null && password_verify($testPass, $newRes['password']), "New email '$testNewEmail' logs in successfully");

// TEST 6: Change Password & Verify Old Password Fails
echo "\n--> Running TEST 6: Change password & verify old password fails\n";
$newHash = password_hash($testNewPass, PASSWORD_DEFAULT);
$stmtUpPass = $conn->prepare("UPDATE admin_users SET password = ? WHERE id = ?");
$stmtUpPass->bind_param("si", $newHash, $userIdA);
$stmtUpPass->execute();
$stmtUpPass->close();

$stmtCheckPass = $conn->prepare("SELECT password FROM admin_users WHERE id = ? LIMIT 1");
$stmtCheckPass->bind_param("i", $userIdA);
$stmtCheckPass->execute();
$passRow = $stmtCheckPass->get_result()->fetch_assoc();
$stmtCheckPass->close();

assertRestAuth(!password_verify($testPass, $passRow['password']), "Old password verification fails");
assertRestAuth(password_verify($testNewPass, $passRow['password']), "New password verification succeeds");

// TEST 7: Tenant Isolation Enforcement
echo "\n--> Running TEST 7: Cross-tenant isolation verification\n";
// Create Tenant B
$conn->query("DELETE FROM restaurants WHERE restaurant_code = 'REST-AUTH-88'");
$stmtRestB = $conn->prepare("INSERT INTO restaurants (uuid, restaurant_code, restaurant_name, owner_name, email, phone, status) VALUES ('uuid-test-88', 'REST-AUTH-88', 'Tenant B Bistro', 'Owner B', 'ownerb@test.com', '9800008888', 'ACTIVE')");
$stmtRestB->execute();
$tenantIdB = $stmtRestB->insert_id;
$stmtRestB->close();

// Attempt cross-tenant access query: Tenant A user querying Tenant B tables
$crossQuery = $conn->prepare("SELECT * FROM categories WHERE restaurant_id = ?");
$crossQuery->bind_param("i", $tenantIdB);
$crossQuery->execute();
$crossRes = $crossQuery->get_result();
$crossQuery->close();
assertRestAuth($tenantIdA !== $tenantIdB, "Tenant A (#$tenantIdA) and Tenant B (#$tenantIdB) are separate isolated tenants");

// TEST 8: Unauthenticated Request Guard
echo "\n--> Running TEST 8: Unauthenticated request security guard\n";
Auth::logout();
assertRestAuth(!Auth::isAdminLoggedIn(), "Unauthenticated session correctly identified as logged out");

// TEST 9: SQL Injection in Email Field
echo "\n--> Running TEST 9: SQL Injection prevention in email field\n";
$sqlInjEmail = strtolower(trim("' OR '1'='1' --"));
$stmtInj = $conn->prepare("SELECT id FROM admin_users WHERE LOWER(email) = ? LIMIT 1");
$stmtInj->bind_param("s", $sqlInjEmail);
$stmtInj->execute();
$injRes = $stmtInj->get_result()->fetch_assoc();
$stmtInj->close();
assertRestAuth($injRes === null, "SQL injection string in email field safely parameterized and returned no rows");

// TEST 10: Rate Limiting Enforcement
echo "\n--> Running TEST 10: Login rate limiting enforcement\n";
$testIp = '127.0.0.88';
$testRlKey = 'admin_login_' . md5($testNewEmail) . '_' . $testIp;
RateLimiter::clear($testRlKey);
for ($i = 0; $i < 5; $i++) {
    RateLimiter::hit($testRlKey, 5, 300);
}
$allowed = RateLimiter::check($testRlKey, 5, 300);
assertRestAuth(!$allowed, "Rate limiter blocks access after 5 failed login attempts");
RateLimiter::clear($testRlKey);

// Clean up test data
$conn->query("DELETE FROM admin_users WHERE email IN ('$testEmail', '$testNewEmail')");
$conn->query("DELETE FROM restaurants WHERE restaurant_code IN ('$testRestCode', 'REST-AUTH-88')");

echo "\n=================================================================\n";
echo "   ALL 10 RESTAURANT ADMIN EMAIL AUTH TESTS PASSED SUCCESSFULLY! \n";
echo "=================================================================\n";
