<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/CourseVisibility.php';
$username = $_SESSION['admin'];
$pageName = "courses";
$subPageName = "courses";

$conn = db();
$courseSearch = trim((string) ($_GET['q'] ?? ''));
$courseStateFilter = (string) ($_GET['state'] ?? 'all');
$courseCategoryFilter = (string) ($_GET['category'] ?? 'all');
$coursePriceFilter = (string) ($_GET['price'] ?? 'all');
$courseSort = (string) ($_GET['sort'] ?? 'recent');
$coursePage = max(1, (int) ($_GET['page'] ?? 1));
$coursePerPage = (int) ($_GET['per_page'] ?? 25);
$coursePerPage = in_array($coursePerPage, [25, 50, 100], true) ? $coursePerPage : 25;

$courseSortSql = [
  'recent' => 'c.created_at DESC',
  'title' => 'c.course_title ASC',
  'students' => 'enrolled_students DESC, c.created_at DESC',
  'content' => 'content_count DESC, c.created_at DESC',
][$courseSort] ?? 'c.created_at DESC';

$courseFilters = [];
$courseTypes = '';
$courseValues = [];
if ($courseSearch !== '') {
  $courseFilters[] = '(c.course_title LIKE ? OR c.course_id LIKE ?)';
  $courseTypes .= 'ss';
  $like = '%' . $courseSearch . '%';
  $courseValues[] = $like;
  $courseValues[] = $like;
}
if (in_array($courseStateFilter, ['public', 'private', 'draft'], true)) {
  $courseFilters[] = 'c.course_state = ?';
  $courseTypes .= 's';
  $courseValues[] = $courseStateFilter;
}
if ($courseCategoryFilter !== 'all' && ctype_digit($courseCategoryFilter)) {
  $courseFilters[] = 'c.course_category = ?';
  $courseTypes .= 'i';
  $courseValues[] = (int) $courseCategoryFilter;
}
if ($coursePriceFilter === 'free') $courseFilters[] = 'c.course_price = 0';
if ($coursePriceFilter === 'paid') $courseFilters[] = 'c.course_price > 0';
$courseFilters[] = 'c.archived_at IS NULL';
$courseWhere = 'WHERE ' . implode(' AND ', $courseFilters);

$courseLoadError = null;
$courseTotal = 0;
$courseRows = [];
$courseCategories = [];
$coursePages = 1;

try {
  $countStatement = $conn->prepare("SELECT COUNT(*) AS total FROM courses c $courseWhere");
  if (!$countStatement) throw new RuntimeException('Unable to prepare the course count query.');
  if ($courseTypes !== '') $countStatement->bind_param($courseTypes, ...$courseValues);
  if (!$countStatement->execute()) throw new RuntimeException('Unable to execute the course count query.');
  $courseTotal = (int) ($countStatement->get_result()->fetch_assoc()['total'] ?? 0);
  $countStatement->close();
  $coursePages = max(1, (int) ceil($courseTotal / $coursePerPage));
  $coursePage = min($coursePage, $coursePages);
  $courseOffset = ($coursePage - 1) * $coursePerPage;

  $courseQuery = "SELECT c.*, COALESCE(cat.category_title, 'Uncategorized') AS category_title,
    COALESCE(enrollment.total, 0) AS enrolled_students,
    COALESCE(content.total, 0) AS content_count
    FROM courses c
    LEFT JOIN categories cat ON cat.id = c.course_category
    LEFT JOIN (SELECT course_id, COUNT(DISTINCT user_id) AS total FROM course_logs GROUP BY course_id) enrollment
      ON enrollment.course_id = CAST(c.course_id AS UNSIGNED)
    LEFT JOIN (SELECT course_id COLLATE utf8mb3_unicode_ci AS course_key, COUNT(*) AS total FROM course_items GROUP BY course_id) content
      ON content.course_key = c.course_id COLLATE utf8mb3_unicode_ci
    $courseWhere
    ORDER BY $courseSortSql
    LIMIT ? OFFSET ?";
  $courseStatement = $conn->prepare($courseQuery);
  if (!$courseStatement) throw new RuntimeException('Unable to prepare the course list query.');
  $bindTypes = $courseTypes . 'ii';
  $bindValues = [...$courseValues, $coursePerPage, $courseOffset];
  $courseStatement->bind_param($bindTypes, ...$bindValues);
  if (!$courseStatement->execute()) throw new RuntimeException('Unable to execute the course list query.');
  $courseRows = $courseStatement->get_result()->fetch_all(MYSQLI_ASSOC);
  $courseStatement->close();

  $courseCategoriesResult = $conn->query('SELECT id, category_title FROM categories WHERE archived_at IS NULL ORDER BY category_title ASC');
  if (!$courseCategoriesResult) throw new RuntimeException('Unable to load course categories.');
  $courseCategories = $courseCategoriesResult->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $courseQueryError) {
  error_log('Admin courses page query failure: ' . $courseQueryError->getMessage());
  $courseLoadError = 'Courses could not be loaded right now. Please try again.';
  $courseTotal = 0;
  $courseRows = [];
  $courseCategories = [];
  $coursePages = 1;
}
$courseListBase = rtrim(mmh_site_public_base_path(), '/');

function admin_course_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function admin_course_link(array $changes = []): string {
  $params = array_merge($_GET, $changes);
  foreach ($params as $key => $value) if ($value === '' || $value === null) unset($params[$key]);
  unset($params['page']);
  if (isset($changes['page'])) $params['page'] = $changes['page'];
  return '?' . http_build_query($params);
}


?>
<!DOCTYPE html>
<html lang="en">

<head>

    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">


    <title>Courses | <?=$site_name;?></title>
    <meta name="title" content="Courses | <?=$site_name;?>">
    <!---
وَما نَيلُ المَطالِبِ بِالتَمَنّي وَلَكِن تُؤخَذُ الدُنيا غِلاباوَ
ما اِستَعصى عَلى قَومٍ مَن الٌإِذا الإِقدامُ كانَ لَهُم رِكابا
أحمد شوقي
--->
<?php include "layouts/admin/header.php"; ?>
<?php $adminCoursesCssPath = __DIR__ . '/../../resources/css/admin-courses.css'; $adminCoursesCssVersion = (string) (is_file($adminCoursesCssPath) ? (filemtime($adminCoursesCssPath) ?: 1) : 1); ?>
<link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/admin-courses.css')?>?v=<?=$adminCoursesCssVersion?>">

<script src="<?=$baseUrl?>/resources/js/jquery-ui.min.js"></script>
<script src="<?=$baseUrl?>/resources/js/jquery.ui.touch-punch.min.js"></script>

<!-- <script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/jquery-ui.min.js"></script> -->

<style type="text/css">
 #page_list li
   {
    padding:16px;
    background-color: var(--surface-elevated);
    border: 1px dotted var(--border);
    cursor:move;
    margin-top:12px;
   }
   #page_list li.ui-state-highlight
   {
    padding:24px;
    background-color: var(--warning-soft);
    border: 1px dotted var(--border);
    cursor:move;
    margin-top:12px;
   }
      </style>


<style>
.accordion {
  background-color: var(--surface-muted);
  color: var(--text-primary);
  cursor: pointer;
  padding: 18px;
  width: 100%;
  border: none;
  text-align: left;
  outline: none;
  font-size: 15px;
  transition: 0.4s;
  text-align: start;
  font-size: 17px;
  font-weight: bold;
}

.activeAccordion, .accordion:hover {
  background-color: var(--surface-hover);
}

.accordion:after {
  content: '\002B';
  color: var(--text-muted);
  font-weight: bold;
  float: left;
  margin-right: 5px;
}

.activeAccordion:after {
  content: "\2212";
}

.panel {
  padding: 0 18px;
  background-color: var(--surface);
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.2s ease-out;
}
.course-builder-quick-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.course-builder-quick-actions .quickItemForm {
  margin: 0;
}
.course-builder-quick-add {
  border-radius: 999px;
  font-weight: 600;
  padding: 6px 12px;
}
.course-builder-quick-actions-empty {
  justify-content: center;
  margin-top: 14px;
}
.course-builder-empty-title {
  font-weight: 700;
  color: var(--text-primary);
  margin-bottom: 4px;
}
.course-builder-empty-subtitle {
  color: var(--text-muted);
  margin-bottom: 10px;
}
.course-builder-inline-title {
  border-radius: 8px;
  padding: 2px 4px;
  cursor: text;
  transition: background-color .16s ease, box-shadow .16s ease;
}
.course-builder-inline-title:hover,
.course-builder-inline-title:focus {
  background: var(--primary-soft);
  box-shadow: 0 0 0 2px var(--primary-soft);
  outline: 0;
}
.course-builder-inline-title-input {
  min-width: min(420px, 70vw);
  display: inline-block;
  height: 32px;
  padding: 4px 8px;
  font-weight: 700;
}
.course-builder-highlight {
  animation: courseBuilderHighlight 1.8s ease;
}
@keyframes courseBuilderHighlight {
  0% { box-shadow: 0 0 0 0 var(--primary-ring); transform: translateY(-2px); }
  45% { box-shadow: 0 0 0 7px var(--primary-soft); }
  100% { box-shadow: 0 0 0 0 transparent; transform: translateY(0); }
}
.course-builder-item.is-selected > div:last-child {
  outline: 2px solid var(--primary-ring);
  outline-offset: 3px;
  border-radius: 14px;
}
.course-builder-status-badge {
  border-radius: 999px;
  padding: .4em .65em;
  letter-spacing: .01em;
}
.course-builder-status-published {
  background: var(--success-soft) !important;
  color: var(--success) !important;
  border: 1px solid color-mix(in srgb, var(--success) 24%, var(--border));
}
.course-builder-status-draft {
  background: var(--warning-soft) !important;
  color: var(--warning) !important;
  border: 1px solid color-mix(in srgb, var(--warning) 28%, var(--border));
}
.course-builder-status-hidden {
  background: var(--surface-muted) !important;
  color: var(--text-muted) !important;
  border: 1px solid var(--border);
}
.course-builder-lesson-actions .btn,
.course-builder-section-header .btn {
  border-radius: 999px;
}
@media (max-width: 767.98px) {
  .course-builder-section-header {
    align-items: stretch !important;
  }
  .course-builder-section-header > div:last-child,
  .course-builder-quick-actions {
    width: 100%;
  }
  .course-builder-quick-add,
  .course-builder-section-header .btn,
  .course-builder-lesson-actions .btn {
    width: 100%;
    justify-content: center;
  }
}
</style>

