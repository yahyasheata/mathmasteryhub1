<?php
require_once __DIR__ . '/inc/RequestPolicy.php';
require_once __DIR__ . '/inc/AdminSecurity.php';
mmh_redirect_legacy_www_host();

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

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($requestPath === '/user' || str_starts_with($requestPath, '/user/')) {
    mmh_send_private_response_headers();
}
if ($requestPath === '/admin' || str_starts_with($requestPath, '/admin/')) {
    mmh_admin_response_headers();
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
    // The landing view is a complete document and must not be preceded by a
    // partial that emits HTML. Send its response policy before rendering.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Vary: Cookie');
    include('views/index.php');
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
        header('Location: ' . mmh_auth_destination(db(), (string) $_SESSION['admin'], 'admin', mmh_current_request_base_url()));
        exit();
    }
    if (isset($_SESSION['username'])) {
        header('Location: ' . mmh_auth_destination(db(), (string) $_SESSION['username'], 'user', mmh_current_request_base_url()));
        exit();
    }
    include("views/auth/{$authPage}.php");
});
$router->post('/auth/{authPage}', function($authPage) {
    if (!in_array($authPage, ['login', 'register'], true)) {
        header('HTTP/1.1 404 Not Found');
        exit();
    }
    require_once '__init.php';
    include("views/auth/{$authPage}_request.php");
});

// =======================
// Admin Routes
// =======================
$router->mount('/admin', function() use ($router) {

    $router->post('/logout', function() {
        mmh_admin_require_mutation();
        require __DIR__ . '/views/auth/logout.php';
    });

    $router->get('/logout', function() {
        mmh_admin_require_admin();
        http_response_code(405);
        header('Allow: POST');
        echo 'Use the administrator logout form.';
        exit;
    });

    $router->get('/', function() {
        require_once '__init.php';
        header('Location: ' . mmh_current_request_base_url() . '/admin/dashboard');
        exit();
    });

    $router->get('/courses/{courseId}/content', function($courseId) {
        require_once '__init.php';
        mmh_admin_require_admin();
        require __DIR__ . '/views/admin/course-content.php';
    });

    $router->get('/courses/{courseId}/content/{itemId}/preview', function($courseId, $itemId) {
        require_once '__init.php';
        mmh_admin_require_admin();
        require __DIR__ . '/views/admin/course-content-preview.php';
    });

    // Course Content's initial item list is read-only. Keep it outside the
    // mutation middleware so refreshes can use a normal GET request while
    // retaining the handler's own admin guard and validation.
    $router->get('/requests/item/items', function() {
        require_once '__init.php';
        mmh_admin_require_admin();
        require __DIR__ . '/views/admin/requests/items-item.php';
    });

    // Compatibility for already-open Course Content pages that still issue
    // the former POST + _method=GET list request. The handler accepts only
    // that explicit read marker and performs no mutation.
    $router->post('/requests/item/items', function() {
        require_once '__init.php';
        mmh_admin_require_admin(false);
        require __DIR__ . '/views/admin/requests/items-item.php';
    });

    $router->get('/free-learning/resource-search', function() {
        require_once '__init.php';
        mmh_admin_require_admin(false);
        require __DIR__ . '/views/admin/requests/search-resource-free-learning.php';
    });

    $router->get('/timed-exam-submissions/{courseId}/{examId}', function($courseId, $examId) {
        require_once '__init.php';
        mmh_admin_require_admin(false);
        require __DIR__ . '/views/admin/timed-exam-submissions.php';
    });

    $router->get('/timed-exam-answer/{versionId}', function($versionId) {
        require_once '__init.php';
        mmh_admin_require_admin(false);
        require __DIR__ . '/views/admin/requests/download-timed-exam-answer.php';
    });

    // Temporary, admin-only diagnostic for validating the exact SharePoint
    // Stream URL supplied by the administrator. This intentionally bypasses
    // all course/resource handling so the existing viewer remains untouched.
    $router->get('/diagnostics/sharepoint-stream-test', function() {
        require_once '__init.php';
        mmh_admin_require_admin();
        require __DIR__ . '/views/admin/diagnostics/sharepoint-stream-test.php';
    });

    // Parent Reports renders and processes the same page so Preview, comments,
    // and PDF output retain the existing Admin form workflow.
    $router->post('/parent-reports', function() {
        require_once '__init.php';
        mmh_admin_require_mutation();
        require __DIR__ . '/views/admin/parent-reports.php';
    });

    $router->get('/{pageName}', function($pageName) {
        require_once '__init.php';
        mmh_admin_require_admin();
        if (!mmh_admin_allowed_page((string) $pageName)) {
            http_response_code(404);
            echo 'Not found.';
            return;
        }
        require __DIR__ . '/views/admin/' . $pageName . '.php';
    });

    // Course Content bulk actions use the existing plural handler name. Keep
    // the generic request convention intact while routing this established
    // endpoint explicitly.
    $router->post('/requests/item/bulk', function() {
        require_once '__init.php';
        mmh_admin_require_mutation();
        require __DIR__ . '/views/admin/requests/bulk-items.php';
    });

    $router->post('/requests/{page}/{action}', function($page, $action) {
        require_once '__init.php';
        $handler = mmh_admin_allowed_handler((string) $page, (string) $action);
        if ($handler === null) {
            http_response_code(404);
            echo 'Not found.';
            return;
        }
        mmh_admin_require_mutation();
        require __DIR__ . '/views/admin/requests/' . $handler;
    });

});

