#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Auditable Microsoft Recording reconciliation.
 *
 * Default mode is a read-only CSV dry run. No URL is invented: only an
 * existing HTTPS SharePoint/Teams URL can be converted, and legacy embed.aspx
 * records are reported for manual replacement. Apply and rollback are explicit
 * and transaction-protected.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../inc/CourseResourceResolver.php';

const MMH_RECORDING_MIGRATION = 'external_recordings_v1';

function rcm_has(array $args, string $value): bool { return in_array($value, $args, true); }
function rcm_confirm(array $args, string $value): bool { return in_array('--confirm=' . $value, $args, true); }
function rcm_json(array $args): bool { return rcm_has($args, '--json'); }

function rcm_source_url(array $item, array $data): ?string
{
    $candidates = [
        $data['resource_url'] ?? ($data['resource']['url'] ?? ''),
        $data['url'] ?? '',
    ];
    foreach ($candidates as $candidate) {
        $safe = mmh_course_resource_safe_url($candidate);
        if ($safe !== null) return $safe;
    }
    $legacy = mmh_course_resource_simple_legacy_url($item['item_description'] ?? '', (string) ($item['item_title'] ?? ''));
    return $legacy !== null ? $legacy : null;
}

function rcm_is_recording(array $item, array $data): bool
{
    $template = strtolower(trim((string) ($item['template_type'] ?? '')));
    $itemType = strtolower(trim((string) ($item['item_type'] ?? '')));
    $resourceType = strtolower(trim((string) ($data['resource_type'] ?? ($data['resource']['type'] ?? ''))));
    $provider = strtolower(trim((string) ($data['resource_provider'] ?? ($data['resource']['provider'] ?? ''))));
    return in_array($template, ['recording', 'video'], true)
        || in_array($itemType, ['recording', 'video'], true)
        || in_array($resourceType, ['recording', 'video'], true)
        || in_array($provider, ['sharepoint', 'microsoft_stream', 'teams'], true);
}

function rcm_plan(array $item): array
{
    $data = mmh_course_resource_template_data($item['template_data'] ?? '');
    if (!rcm_is_recording($item, $data)) {
        return ['result' => 'not_recording', 'reason' => 'Not a Recording course item.'];
    }
    $url = rcm_source_url($item, $data);
    if ($url === null) {
        return ['result' => 'malformed_or_unrecognized', 'reason' => 'Recording item has no usable URL in structured data or legacy content.', 'url' => null];
    }
    $status = mmh_course_resource_microsoft_recording_status($url);
    $migration = is_array($data['recording_migration'] ?? null) ? $data['recording_migration'] : [];
    if (($migration['name'] ?? '') === MMH_RECORDING_MIGRATION) {
        return ['result' => 'already_external', 'reason' => 'Already reconciled by this tool.', 'url' => $url];
    }
    if (empty($status['is_microsoft'])) {
        return ['result' => 'non_microsoft', 'reason' => 'Non-Microsoft recording remains under its existing provider behavior.', 'url' => $url];
    }
    if (($status['state'] ?? '') === 'external') {
        $template = strtolower(trim((string) ($item['template_type'] ?? '')));
        return [
            'result' => $template === 'resource' ? 'already_external' : 'safe_to_migrate',
            'reason' => $template === 'resource' ? 'Structured external recording link is already canonical.' : 'Existing external link can be represented without changing its URL.',
            'url' => $status['url'],
            'provider' => $status['provider'] ?? 'sharepoint',
            'data' => $data,
        ];
    }
    if (($status['state'] ?? '') === 'legacy_embed') {
        return ['result' => 'legacy_embed', 'reason' => 'Legacy embed.aspx URL cannot be converted into an anonymous sharing link safely.', 'url' => $status['url']];
    }
    return ['result' => 'malformed_or_unrecognized', 'reason' => 'No supported HTTPS SharePoint/Teams recording link was found.', 'url' => $url];
}

