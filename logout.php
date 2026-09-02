<?php
require_once __DIR__ . '/src/bootstrap.php';

Auth::logout();
redirect('login.php');
