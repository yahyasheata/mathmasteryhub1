#!/usr/bin/env php
<?php
/**
 * One-time native Course Resource migration.
 *
 * Default mode is read-only and prints a CSV report.
 * Apply requires: --apply --confirm=MIGRATE_NATIVE_RESOURCES
 * Rollback requires: --rollback --confirm=ROLLBACK_NATIVE_RESOURCES
 * Notes-only and videos-only modes require a course ID and limit conversion to the named legacy content type.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../inc/CourseResourceResolver.php';

const MMH_RESOURCE_MIGRATION = 'native_course_resources_v1';

function crm_arg(array $args, string $argument): bool { return in_array($argument, $args, true); }
function crm_confirmed(array $args, string $value): bool { return in_array('--confirm=' . $value, $args, true); }
function crm_course_id(array $args): string {
    foreach (array_slice($args, 1) as $arg) {
        if ($arg !== '' && $arg[0] !== '-') return trim($arg);
    }
    return '';
}
function crm_source(array $item, array $data): string {
    $content = trim((string) ($data['content'] ?? ''));
    if ($content !== '' && preg_match('/https?:\/\//i', $content)) return $content;
    return trim((string) ($item['item_description'] ?? '')) ?: $content;
}
/** A Notes-specific migration must never infer from the title alone. */
function crm_single_safe_link(string $html): ?string {
    if (!preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $matches)) return null;
    $urls = [];
    foreach ($matches[2] as $value) {
        $url = mmh_course_resource_safe_url(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
        if ($url !== null) $urls[$url] = $url;
    }
    return count($urls) === 1 ? array_values($urls)[0] : null;
}
function crm_is_legacy_notes_card(array $item, array $data): bool {
    $template = strtolower(trim((string) ($item['template_type'] ?? '')));
    if ($template === 'notes') return true;
    // Some old Notes cards were saved under Custom HTML. Restrict conversion
    // to the known generated one-link Notes card, never arbitrary custom HTML.
    if ($template !== 'custom_html') return false;
    $source = crm_source($item, $data);
    $title = strtolower((string) ($item['item_title'] ?? ''));
    $looksLikeNotes = str_contains($title, 'notes')
        || preg_match('/\bopen\s*\/?\s*download\s+notes\b/i', $source);
    $generatedBody = preg_match('/\bthese notes have been prepared to help you review the lecture\b/i', $source)
        && preg_match('/\byou can view or download them directly from the link below\b/i', $source)
        && preg_match('/\bopen\s*\/?\s*download\s+notes\b/i', $source);
    return $looksLikeNotes && $generatedBody && crm_single_safe_link($source) !== null;
}
function crm_notes_profile(array $item, string $url): array {
    $profile = crm_profile($item, $url);
    // Notes is the semantic type. The provider remains the detected host so
    // the existing viewer can select the correct preview behavior.
    $profile['resource_type'] = 'notes';
    $profile['embed_enabled'] = mmh_course_resource_embed_details($url, 'notes') !== null;
    $profile['behavior'] = $profile['embed_enabled'] ? 'embed' : 'redirect';
    return $profile;
}

