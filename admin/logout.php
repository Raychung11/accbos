<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

auth_logout();
// Restart session purely to carry the flash message to the login page.
session_start();
flash('info', 'You have been signed out.');
redirect('/admin/login.php');
