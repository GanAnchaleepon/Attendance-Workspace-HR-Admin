<?php
require_once __DIR__ . '/src/bootstrap.php';

if (Auth::check()) {
    redirect('dashboard.php');
}

if (!Auth::hasAnyUser()) {
    redirect('setup.php');
}

redirect('login.php');
