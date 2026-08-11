<?php
// docs/archive/tests/super_admin_auth_test.php - Super Admin Email Auth Test Suite
require_once __DIR__ . '/../../../config.php';

function assertSaTest($condition, $message) {
    if ($condition) {
        echo "  ✅ PASS: $message\n";
    } else {
        echo "  ❌ FAIL: $message\n";
        exit(1);
    }
}

echo "=================================================================\n";
echo "          SUPER ADMIN EMAIL AUTHENTICATION TEST SUITE            \n";
echo "=================================================================\n\n";

$conn = getDBConnection();
assertSaTest($conn !== false, "Database connection established");

// Ensure Super Admin account is provisioned
$targetEmail = 'sovryxrms29@gmail.com';
$rawPass = 'SovryxRms29@';

// Fetch Super Admin account from DB
$stmt = $conn->prepare("SELECT id, username, email, password, role, is_super_admin FROM admin_users WHERE LOWER(email) = ? AND (is_super_admin = 1 OR LOWER(role) = 'super_admin') LIMIT 1");
$stmt->bind_param("s", $targetEmail);
$stmt->execute();
$saUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

assertSaTest($saUser !== null, "Super Admin user record exists for email $targetEmail");
assertSaTest($saUser['email'] === 'sovryxrms29@gmail.com', "Stored email matches sovryxrms29@gmail.com");
assertSaTest((int)$saUser['is_super_admin'] === 1, "is_super_admin flag is 1");
assertSaTest($saUser['role'] === 'SUPER_ADMIN', "role is SUPER_ADMIN");

// Ensure password is standard bcrypt hash and does not equal plaintext
assertSaTest($saUser['password'] !== $rawPass, "Password is NOT stored in plaintext");
assertSaTest(password_verify($rawPass, $saUser['password']), "password_verify successfully authenticates password hash");

// TEST 1: Correct Email + Password -> LOGIN SUCCESS
echo "\n--> Running TEST 1: Correct email & password\n";
$emailTest1 = strtolower(trim('sovryxrms29@gmail.com'));
$passTest1 = 'SovryxRms29@';
$valid1 = filter_var($emailTest1, FILTER_VALIDATE_EMAIL) && password_verify($passTest1, $saUser['password']);
assertSaTest($valid1, "Correct credentials authenticate successfully");

// TEST 2: Wrong Email -> LOGIN FAILED
echo "\n--> Running TEST 2: Wrong email\n";
$emailTest2 = strtolower(trim('wrongemail@gmail.com'));
$stmt = $conn->prepare("SELECT id, password FROM admin_users WHERE LOWER(email) = ? AND (is_super_admin = 1 OR LOWER(role) = 'super_admin') LIMIT 1");
$stmt->bind_param("s", $emailTest2);
$stmt->execute();
$user2 = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSaTest($user2 === null, "Lookup for wrong email returns no user (Generic error triggered)");

// TEST 3: Correct Email + Wrong Password -> LOGIN FAILED
echo "\n--> Running TEST 3: Correct email & wrong password\n";
$emailTest3 = strtolower(trim('sovryxrms29@gmail.com'));
$passTest3 = 'WrongPass123!';
$valid3 = password_verify($passTest3, $saUser['password']);
assertSaTest(!$valid3, "Wrong password fails verification");

// TEST 4: Uppercase Email -> LOGIN SUCCESS
echo "\n--> Running TEST 4: Uppercase email normalization\n";
$emailTest4 = strtolower(trim('SOVRYXRMS29@GMAIL.COM'));
assertSaTest($emailTest4 === 'sovryxrms29@gmail.com', "Uppercase email normalized to lowercase");
$stmt = $conn->prepare("SELECT id, password FROM admin_users WHERE LOWER(email) = ? AND (is_super_admin = 1 OR LOWER(role) = 'super_admin') LIMIT 1");
$stmt->bind_param("s", $emailTest4);
$stmt->execute();
$user4 = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSaTest($user4 !== null && password_verify($rawPass, $user4['password']), "Uppercase email authenticates successfully after normalization");

// TEST 5: Empty Email -> VALIDATION ERROR
echo "\n--> Running TEST 5: Empty email\n";
$emailTest5 = strtolower(trim(''));
$valid5 = !empty($emailTest5) && filter_var($emailTest5, FILTER_VALIDATE_EMAIL);
assertSaTest(!$valid5, "Empty email rejected during validation");

// TEST 6: Invalid Email Format -> VALIDATION ERROR
echo "\n--> Running TEST 6: Invalid email format\n";
$emailTest6 = strtolower(trim('notanemailformat'));
$valid6 = !empty($emailTest6) && filter_var($emailTest6, FILTER_VALIDATE_EMAIL);
assertSaTest(!$valid6, "Invalid email format rejected by filter_var");

// TEST 7: SQL Injection Attempt -> LOGIN FAILED
echo "\n--> Running TEST 7: SQL injection attempt\n";
$emailTest7 = strtolower(trim("' OR '1'='1' --"));
$stmt = $conn->prepare("SELECT id, password FROM admin_users WHERE LOWER(email) = ? AND (is_super_admin = 1 OR LOWER(role) = 'super_admin') LIMIT 1");
$stmt->bind_param("s", $emailTest7);
$stmt->execute();
$user7 = $stmt->get_result()->fetch_assoc();
$stmt->close();
assertSaTest($user7 === null, "SQL injection attempt safely parameterized and rejected");

// TEST 8: Repeated Failed Logins (Rate Limiting)
echo "\n--> Running TEST 8: Rate limiting check\n";
$testIp = '127.0.0.99';
RateLimiter::clear("superadmin_login_" . $testIp);
for ($i = 0; $i < 5; $i++) {
    RateLimiter::hit("superadmin_login_" . $testIp, 5, 300);
}
$allowed = RateLimiter::check("superadmin_login_" . $testIp, 5, 300);
assertSaTest(!$allowed, "Rate limiter blocks access after 5 failed login attempts");
RateLimiter::clear("superadmin_login_" . $testIp);

// TEST 9: Session Regeneration on Successful Login
echo "\n--> Running TEST 9: Session ID regeneration\n";
Auth::startSession();
$oldSessionId = session_id();
Auth::regenerateSession();
$newSessionId = session_id();
assertSaTest($oldSessionId !== $newSessionId, "Session ID regenerated on login");

// TEST 10: Logout Invalidation
echo "\n--> Running TEST 10: Logout session invalidation\n";
$_SESSION['admin_logged_in'] = true;
$_SESSION['is_super_admin'] = true;
Auth::logout();
assertSaTest(!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['is_super_admin']), "Session invalidated on logout");

echo "\n=================================================================\n";
echo "       ALL 10 SUPER ADMIN EMAIL AUTH TESTS PASSED SUCCESSFULLY!  \n";
echo "=================================================================\n";
