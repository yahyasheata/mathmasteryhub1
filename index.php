<?php
if (PHP_SAPI === 'cli-server') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

$sessionIsSecure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0])) === 'https';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $sessionIsSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Maintenance is a centralized public gate. Admin sessions always bypass it.
$requestPathForMaintenance = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!isset($_SESSION['admin']) && !str_starts_with($requestPathForMaintenance, '/admin')) {
    require_once __DIR__ . '/__init.php';
    require_once __DIR__ . '/connection/config.php';
    require_once __DIR__ . '/inc/SiteSettings.php';
    $siteSettings = mmh_site_settings(db());
    if (mmh_site_settings_maintenance_enabled($siteSettings)) {
        include __DIR__ . '/views/public/maintenance.php';
        exit;
    }
}

// Require composer autoloader
require __DIR__ . '/vendor/autoload.php';

// =======================
// Meta Data for Social Sharing
// =======================
$meta_title = "Math Mastery Hub";
$meta_description = "The best platform for learning math: courses, quizzes, and interactive lessons.";
$meta_url = "https://mathmasteryhub.com/";
$meta_image = "https://mathmasteryhub.com/resources/assets/img/social/og-image.png";

// =======================
// Create Router instance
// =======================
$router = new \Bramus\Router\Router();

// =======================
// Main Routes
// =======================
$router->get('/', function() use ($meta_title, $meta_description, $meta_url, $meta_image) {
    include('views/partials/header.php');
    include('views/index.php');
    include('views/partials/footer.php');
});

$router->get('/testFunc', function() {
    include('views/test/t1.php');
});

// =======================
// Authentication Routes
// =======================
$router->match('GET|POST', '/login/', function() {
    header("Location: auth/login");
});
$router->match('GET|POST', '/register/', function() {
    header("Location: auth/register");
});

$router->get('/auth/{provider}/start', function($provider) {
    include('views/auth/oauth_start.php');
});
$router->match('GET|POST', '/auth/{provider}/callback', function($provider) {
    include('views/auth/oauth_callback.php');
});
$router->get('/auth/{authPage}', function($authPage) {
    if (!in_array($authPage, ['login', 'register'], true)) {
        header('HTTP/1.1 404 Not Found');
        include('views/404.php');
        return;
    }
    require_once '__init.php';
    require_once 'connection/config.php';
    require_once 'inc/Auth.php';
    if (isset($_SESSION['admin'])) {
        header('Location: ' . mmh_auth_destination(db(), (string) $_SESSION['admin'], 'admin', (string) $baseUrl));
        exit();
    }
    if (isset($_SESSION['username'])) {
        header('Location: ' . mmh_auth_destination(db(), (string) $_SESSION['username'], 'user', (string) $baseUrl));
        exit();
    }
    include("views/auth/{$authPage}.php");
});
$router->post('/auth/{authPage}', function($authPage) {
    if (!in_array($authPage, ['login', 'register'], true)) {
        header('HTTP/1.1 404 Not Found');
        exit();
    }
    include("views/auth/{$authPage}_request.php");
});