</head>

<body class='dash ds-bg-primary admin-courses-page'>
    <style type="text/css">
        #toast-container>div {
            opacity: 1;
        }

        .phpdebugbar * {
            direction: ltr !important
        }
    </style>
    <div class="col-12 justify-content-end d-flex">
    </div>
    <form method="POST" action="<?=$baseUrl?>/admin/logout" id="logout-form" class="d-none"><input type="hidden" name="mmh_csrf_token" value="<?=htmlspecialchars(mmh_admin_csrf_token(), ENT_QUOTES, 'UTF-8')?>"></form>
    <div class="col-12 d-flex">

        <?php include "layouts/admin/aside.php"; ?>


        <div class="main-content in-active" style="overflow: hidden">

        <?php include "layouts/admin/top-nav.php"; ?>


            <div class="col-12 px-0" style="margin-top: 55px; position: relative">
                <div
                    id="loading-image-container" class="ds-surface admin-page-loader" role="status" aria-live="polite" aria-label="Loading courses">
                    <img src="<?=$baseUrl?>/resources/images/loading.gif" style="position:fixed;width: 120px;max-width: 80%;margin-top: -60px;"
                        id="loading-image">
                </div>

                <div class="col-12 p-3">
                    <div class="col-12 col-lg-12 p-0 main-box">

                        <div class="col-12 px-0">
                            <div class="col-12 p-0 row">
                                <div class="col-12 col-lg-4 py-3 px-3">
                                    <span class="fas fa-tags"></span> Courses
                                </div>
                                <div class="col-12 col-lg-4 p-0">
                                </div>
                                <div class="col-12 col-lg-4 p-2 text-lg-end">
                               
                                <!-- Button trigger modal -->
<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#exampleModal">
<span class="fas fa-plus"></span> Add New
</button>
                               
                                    <!-- Modal -->
<div class="modal fade" id="exampleModal"  aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="exampleModalLabel">Add New Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="requests/add-category" method="POST" id="addCategory" enctype="multipart/form-data">
            <fieldset class="form-fieldset api-mode">
                    <label
                    class='ds-text-secondary' style="display: flex; justify-content: center; font-size: 18px">Details
                    Course</label>

                    <div class="col-12 p-3 row">

                    

                    <div class="col-12 col-lg-12 p-2">
                          <div class="col-12">
                            Category
                          </div>
                          <div class="col-12 pt-3">
                          <select class="form-control select2" id="" name="course_category"  data-placeholder="أختار Category" style="width: 100%"  required>
                              <?php
                                  
                              
                                  $categories_result = mysqli_query(db(),'SELECT * FROM categories');
                                  if( mysqli_num_rows($categories_result) > 0 ){
                                      
                                      while($category_data = mysqli_fetch_array($categories_result) ){
                                          $category_title = $category_data['category_title'];
                                          $category_id = $category_data['id'];
                                          echo "
                                              <option value='$category_id'>$category_title</option>
                                          ";
                                      }
                                  }
                              
                              ?>
                          </select>
                          </div>
                    </div>


                    <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Title
                            </div>
                            <div class="col-12 pt-3">
                                <input type="text" name="course_title" required="" maxlength="190" class="form-control"  placeholder="اكتب عنوان Course">
                            </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            English Title
                            </div>
                            <div class="col-12 pt-3">
                                <input type="text" name="course_title_en" required="" maxlength="190" class="form-control"  placeholder="اكتب عنوان Course بالأنجلش">
                            </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Current Price
                            </div>
                            <div class="col-12 pt-3">
                                <input type="number" name="course_price" required="" min="0" maxlength="190" class="form-control"  placeholder="سعر Course باللغة الانجليزية">
                          </div>
                        </div>

                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Price Before Discount
                            </div>
                            <div class="col-12 pt-3">
                                <input type="number" name="preDiscount_course_price" required="" min="0" maxlength="190" class="form-control"  placeholder="سعر Course قبل التخفيض باللغة الانجليزية">
                          </div>
                        </div>


                        <div class="col-12 col-lg-12 p-2">
                            <div class="col-12">
                            Description
                            </div>
                            <div class="col-12 pt-3">
                                <textarea class="form-control" name="course_description" rows="2" placeholder="اكتب وصف Course هنا - SEO" required></textarea>
                            </div>
                        </div>


                        <div class="col-12 col-lg-12 p-2">
                            <div class="col-12">
                            WhatsApp Group Link
                            </div>
                            <div class="col-12 pt-3">
                                <input type="url" name="whatsapp_group" class="form-control"  placeholder="WhatsApp Group Link">
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Sequential Learning
                            </div>
                            <div class="col-12 pt-3">
                                <select name="sequential_learning" class="form-control">
                                    <option value="0" selected>OFF — all sections available</option>
                                    <option value="1">ON — apply section learning rules</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">Default Homework Score Mode</div>
                            <div class="col-12 pt-3">
                                <select name="default_homework_score_mode" class="form-control">
                                    <option value="disabled" selected>Disabled</option>
                                    <option value="accept_automatically">Accept Automatically</option>
                                    <option value="require_teacher_verification">Require Teacher Verification</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-12 col-lg-12 p-2">
                          <div class="col-12">
                            صورة Course <small class='text-primary'>{Optional}</small>
                          </div>
                          <div class="col-12 pt-3">
                            <input type="file" name="course_image" class="form-control" accept="image/*">
                          </div>
                        </div>


                    </div>
            </fieldset>
            
            <div class='progress ds-text-inverse' style="margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px" id="progress-div"><div class="progress-bar bg-success" role="progressbar" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100" id="progress-bar"></div></div>
        
        
            <!-- </form> -->
      </div>
      <div class="modal-footer p-2">
        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-outline-primary submitBtn">Save</button>
      </div>
      </form>

    </div>
  </div>
