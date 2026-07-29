<?php 
if (!isset($baseUrl) || !is_string($baseUrl) || trim($baseUrl) === '') {
    require_once __DIR__ . '/../__init.php';
}

if (!isset($baseUrl) || !is_string($baseUrl) || trim($baseUrl) === '') {
    $baseUrl = function_exists('mmh_current_request_origin')
        ? mmh_current_request_origin()
        : 'http://127.0.0.1:8091';
}

// Site Settings :
$site_settings = getSiteSettings();
$site_name = (string) ($site_settings["website_name"] ?? 'Math Mastery Hub');
$site_description = trim((string) ($site_settings['seo_default_description'] ?? '')) ?: (string) ($site_settings["website_bio"] ?? '');
$website_keywords = (string) ($site_settings["website_keywords"] ?? '');
$website_logo = (string) ($site_settings["website_logo"] ?? '');
$site_icon = (string) ($site_settings["website_icon"] ?? '');
$website_cover = (string) ($site_settings["website_cover"] ?? '');
$seoTitle = str_replace('{site_name}', $site_name, (string) ($site_settings['seo_default_title'] ?? '{site_name}'));
if (trim($seoTitle) === '') $seoTitle = $site_name;
$seoCanonicalBase = trim((string) ($site_settings['seo_canonical_base_url'] ?? ''));
if ($seoCanonicalBase === '' || !mmh_site_settings_safe_external_url($seoCanonicalBase)) $seoCanonicalBase = $baseUrl;
$seoIndexing = mmh_site_setting_truthy($site_settings['seo_indexing'] ?? '1');

// Social Media Links 
$phone = $site_settings["phone"];
$phone2 = $site_settings["phone2"];
$whatsapp_phone = $site_settings["whatsapp_phone"];
$facebook_link = $site_settings["facebook_link"];
$telegram_link = $site_settings["telegram_link"];
$whatsapp_link = $site_settings["whatsapp_link"];
$twitter_link = $site_settings["twitter_link"];
$instagram_link = $site_settings["instagram_link"];
$youtube_link = $site_settings["youtube_link"];
$tutorial_link = $site_settings["tutorial_link"];




require __DIR__ . '/../vendor/autoload.php';

use Melbahja\Seo\Schema;
use Melbahja\Seo\Schema\Thing;
use Melbahja\Seo\MetaTags;

use Arcanedev\SeoHelper\Entities\Title;
use Arcanedev\SeoHelper\Entities\OpenGraph\Graph;
use Arcanedev\SeoHelper\Entities\Keywords;




$metatags = new MetaTags();

$metatags
        ->title($seoTitle)
        ->description($site_description)
        ->meta('author', 'MetaPhilia')
        ->image($baseUrl."/".$website_cover)
        ->mobile($seoCanonicalBase)
        ->canonical($seoCanonicalBase)
        ->shortlink($seoCanonicalBase)
        ->amp($seoCanonicalBase);
if (!$seoIndexing) $metatags->meta('robots', 'noindex,nofollow');

        $schema = new Schema(
          new Thing('Organization', [
              'url'          => $seoCanonicalBase,
              'logo'         => $baseUrl."/".$site_icon,
              'contactPoint' => new Thing('ContactPoint', [
                  'telephone' => '+1-000-555-1212',
                  'contactType' => 'customer service'
              ])
          ])
      );


      $keywords = new Keywords;
      $keywords->set($website_keywords);
      
      $keywords = $keywords->render();


      
      $openGraph = new Graph;

      $openGraph->setType('website');
      $openGraph->setTitle($seoTitle);
      $openGraph->setDescription($site_description);
      $openGraph->setSiteName($site_name);
      $openGraph->setUrl($seoCanonicalBase);
      $openGraph->setImage($website_cover !== '' ? rtrim($seoCanonicalBase, '/') . '/' . ltrim($website_cover, '/') : rtrim($seoCanonicalBase, '/') . '/' . ltrim($site_icon, '/'));
      // Of course you can chain all these methods
      
      $openGraph = $openGraph->render();   




?>