function crm_video_legacy_url(array $item, array $data): ?string {
    $source = crm_source($item, $data);
    // Generated YouTube cards contain an iframe plus an external fallback.
    // The iframe is the canonical target because it retains the original
    // playback position while letting the resolver normalize the embed safely.
    if (preg_match_all('/<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/is', $source, $matches)) {
        $urls = [];
        foreach ($matches[2] as $value) {
            $url = mmh_course_resource_safe_url(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
            if ($url !== null) $urls[$url] = $url;
        }
        if (count($urls) === 1) return array_values($urls)[0];
    }
    return crm_single_safe_link($source);
}
function crm_is_legacy_video_card(array $item, array $data): bool {
    $template = strtolower(trim((string) ($item['template_type'] ?? '')));
    $itemType = strtolower(trim((string) ($item['item_type'] ?? '')));
    if ($template === 'resource' || $template === 'custom_lesson') return false;
    // The migration is intentionally restricted to the established legacy
    // video contract, not arbitrary Custom HTML that happens to contain media.
    return ($template === 'video' || $template === 'recording' || $itemType === 'video')
        && crm_video_legacy_url($item, $data) !== null;
}
function crm_video_description(array $item, array $data): string {
    $text = html_entity_decode(strip_tags(crm_source($item, $data)), ENT_QUOTES, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text)));
    // Preserve explicit teacher guidance; known generated boilerplate remains
    // in the exact HTML backup and should not create empty viewer prose.
    if (preg_match('/\bdisclaimer\s+(.+?)(?=\s*(?:click below|open the recording|$))/i', $text, $match)) {
        return 'Disclaimer: ' . trim((string) $match[1]);
    }
    return '';
}
function crm_video_profile(array $item, string $url): array {
    $profile = crm_profile($item, $url);
    // Keep the established provider-specific semantic resource type:
    // YouTube remains youtube; SharePoint/Teams recordings remain recording.
    if (!in_array($profile['resource_type'], ['youtube', 'recording'], true)) {
        $profile['resource_type'] = 'recording';
        $profile['embed_enabled'] = mmh_course_resource_embed_details($url, 'recording') !== null;
        $profile['behavior'] = $profile['embed_enabled'] ? 'embed' : 'redirect';
    }
    return $profile;
}