</div>

                                </div>
                            </div>
                            <div class="col-12 divider" style="min-height: 2px"></div>
                        </div>


                        <section class="admin-course-list" aria-labelledby="courses-list-title">
                          <div class="admin-course-list-toolbar">
                            <div><p class="admin-course-list-eyebrow">Course operations</p><h2 id="courses-list-title">All courses</h2><p><?=number_format($courseTotal)?> course<?= $courseTotal === 1 ? '' : 's' ?> found</p></div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#exampleModal"><span class="fas fa-plus" aria-hidden="true"></span> Add course</button>
                          </div>
                          <form class="admin-course-filters" method="get" action="<?=$courseListBase?>/admin/courses" role="search">
                            <label class="admin-course-search"><span class="fas fa-search" aria-hidden="true"></span><input type="search" name="q" value="<?=admin_course_escape($courseSearch)?>" placeholder="Search title or course ID"></label>
                            <select class="form-select" name="state" aria-label="Filter by course state"><option value="all">All course states</option><option value="public" <?=$courseStateFilter==='public'?'selected':''?>>Public</option><option value="private" <?=$courseStateFilter==='private'?'selected':''?>>Private</option><option value="draft" <?=$courseStateFilter==='draft'?'selected':''?>>Draft</option></select>
                            <select class="form-select" name="category" aria-label="Filter by category"><option value="all">All categories</option><?php foreach($courseCategories as $courseCategory): ?><option value="<?=admin_course_escape($courseCategory['id'])?>" <?=$courseCategoryFilter === (string)$courseCategory['id']?'selected':''?>><?=admin_course_escape($courseCategory['category_title'])?></option><?php endforeach;?></select>
                            <select class="form-select" name="price" aria-label="Filter by price"><option value="all">Free and paid</option><option value="free" <?=$coursePriceFilter==='free'?'selected':''?>>Free</option><option value="paid" <?=$coursePriceFilter==='paid'?'selected':''?>>Paid</option></select>
                            <select class="form-select" name="sort" aria-label="Sort courses"><option value="recent" <?=$courseSort==='recent'?'selected':''?>>Recently added</option><option value="title" <?=$courseSort==='title'?'selected':''?>>Title</option><option value="students" <?=$courseSort==='students'?'selected':''?>>Most students</option><option value="content" <?=$courseSort==='content'?'selected':''?>>Most content</option></select>
                            <select class="form-select" name="per_page" aria-label="Results per page"><option value="25" <?=$coursePerPage===25?'selected':''?>>25 per page</option><option value="50" <?=$coursePerPage===50?'selected':''?>>50 per page</option><option value="100" <?=$coursePerPage===100?'selected':''?>>100 per page</option></select>
                            <button class="btn btn-outline-secondary" type="submit">Apply</button><a class="btn btn-ghost" href="<?=$courseListBase?>/admin/courses">Reset</a>
                          </form>
                          <div class="admin-course-table-wrap">
                            <table class="admin-course-table" id="coursesTable">
                              <colgroup>
                                <col class="admin-course-column-course">
                                <col class="admin-course-column-status">
                                <col class="admin-course-column-students">
                                <col class="admin-course-column-content">
                                <col class="admin-course-column-price">
                                <col class="admin-course-column-updated">
                                <col class="admin-course-column-manage">
                                <col class="admin-course-column-actions">
                              </colgroup>
                              <thead><tr><th>Course</th><th>Course State</th><th>Students</th><th>Content</th><th>Price</th><th>Updated</th><th>Manage content</th><th><span class="visually-hidden">More actions</span></th></tr></thead>
                              <tbody><?php if ($courseLoadError): ?><tr><td colspan="8"><div class="admin-course-empty admin-course-load-error" role="alert"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><strong><?=admin_course_escape($courseLoadError)?></strong><a href="<?=admin_course_escape($_SERVER['REQUEST_URI'] ?? ($courseListBase . '/admin/courses'))?>">Try again</a></div></td></tr><?php elseif (!$courseRows): ?><tr><td colspan="8"><div class="admin-course-empty"><span class="fas fa-search" aria-hidden="true"></span><strong>No courses match these filters.</strong><a href="<?=$courseListBase?>/admin/courses">Clear filters</a></div></td></tr><?php endif; ?>
                              <?php foreach($courseRows as $course):
                                $courseId = (string)$course['course_id'];
                                $courseContentUrl = $courseListBase . '/admin/courses/' . rawurlencode($courseId) . '/content';
                                $courseImageUrl = mmh_site_public_url(mmh_site_settings_valid_local_asset($course['course_image'] ?? '') ?? 'resources/images/default/cover.png');
                                $courseState = mmh_course_state($course);
                                $stateLabel = ucfirst($courseState);
                                $stateHelp = ['public' => 'Visible on website', 'private' => 'Only manually enrolled students can access', 'draft' => 'Admin only'][$courseState];
                              ?><tr>
                                <td class="admin-course-cell"><div class="admin-course-cell-content"><img src="<?=admin_course_escape($courseImageUrl)?>" alt="" loading="lazy"><div class="admin-course-cell-copy"><strong><?=admin_course_escape($course['course_title'])?></strong><small><?=admin_course_escape($course['category_title'])?> · ID <?=admin_course_escape($courseId)?></small><p><?=admin_course_escape($course['course_description'])?></p></div></div></td>
                                <td><form action="" method="post" class="update-course-state"><label class="admin-course-state admin-course-state--<?=$courseState?>" title="<?=admin_course_escape($stateHelp)?>"><select name="course_state" aria-label="Course state for <?=admin_course_escape($course['course_title'])?>"><option value="public" <?=$courseState==='public'?'selected':''?>>Public</option><option value="private" <?=$courseState==='private'?'selected':''?>>Private</option><option value="draft" <?=$courseState==='draft'?'selected':''?>>Draft</option></select><span class="admin-course-state-label"><?=admin_course_escape($stateLabel)?></span><input type="hidden" name="mmh_csrf_token" value="<?=admin_course_escape(mmh_admin_csrf_token())?>"><input type="hidden" name="course_id" value="<?=admin_course_escape($courseId)?>"><input type="hidden" name="update-state" value="1"></label></form></td>
                                <td><form method="post" action="" class="addUserToCourse"><input type="hidden" name="_token" value="<?=admin_course_escape(mmh_auth_csrf_token())?>"><input type="hidden" name="course_id" value="<?=admin_course_escape($courseId)?>"><input type="hidden" name="_method" value="GET"><button class="admin-course-count" title="Manage students" aria-label="Manage students for <?=admin_course_escape($course['course_title'])?>"><?=number_format((int)$course['enrolled_students'])?></button></form></td>
                                <td><a class="admin-course-count" href="<?=admin_course_escape($courseContentUrl)?>" title="Manage content" aria-label="Manage content for <?=admin_course_escape($course['course_title'])?>"><?=number_format((int)$course['content_count'])?> <span>items</span></a></td>
                                <td class="admin-course-price"><?=(int)$course['course_price'] === 0 ? 'Free' : number_format((int)$course['course_price']) . ' EGP'?></td>
                                <td><time datetime="<?=admin_course_escape($course['created_at'])?>"><?=admin_course_escape(date('M j, Y', strtotime($course['created_at'])))?></time></td>
                                <td><a class="btn btn-primary btn-sm" href="<?=admin_course_escape($courseContentUrl)?>"><span class="fas fa-layer-group" aria-hidden="true"></span> Manage content</a></td>
                                <td><div class="dropdown"><button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for <?=admin_course_escape($course['course_title'])?>"><span class="fas fa-ellipsis-h" aria-hidden="true"></span></button><ul class="dropdown-menu dropdown-menu-end"><li><form method="post" action="" class="editCourse"><input type="hidden" name="_token" value="<?=admin_course_escape(mmh_auth_csrf_token())?>"><input type="hidden" name="course_id" value="<?=admin_course_escape($courseId)?>"><input type="hidden" name="_method" value="GET"><button class="dropdown-item"><span class="fas fa-edit" aria-hidden="true"></span> Edit details</button></form></li><li><a class="dropdown-item" href="<?=$courseListBase?>/course/<?=rawurlencode($courseId)?>" target="_blank" rel="noopener"><span class="fas fa-external-link-alt" aria-hidden="true"></span> Preview</a></li><li><form method="post" action="" class="notificationForm"><input type="hidden" name="_token" value="<?=admin_course_escape(mmh_auth_csrf_token())?>"><input type="hidden" name="course_id" value="<?=admin_course_escape($courseId)?>"><input type="hidden" name="_method" value="GET"><button class="dropdown-item"><span class="fas fa-bell" aria-hidden="true"></span> Send announcement</button></form></li><li><a class="dropdown-item" href="<?=$courseListBase?>/admin/assignments"><span class="fas fa-clipboard-list" aria-hidden="true"></span> Assignments</a></li><li><a class="dropdown-item" href="<?=$courseListBase?>/admin/exams"><span class="fas fa-file-alt" aria-hidden="true"></span> Exams &amp; quizzes</a></li><li><hr class="dropdown-divider"></li><li><form method="post" action="" class="deleteCourse"><input type="hidden" name="_token" value="<?=admin_course_escape(mmh_auth_csrf_token())?>"><input type="hidden" name="course_id" value="<?=admin_course_escape($courseId)?>"><input type="hidden" name="_method" value="DELETE"><button type="submit" class="dropdown-item text-danger"><span class="fas fa-trash" aria-hidden="true"></span> Delete permanently</button></form></li></ul></div></td>
                              </tr><?php endforeach;?></tbody>
                            </table>
                          </div>
                          <?php if ($coursePages > 1): ?><nav class="admin-course-pagination" aria-label="Course pagination"><?php if ($coursePage > 1): ?><a href="<?=admin_course_escape(admin_course_link(['page'=>$coursePage-1]))?>">Previous</a><?php endif; ?><span>Page <?=$coursePage?> of <?=$coursePages?></span><?php if ($coursePage < $coursePages): ?><a href="<?=admin_course_escape(admin_course_link(['page'=>$coursePage+1]))?>">Next</a><?php endif;?></nav><?php endif; ?>
                        </section>
                        <div class="col-12 p-3">

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<div class="ajax-response"></div>

                
<!-- Modal -->
<div class='modal fade' id='SortItemsHtmlModal' tabindex='-1' aria-labelledby='exampleModalLabel' aria-hidden='true'>
  <div class='modal-dialog modal-lg'>
    <div class='modal-content'>
      <div class='modal-header'>
        <h5 class='modal-title' id='exampleModalLabel'>Edit Items</h5>
        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
      </div>
      <div class='modal-body'>

      </div>


    </div>
  </div>
</div>





<script type="text/javascript">
  $(document).ready(function (e) {
        
  jQuery( document ).ajaxStart(function() {
    NProgress.start();
  });

  jQuery( document ).ajaxStop(function() {
    NProgress.done();
  });

  });

</script>


    <script>
