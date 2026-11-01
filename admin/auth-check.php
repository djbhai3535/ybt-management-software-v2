<?php
/**
 * YBT Management Software — Super Admin Route Security Guard
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Enforce strict Super Admin authentication
Auth::requireSuperAdmin();
