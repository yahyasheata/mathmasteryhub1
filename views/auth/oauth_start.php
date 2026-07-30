<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/OAuth.php';
mmh_oauth_start((string) ($provider ?? ''), (string) ($baseUrl ?? ''));
