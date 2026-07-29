<?php
require_once 'connection/config.php';
require_once '__init.php';

header('Location: ' . rtrim((string) $baseUrl, '/') . '/');
exit();
