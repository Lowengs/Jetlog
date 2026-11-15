<?php
require_once __DIR__ . '/auth.php';
logoutUser();
header('Location: index.php?msg=' . urlencode('You have been logged out successfully.'));
exit;
