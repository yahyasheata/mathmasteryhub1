<div class="collapse navbar-collapse" id="navbarSupportedContent">
                                <div class="navbar-nav me-auto mb-0 mb-lg-0 student-primary-nav">
                                    <a href="<?=rtrim((string)$baseUrl, '/')?>/" class="user-menu student-nav-link d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= ($pageName == 'home') ? 'active' : ''; ?>" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                        <span class="fas fa-home mx-2"></span> Home
                                    </a>
                                    <a href="<?=rtrim((string)$baseUrl, '/')?>/user/my-courses" class="user-menu student-nav-link d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= ($pageName == 'mycourses') ? 'active' : ''; ?>" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                        <span class="fas fa-play-circle mx-2"></span> My Courses
                                    </a>
                                    <a href="<?=rtrim((string)$baseUrl, '/')?>/user/analytics" class="user-menu student-nav-link d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= ($pageName == 'analytics') ? 'active' : ''; ?>" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                        <span class="fas fa-chart-line mx-2"></span> My Progress
                                    </a>
                                    <a href="<?=rtrim((string)$baseUrl, '/')?>/user/revision-plans" class="user-menu student-nav-link d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= ($pageName == 'revision_plans') ? 'active' : ''; ?>" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                        <span class="fas fa-route mx-2"></span> Your Plans
                                    </a>
                                    <a href="<?=rtrim((string)$baseUrl, '/')?>/user/assignments" class="user-menu student-nav-link d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= ($pageName == 'assignments' || $pageName == 'assignment_submissions') ? 'active' : ''; ?>" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                        <span class="fas fa-tasks mx-2"></span> Assignments
                                    </a>
                                    <a href="<?=rtrim((string)$baseUrl, '/')?>/user/live-sessions" class="user-menu student-nav-link d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= ($pageName == 'live_sessions') ? 'active' : ''; ?>" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                        <span class="fas fa-video mx-2"></span> Live Sessions
                                    </a>
                                    <a href="<?=rtrim((string)$baseUrl, '/')?>/user/notifications" class="user-menu student-nav-link d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= ($pageName == 'notifications') ? 'active' : ''; ?>" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                        <span class="fas fa-bell mx-2"></span> Notifications
                                    </a>
                                    <div class="nav-item dropdown student-nav-more">
                                        <button class="user-menu student-nav-link dropdown-toggle d-flex align-items-center col-auto justify-content-lg-center justify-content-start py-3 px-2 <?= in_array($pageName, ['courses', 'exam_submissions', 'exams', 'settings'], true) ? 'active' : ''; ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="min-width:120px;border-bottom:6px solid transparent;height: 100%;color: inherit;transition: 0s all ease;">
                                            <span class="fas fa-ellipsis-h mx-2"></span> More
                                        </button>
                                        <div class="dropdown-menu student-nav-more-menu">
                                            <a href="<?=rtrim((string)$baseUrl, '/')?>/user/courses" class="dropdown-item <?= ($pageName == 'courses') ? 'active' : ''; ?>"><span class="fas fa-bars mx-2"></span> All Courses</a>
                                            <a href="<?=rtrim((string)$baseUrl, '/')?>/user/exams" class="dropdown-item <?= ($pageName == 'exam_submissions' || $pageName == 'exams') ? 'active' : ''; ?>"><span class="fas fa-file-alt mx-2"></span> Exam Submissions</a>
                                            <a href="<?=rtrim((string)$baseUrl, '/')?>/user/settings" class="dropdown-item <?= ($pageName == 'settings') ? 'active' : ''; ?>"><span class="fas fa-wrench mx-2"></span> Settings</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