// =======================
// Admin Routes
// =======================
$router->mount('/admin', function() use ($router) {

    $router->get('/logout', function() {
        include('views/auth/logout.php');
    });

    $router->get('/', function() {
        require_once '__init.php';
        header("Location: {$baseUrl}/admin/dashboard");
        exit();
    });

    $router->get('/courses/{courseId}/content', function($courseId) {
        require_once '__init.php';
        if (!isset($_SESSION['admin'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        }
        @include('views/admin/course-content.php');
    });

    $router->get('/courses/{courseId}/content/{itemId}/preview', function($courseId, $itemId) {
        require_once '__init.php';
        if (!isset($_SESSION['admin'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        }
        @include('views/admin/course-content-preview.php');
    });

    $router->get('/free-learning/resource-search', function() {
        require_once '__init.php';
        if (empty($_SESSION['admin'])) { http_response_code(403); exit(); }
        @include('views/admin/requests/search-resource-free-learning.php');
    });

    // Parent Reports renders and processes the same page so Preview, comments,
    // and PDF output retain the existing Admin form workflow.
    $router->post('/parent-reports', function() {
        require_once '__init.php';
        if (!isset($_SESSION['admin'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        }
        @include('views/admin/parent-reports.php');
    });

    $router->get('/{pageName}', function($pageName) {
        require_once '__init.php';
        if (!isset($_SESSION['admin'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        } else {
            @include("views/admin/$pageName.php");
        }
    });

    // Course Content bulk actions use the existing plural handler name. Keep
    // the generic request convention intact while routing this established
    // endpoint explicitly.
    $router->post('/requests/item/bulk', function() {
        require_once '__init.php';
        if (!isset($_SESSION['admin'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        }
        @include('views/admin/requests/bulk-items.php');
    });

    $router->post('/requests/{page}/{action}', function($page, $action) {
        require_once '__init.php';
        if (!isset($_SESSION['admin'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        } else {
            @include("views/admin/requests/$action-$page.php");
        }
    });

});

// =======================
// User Routes
// =======================
$router->post('/user/save-subscription/', function() {
    include('notification/save-subscription.php');
});

$router->mount('/user', function() use ($router) {

    $router->get('/logout', function() {
        include('views/auth/logout.php');
    });

    $router->get('/', function() {
        require_once '__init.php';
        header("Location: {$baseUrl}/user/my-courses");
        exit();
    });

    $router->get('/live-session/join/{occurrenceId}', function($occurrenceId) {
        global $baseUrl;
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        }
        include('views/user/requests/join-live-session.php');
    });

    $router->get('/course/resource/{courseId}/{itemId}', function($courseId, $itemId) {
        // __init.php is loaded during the global bootstrap. Reuse its origin in
        // this callback scope; require_once alone does not import local vars.
        global $baseUrl;
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        }
        include('views/user/requests/open-course-resource.php');
    });

    $router->get('/{pageName}/{courseId}?', function($pageName, $courseId = null) {
        global $baseUrl;
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        } else {
            @include("views/user/$pageName.php");
        }
    });

    $router->get('/{pageName}', function($pageName) {
        global $baseUrl;
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        } else {
            @include("views/user/$pageName.php");
        }
    });

    $router->post('/requests/{page}/{action}', function($page, $action) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header("Location: {$baseUrl}/auth/login");
            exit();
        } else {
            @include("views/user/requests/$action-$page.php");
        }
    });

});

// =======================
// Payment & Webhook
// =======================
$router->post('/payment/webhook_json', function() {
    require_once '__init.php';
    @include("views/public/payment/webhook_json.php");
});

// =======================
// Public Routes
// =======================
$router->get('/category/{categoryId}', function($categoryId = null) {
    require_once '__init.php';
    @include("views/public/category.php");
});

$router->get('/course/{courseId}', function($courseId = null) {
    require_once '__init.php';
    @include("views/public/course.php");
});


$router->get('/blog', function() {
    require_once '__init.php';
    @include('views/public/blog.php');
});
$router->get('/contact', function() {
    require_once '__init.php';
    @include('views/public/contact.php');
});

$router->get('/courses', function() {
    require_once '__init.php';
    @include('views/public/courses.php');
});

$router->get('/free-learning/collection/([A-Za-z0-9-]+)', function($collectionSlug = null) {
    require_once '__init.php';
    $freeLearningMode = 'collection';
    $freeLearningCollectionSlug = $collectionSlug;
    @include('views/public/free-learning.php');
});

$router->get('/free-learning/resource/([A-Za-z0-9_-]+)', function($resourceId = null) {
    require_once '__init.php';
    $freeLearningMode = 'resource';
    $freeLearningResourceId = $resourceId;
    @include('views/public/free-learning.php');
});

$router->get('/free-learning', function() {
    require_once '__init.php';
    $freeLearningMode = !empty($_GET['browse']) ? 'browse' : 'home';
    @include('views/public/free-learning.php');
});

$router->get('/videos', function() {
    require_once '__init.php';
    header('Location: ' . rtrim((string) $baseUrl, '/') . '/free-learning?browse=1&type=youtube_video', true, 302);
    exit();
});

$router->get('/free-notes', function() {
    require_once '__init.php';
    header('Location: ' . rtrim((string) $baseUrl, '/') . '/free-learning?browse=1&type=free_notes', true, 302);
    exit();
});

$router->get('/worksheets', function() {
    require_once '__init.php';
    header('Location: ' . rtrim((string) $baseUrl, '/') . '/free-learning?browse=1&type=worksheet', true, 302);
    exit();
});


$router->get('/resources/open/([A-Za-z0-9_-]+)', function($resourceId = null) {
    require_once '__init.php';
    @include('views/public/requests/open-free-resource.php');
});

$router->get('/past-papers', function() {
    require_once '__init.php';
    @include('views/public/past-papers.php');
});

$router->get('/past-papers/resource/{resourceId}', function($resourceId = null) {
    require_once '__init.php';
    @include('views/public/requests/open-past-paper-resource.php');
});

$router->post('/requests/{page}/{action}', function($page, $action) {
    require_once '__init.php';
    @include("views/public/requests/$action-$page.php");
});

// =======================
// 404 Page
// =======================
$router->set404(function() {
    header('HTTP/1.1 404 Not Found');
    include('views/404.php');
});

// =======================
// Run the Router
// =======================
$router->run();