function rcm_migrated_data(array $item, array $plan): array
{
    $data = $plan['data'];
    $url = (string) $plan['url'];
    $data['resource_type'] = 'recording';
    $data['resource_provider'] = (string) ($plan['provider'] ?? 'sharepoint');
    $data['resource_url'] = $url;
    $data['url'] = $url;
    $data['embed_enabled'] = false;
    $data['resource_behavior'] = 'external_recording';
    $data['resource'] = ['type' => 'recording', 'provider' => $data['resource_provider'], 'url' => $url, 'embed' => false];
    $data['recording_migration'] = [
        'name' => MMH_RECORDING_MIGRATION,
        'version' => 1,
        'migrated_at' => gmdate('c'),
        'original_template_type' => (string) ($item['template_type'] ?? ''),
        'original_template_data' => ($item['template_data'] ?? '') === '' ? null : (string) ($item['template_data'] ?? ''),
        'item_description_preserved' => true,
    ];
    return $data;
}

function rcm_csv_row($out, array $item, array $plan): void
{
    fputcsv($out, [
        $item['course_id'] ?? '', $item['course_title'] ?? '', $item['section_title'] ?? 'General',
        $item['item_id'] ?? '', $item['item_title'] ?? '', $item['template_type'] ?? '',
        $plan['result'] ?? '', $plan['reason'] ?? '', $plan['url'] ?? '',
    ]);
}

$args = $_SERVER['argv'] ?? [];
$apply = rcm_has($args, '--apply');
$rollback = rcm_has($args, '--rollback');
if ($apply && $rollback) { fwrite(STDERR, "Choose either --apply or --rollback.\n"); exit(1); }
if ($apply && !rcm_confirm($args, 'RECONCILE_RECORDINGS')) { fwrite(STDERR, "Apply is blocked. Use --apply --confirm=RECONCILE_RECORDINGS.\n"); exit(1); }
if ($rollback && !rcm_confirm($args, 'ROLLBACK_RECORDINGS')) { fwrite(STDERR, "Rollback is blocked. Use --rollback --confirm=ROLLBACK_RECORDINGS.\n"); exit(1); }

$conn = db();
$query = "SELECT i.id, i.course_id, c.course_title, i.section_id, s.title AS section_title,
    i.item_id, i.item_title, i.item_type, i.template_type, i.template_data, i.item_description
    FROM course_items i
    LEFT JOIN courses c ON c.course_id = i.course_id
    LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id
    ORDER BY i.course_id, s.sort_order, i.sort_order, i.page_order, i.id";
$stmt = $conn->prepare($query);
if (!$stmt || !$stmt->execute()) { fwrite(STDERR, "Unable to inspect course items.\n"); exit(1); }
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$plans = [];
$counts = ['total_recordings' => 0, 'microsoft_recordings' => 0, 'external_links' => 0, 'already_external' => 0, 'legacy_embed' => 0, 'safe_to_migrate' => 0, 'automatically_migrated' => 0, 'manual_replacement' => 0, 'malformed_or_unrecognized' => 0, 'non_microsoft' => 0];
foreach ($items as $item) {
    $plan = rcm_plan($item);
    if (($plan['result'] ?? '') === 'not_recording') continue;
    $counts['total_recordings']++;
    if (($plan['result'] ?? '') !== 'non_microsoft') $counts['microsoft_recordings']++;
    if (($plan['result'] ?? '') === 'non_microsoft') $counts['non_microsoft']++;
    if (in_array(($plan['result'] ?? ''), ['already_external', 'safe_to_migrate'], true)) $counts['external_links']++;
    if (($plan['result'] ?? '') === 'already_external') $counts['already_external']++;
    if (($plan['result'] ?? '') === 'legacy_embed') { $counts['legacy_embed']++; $counts['manual_replacement']++; }
    if (($plan['result'] ?? '') === 'safe_to_migrate') $counts['safe_to_migrate']++;
    if (($plan['result'] ?? '') === 'manual_replacement') $counts['manual_replacement']++;
    if (($plan['result'] ?? '') === 'malformed_or_unrecognized') $counts['malformed_or_unrecognized']++;
    $plans[] = [$item, $plan];
}

