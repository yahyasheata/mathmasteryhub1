<?php 

?>
<script>
    /* Prompt 23: resolve theme + load design tokens before any other stylesheet
       so there is no flash of the wrong theme while the rest of <head> loads. */
    (function () {
        var storedTheme = null;
        try {
            storedTheme = window.localStorage.getItem('math-mastery-student-theme');
        } catch (error) {
            storedTheme = null;
        }

        var theme = storedTheme === 'light' || storedTheme === 'dark' ? storedTheme : 'dark';
        document.documentElement.dataset.studentTheme = theme;
        document.documentElement.style.colorScheme = theme;
    }());
    document.documentElement.lang = 'en';
    document.documentElement.dir = 'ltr';
</script>
<?php $userBrandSettings = isset($site_settings) && is_array($site_settings) ? $site_settings : getSiteSettings(); $userFaviconUrl = mmh_site_settings_asset_url($userBrandSettings, 'website_icon', 'resources/images/default/favicon.png'); ?>
<link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/design-system.css')?>" data-design-system="mathhub" />
<script src="<?=mmh_site_public_url('resources/js/student-theme.js')?>" defer></script>
    <?=$metatags."\n"?>
    <?=$keywords."\n"?>

    <?=$openGraph?>

    <?=$schema?> 
<link rel="icon" type="image/png" href="<?=$userFaviconUrl?>" /> 
<link rel="icon" type="image/png" sizes="512x512" href="<?=mmh_site_public_url('resources/images/default/favicon.png')?>" />
<link rel="manifest" href="<?=mmh_site_public_url('resources/manifest.json')?>">
<meta name="theme-color" content="#F15A22">
<meta name="mobile-web-app-capable" content="no">
<meta name="application-name" content="<?=$site_name;?>">


<meta name="facebook-domain-verification" content="vymdke86bl9vdcyleijy0r173c6k7c" />
<meta name="apple-mobile-web-app-capable" content="no">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?=$site_name;?>">
<link rel="apple-touch-icon" href="<?=mmh_site_public_url('resources/images/default/favicon.png')?>?v=2">


<link rel='alternate' href="<?=$baseUrl?>/resources/dashboard" hreflang='x-default' />

<meta name="author" content="<?=$site_name;?>" />
<meta name="description" content="<?=$site_name;?>">
<link rel="canonical" href="<?=$baseUrl?>/resources/dashboard">


<?php
$studentFontAwesomeCss = 'resources/css/fontawsome5.min.css';
$studentAppCss = 'resources/build/assets/app-38448552.css';
$studentExperienceCss = 'resources/css/student-experience.css';
$studentAssetsRoot = dirname(__DIR__, 4);
$studentFontAwesomeVersion = @filemtime($studentAssetsRoot . '/' . $studentFontAwesomeCss) ?: 1;
$studentAppCssVersion = @filemtime($studentAssetsRoot . '/' . $studentAppCss) ?: 1;
$studentExperienceCssVersion = @filemtime($studentAssetsRoot . '/' . $studentExperienceCss) ?: 1;
?>
<link rel="stylesheet" href="<?=mmh_site_public_url($studentFontAwesomeCss)?>?v=<?=$studentFontAwesomeVersion?>" referrerpolicy="no-referrer" />


<link rel='help' title='FAQ' href='<?=$baseUrl?>/resources/faq'/>
<link rel="alternate" type="application/rss+xml" title="Latest News" href="<?=$baseUrl?>/resources/feed">

<script type="text/javascript" src="<?=mmh_site_public_url('resources/js/sweetalert2.min.js')?>"></script>

<link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/sweetalert2.min.css')?>" />

<script type="text/javascript" src="<?=mmh_site_public_url('resources/js/jquery-3.6.1.min.js')?>"></script>
    

    
    <!-- Livewire Styles --><style >[wire\:loading], [wire\:loading\.delay], [wire\:loading\.inline-block], [wire\:loading\.inline], [wire\:loading\.block], [wire\:loading\.flex], [wire\:loading\.table], [wire\:loading\.grid], [wire\:loading\.inline-flex] {display: none;}[wire\:loading\.delay\.shortest], [wire\:loading\.delay\.shorter], [wire\:loading\.delay\.short], [wire\:loading\.delay\.long], [wire\:loading\.delay\.longer], [wire\:loading\.delay\.longest] {display:none;}[wire\:offline] {display: none;}[wire\:dirty]:not(textarea):not(input):not(select) {display: none;}</style>
                    <link rel="preload" as="style" href="<?=mmh_site_public_url($studentAppCss)?>?v=<?=$studentAppCssVersion?>" /><link rel="stylesheet" href="<?=mmh_site_public_url($studentAppCss)?>?v=<?=$studentAppCssVersion?>" data-navigate-track="reload" />
    <link rel="stylesheet" href="<?=mmh_site_public_url($studentExperienceCss)?>?v=<?=$studentExperienceCssVersion?>" />
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
    </style>
    
<script type="text/javascript" class="flasher-js">(function() {    var rootScript = '/vendor/flasher/flasher.min.js';    var FLASHER_FLASH_BAG_PLACE_HOLDER = {};    var options = mergeOptions([], FLASHER_FLASH_BAG_PLACE_HOLDER);    function mergeOptions(first, second) {        return {            context: merge(first.context || {}, second.context || {}),            envelopes: merge(first.envelopes || [], second.envelopes || []),            options: merge(first.options || {}, second.options || {}),            scripts: merge(first.scripts || [], second.scripts || []),            styles: merge(first.styles || [], second.styles || []),        };    }    function merge(first, second) {        if (Array.isArray(first) && Array.isArray(second)) {            return first.concat(second).filter(function(item, index, array) {                return array.indexOf(item) === index;            });        }        return Object.assign({}, first, second);    }    function renderOptions(options) {        if(!window.hasOwnProperty('flasher')) {            console.error('Flasher is not loaded');            return;        }        requestAnimationFrame(function () {            window.flasher.render(options);        });    }    function render(options) {        if ('loading' !== document.readyState) {            renderOptions(options);            return;        }        document.addEventListener('DOMContentLoaded', function() {            renderOptions(options);        });    }    if (1 === document.querySelectorAll('script.flasher-js').length) {        document.addEventListener('flasher:render', function (event) {            render(event.detail);        });    }    if (window.hasOwnProperty('flasher') || !rootScript || document.querySelector('script[src="' + rootScript + '"]')) {        render(options);    } else {        var tag = document.createElement('script');        tag.setAttribute('src', rootScript);        tag.setAttribute('type', 'text/javascript');        tag.onload = function () {            render(options);        };        document.head.appendChild(tag);    }})();</script>

<style>
    .purchaseForm button {
        width: 100%;
    }
</style>
