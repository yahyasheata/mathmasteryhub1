<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/OAuth.php';
require_once 'inc/LearningEvents.php';
mmh_oauth_callback(db(), (string) ($provider ?? ''), mmh_current_request_base_url());
