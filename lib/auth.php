<?php
// lib/auth.php
require_once __DIR__ . '/../app/config/db.php'; // عدّل المسار لو مختلف عندك
require_once __DIR__ . '/../lib/helpers.php';

session_start();

/** Helpers: وجود جدول/عمود */
function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function login($username, $password) {
    $pdo = db();

    // اكتشاف المخطط فعليًا
    $users_has_is_active = column_exists($pdo, 'users', 'is_active');
    $users_has_role      = column_exists($pdo, 'users', 'role');      // قد لا يكون موجود
    $users_has_role_id   = column_exists($pdo, 'users', 'role_id');   // قد لا يكون موجود
    $roles_table_exists  = table_exists($pdo, 'roles');
    $roles_has_name      = $roles_table_exists && column_exists($pdo, 'roles', 'name');

    // بناء الـSELECT و الـJOIN ديناميكيًا
    $select = "u.id, u.username, u.password";
    if ($users_has_is_active) {
        $select .= ", u.is_active";
    }

    $from   = " FROM users u ";
    $joins  = "";
    $role_expr_selected = false;

    if ($users_has_role_id && $roles_table_exists && $roles_has_name) {
        $select .= ", r.name AS role_name";
        $joins   = " LEFT JOIN roles r ON r.id = u.role_id ";
        $role_expr_selected = true;
    } elseif ($users_has_role) {
        $select .= ", u.role AS role_name";
        $role_expr_selected = true;
    }
    // لو مفيش أي عمود/جدول للدور، هنضبطه بعد الجلب كقيمة افتراضية.

    $where  = " WHERE u.username = ? ";
    if ($users_has_is_active) {
        $where .= " AND u.is_active = 1 ";
    }
    $limit  = " LIMIT 1 ";

    $sql = "SELECT {$select}{$from}{$joins}{$where}{$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    // تحقق الباسورد النصي (بدون hash)
    if ($u && hash_equals((string)$u['password'], (string)$password)) {
        $role = $role_expr_selected ? ($u['role_name'] ?? null) : null;
        if (!$role) {
            // قيمة افتراضية آمنة لو مفيش أدوار عندك
            $role = 'cashier';
        }

        $_SESSION['user'] = [
            'id'       => $u['id'],
            'username' => $u['username'],
            'role'     => $role,
        ];
        return true;
    }

    return false;
}

function require_login() {
    if (empty($_SESSION['user'])) {
        header('Location: /3zbawyh/public/login.php');
        exit;
    }
}

function current_user() { return $_SESSION['user'] ?? null; }

function logout_user() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
}

function is_admin()   { return !empty($_SESSION['user']) && $_SESSION['user']['role'] === 'admin'; }
function is_cashier() { return !empty($_SESSION['user']) && $_SESSION['user']['role'] === 'cashier'; }

function require_role($role) {
    require_login();
    if (($_SESSION['user']['role'] ?? null) !== $role) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function require_admin_or_redirect() {
    require_login();
    if (!is_admin()) {
        header('Location: /3zbawyh/public/no_access.php');
        exit;
    }
}

function require_role_in_or_redirect(array $allowed_roles) {
    require_login();
    if (!in_array($_SESSION['user']['role'], $allowed_roles, true)) {
        header('Location: /3zbawyh/public/pos.php');
        exit;
    }
}
