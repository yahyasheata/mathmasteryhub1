    <?php 
        $site_settings = getSiteSettings();
        $site_name = $site_settings["website_name"];
        $site_description = $site_settings["website_bio"];
        $site_icon = $site_settings["website_icon"];
        
 
        
    ?>
   
    <?php include __DIR__ . '/../../partials/favicon.php'; ?>




    <link rel="preload" as="style" href="<?=$baseUrl?>/resources//build/assets/app-38448552.css" />
    <link rel="stylesheet" href="<?=$baseUrl?>/resources//build/assets/app-38448552.css" data-navigate-track="reload" />
    
    <script type="text/javascript" src="<?=$baseUrl?>/resources/js/sweetalert2.min.js"></script>

    <link rel="stylesheet" href="<?=$baseUrl?>/resources/css/sweetalert2.min.css" />


    <script type="text/javascript" src="<?=$baseUrl?>/resources/js/jquery-3.6.1.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/jquery-validation@1.20.0/dist/jquery.validate.min.js"></script>
    <link rel="stylesheet" href="<?=$baseUrl?>/resources/css/fontawsome5.min.css"  referrerpolicy="no-referrer" />

    
    <link rel="preload" as="style" href="<?=$baseUrl?>/resources/build/assets/app-38448552.css" />
    <link rel="stylesheet" href="<?=$baseUrl?>/resources/build/assets/app-38448552.css" data-navigate-track="reload" />
        <script>
        document.documentElement.lang = 'en';
        document.documentElement.dir = 'ltr';
    </script>
    <style type="text/css">
        html,
        body {
            direction: ltr;
            text-align: start;
        }

        body input,
        body select,
        body textarea,
        body table,
        body .modal,
        body .dropdown-menu {
            text-align: start;
        }


        body {
            --bg-main: var(--surface);
            --bg-second: var(--bg-primary);
            --font-1: var(--text-primary);
            --font-2: var(--text-secondary);
            --border-color: var(--border);
            --main-color: var(--primary);
            --main-color-rgb: 241, 90, 34;
            --main-color-flexable: var(--primary);
            --scroll-bar-color: var(--border-strong);
        }

        body.night {
            --bg-main: var(--surface);
            --bg-second: var(--bg-primary);
            --font-1: var(--text-primary);
            --font-2: var(--text-secondary);
            --border-color: var(--border);
            --main-color: var(--primary);
            --main-color-rgb: 241, 90, 34;
            --main-color-flexable: var(--surface-muted);
            --scroll-bar-color: var(--border-strong);
        }
        .userBalance {
            background: var(--secondary-soft);
            font-size: 13px;
            margin-left: 5px;
            --bs-badge-padding-x: .7em;
            --bs-badge-padding-y: .4em;
            --bs-badge-font-size: .75em;
            --bs-badge-font-weight: 700;
            --bs-badge-color: var(--surface);
            --bs-badge-border-radius: .4rem;
            display: inline-block;
            padding: var(--bs-badge-padding-y) var(--bs-badge-padding-x);
            font-size: var(--bs-badge-font-size);
            font-weight: var(--bs-badge-font-weight);
            line-height: 1;
            color: var(--bs-badge-color);
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: var(--bs-badge-border-radius);            
        }
    </style>

<style>
    .purchaseForm button {
        width: 100%;
    }
</style>
<link rel="stylesheet" href="<?=$baseUrl?>/resources/css/design-system.css" data-design-system="mathhub" />
