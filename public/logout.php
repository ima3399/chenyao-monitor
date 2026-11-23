<?php
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    logout_user();
    redirect('/login.php');
}

redirect(is_logged_in() ? '/dashboard.php' : '/login.php');
