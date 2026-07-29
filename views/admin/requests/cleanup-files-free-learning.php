<?php
require_once 'connection/config.php'; require_once 'inc/FreeResources.php'; mmh_free_require_admin_csrf();
$conn=db(); $available=array_flip(mmh_free_orphan_files($conn)); $deleted=0;
foreach ((array)($_POST['orphan_paths'] ?? []) as $path) { $path=(string)$path; if (isset($available[$path]) && is_file($path) && @unlink($path)) $deleted++; }
mmh_free_flash('success', $deleted ? "Deleted {$deleted} orphaned file(s)." : 'No selected orphaned files were deleted.'); header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/free-learning?maintenance=files'); exit;