// =======================
// User Routes
// =======================
$router->get('/resources/dashboard/notifications', function() {
    require_once '__init.php';
    if (!isset($_SESSION['username'])) {
        header('Location: ' . mmh_current_request_base_url() . '/auth/login');
        exit();
    }
    header('Location: ' . mmh_current_request_base_url() . '/user/notifications', true, 302);
    exit();
});

$router->post('/user/save-subscription/', function() {
    include('notification/save-subscription.php');
});

$router->mount('/user', function() use ($router) {

    $router->get('/logout', function() {
        include('views/auth/logout.php');
    });

    $router->get('/', function() {
        require_once '__init.php';
        header('Location: ' . mmh_current_request_base_url() . '/user/my-courses');
        exit();
    });

    $router->get('/live-session/join/{occurrenceId}', function($occurrenceId) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header('Location: ' . mmh_current_request_base_url() . '/auth/login');
            exit();
        }
        include('views/user/requests/join-live-session.php');
    });

    $router->get('/course/resource/{courseId}/{itemId}', function($courseId, $itemId) {
        // __init.php is loaded during the global bootstrap. Reuse its origin in
        // this callback scope; require_once alone does not import local vars.
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header('Location: ' . mmh_current_request_base_url() . '/auth/login');
            exit();
        }
        include('views/user/requests/open-course-resource.php');
    });

    // Register the paper endpoint before the shorter exam workspace pattern.
    // Bramus Router matches the shorter dynamic path first, so placing this
    // route after it makes /paper requests render the exam page again.
    $router->get('/course/{courseId}/exam/{examId}/paper', function($courseId, $examId) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) { http_response_code(401); exit('Sign in required.'); }
        include('views/user/requests/open-timed-exam-paper.php');
    });

    $router->get('/course/{courseId}/exam/{examId}', function($courseId, $examId) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header('Location: ' . mmh_current_request_base_url() . '/auth/login');
            exit();
        }
        include('views/user/timed-exam.php');
    });

    $router->post('/course/{courseId}/exam/{examId}/upload', function($courseId, $examId) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) { http_response_code(401); exit('Sign in required.'); }
        include('views/user/requests/upload-timed-exam.php');
    });

    $router->post('/course/{courseId}/exam/{examId}/remove-upload', function($courseId, $examId) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) { http_response_code(401); exit('Sign in required.'); }
        include('views/user/requests/remove-timed-exam-upload.php');
    });

    $router->post('/course/{courseId}/exam/{examId}/submit', function($courseId, $examId) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) { http_response_code(401); exit('Sign in required.'); }
        include('views/user/requests/submit-timed-exam.php');
    });

    $router->get('/course/{courseId}/recovery-plan/{planId}', function($courseId, $planId) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header('Location: ' . mmh_current_request_base_url() . '/auth/login');
            exit();
        }
        include('views/user/recovery-plan.php');
    });

    $router->get('/{pageName}/{courseId}?', function($pageName, $courseId = null) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header('Location: ' . mmh_current_request_base_url() . '/auth/login');
            exit();
        } else {
            @include("views/user/$pageName.php");
        }
    });

    $router->get('/{pageName}', function($pageName) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header('Location: ' . mmh_current_request_base_url() . '/auth/login');
            exit();
        } else {
            @include("views/user/$pageName.php");
        }
    });

    $router->post('/requests/{page}/{action}', function($page, $action) {
        require_once '__init.php';
        if (!isset($_SESSION['username'])) {
            header('Location: ' . mmh_current_request_base_url() . '/auth/login');
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
    require __DIR__ . '/views/public/payment/webhook_json.php';
});

// =======================
// Public Routes
// =======================
$router->get('/category/{categoryId}', function($categoryId = null) {
    require_once '__init.php';
    @include("views/public/category.php");
});

$router->get('/course/{courseId}/checkout', function($courseId = null) {
    require_once '__init.php';
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__DIR__ . '/views/public/checkout.php', true);
        @opcache_invalidate(__DIR__ . '/inc/PublicCourse.php', true);
    }
    @include('views/public/checkout.php');
});

$router->get('/course/{courseId}', function($courseId = null) {
    require_once '__init.php';
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__DIR__ . '/views/public/course.php', true);
        @opcache_invalidate(__DIR__ . '/inc/PublicCourse.php', true);
    }
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
    header('Location: ' . rtrim(mmh_current_request_base_url(), '/') . '/free-learning?browse=1&type=youtube_video', true, 302);
    exit();
});

$router->get('/free-notes', function() {
    require_once '__init.php';
    header('Location: ' . rtrim(mmh_current_request_base_url(), '/') . '/free-learning?browse=1&type=free_notes', true, 302);
    exit();
});

$router->get('/worksheets', function() {
    require_once '__init.php';
    header('Location: ' . rtrim(mmh_current_request_base_url(), '/') . '/free-learning?browse=1&type=worksheet', true, 302);
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