if (rcm_json($args)) {
    $report = ['counts' => $counts, 'items' => []];
    foreach ($plans as [$item, $plan]) $report['items'][] = ['course_id' => $item['course_id'], 'course_title' => $item['course_title'], 'section' => $item['section_title'] ?: 'General', 'item_id' => $item['item_id'], 'title' => $item['item_title'], 'current_url' => $plan['url'] ?? '', 'result' => $plan['result'], 'reason' => $plan['reason']];
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['course_id', 'course_title', 'section', 'item_id', 'recording_title', 'template_type', 'result', 'reason', 'current_url']);
    foreach ($plans as [$item, $plan]) rcm_csv_row($out, $item, $plan);
    fclose($out);
}

if ($rollback) {
    $conn->begin_transaction();
    try {
        $restore = $conn->prepare('UPDATE course_items SET template_type = ?, template_data = ? WHERE id = ? AND item_id = ?');
        if (!$restore) throw new RuntimeException('Unable to prepare rollback.');
        $restored = 0;
        foreach ($plans as [$item, $plan]) {
            $data = mmh_course_resource_template_data($item['template_data'] ?? '');
            $migration = $data['recording_migration'] ?? [];
            if (!is_array($migration) || ($migration['name'] ?? '') !== MMH_RECORDING_MIGRATION) continue;
            $type = (string) ($migration['original_template_type'] ?? '');
            $json = $migration['original_template_data'] ?? null;
            $json = $json === null ? 'null' : (string) $json;
            $id = (int) $item['id']; $itemId = (string) $item['item_id'];
            $restore->bind_param('ssis', $type, $json, $id, $itemId);
            if (!$restore->execute()) throw new RuntimeException('Unable to restore ' . $itemId . '.');
            $restored++;
        }
        $restore->close(); $conn->commit();
        fwrite(STDERR, "Rollback completed; restored={$restored}.\n");
    } catch (Throwable $e) { $conn->rollback(); fwrite(STDERR, 'Rollback failed; no changes committed: ' . $e->getMessage() . "\n"); exit(1); }
    exit;
}

if ($apply) {
    $conn->begin_transaction();
    try {
        $update = $conn->prepare('UPDATE course_items SET template_type = ?, template_data = ? WHERE id = ? AND item_id = ?');
        if (!$update) throw new RuntimeException('Unable to prepare migration update.');
        $type = 'resource'; $migrated = 0;
        foreach ($plans as [$item, $plan]) {
            if (($plan['result'] ?? '') !== 'safe_to_migrate') continue;
            $json = json_encode(rcm_migrated_data($item, $plan), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) throw new RuntimeException('Unable to encode ' . $item['item_id'] . '.');
            $id = (int) $item['id']; $itemId = (string) $item['item_id'];
            $update->bind_param('ssis', $type, $json, $id, $itemId);
            if (!$update->execute()) throw new RuntimeException('Unable to migrate ' . $itemId . '.');
            $migrated++;
        }
        $update->close(); $conn->commit();
        $counts['automatically_migrated'] = $migrated;
        fwrite(STDERR, "Migration completed; migrated={$migrated}; manual_replacement={$counts['manual_replacement']}.\n");
    } catch (Throwable $e) { $conn->rollback(); fwrite(STDERR, 'Migration failed; no changes committed: ' . $e->getMessage() . "\n"); exit(1); }
}

fwrite(STDERR, sprintf("Dry run/report: total_recordings=%d microsoft_recordings=%d external_links=%d already_external=%d legacy_embed=%d automatically_migrated=%d safe_to_migrate=%d manual_replacement=%d malformed_or_unrecognized=%d non_microsoft=%d\n", $counts['total_recordings'], $counts['microsoft_recordings'], $counts['external_links'], $counts['already_external'], $counts['legacy_embed'], $counts['automatically_migrated'], $counts['safe_to_migrate'], $counts['manual_replacement'], $counts['malformed_or_unrecognized'], $counts['non_microsoft']));
