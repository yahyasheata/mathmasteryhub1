<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/CourseVisibility.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Vary: Cookie');
$pageName = "courses";
$username = $_SESSION['username'];

$site_settings = getSiteSettings();
$site_name = $site_settings["website_name"];

$conn = db();
$courses_query = "SELECT *, courses.id as cid FROM courses INNER JOIN categories ON courses.course_category = categories.id WHERE category_link = '$categoryId' AND course_status=1 AND COALESCE(courses.course_visibility, 'public') = 'public'";
$coures_result = mysqli_query($conn,$courses_query);

$categorie_header = '';
$courses_grid = '';

if(mysqli_num_rows($coures_result) > 0){
  $first = true;
  while($courses_data = mysqli_fetch_assoc($coures_result)){
    $date = date('Y-m-d', strtotime($courses_data['created_at']));
    if($first) {
      $categorie_header = "
        <div class='max-w-2xl mx-auto text-center mb-12'>
          <h1 class='text-4xl md:text-5xl font-extrabold mb-4 ds-text-primary'>{$courses_data['category_title']}</h1>
          <p class='text-lg ds-text-secondary'>{$courses_data['category_description']}</p>
        </div>
      ";
      $first = false;
    }
    $content = htmlspecialchars($courses_data['course_content'] ?? '', ENT_QUOTES, 'UTF-8');
    $courseImageUrl = htmlspecialchars(mmh_site_public_url((string) ($courses_data['course_image'] ?? '')), ENT_QUOTES, 'UTF-8');
    $preDiscount = !empty($courses_data['preDiscount_course_price']) ? "<span class='line-through ds-text-muted text-sm mr-2'>{$courses_data['preDiscount_course_price']} EGP</span>" : "";
    $courses_grid .= "
      <div class='ds-surface border ds-border rounded-xl overflow-hidden shadow-lg hover:shadow-xl transition duration-300 flex flex-col'>
        <a href='{$baseUrl}/course/{$courses_data['cid']}'>
          <div class='h-48 overflow-hidden'>
            <img src='{$courseImageUrl}' alt='{$courses_data['course_title']}' class='w-full h-full object-cover transition-transform duration-300 hover:scale-105'>
          </div>
        </a>
        <div class='p-6 flex flex-col h-full'>
          <h2 class='text-xl font-bold mb-2 ds-text-primary'><a href='{$baseUrl}/course/{$courses_data['cid']}'>{$courses_data['course_title']}</a></h2>
          <p class='ds-text-secondary mb-4'>{$courses_data['course_description']}</p>
          <div class='flex items-center mb-4'>
            $preDiscount
            <span class='bg-primary/10 text-primary text-base px-3 py-1 rounded-full font-semibold ml-2'>{$courses_data['course_price']} EGP</span>
          </div>
          <div class='flex gap-2 mb-4'>
            <a href='{$baseUrl}/course/{$courses_data['cid']}' type='button' class='show-content-btn ds-surface-muted ds-text-primary px-4 py-2 rounded-lg hover:bg-[var(--surface-hover)] transition text-sm font-bold flex items-center' data-content='$content'>
              <i class='fas fa-list mr-2'></i> View Content
            </a>
            <form action='' method='POST' class='purchaseForm'>
              <input type='hidden' name='course_id' value='{$courses_data['course_id']}'>
              <input type='hidden' name='course_title' value='{$courses_data['course_title']}'>
              <input type='hidden' name='_method' value='POST'>
              <button type='submit' class='bg-secondary ds-text-inverse px-4 py-2 rounded-lg hover:bg-secondary/90 transition text-sm font-bold flex items-center'>
                <i class='fas fa-shopping-cart mr-2'></i> Subscribe Now
              </button>
            </form>
          </div>
          <div class='flex items-center mt-auto ds-text-muted text-xs'>
            <i class='far fa-clock mr-1'></i> {$date}
          </div>
        </div>
      </div>
    ";
  }
} else {
  $courses_grid = "
    <div class='col-span-full text-center py-16'>
      <div class='inline-flex flex-col items-center justify-center bg-yellow-50 border border-yellow-200 rounded-xl p-8'>
        <i class='fas fa-info-circle text-4xl text-yellow-400 mb-4'></i>
        <p class='text-lg ds-text-secondary mb-4'>No courses available at the moment.</p>
        <a href='/' class='bg-primary ds-text-inverse px-6 py-2 rounded-lg hover:bg-primary/90 transition font-bold flex items-center'>
          <i class='fas fa-home mr-2'></i> Go to Home
        </a>
      </div>
    </div>
  ";
}

?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=$categorie_title ?? 'Courses'?> | <?=$site_name;?></title>
    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/fontawsome5.min.css')?>">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: 'var(--primary)',
                        secondary: 'var(--secondary)',
                        dark: 'var(--bg-primary)',
                        light: 'var(--surface)'
                    },
                    fontFamily: {
                        tajawal: ['Tajawal', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Tajawal', sans-serif; }
        .dark .dark-invert { filter: invert(1); }
        .dark-transition { transition: background-color 0.3s, color 0.3s, border-color 0.3s, box-shadow 0.3s; }
    </style>
    <link rel="stylesheet" href="<?=$baseUrl?>/resources/css/design-system.css" data-design-system="mathhub" />
</head>
<body class='font-tajawal ds-bg-primary ds-text-primary transition-colors duration-300'>
<?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/aside.php"; ?>

<!-- Category Hero/Header -->
<section class='relative ds-surface py-16 lg:py-24 overflow-hidden transition-colors duration-300'>
  <div class="container mx-auto px-4 relative">
    <?=$categorie_header;?>
  </div>
</section>

<!-- Courses Grid -->
<section class='py-12 ds-surface transition-colors duration-300'>
  <div class="container mx-auto px-4">
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
      <?=$courses_grid?>
    </div>
  </div>
</section>

<?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/footer.php"; ?>

<!-- JavaScript for purchase form (SweetAlert) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $(".purchaseForm").on("submit", function (e) {
      e.preventDefault();
      var course_title = $(this).find("[name='course_title']").val();
      Swal.fire({
        title: `You are about to subscribe to <strong class='text-info'>[${course_title}]</strong>`,
        text: "Do you want to proceed with the purchase?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--primary)",
        cancelButtonColor: "var(--danger)",
        confirmButtonText: "Yes, Subscribe!",
        cancelButtonText: "Cancel",
      }).then((result) => {
        if (result.isConfirmed) {
          $.ajax({
            type: "POST",
            url: "../requests/course/purchase",
            data: new FormData(this),
            dataType: "json",
            contentType: false,
            cache: false,
            processData: false,
            success: function (response) {
              if (response.status == 1 && response.payment_url) {
                window.location.href = response.payment_url;
              } else {
                Swal.fire({
                  icon: "error",
                  title: response.message,
                  text: response.reason || response.error || '',
                  showConfirmButton: true,
                  timer: 10000,
                  timerProgressBar: true,
                });
              }
            },
          });
        }
      });
    });
});
</script>
</body>
</html>