function crm_profile(array $item, string $url): array {
    $title = strtolower((string) ($item['item_title'] ?? ''));
    $legacyType = strtolower((string) ($item['item_type'] ?? ''));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    $provider = 'external'; $type = 'external_link';
    if ($host === 'teams.microsoft.com' || str_ends_with($host, '.teams.microsoft.com')) { $provider = 'teams'; $type = 'teams'; }
    elseif ($host === 'drive.google.com' || str_ends_with($host, '.drive.google.com') || $host === 'docs.google.com' || str_ends_with($host, '.docs.google.com')) { $provider = 'google_drive'; $type = preg_match('~/(?:drive/)?folders/[^/?]+~i', $path) ? 'google_drive_folder' : 'google_drive'; }
    elseif ($host === 'youtube.com' || str_ends_with($host, '.youtube.com') || $host === 'youtu.be' || $host === 'youtube-nocookie.com' || str_ends_with($host, '.youtube-nocookie.com')) { $provider = 'youtube'; $type = 'youtube'; }
    elseif ($host === 'sharepoint.com' || str_ends_with($host, '.sharepoint.com')) { $provider = 'sharepoint'; $type = 'recording'; }
    elseif (preg_match('/\.pdf(?:$|[?#])/i', $url)) { $provider = 'pdf'; $type = 'pdf'; }
    elseif (str_contains($title, 'model answer') || str_contains($title, 'answers')) { $type = 'model_answer'; }
    elseif (str_contains($title, 'notes')) { $type = 'notes'; }
    elseif (str_contains($title, 'worksheet')) { $type = 'worksheet'; }
    elseif (str_contains($title, 'revision')) { $type = 'revision_sheet'; }
    elseif (str_contains($title, 'booklet')) { $type = 'booklet'; }
    elseif ($legacyType === 'video') { $type = 'recording'; }
    elseif ($legacyType === 'file') { $type = 'download'; }
    $embed = mmh_course_resource_embed_details($url, $type);
    return ['resource_type' => $type, 'resource_provider' => $provider, 'resource_url' => $url, 'embed_enabled' => $embed !== null, 'behavior' => $embed !== null ? 'embed' : 'redirect'];
}
function crm_plan(array $item, bool $notesOnly = false, bool $videosOnly = false): array {
    $data = mmh_course_resource_template_data($item['template_data'] ?? '');
    $migration = is_array($data['resource_migration'] ?? null) ? $data['resource_migration'] : [];
    if ($videosOnly) {
        $template = strtolower(trim((string) ($item['template_type'] ?? '')));
        $itemType = strtolower(trim((string) ($item['item_type'] ?? '')));
        $structuredUrl = mmh_course_resource_safe_url($data['resource_url'] ?? ($data['resource']['url'] ?? ''));
        if ($template === 'resource') {
            $structuredType = strtolower(trim((string) ($data['resource_type'] ?? ($data['resource']['type'] ?? ''))));
            $isStructuredVideo = $itemType === 'video' || in_array($structuredType, ['video', 'youtube', 'recording'], true);
            return [
                'state' => $isStructuredVideo && $structuredUrl !== null ? 'already_structured' : 'skipped',
                'reason' => $structuredUrl !== null ? ($isStructuredVideo ? 'Structured Video resource data already exists.' : 'Structured non-Video resource is outside this migration.') : 'Structured resource has no valid target.',
            ];
        }
        if (!in_array($template, ['video', 'recording'], true) && $itemType !== 'video') {
            return ['state' => 'skipped', 'reason' => 'Not a legacy Video lesson.'];
        }
        if (!crm_is_legacy_video_card($item, $data)) {
            return ['state' => 'manual_review', 'reason' => 'Legacy Video has no single safe playback target.'];
        }
        $url = crm_video_legacy_url($item, $data);
        if ($url === null) return ['state' => 'manual_review', 'reason' => 'Legacy Video has no single safe playback target.'];
        $microsoftStatus = mmh_course_resource_microsoft_recording_status($url);
        if (!empty($microsoftStatus['is_microsoft']) && ($microsoftStatus['state'] ?? '') !== 'external') {
            return ['state' => 'manual_review', 'reason' => 'Microsoft embed or unsupported link requires a real external sharing URL; no URL was guessed.'];
        }
        return [
            'state' => 'convert',
            'reason' => 'Verified legacy Video with one canonical playback target.',
            'profile' => crm_video_profile($item, $url),
            'data' => $data,
            'description' => crm_video_description($item, $data),
        ];
    }
    if ($notesOnly) {
        $template = strtolower(trim((string) ($item['template_type'] ?? '')));
        $title = strtolower((string) ($item['item_title'] ?? ''));
        $structuredUrl = mmh_course_resource_safe_url($data['resource_url'] ?? ($data['resource']['url'] ?? ''));
        $structuredType = strtolower(trim((string) ($data['resource_type'] ?? ($data['resource']['type'] ?? ''))));
        if ($template === 'resource') {
            return [
                'state' => (str_contains($title, 'notes') || $structuredType === 'notes') && $structuredUrl !== null ? 'already_structured' : 'skipped',
                'reason' => $structuredUrl !== null ? 'Structured resource data already exists.' : 'Structured resource has no valid target.',
            ];
        }
        if (!crm_is_legacy_notes_card($item, $data)) {
            return ['state' => 'skipped', 'reason' => 'Not a verified legacy Notes card.'];
        }
        $source = crm_source($item, $data);
        $url = mmh_course_resource_simple_legacy_url($source, (string) ($item['item_title'] ?? '')) ?: crm_single_safe_link($source);
        if ($url === null) return ['state' => 'manual_review', 'reason' => 'Legacy Notes card has no single safe resource URL.'];
        return ['state' => 'convert', 'reason' => 'Verified one-link legacy Notes card.', 'profile' => crm_notes_profile($item, $url), 'data' => $data];
    }
    if (($migration['name'] ?? '') === MMH_RESOURCE_MIGRATION) return ['state' => 'already_migrated', 'reason' => 'Already migrated by this tool.'];
    if (!empty($data['resource_url']) || !empty($data['resource']['url'])) return ['state' => 'already_structured', 'reason' => 'Structured resource data already exists.'];
    $template = strtolower(trim((string) ($item['template_type'] ?? '')));
    if (in_array($template, ['classified_assignment', 'assignment', 'exam'], true) || !empty($item['assignment_id'])) return ['state' => 'skipped', 'reason' => 'Assignment/exam data is preserved.'];
    if (in_array($template, ['custom_lesson', 'custom_html'], true)) return ['state' => 'skipped', 'reason' => 'Custom HTML/Lesson content is preserved.'];
    $source = crm_source($item, $data);
    if ($source === '') return ['state' => 'skipped', 'reason' => 'No resource source was found.'];
    $url = mmh_course_resource_simple_legacy_url($source, (string) ($item['item_title'] ?? ''));
    if ($url === null) {
        return ['state' => preg_match('/https?:\/\//i', $source) ? 'manual_review' : 'skipped', 'reason' => preg_match('/https?:\/\//i', $source) ? 'Rich, multiple-target, or ambiguous content was preserved.' : 'No safe resource URL was found.'];
    }
    $microsoftStatus = mmh_course_resource_microsoft_recording_status($url);
    if (!empty($microsoftStatus['is_microsoft']) && ($microsoftStatus['state'] ?? '') !== 'external') {
        return ['state' => 'manual_review', 'reason' => 'Microsoft embed or unsupported link requires a real external sharing URL; no URL was guessed.'];
    }
    return ['state' => 'convert', 'reason' => 'Single safe resource with generated boilerplate only.', 'profile' => crm_profile($item, $url), 'data' => $data];
}
function crm_native_data(array $item, array $plan): array {
    $profile = $plan['profile']; $data = $plan['data'];
    $legacyHtml = (string) ($item['item_description'] ?? '');
    if (trim($legacyHtml) === '') $legacyHtml = (string) ($data['content'] ?? '');
    $data['resource_type'] = $profile['resource_type'];
    $data['resource_provider'] = $profile['resource_provider'];
    $data['resource_url'] = $profile['resource_url'];
    $data['url'] = $profile['resource_url']; // Existing editor/resolver compatibility.
    $data['embed_enabled'] = $profile['embed_enabled'];
    $data['resource_behavior'] = $profile['behavior'];
    $data['resource'] = ['type' => $profile['resource_type'], 'provider' => $profile['resource_provider'], 'url' => $profile['resource_url'], 'embed' => $profile['embed_enabled']];
    if (array_key_exists('description', $plan) && trim((string) $plan['description']) !== '') $data['description'] = (string) $plan['description'];
    $data['resource_migration'] = [
        'name' => MMH_RESOURCE_MIGRATION,
        'version' => 1,
        'migrated_at' => gmdate('c'),
        'original_template_type' => (string) ($item['template_type'] ?? ''),
        'original_template_data' => ($item['template_data'] ?? '') === '' ? null : (string) ($item['template_data'] ?? ''),
        // item_description is deliberately not touched. A copy is retained in
        // structured metadata so rollback remains possible after future edits.
        'legacy_html_backup' => $legacyHtml,
        'original_html_preserved_in_item_description' => true,
    ];
    return $data;
}
function crm_csv($out, array $item, array $plan): void {
    $profile = $plan['profile'] ?? [];
    fputcsv($out, [$item['course_id'], trim((string) ($item['section_title'] ?? '')) ?: 'General', $item['item_id'], $item['item_title'], $item['template_type'] ?: 'legacy:' . $item['item_type'], $plan['state'], $profile['resource_type'] ?? '', $profile['resource_provider'] ?? '', $profile['behavior'] ?? '', $plan['reason']], ',', chr(34), '');
}
function crm_restore(mysqli $conn, array $item): bool {
    $data = mmh_course_resource_template_data($item['template_data'] ?? '');
    $migration = $data['resource_migration'] ?? [];
    if (!is_array($migration) || ($migration['name'] ?? '') !== MMH_RESOURCE_MIGRATION) return false;
    $type = (string) ($migration['original_template_type'] ?? '');
    $json = $migration['original_template_data'] ?? null;
    $json = $json === null ? 'null' : (string) $json;
    $stmt = $conn->prepare('UPDATE course_items SET template_type = ?, template_data = ? WHERE id = ? AND item_id = ?');
    if (!$stmt) throw new RuntimeException('Unable to prepare rollback.');
    $id = (int) $item['id']; $itemId = (string) $item['item_id'];
    $stmt->bind_param('ssis', $type, $json, $id, $itemId); $ok = $stmt->execute(); $stmt->close(); return $ok;
}