$(document).ready(function() {
    // Add New Teacher
    $("#addCategory").on("submit", function (e) {
        
      const bar = $('.bar');
      const percent = $('.percent');
      const status = $('#status');

      e.preventDefault();
      $.ajax({
        xhr: function() {
          var xhr = new window.XMLHttpRequest();
          xhr.upload.addEventListener("progress", function(evt) {
          if (evt.lengthComputable) {
            var percentComplete = parseInt(((evt.loaded / evt.total) * 100));
            $("#progress-bar").width(percentComplete + '%');
            $("#progress-bar").html(percentComplete+'%');
          }
          }, false);
          return xhr;
        },
        type: "POST",
        url: "requests/course/add",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $("#addCategory").css("opacity", ".5");
          
          $("#progress-bar").width('0%');
          $('#loader-icon').show();
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            $("#addCategory")[0].reset();
            $(".response-msg").html(
              Swal.fire({
                icon: "success",
                title: response.message,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
              }).then(function (isConfirm) {
                if (isConfirm) {
                  location.reload();
                } else {
                  //if no clicked => do something else
                }
              })
            );
          } else {
            $(".response-msg").html(
              Swal.fire({
                icon: "error",
                title: response.message,
                text: response.reason,
                showConfirmButton: true,
                timer: 10000,
                timerProgressBarColor: "var(--primary)",
                timerProgressBar: true,
              }).then(function (isConfirm) {
                if (isConfirm) {
                //   location.reload();
                } else {
                  //if no clicked => do something else
                }
              })
            );
          }
          $("#addCategory").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Add New Teacher


   // Edit a Teacher
   $(".editCourse").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/course/edit",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $("#addApi").css("opacity", ".5");
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            console.log("goodddddddddddddd");
            $(".ajax-response").html(response.html);
            //Initialize Select2 Elements
            // Initialize Select2 with tags enabled
            $('.select2-edit').select2({
              tags: true
            });

            // Get all options
            var options = $('.select2-edit option');

            // Set selected for each option
            options.each(function() {
              $(this).prop('selected', true);
            });

            // Trigger change event to update Select2
            $('.select2-edit').trigger('change');

            $('#response-html-modal').modal('show');
            

            //*** Send Edit Request
            $("#updateCourse").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                type: "POST",
                url: "requests/course/edit",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#updateCourse").css("opacity", ".5");
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#updateCourse")[0].reset();
                    $(".response-msg").html(
                      Swal.fire({
                        icon: "success",
                        title: response.message,
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                      }).then(function (isConfirm) {
                        if (isConfirm) {
                          location.reload();
                        } else {
                          //if no clicked => do something else
                        }
                      })
                    );
                  } else {
                    $(".response-msg").html(
                      Swal.fire({
                        icon: "error",
                        title: response.message,
                        text: response.reason,
                        showConfirmButton: true,
                        timer: 10000,
                        timerProgressBarColor: "var(--primary)",
                        timerProgressBar: true,
                      }).then(function (isConfirm) {
                        if (isConfirm) {
                          // location.reload();
                        } else {
                          //if no clicked => do something else
                        }
                      })
                    );
                  }
                  $("#updateCourse").css("opacity", "");
                  $(".submitBtn").removeAttr("disabled");
                },
              });
            });
            //*** Send Edit Request









            // $("#addApi")[0].reset();
            // $(".response-msg").html(
            //   Swal.fire({
            //     icon: "success",
            //     title: response.message,
            //     showConfirmButton: false,
            //     timer: 2000,
            //     timerProgressBar: true,
            //   }).then(function (isConfirm) {
            //     if (isConfirm) {
            //       location.reload();
            //     } else {
            //       //if no clicked => do something else
            //     }
            //   })
            // );

          } else {
            $(".response-msg").html(
              Swal.fire({
                icon: "error",
                title: response.message,
                text: response.reason,
                showConfirmButton: true,
                timer: 10000,
                timerProgressBarColor: "var(--primary)",
                timerProgressBar: true,
              }).then(function (isConfirm) {
                if (isConfirm) {
                  location.reload();
                } else {
                  //if no clicked => do something else
                }
              })
            );
          }
          $("#addApi").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Edit Teacher






    // start of delete teacher
$(".deleteCourse").on("submit", function (e) {
  e.preventDefault();

  // Display the confirmation dialog using SweetAlert
  Swal.fire({
    title: "Are you sure?",
    text: "You will not be able to undo this!",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "var(--primary)",
    cancelButtonColor: "var(--danger)",
    confirmButtonText: "Yes, delete it!",
    cancelButtonText: "Cancel",
  }).then((result) => {
    if (result.isConfirmed) {
      // User clicked "Yes," proceed with the request
      $.ajax({
        type: "POST",
        url: "requests/course/delete",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          // Code to execute before sending the request
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            $(".response-msg").html(
              Swal.fire({
                icon: "success",
                title: response.message,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
              }).then(function (isConfirm) {
                if (isConfirm) {
                  location.reload();
                } else {
                  // If "No" is clicked, do something else
                }
              })
            );
          } else {
            $(".response-msg").html(
              Swal.fire({
                icon: "error",
                title: response.message,
                text: response.reason,
                showConfirmButton: true,
                timer: 10000,
                timerProgressBarColor: "var(--primary)",
                timerProgressBar: true,
              }).then(function (isConfirm) {
                if (isConfirm) {
                  location.reload();
                } else {
                  // If "No" is clicked, do something else
                }
              })
            );
          }
          // $("#addApi").css("opacity", "");
          // $(".submitBtn").removeAttr("disabled");
        },
      });
    } else {
      // User clicked "Cancel," do something else or simply return
    }
  });
});


$(".update-course-state").change(function () {
  var form = this;
  var select = $(form).find('select');
  var previous = select.data('previous-value') || select.val();
  var Toast = Swal.mixin({toast:true, position:'top-end', showConfirmButton:false, timer:5000});
  $.ajax({
    type: 'POST', url: 'requests/course/status', data: new FormData(form), dataType: 'json',
    contentType: false, cache: false, processData: false,
    success: function (response) {
      if (response.status == 1) {
        select.data('previous-value', select.val());
        Toast.fire({icon:'success', title:response.message});
      } else {
        select.val(previous);
        Toast.fire({icon:'error', title:response.message || 'Course state could not be updated.'});
      }
    },
    error: function () { select.val(previous); Toast.fire({icon:'error', title:'Course state could not be updated.'}); }
  });
});






//Items Part









//End Items Part












   // script Of send notification
   $(".notificationForm").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/course/notification",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $(".notificationForm").css("opacity", ".5");
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            console.log("goodddddddddddddd");
            $(".ajax-response").html(response.html);
            //Initialize Select2 Elements
            // Initialize Select2 with tags enabled
            $('.select2-edit').select2({
              tags: true
            });

            // Get all options
            var options = $('.select2-edit option');

            // Set selected for each option
            options.each(function() {
              $(this).prop('selected', true);
            });

            // Trigger change event to update Select2
            $('.select2-edit').trigger('change');

            $('#response-html-modal').modal('show');
            

            //*** Send Edit Request
            $("#sendNotification").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                type: "POST",
                url: "requests/course/notification",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#sendNotification").css("opacity", ".5");
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#sendNotification")[0].reset();
                    $(".response-msg").html(
                      Swal.fire({
                        icon: "success",
                        title: response.message,
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                      }).then(function (isConfirm) {
                        if (isConfirm) {
                          location.reload();
                        } else {
                          //if no clicked => do something else
                        }
                      })
                    );
                  } else {
                    $(".response-msg").html(
                      Swal.fire({
                        icon: "error",
                        title: response.message,
                        text: response.reason,
                        showConfirmButton: true,
                        timer: 10000,
                        timerProgressBarColor: "var(--primary)",
                        timerProgressBar: true,
                      }).then(function (isConfirm) {
                        if (isConfirm) {
                          // location.reload();
                        } else {
                          //if no clicked => do something else
                        }
                      })
                    );
                  }
                  $("#sendNotification").css("opacity", "");
                  $(".submitBtn").removeAttr("disabled");
                },
              });
            });
            //*** Send Edit Request









            // $("#addApi")[0].reset();
            // $(".response-msg").html(
            //   Swal.fire({
            //     icon: "success",
            //     title: response.message,
            //     showConfirmButton: false,
            //     timer: 2000,
            //     timerProgressBar: true,
            //   }).then(function (isConfirm) {
            //     if (isConfirm) {
            //       location.reload();
            //     } else {
            //       //if no clicked => do something else
            //     }
            //   })
            // );

          } else {
            $(".response-msg").html(
              Swal.fire({
                icon: "error",
                title: response.message,
                text: response.reason,
                showConfirmButton: true,
                timer: 10000,
                timerProgressBarColor: "var(--primary)",
                timerProgressBar: true,
              }).then(function (isConfirm) {
                if (isConfirm) {
                  location.reload();
                } else {
                  //if no clicked => do something else
                }
              })
            );
          }
          $(".notificationForm").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of send notification







  });
</script>


