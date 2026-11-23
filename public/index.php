<?php
require_once __DIR__ . '/../lib/bootstrap.php';

redirect(is_logged_in() ? '/dashboard.php' : '/login.php');