$args = $_SERVER['argv'] ?? [];
$apply = crm_arg($args, '--apply'); $rollback = crm_arg($args, '--rollback'); $notesOnly = crm_arg($args, '--notes-only'); $videosOnly = crm_arg($args, '--videos-only'); $courseId = crm_course_id($args);
if ($apply && $rollback) { fwrite(STDERR, "Choose either --apply or --rollback.\n"); exit(1); }
if ($notesOnly && $videosOnly) { fwrite(STDERR, "Choose either notes-only or videos-only mode.\n"); exit(1); }
if (($notesOnly || $videosOnly) && $courseId === '') { fwrite(STDERR, "Content-type-only mode requires a course ID.\n"); exit(1); }
if ($apply && !crm_confirmed($args, 'MIGRATE_NATIVE_RESOURCES')) { fwrite(STDERR, "Apply is blocked. Re-run only after approval with --apply --confirm=MIGRATE_NATIVE_RESOURCES.\n"); exit(1); }
if ($rollback && !crm_confirmed($args, 'ROLLBACK_NATIVE_RESOURCES')) { fwrite(STDERR, "Rollback is blocked. Re-run with --rollback --confirm=ROLLBACK_NATIVE_RESOURCES.\n"); exit(1); }

$conn = db();
$sql = "SELECT i.id,i.course_id,i.section_id,i.item_id,i.item_title,i.item_type,i.item_description,i.template_type,i.template_data,i.assignment_id,i.status,i.sort_order,i.page_order,s.title AS section_title FROM course_items i LEFT JOIN course_sections s ON s.course_id=i.course_id AND s.section_id=i.section_id";
if ($courseId !== '') $sql .= ' WHERE i.course_id = ?';
$sql .= " ORDER BY i.course_id, CASE WHEN i.section_id IS NULL OR i.section_id='' THEN 0 ELSE 1 END, s.sort_order, i.sort_order, i.page_order, i.id";
$stmt = $conn->prepare($sql); if (!$stmt) { fwrite(STDERR, "Unable to prepare migration query.\n"); exit(1); }
if ($courseId !== '') $stmt->bind_param('s', $courseId);
$stmt->execute(); $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