<script>
jQuery(document).ready(function($) {
  var activeCourseId = null;
  var itemListModal = null;
  var reopenLessonListAfterItemSave = false;
  var sortingSaveTimer = null;
  var selectedBuilderItem = null;
  var inlineTitleSaving = false;
  var Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 5000
  });

  function responseOk(response) {
    return response && (response.success === true || response.status == 1);
  }

  function responseMessage(response, fallback) {
    if (!response) {
      return fallback || 'Unexpected server error';
    }
    return response.message || response.reason || fallback || 'Unexpected server error';
  }

  function showToast(icon, message) {
    Toast.fire({ icon: icon, title: message });
  }

  function showError(response, fallback) {
    Swal.fire({
      icon: 'error',
      title: responseMessage(response, fallback),
      text: response && response.reason ? response.reason : '',
      showConfirmButton: true
    });
  }

  function cleanModalArtifacts(force) {
    setTimeout(function() {
      if (force || $('.modal.show').length === 0) {
        $('.modal-backdrop').remove();
        $('body')
          .removeClass('modal-open')
          .css({ overflow: '', paddingRight: '', pointerEvents: '' });
        $('html').css('overflow', '');
        $('.main-content, #app').css('pointerEvents', '');
      } else if ($('.modal-backdrop').length > 1) {
        $('.modal-backdrop').slice(1).remove();
      }
    }, 80);
  }

  function closeItemModalSmooth(callback) {
    var $modal = $('#response-html-modal');
    var callbackFired = false;

    function done() {
      if (callbackFired) {
        return;
      }
      callbackFired = true;
      destroyCourseBuilderEditors();
      if ($modal.length) {
        if (window.bootstrap && bootstrap.Modal) {
          var existingInstance = bootstrap.Modal.getInstance($modal[0]);
          if (existingInstance) {
            existingInstance.dispose();
          }
        }
        $modal.remove();
      }
      cleanModalArtifacts(true);
      if (typeof callback === 'function') {
        callback();
      }
    }

    if (!$modal.length) {
      done();
      return;
    }

    $modal.data('allow-close', true).data('dirty', false);
    $modal.one('hidden.bs.modal.courseBuilderSave', done);

    if (window.bootstrap && bootstrap.Modal) {
      var instance = bootstrap.Modal.getOrCreateInstance($modal[0]);
      instance.hide();
    } else {
      $modal.modal('hide');
    }

    setTimeout(done, 350);
  }

  function hideLessonListModal(callback) {
    var $modal = $('#SortItemsHtmlModal');
    if (!$modal.length || !$modal.hasClass('show')) {
      cleanModalArtifacts(false);
      if (typeof callback === 'function') {
        callback();
      }
      return;
    }

    var callbackFired = false;
    function done() {
      if (callbackFired) {
        return;
      }
      callbackFired = true;
      if (window.bootstrap && bootstrap.Modal) {
        var instance = bootstrap.Modal.getInstance($modal[0]);
        if (instance) {
          instance.dispose();
        }
      }
      $modal.removeClass('show').hide().attr('aria-hidden', 'true').removeAttr('aria-modal role');
      cleanModalArtifacts(true);
      if (typeof callback === 'function') {
        callback();
      }
    }

    $modal.one('hidden.bs.modal.courseBuilderList', done);
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
    } else {
      $modal.modal('hide');
    }
    setTimeout(done, 250);
  }

  function setLoading($button, isLoading, text) {
    if (!$button || !$button.length) {
      return;
    }
    if (isLoading) {
      if (!$button.data('original-text')) {
        $button.data('original-text', $button.html());
      }
      $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> ' + (text || 'Loading...'));
    } else {
      $button.prop('disabled', false).html($button.data('original-text') || $button.html());
      $button.removeData('original-text');
    }
  }


  function highlightBuilderTarget(selector) {
    if (!selector) {
      return;
    }
    var $target = $(selector).first();
    if (!$target.length) {
      return;
    }
    $target.addClass('course-builder-highlight');
    if ($target[0] && typeof $target[0].scrollIntoView === 'function') {
      $target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    setTimeout(function() {
      $target.removeClass('course-builder-highlight');
    }, 1900);
  }

  function collectCourseBuilderFormState($form) {
    if (!$form || !$form.length) {
      return '';
    }
    if (typeof tinymce !== 'undefined') {
      tinymce.triggerSave();
    }
    return $form.serialize();
  }

  function setupUnsavedChanges($scope) {
    var $modal = ($scope && $scope.length ? $scope : $('#response-html-modal')).first();
    var $form = $modal.find('form').first();
    if (!$modal.length || !$form.length) {
      return;
    }

    $modal.data('allow-close', false).data('dirty', false);
    setTimeout(function() {
      $modal.data('initial-state', collectCourseBuilderFormState($form));
      $modal.data('dirty', false);
    }, 160);

    $modal.off('input.courseBuilderDirty change.courseBuilderDirty', 'input, select, textarea')
      .on('input.courseBuilderDirty change.courseBuilderDirty', 'input, select, textarea', function() {
        if ($modal.data('initial-state') !== undefined) {
          $modal.data('dirty', true);
        }
      });

    $modal.off('hide.bs.modal.courseBuilderUnsaved').on('hide.bs.modal.courseBuilderUnsaved', function(e) {
      var allowClose = $modal.data('allow-close') === true;
      if (allowClose || !$modal.data('dirty')) {
        return;
      }

      e.preventDefault();
      Swal.fire({
        icon: 'warning',
        title: 'You have unsaved changes.',
        text: 'Leave this form or stay editing?',
        showCancelButton: true,
        confirmButtonText: 'Leave',
        cancelButtonText: 'Stay Editing',
        reverseButtons: true
      }).then(function(result) {
        if (!result.isConfirmed) {
          return;
        }
        $modal.data('allow-close', true).data('dirty', false);
        if (window.bootstrap && bootstrap.Modal && $modal[0]) {
          bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
        } else {
          $modal.modal('hide');
        }
      });
    });
  }

  function markModalDirty() {
    var $modal = $('#response-html-modal');
    if ($modal.length && $modal.data('initial-state') !== undefined) {
      $modal.data('dirty', true);
    }
  }

  function destroyCourseBuilderEditors() {
    if (typeof tinymce === 'undefined') {
      return;
    }
    tinymce.remove('.course-builder-editor');
  }

  function initCourseBuilderEditors($scope) {
    if (typeof tinymce === 'undefined') {
      return;
    }

    var $root = $scope && $scope.length ? $scope : $('#response-html-modal');
    var selector = '#response-html-modal .template-pane:not(.d-none) .course-builder-editor';
    var hasEditorToInit = false;

    $root.find('.course-builder-editor').each(function() {
      var editor = this.id ? tinymce.get(this.id) : null;
      if (editor && $(this).closest('.template-pane').hasClass('d-none')) {
        editor.save();
        editor.remove();
      }
    });

    $(selector).each(function() {
      if (this.id && !tinymce.get(this.id)) {
        hasEditorToInit = true;
      }
    });

    if (!hasEditorToInit) {
      return;
    }

    tinymce.init({
      selector: selector,
      mobile: {
        menubar: true,
        plugins: 'advlist image media autolink code codesample directionality table wordcount quickbars link lists',
        toolbar: 'undo bold italic styles'
      },
      plugins: 'advlist image media autolink code codesample directionality table wordcount quickbars link lists',
      images_upload_url: '/admin/requests/image/upload',
      automatic_uploads: true,
      file_picker_types: 'file image media',
      image_caption: true,
      image_dimensions: true,
      directionality: 'ltr',
      language: 'en',
      quickbars_selection_toolbar: 'bold italic | h1 h2 h3 h4 h5 h6 | formatselect | quicklink blockquote | numlist bullist',
      entity_encoding: 'raw',
      verify_html: false,
      object_resizing: 'img',
      height: 300,
      setup: function(editor) {
        editor.on('change keyup undo redo input SetContent', function() {
          markModalDirty();
        });
      }
    });
  }

  function itemTypeForTemplate(template, $form) {
    if (template === 'recording' || template === 'video') {
      return 'video';
    }
    if (template === 'classified_assignment' || template === 'assignment' || template === 'exam') {
      return 'quiz';
    }
    if (template === 'custom_html' && $form && $form.find('[name="_method"]').val() === 'UPDATE') {
      return $form.find('[name="item_type"]').data('originalItemType') || 'file';
    }
    return 'file';
  }

  function updateCustomLessonLabel($form) {
    if (!$form || !$form.length) {
      return;
    }

    var $selector = $form.find('[data-custom-label-selector]');
    var $input = $form.find('[data-custom-label-input]');
    if (!$selector.length || !$input.length) {
      return;
    }

    var isActivePane = !$selector.closest('.template-pane').hasClass('d-none');
    var shouldShow = isActivePane && $selector.val() === 'custom';
    $input.toggleClass('d-none', !shouldShow).prop('required', shouldShow);
  }

  function activateTemplate($form, template) {
    if (!$form.length) {
      return;
    }

    if (typeof tinymce !== 'undefined') {
      tinymce.triggerSave();
    }

    template = template || 'custom_html';
    $form.find('[name="template_type"]').val(template);
    $form.find('[name="item_type"]').val(itemTypeForTemplate(template, $form));
    $form.find('.template-card').removeClass('border-primary bg-light');
    $form.find('.template-card[data-template="' + template + '"]').addClass('border-primary bg-light');
    $form.find('[data-template-required]').prop('required', false);
    $form.find('[data-classified-required]').prop('required', template === 'classified_assignment');
    $form.find('.template-pane').addClass('d-none');

    var $pane = $form.find('.template-pane[data-template-pane="' + template + '"]');
    if (!$pane.length) {
      $pane = $form.find('.template-pane[data-template-pane="custom_html"]');
      $form.find('[name="template_type"]').val('custom_html');
      $form.find('[name="item_type"]').val(itemTypeForTemplate('custom_html', $form));
    }

    $pane.removeClass('d-none');
    $pane.find('[data-template-required]').prop('required', true);
    updateCustomLessonLabel($form);
    initCourseBuilderEditors($form);
  }

  function setupTemplateBuilder($scope) {
    var $form = ($scope && $scope.length ? $scope : $('#response-html-modal')).find('.courseBuilderItemForm').first();
    if (!$form.length) {
      return;
    }

    activateTemplate($form, $form.find('[name="template_type"]').val() || 'custom_html');
  }

  function setupAccordion($scope) {
    var $root = $scope || $(document);
    $root.find('.accordion').off('click.courseBuilderAccordion').on('click.courseBuilderAccordion', function() {
      this.classList.toggle('activeAccordion');
      var panel = this.nextElementSibling;
      if (!panel) {
        return;
      }
      if (panel.style.maxHeight) {
        panel.style.maxHeight = null;
      } else {
        panel.style.maxHeight = panel.scrollHeight + 10 + 'px';
      }
    });
  }

  function setupSectionToggles($scope) {
    var $root = $scope || $(document);
    $root.find('.course-builder-section-toggle').off('click.courseBuilderSectionToggle').on('click.courseBuilderSectionToggle', function(e) {
      e.preventDefault();
      var $section = $(this).closest('.course-builder-section');
      var $body = $section.find('> .course-builder-section-body');
      var $chevron = $(this).find('.section-chevron');
      $section.toggleClass('is-collapsed');
      $chevron.toggleClass('fa-chevron-down fa-chevron-right');
      $body.stop(true, true).slideToggle(160);
    });
  }

  function updateSectionTypeFields($form) {
    if (!$form || !$form.length) {
      return;
    }
    var isCustom = $form.find('.sectionTypeSelector').val() === 'custom';
    var $customWrap = $form.find('[data-custom-section-type]');
    var $customInput = $customWrap.find('input[name="custom_type"]');
    $customWrap.toggleClass('d-none', !isCustom);
    $customInput.prop('required', isCustom);
    if (!isCustom) {
      $customInput.val('');
    }
  }

  function updateLearningRuleFields($form) {
    if (!$form || !$form.length) {
      return;
    }

    var unlockMode = $form.find('.sectionUnlockModeSelector').val() || 'always';
    var completionRule = $form.find('.sectionCompletionRuleSelector').val() || 'manual_completion';
    var needsDate = unlockMode === 'on_date';
    var needsHomework = unlockMode === 'after_homework_submission' || unlockMode === 'after_homework_approval' || completionRule === 'homework_submitted' || completionRule === 'homework_approved';
    var needsManualUnlock = unlockMode === 'manual_unlock';

    $form.find('[data-learning-unlock-date]').toggleClass('d-none', !needsDate)
      .find('input, select').prop('required', needsDate);
    $form.find('[data-learning-homework]').toggleClass('d-none', !needsHomework)
      .find('select').prop('required', needsHomework);
    $form.find('[data-learning-manual-unlock]').toggleClass('d-none', !needsManualUnlock);
  }

  function setupSectionForm($scope) {
    var $form = ($scope && $scope.length ? $scope : $('#response-html-modal')).find('.courseBuilderSectionForm').first();
    updateSectionTypeFields($form);
    updateLearningRuleFields($form);
  }

  function scheduleCourseBuilderSortSave() {
    clearTimeout(sortingSaveTimer);
    sortingSaveTimer = setTimeout(saveCourseBuilderSorting, 180);
  }

  function saveCourseBuilderSorting() {
    if (!activeCourseId) {
      return;
    }

    var sections = [];
    $('#course_real_sections_list > .course-builder-section').each(function() {
      var sectionId = $(this).data('section-id');
      if (sectionId && sectionId !== '__general__') {
        sections.push(String(sectionId));
      }
    });

    var lessons = [];
    $('.course-builder-section-lessons').each(function() {
      var sectionId = $(this).data('section-id') || '__general__';
      $(this).find('> .course-builder-item').each(function(index) {
        var itemId = $(this).data('item-db-id');
        if (itemId) {
          lessons.push({
            id: itemId,
            section_id: sectionId === '__general__' ? '' : String(sectionId),
            page_order: index + 1
          });
        }
      });
    });

    $.ajax({
      url: 'requests/section/sorting',
      method: 'POST',
      data: {
        _method: 'update',
        course_id: activeCourseId,
        sections: JSON.stringify(sections),
        lessons: JSON.stringify(lessons)
      },
      dataType: 'json',
      success: function(response) {
        if (responseOk(response)) {
          showToast('success', responseMessage(response, 'Course order updated successfully.'));
          refreshLessonList(activeCourseId, false);
        } else {
          showError(response, 'Sorting failed.');
        }
      },
      error: function() {
        showError(null, 'Sorting failed because the server could not be reached.');
      }
    });
  }

  function initSortable() {
    var $sectionList = $('#course_real_sections_list');
    var $lessonLists = $('.course-builder-section-lessons');

    if ($sectionList.length && $sectionList.data('ui-sortable')) {
      $sectionList.sortable('destroy');
    }
    $lessonLists.each(function() {
      if ($(this).data('ui-sortable')) {
        $(this).sortable('destroy');
      }
    });

    if ($sectionList.length && $sectionList.find('> .course-builder-section').length > 1) {
      $sectionList.sortable({
        items: '> .course-builder-section',
        handle: '.course-builder-section-handle',
        placeholder: 'ui-state-highlight',
        update: scheduleCourseBuilderSortSave
      });
    }

    if ($lessonLists.length) {
      $lessonLists.sortable({
        items: '> .course-builder-item',
        connectWith: '.course-builder-section-lessons',
        handle: '.course-builder-sort-handle',
        placeholder: 'ui-state-highlight',
        tolerance: 'pointer',
        update: function(event, ui) {
          if (this === ui.item.parent()[0]) {
            scheduleCourseBuilderSortSave();
          }
        },
        receive: scheduleCourseBuilderSortSave
      });
    }
  }

  function getListModal() {
    var modalEl = document.getElementById('SortItemsHtmlModal');
    if (!modalEl) {
      return null;
    }
    if (window.bootstrap && bootstrap.Modal) {
      itemListModal = bootstrap.Modal.getOrCreateInstance(modalEl);
      return itemListModal;
    }
    return null;
  }

  function showListModal() {
    cleanModalArtifacts(false);
    var modal = getListModal();
    if (modal) {
      modal.show();
    } else {
      $('#SortItemsHtmlModal').modal('show');
    }
  }

  function refreshLessonList(courseId, showModal, highlightSelector) {
    if (!courseId) {
      return $.Deferred().reject().promise();
    }
    activeCourseId = courseId;
    return $.ajax({
      type: 'POST',
      url: 'requests/item/items',
      data: { course_id: courseId, _method: 'GET' },
      dataType: 'json',
      success: function(response) {
        if (responseOk(response)) {
          $('#SortItemsHtmlModal .modal-body').html(response.html);
          setupAccordion($('#SortItemsHtmlModal'));
          setupSectionToggles($('#SortItemsHtmlModal'));
          initSortable();
          if (showModal) {
            showListModal();
          }
          if (highlightSelector) {
            setTimeout(function() {
              highlightBuilderTarget(highlightSelector);
            }, showModal ? 260 : 90);
          }
        } else {
          showError(response, 'Could not load lessons.');
        }
      },
      error: function() {
        showError(null, 'Could not load lessons because the server could not be reached.');
      }
    });
  }

  function openItemModal(formData, $button) {
    destroyCourseBuilderEditors();
    return $.ajax({
      type: 'POST',
      url: 'requests/item/form',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Loading...');
      },
      success: function(response) {
        if (responseOk(response)) {
          $('.ajax-response').html(response.html);
          cleanModalArtifacts(false);
          var modalEl = document.getElementById('response-html-modal');
          if (window.bootstrap && bootstrap.Modal && modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: true, keyboard: true, focus: true }).show();
          } else {
            $('#response-html-modal').modal('show');
          }
          setupTemplateBuilder($('#response-html-modal'));
          initCourseBuilderEditors($('#response-html-modal'));
          setupUnsavedChanges($('#response-html-modal'));
        } else {
          showError(response, 'Could not open the lesson form.');
        }
      },
      error: function() {
        showError(null, 'Could not open the lesson form because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  }

  function openSectionModal(formData, $button) {
    destroyCourseBuilderEditors();
    return $.ajax({
      type: 'POST',
      url: 'requests/section/form',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Loading...');
      },
      success: function(response) {
        if (responseOk(response)) {
          $('.ajax-response').html(response.html);
          cleanModalArtifacts(false);
          var modalEl = document.getElementById('response-html-modal');
          if (window.bootstrap && bootstrap.Modal && modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: true, keyboard: true, focus: true }).show();
          } else {
            $('#response-html-modal').modal('show');
          }
          setupSectionForm($('#response-html-modal'));
          setupUnsavedChanges($('#response-html-modal'));
        } else {
          showError(response, 'Could not open the section form.');
        }
      },
      error: function() {
        showError(null, 'Could not open the section form because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  }

  $(document).on('hidden.bs.modal', '#response-html-modal', function() {
    destroyCourseBuilderEditors();
    $(this).remove();
    cleanModalArtifacts();
  });


  $(document).off('click.courseBuilderTemplate').on('click.courseBuilderTemplate', '.template-card', function(e) {
    e.preventDefault();
    activateTemplate($(this).closest('form'), $(this).data('template'));
  });

  $(document).off('change.courseBuilderCustomLabel').on('change.courseBuilderCustomLabel', '[data-custom-label-selector]', function() {
    updateCustomLessonLabel($(this).closest('form'));
  });

  $(document).off('change.courseBuilderSectionType').on('change.courseBuilderSectionType', '.sectionTypeSelector', function() {
    updateSectionTypeFields($(this).closest('form'));
  });

  $(document).off('change.courseBuilderLearningRules').on('change.courseBuilderLearningRules', '.sectionUnlockModeSelector, .sectionCompletionRuleSelector', function() {
    updateLearningRuleFields($(this).closest('form'));
  });

  $(document).on('submit', '.itemForm', function(e) {
    e.preventDefault();
    var formData = new FormData(this);
    var $form = $(this);
    var $button = $form.find('button[type="submit"], button').first();
    activeCourseId = formData.get('course_id');
    reopenLessonListAfterItemSave = $form.closest('#SortItemsHtmlModal').length > 0;
    if (reopenLessonListAfterItemSave) {
      hideLessonListModal(function() {
        openItemModal(formData, $button);
      });
    } else {
      openItemModal(formData, $button);
    }
  });

  $(document).on('submit', '.allItems', function(e) {
    e.preventDefault();
    var formData = new FormData(this);
    var courseId = formData.get('course_id');
    var $button = $(this).find('button[type="submit"], button').first();
    setLoading($button, true, 'Loading...');
    refreshLessonList(courseId, true).always(function() {
      setLoading($button, false);
    });
  });

  $(document).on('submit', '.addSectionForm, .editSection', function(e) {
    e.preventDefault();
    var formData = new FormData(this);
    var $form = $(this);
    var $button = $form.find('button[type="submit"], button').first();
    activeCourseId = formData.get('course_id') || activeCourseId;
    if ($form.closest('#SortItemsHtmlModal').length) {
      hideLessonListModal(function() {
        openSectionModal(formData, $button);
      });
    } else {
      openSectionModal(formData, $button);
    }
  });

  $(document).on('submit', '.editItem', function(e) {
    e.preventDefault();
    var formData = new FormData(this);
    var $button = $(this).find('button[type="submit"], button').first();
    activeCourseId = formData.get('course_id') || activeCourseId;
    reopenLessonListAfterItemSave = true;
    hideLessonListModal(function() {
      openItemModal(formData, $button);
    });
  });

  $(document).on('submit', '#addSection, #updateSection', function(e) {
    e.preventDefault();

    var form = this;
    var formData = new FormData(form);
    var courseId = formData.get('course_id') || activeCourseId;
    var $button = $(form).find('.submitBtn').first();

    $.ajax({
      type: 'POST',
      url: 'requests/section/add',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Saving...');
      },
      success: function(response) {
        if (responseOk(response)) {
          showToast('success', responseMessage(response, 'Section saved successfully.'));
          closeItemModalSmooth(function() {
            var highlightSelector = response.section_id ? '.course-builder-section[data-section-id="' + response.section_id + '"]' : '';
            refreshLessonList(courseId, true, highlightSelector);
          });
        } else {
          showError(response, 'Section could not be saved.');
        }
      },
      error: function() {
        showError(null, 'Section could not be saved because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  });

  $(document).on('submit', '#addNewItem, #updateItem', function(e) {
    e.preventDefault();
    if (typeof tinymce !== 'undefined') {
      tinymce.triggerSave();
    }

    var form = this;
    var formData = new FormData(form);
    var courseId = formData.get('course_id') || activeCourseId;
    var $button = $(form).find('.submitBtn').first();

    $.ajax({
      type: 'POST',
      url: 'requests/item/add',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Saving...');
      },
      success: function(response) {
        if (responseOk(response)) {
          showToast('success', responseMessage(response, 'Lesson saved successfully.'));
          var keepListOpen = reopenLessonListAfterItemSave || $('#SortItemsHtmlModal').hasClass('show');
          reopenLessonListAfterItemSave = false;
          closeItemModalSmooth(function() {
            var highlightSelector = response.item_id ? '.course-builder-item[data-item-id="' + response.item_id + '"]' : '';
            refreshLessonList(courseId, keepListOpen, highlightSelector);
          });
        } else {
          showError(response, 'Lesson could not be saved.');
        }
      },
      error: function() {
        showError(null, 'Lesson could not be saved because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  });

  $(document).on('submit', '.toggleItemVisibility', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);
    var courseId = formData.get('course_id') || activeCourseId;
    var $button = $(form).find('button[type="submit"], button').first();

    $.ajax({
      type: 'POST',
      url: 'requests/item/status',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Updating...');
      },
      success: function(response) {
        if (responseOk(response)) {
          showToast('success', responseMessage(response, 'Lesson visibility updated.'));
          refreshLessonList(courseId, true);
        } else {
          showError(response, 'Lesson visibility could not be updated.');
        }
      },
      error: function() {
        showError(null, 'Lesson visibility could not be updated because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  });


  function duplicateItemRequest(formData, courseId, $button) {
    $.ajax({
      type: 'POST',
      url: 'requests/item/duplicate',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Duplicating...');
      },
      success: function(response) {
        if (responseOk(response)) {
          showToast('success', responseMessage(response, 'Lesson duplicated successfully.'));
          var highlightSelector = response.item_id ? '.course-builder-item[data-item-id="' + response.item_id + '"]' : '';
          refreshLessonList(courseId, true, highlightSelector);
        } else {
          showError(response, 'Lesson could not be duplicated.');
        }
      },
      error: function() {
        showError(null, 'Lesson could not be duplicated because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  }

  function duplicateSectionRequest(formData, courseId, $button) {
    $.ajax({
      type: 'POST',
      url: 'requests/section/duplicate',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Duplicating...');
      },
      success: function(response) {
        if (responseOk(response)) {
          showToast('success', responseMessage(response, 'Section duplicated successfully.'));
          var highlightSelector = response.section_id ? '.course-builder-section[data-section-id="' + response.section_id + '"]' : '';
          refreshLessonList(courseId, true, highlightSelector);
        } else {
          showError(response, 'Section could not be duplicated.');
        }
      },
      error: function() {
        showError(null, 'Section could not be duplicated because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  }

  function restoreInlineTitle($title, value) {
    $title.text(value);
    $title.attr('data-original-title', value).data('originalTitle', value);
    $title.removeData('inlineCommitting');
    inlineTitleSaving = false;
  }

  function commitInlineTitle($input) {
    var $title = $input.closest('.course-builder-inline-title');
    if (!$title.length || $title.data('inlineCommitting')) {
      return;
    }

    var original = String($title.data('originalTitle') || $title.attr('data-original-title') || '').trim();
    var value = String($input.val() || '').trim();
    if (value === '' || value === original) {
      restoreInlineTitle($title, original || value);
      return;
    }

    $title.data('inlineCommitting', true);
    inlineTitleSaving = true;
    $input.prop('disabled', true);

    var kind = $title.data('title-kind');
    var formData = new FormData();
    formData.append('_method', 'TITLE');
    formData.append('course_id', $title.data('course-id') || activeCourseId || '');
    formData.append('title', value);

    var url = 'requests/item/title';
    if (kind === 'section') {
      url = 'requests/section/title';
      formData.append('section_id', $title.data('section-id') || '');
    } else {
      formData.append('item_id', $title.data('item-id') || '');
    }

    $.ajax({
      type: 'POST',
      url: url,
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      success: function(response) {
        if (responseOk(response)) {
          restoreInlineTitle($title, response.title || value);
          showToast('success', responseMessage(response, 'Title updated.'));
        } else {
          restoreInlineTitle($title, original);
          showError(response, 'Title could not be updated.');
        }
      },
      error: function() {
        restoreInlineTitle($title, original);
        showError(null, 'Title could not be updated because the server could not be reached.');
      }
    });
  }

  function startInlineTitleEdit($title) {
    if (!$title.length || $title.find('input').length) {
      return;
    }

    var original = String($title.text() || '').trim();
    $title.attr('data-original-title', original).data('originalTitle', original);
    var $input = $('<input type="text" class="form-control form-control-sm course-builder-inline-title-input">').val(original);
    $title.empty().append($input);
    setTimeout(function() {
      $input.trigger('focus').trigger('select');
    }, 20);
  }

  function moveBuilderElement($element, direction, itemSelector) {
    if (!$element.length) {
      return;
    }
    var $target = direction === 'up' ? $element.prev(itemSelector) : $element.next(itemSelector);
    if (!$target.length) {
      showToast('info', direction === 'up' ? 'Already at the top.' : 'Already at the bottom.');
      return;
    }
    if (direction === 'up') {
      $element.insertBefore($target);
    } else {
      $element.insertAfter($target);
    }
    highlightBuilderTarget($element);
    scheduleCourseBuilderSortSave();
  }

  $(document).on('submit', '.duplicateItem', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);
    var courseId = formData.get('course_id') || activeCourseId;
    var $button = $(form).find('button[type="submit"], button').first();
    duplicateItemRequest(formData, courseId, $button);
  });

  $(document).on('submit', '.duplicateSection', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);
    var courseId = formData.get('course_id') || activeCourseId;
    var $button = $(form).find('button[type="submit"], button').first();
    duplicateSectionRequest(formData, courseId, $button);
  });

  $(document).on('click', '.moveItem', function(e) {
    e.preventDefault();
    e.stopPropagation();
    moveBuilderElement($(this).closest('.course-builder-item'), $(this).data('direction'), '.course-builder-item');
  });

  $(document).on('click', '.moveSection', function(e) {
    e.preventDefault();
    e.stopPropagation();
    moveBuilderElement($(this).closest('.course-builder-section'), $(this).data('direction'), '.course-builder-section');
  });

  $(document).on('click keydown', '.course-builder-inline-title', function(e) {
    if (e.type === 'keydown' && e.key !== 'Enter') {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    startInlineTitleEdit($(this));
  });

  $(document).on('click keydown', '.course-builder-inline-title-input', function(e) {
    e.stopPropagation();
    if (e.type === 'keydown') {
      if (e.key === 'Enter') {
        e.preventDefault();
        commitInlineTitle($(this));
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        var $title = $(this).closest('.course-builder-inline-title');
        restoreInlineTitle($title, $title.data('originalTitle') || $title.attr('data-original-title') || '');
      }
    }
  });

  $(document).on('blur', '.course-builder-inline-title-input', function() {
    var $input = $(this);
    setTimeout(function() {
      if (!$input.closest('.course-builder-inline-title').data('inlineCommitting')) {
        commitInlineTitle($input);
      }
    }, 80);
  });

  $(document).on('click focusin', '.course-builder-item', function() {
    $('.course-builder-item.is-selected').removeClass('is-selected');
    $(this).addClass('is-selected');
    selectedBuilderItem = this;
  });

  function deleteSectionRequest(formData, courseId, $button) {
    $.ajax({
      type: 'POST',
      url: 'requests/section/delete',
      data: formData,
      dataType: 'json',
      contentType: false,
      cache: false,
      processData: false,
      beforeSend: function() {
        setLoading($button, true, 'Deleting...');
      },
      success: function(response) {
        if (responseOk(response)) {
          showToast('success', responseMessage(response, 'Section deleted successfully.'));
          refreshLessonList(courseId, true);
          return;
        }

        if (response && response.requires_move && response.options) {
          Swal.fire({
            title: 'This section contains lessons',
            text: 'Choose where to move the lessons before deleting this section.',
            icon: 'warning',
            input: 'select',
            inputOptions: response.options,
            inputPlaceholder: 'Move lessons to...',
            showCancelButton: true,
            confirmButtonText: 'Move and Delete',
            cancelButtonText: 'Cancel',
            inputValidator: function(value) {
              if (!value) {
                return 'Please choose a target section.';
              }
              return null;
            }
          }).then(function(result) {
            if (!result.isConfirmed) {
              return;
            }
            formData.set('move_to', result.value);
            deleteSectionRequest(formData, courseId, $button);
          });
          return;
        }

        showError(response, 'Section could not be deleted.');
      },
      error: function() {
        showError(null, 'Section could not be deleted because the server could not be reached.');
      },
      complete: function() {
        setLoading($button, false);
      }
    });
  }

  $(document).on('submit', '.deleteSection', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);
    var courseId = formData.get('course_id') || activeCourseId;
    var $button = $(form).find('button[type="submit"], button').first();

    Swal.fire({
      title: 'Delete this section?',
      text: 'Empty sections can be deleted immediately. Sections with lessons will ask where to move them.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: 'var(--primary)',
      cancelButtonColor: 'var(--danger)',
      confirmButtonText: 'Continue',
      cancelButtonText: 'Cancel'
    }).then(function(result) {
      if (!result.isConfirmed) {
        return;
      }
      deleteSectionRequest(formData, courseId, $button);
    });
  });

  $(document).on('submit', '.deleteItem', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);
    var courseId = formData.get('course_id') || activeCourseId;
    var $button = $(form).find('button[type="submit"], button').first();

    Swal.fire({
      title: 'Are you sure?',
      text: 'You will not be able to undo this!',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: 'var(--primary)',
      cancelButtonColor: 'var(--danger)',
      confirmButtonText: 'Yes, delete it!',
      cancelButtonText: 'Cancel'
    }).then(function(result) {
      if (!result.isConfirmed) {
        return;
      }

      $.ajax({
        type: 'POST',
        url: 'requests/item/delete',
        data: formData,
        dataType: 'json',
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function() {
          setLoading($button, true, 'Deleting...');
        },
        success: function(response) {
          if (responseOk(response)) {
            showToast('success', responseMessage(response, 'Lesson deleted successfully.'));
            refreshLessonList(courseId, true);
          } else {
            showError(response, 'Lesson could not be deleted.');
          }
        },
        error: function() {
          showError(null, 'Lesson could not be deleted because the server could not be reached.');
        },
        complete: function() {
          setLoading($button, false);
        }
      });
    });
  });

  $(document).on('keydown.courseBuilderShortcuts', function(e) {
    var target = e.target;
    var $target = $(target);
    var isTyping = $target.is('input, textarea, select') || $target.closest('.tox-tinymce, .tox-tinymce-aux').length > 0;

    if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 's') {
      var $modal = $('#response-html-modal');
      if ($modal.length && $modal.hasClass('show')) {
        e.preventDefault();
        $modal.find('form').first().trigger('submit');
      }
      return;
    }

    if (e.key === 'Escape') {
      var $openModal = $('#response-html-modal.show');
      if ($openModal.length) {
        e.preventDefault();
        if (window.bootstrap && bootstrap.Modal && $openModal[0]) {
          bootstrap.Modal.getOrCreateInstance($openModal[0]).hide();
        } else {
          $openModal.modal('hide');
        }
      }
      return;
    }

    if (e.key === 'Delete' && !isTyping && !$('#response-html-modal.show').length && selectedBuilderItem) {
      var $selected = $(selectedBuilderItem);
      if ($selected.closest(document.documentElement).length) {
        e.preventDefault();
        $selected.find('.deleteItem').first().trigger('submit');
      }
    }
  });

  document.addEventListener('focusin', function(e) {
    if (e.target.closest('.tox-tinymce-aux, .moxman-window, .tam-assetmanager-root') !== null) {
      e.stopImmediatePropagation();
    }
  });
});

</script>




<script>

jQuery( document ).ready(function( $ ) {


   // Edit a Teacher
   $(".addUserToCourse").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/course/addUser",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $("#editUser").css("opacity", ".5");
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            console.log("goodddddddddddddd");
            $(".ajax-response").html(response.html);
            //Initialize Select2 Elements
            // Initialize Select2 with tags enabled
            $('.select2').select2({
              tags: true
            });

            // Get all options
            // var options = $('.select2 option');

            // Set selected for each option
            // options.each(function() {
            //   $(this).prop('selected', true);
            // });

            // Trigger change event to update Select2
            $('.select2').trigger('change');
            
            $('.select2').select2({
                dropdownParent: $('#addUserToCourseResponse')
            });          
            // $(".select2").select2({
            //     dropdownParent: $('#addUserToCourseResponse .modal-content')
            // });
  

            function updateStudentLearningOverrideFields(){
              var mode = $('[data-student-learning-override]').val();
              $('[data-unlock-selected-sections]').toggleClass('d-none', mode !== 'unlock_selected');
            }
            $(document).off('change.studentLearningOverride').on('change.studentLearningOverride', '[data-student-learning-override]', updateStudentLearningOverrideFields);
            updateStudentLearningOverrideFields();

            $('#addUserToCourseResponse').modal('show');
            // $('#response-html-modal').modal('show');
            

            //*** Send Edit Request
            $("#enrollUserToCourse").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                type: "POST",
                url: "requests/course/addUser",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#enrollUserToCourse").css("opacity", ".5");
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#enrollUserToCourse")[0].reset();
                    $(".response-msg").html(
                      Swal.fire({
                        icon: "success",
                        title: response.message,
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                      }).then(function (isConfirm) {
                        if (isConfirm) {
                          location.reload();
                        } else {
                          //if no clicked => do something else
                        }
                      })
                    );
                  } else {
                    $(".response-msg").html(
                      Swal.fire({
                        icon: "error",
                        title: response.message,
                        text: response.reason,
                        showConfirmButton: true,
                        timer: 10000,
                        timerProgressBarColor: "var(--primary)",
                        timerProgressBar: true,
                      }).then(function (isConfirm) {
                        if (isConfirm) {
                          // location.reload();
                        } else {
                          //if no clicked => do something else
                        }
                      })
                    );
                  }
                  $("#enrollUserToCourse").css("opacity", "");
                  $(".submitBtn").removeAttr("disabled");
                },
              });
            });
            //*** Send Edit Request









            // $("#addApi")[0].reset();
            // $(".response-msg").html(
            //   Swal.fire({
            //     icon: "success",
            //     title: response.message,
            //     showConfirmButton: false,
            //     timer: 2000,
            //     timerProgressBar: true,
            //   }).then(function (isConfirm) {
            //     if (isConfirm) {
            //       location.reload();
            //     } else {
            //       //if no clicked => do something else
            //     }
            //   })
            // );

          } else {
            $(".response-msg").html(
              Swal.fire({
                icon: "error",
                title: response.message,
                text: response.reason,
                showConfirmButton: true,
                timer: 10000,
                timerProgressBarColor: "var(--primary)",
                timerProgressBar: true,
              }).then(function (isConfirm) {
                if (isConfirm) {
                  location.reload();
                } else {
                  //if no clicked => do something else
                }
              })
            );
          }
          $("#editUser").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Edit Teacher

  });


</script>



<script>
    $("input,textarea").on('keyup',function(){$(this).parent().find('.last_appended_counter').remove();$(this).parent().append('<div class="col-12 p-2 last_appended_counter"><span class="d-inline-block" style="font-size: 13px">Character count <span class="ds-text-secondary" style="font-weight: bolder; font-size: 15px">'+$(this).val().length+'</span> characters</span></div>');});

</script>




 <script>

// $("table").DataTable({
//   // "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, 100, "All"] ],
//       "responsive": true, "lengthChange": true, "autoWidth": true,
//     //   "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
//       "buttons": [
//         { 
//         extend: 'copy',
//         text: 'Copy',
//         exportOptions:{columns: ':visible'}
//         },{ 
//         extend: 'csv',
//         text: 'Excel (CSV)',
//         exportOptions:{columns: ':visible'}
//         },{ 
//         extend: 'excel',
//         text: 'Excel',
//         exportOptions:{columns: ':visible'}
//         }
//         // ,{ 
//         // extend: 'pdf',
//         // text: 'PDF',
//         // exportOptions:{columns: ':visible'}
//         // }
//         ,{ 
//         extend: 'print',
//         text: 'Print',
//         exportOptions:{columns: ':visible'}
//         },{ 
//         extend: 'colvis',
//         text: 'View'
//         },
//       ],
//       language: {
//         paginate: {
//           next: 'Next', // or '→'
//           previous: 'Previous' // or '←' 
//         },
//         "search": "Search:"
//        },
//        oLanguage: {
//                "sInfo" : "Showing _START_ to _END_ of _TOTAL_ entries",// text you want show for info section
//                "sLengthMenu": "Show _MENU_ rows",

//         },
//     });



document.addEventListener('focusin', (e) => {
  if (e.target.closest(".tox-tinymce-aux, .moxman-window, .tam-assetmanager-root") !== null) {
    e.stopImmediatePropagation();
  }
});

// $('#coursesTable').dataTable( {
//     "drawCallback": function( settings ) {
//         alert( 'DataTables has redrawn the table' );
//     }
// } );
</script>




<script>

// $(document).ready(function() {
//   $('.select2').each(function() { 
//     $(this).select2({ dropdownParent: $(this).parent()});
// })
// });

// $(document).ready(function() {

//   $("#SortItemsHtmlModal").on("shown",function(){
//     $( "#SortItemsHtmlModal #page_list" ).sortable();
//   })

// });


</script>

</body>


    <?php mysqli_close($conn); ?>
</html>
