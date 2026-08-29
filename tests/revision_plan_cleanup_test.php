<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$list = file_get_contents($root . '/views/user/revision-plans.php');
$css = file_get_contents($root . '/resources/css/revision-plans.css');
$migration = file_get_contents($root . '/database/migrations/20260829_reconcile_revision_plan_logical_assignments.php');
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');
$assert = static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
foreach (['NOT EXISTS','newer_v.version_number','newer.id > a.id'] as $marker) $assert(str_contains($service,$marker),'Student assignment deduplication missing: '.$marker);
foreach (['assignmentGroups','Active Plans','Upcoming','Past Plans','Continue Plan','View Plan'] as $marker) $assert(str_contains($list,$marker),'Your Plans grouping missing: '.$marker);
foreach (['revision-plan-group-active','revision-plan-group-past','@media(max-width:780px)'] as $marker) $assert(str_contains($css,$marker),'Your Plans responsive styling missing: '.$marker);
foreach (['MMH_REVISION_RECONCILE_TEMPLATE_IDS','duplicate logical-plan groups found','superseded assignments archived','deleted-plan assignments revoked'] as $marker) $assert(str_contains($migration,$marker),'Scoped reconciliation migration missing: '.$marker);
$assert(str_contains($workflow,'20260829_reconcile_revision_plan_logical_assignments.php'),'Reconciliation migration is not deployed.');
echo "revision_plan_cleanup=defensive_dedup=present ui_grouping=present responsive=present scoped_reconciliation=present\n";