if ($rollback) {
    $restored = 0; $conn->begin_transaction();
    try { foreach ($items as $item) if (crm_restore($conn, $item)) $restored++; $conn->commit(); }
    catch (Throwable $e) { $conn->rollback(); fwrite(STDERR, 'Rollback failed: ' . $e->getMessage() . "\n"); exit(1); }
    echo "Restored {$restored} migrated course items.\n"; exit;
}

$out = fopen('php://output', 'w');
fputcsv($out, ['course_id','section','item_id','title','current_template','result','resource_type','provider','behavior','reason'], ',', chr(34), '');
$counts = ['convert'=>0,'skipped'=>0,'manual_review'=>0,'already_migrated'=>0,'already_structured'=>0]; $plans = [];
foreach ($items as $item) { $plan = crm_plan($item, $notesOnly, $videosOnly); $plans[] = [$item, $plan]; $counts[$plan['state']] = ($counts[$plan['state']] ?? 0) + 1; crm_csv($out, $item, $plan); }
fclose($out);
if (!$apply) { fwrite(STDERR, sprintf("Dry run only%s. convert=%d skipped=%d manual_review=%d already_migrated=%d already_structured=%d\n", ($notesOnly ? ' (Notes only)' : ($videosOnly ? ' (Videos only)' : '')), $counts['convert'],$counts['skipped'],$counts['manual_review'],$counts['already_migrated'],$counts['already_structured'])); exit; }

$converted = 0; $conn->begin_transaction();
try {
    $update = $conn->prepare('UPDATE course_items SET template_type = ?, template_data = ? WHERE id = ? AND item_id = ?');
    if (!$update) throw new RuntimeException('Unable to prepare migration update.');
    foreach ($plans as [$item, $plan]) {
        if ($plan['state'] !== 'convert') continue;
        $type = 'resource';
        $json = json_encode(crm_native_data($item, $plan), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new RuntimeException('Unable to encode item ' . $item['item_id'] . '.');
        $id = (int) $item['id']; $itemId = (string) $item['item_id'];
        $update->bind_param('ssis', $type, $json, $id, $itemId);
        if (!$update->execute()) throw new RuntimeException('Unable to migrate item ' . $itemId . '.');
        $converted++;
    }
    $update->close(); $conn->commit();
} catch (Throwable $e) { $conn->rollback(); fwrite(STDERR, 'Migration failed; all changes rolled back: ' . $e->getMessage() . "\n"); exit(1); }
fwrite(STDERR, "Migration completed. converted={$converted}; skipped={$counts['skipped']}; manual_review={$counts['manual_review']}.\n");
