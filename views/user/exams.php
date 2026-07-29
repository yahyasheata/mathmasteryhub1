<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$pageName = "exams";
$username = $_SESSION['username'];
$user_id = getUserInfo($username)->user_id;
$conn = db();

// Fetch all exam submissions for this user
$query = "SELECT s.*, e.exam_title FROM exam_submissions s JOIN exams e ON s.exam_id = e.exam_id WHERE s.student_id = '$user_id' ORDER BY s.submitted_at DESC";
$result = mysqli_query($conn, $query);
?>
<!doctype html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include "layouts/user/header.php"; ?>
    <title>Exam Submissions | <?= $site_name; ?> </title>
    <style>
        .assignment-card {
            max-width: 650px;
            margin: 0 auto 24px auto;
            border-radius: 18px;
            box-shadow: var(--shadow-sm) !important;
            background: var(--surface);
            border: none;
            transition: box-shadow 0.2s;
            padding: 0;
        }

        .assignment-card:hover {
            box-shadow: var(--shadow-md) !important;
        }

        .btn-info {
            color: var(--text-inverse) !important;
            background-color: var(--info) !important;
            border-color: var(--info) !important;
        }

        .btn-info:hover,
        .btn-info:focus {
            background-color: var(--primary-hover) !important;
            border-color: var(--primary-hover) !important;
            color: var(--text-inverse) !important;
        }

        .bg-info {
            background-color: var(--info) !important;
            color: var(--text-inverse) !important;
        }
    </style>
</head>

<body class='body ds-bg-primary' style="margin-top: 65px">
    <div id="app">
        <div id="body-overlay"
            onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');">
        </div>
        <?php include "layouts/user/aside.php"; ?>
        <main class="p-0 font-2">
            <div class='col-12 ds-bg-primary' style="min-height: 100vh">
                <div class='col-12 p-0 ds-surface'>
                    <div class="container">
                        <div class="col-12 p-0 d-flex align-items-center justify-content-center" style="min-height: 40vh">
                            <div style="width: 700px" class="mx-auto py-8 d-flex align-items-center justify-content-center">
                                <div class="text-center col-12 p-0 mx-auto">
                                    <div class="col-12 px-0 row d-flex justify-content-between">
                                        <div class='col-12 py-5 rounded-2 text-center ds-surface' style="text-align: center; margin-top: -5px">
                                            <div class="col-12" style="display: flex; justify-content: center">
                                                <img src="../<?= $user_data['avatar'] ?>" style="width:130px;height: 130px;border-radius: 50%;">
                                            </div>
                                            <div class="col-12 p-2 text-center" style="overflow: auto">
                                                <?= $user_full_name ?>
                                                <br>
                                                <span class="font-1"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 p-0 border-lg-top">
                        <div class="container p-0">
                            <div class="col-12 row user-menu">
                                <nav class='navbar navbar-expand-lg navbar-light ds-surface-muted'>
                                    <div class="container-fluid p-0">
                                        <div class="col-12 px-0 row d-flex m-0 py-3 py-lg-0 justify-content-between align-items-center d-lg-none">
                                            <div class='navbar-brand navbar-toggler font-2 px-3 col-auto ds-text-secondary' data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">Dashboard</div>
                                            <button class='navbar-toggler d-flex col-auto ds-shadow-sm' type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                                                <span class="fas fa-bars"></span>
                                            </button>
                                        </div>
                                        <?php include "layouts/user/main-nav.php"; ?>
                                    </div>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="container py-2 px-2">
                        <div class="col-12 p-0 row d-flex">
                            <div class='col-12 p-4 mb-3 row d-flex align-items-center justify-content-center ds-surface' style="border-radius: 8px; min-height: 40vh">
                                <h3 class="text-center mb-4">Exam Submissions</h3>
                                <?php if (mysqli_num_rows($result) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <div class="card mb-3 shadow-sm assignment-card" style="max-width: 600px">
                                            <div class="card-body">
                                                <h5 class="card-title mb-2"><?php echo htmlspecialchars($row['exam_title']); ?></h5>
                                                <p class="card-text mb-1"><strong>Submission Date:</strong> <?php echo htmlspecialchars($row['submitted_at']); ?></p>
                                                <p class="card-text mb-1"><strong>File:</strong> <?php echo $row['file_path'] ? '<a href="../' . htmlspecialchars($row['file_path']) . '" target="_blank" class="btn btn-primary btn-sm">Download File</a>' : '-'; ?></p>
                                                <p class="card-text mb-1"><strong>Feedback:</strong> <?php echo $row['feedback'] ? '<a href="../' . htmlspecialchars($row['feedback']) . '" target="_blank" class="btn btn-info btn-sm">Download Feedback</a>' : '<span class="badge bg-danger">No Feedback</span>'; ?></p>
                                                <p class="card-text mb-1"><strong>Grade:</strong> <?php echo $row['grade'] ? '<span class="badge bg-info">' . htmlspecialchars($row['grade']) . '</span>' : '<span class="badge bg-secondary">Not Graded</span>'; ?></p>
                                                <p class="card-text mb-0"><strong>Status:</strong> <?php echo $row['feedback'] ? '<span class="badge bg-success">Feedback Uploaded</span>' : '<span class="badge bg-danger">Waiting for Feedback</span>'; ?></p>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="alert alert-info text-center">No exam submissions yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <?php include "layouts/user/footer.php"; ?>
    </div>
</body>

</html>