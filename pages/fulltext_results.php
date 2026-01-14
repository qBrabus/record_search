<?php
// Charge REDCap
if (!defined('APP_PATH_DOCROOT')) {
    require_once dirname(__FILE__, 4) . '/redcap_connect.php';
}

require_once __DIR__ . '/../lib/search_lib.php';

$pid   = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
$q     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

$pageSize = 100;
$offset = ($page - 1) * $pageSize;

if (!defined('USERID') || USERID === '' || $pid <= 0) {
    http_response_code(403);
    echo "Accès refusé.";
    exit;
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

echo "<script>console.log('[RecordSearch] page texte intégral', " . json_encode([
    'pid'=>$pid,'q'=>$q,'page'=>$page,'debug'=>$debug,'uri'=>($_SERVER['REQUEST_URI'] ?? '')
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ");</script>";

echo "<div class='rs-page'>";
echo "<h3>Recherche texte intégral</h3>";

echo "<form method='get' class='rs-form' style='margin-bottom:10px;'>";
echo "<input type='hidden' name='pid' value='".htmlspecialchars((string)$pid, ENT_QUOTES)."'>";
echo "<input type='hidden' name='debug' value='".($debug ? "1":"0")."'>";
echo "<input type='text' name='q' value='".htmlspecialchars($q, ENT_QUOTES)."' placeholder='Recherche…' style='width:420px;max-width:80%;'>";
echo "<button class='btn btn-primary btn-xs' type='submit'>Rechercher</button>";
echo "</form>";

if (mb_strlen($q, 'UTF-8') < 2) {
    echo "<p class='text-muted'>Tape au moins 2 caractères.</p>";
    echo "</div>";
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
    exit;
}

$t0 = microtime(true);
$rows = rs_fulltext_search($pid, $q, $pageSize + 1, $offset, $debug);
$dt = (int)round((microtime(true) - $t0) * 1000);

$hasMore = count($rows) > $pageSize;
if ($hasMore) array_pop($rows);

echo "<p class='text-muted'>Résultats: ".count($rows)." | Page $page | Temps: {$dt}ms</p>";

echo "<div class='table-responsive'><table class='table table-bordered table-condensed'>";
echo "<thead><tr>
        <th>Patient</th>
        <th>Événement</th>
        <th>Instrument</th>
        <th>Champ</th>
        <th>Valeur</th>
        <th>Lien</th>
      </tr></thead><tbody>";

foreach ($rows as $r) {
    $record = (string)$r['record'];
    $eventId = (int)$r['event_id'];
    $eventName = (string)($r['event_name'] ?? '');
    $repeatInstr = (string)($r['repeat_instrument'] ?? '');
    $repeatInst = (string)($r['repeat_instance'] ?? '');
    $form = (string)($r['form_name'] ?? '');
    $fieldLabelRaw = trim((string)($r['field_label'] ?? ''));
    $fieldLabel = strip_tags($fieldLabelRaw !== '' ? $fieldLabelRaw : (string)$r['field_name']);
    $value = (string)$r['value'];

    $valueDisp = mb_strlen($value, 'UTF-8') > 180 ? mb_substr($value, 0, 180, 'UTF-8') . "…" : $value;

    $dataEntry = APP_PATH_WEBROOT_FULL . "redcap_v" . REDCAP_VERSION .
        "/DataEntry/record_home.php?pid=" . $pid . "&id=" . urlencode($record) .
        ($eventId ? "&event_id=" . $eventId : "");

    if ($form !== '') {
        $dataEntry = APP_PATH_WEBROOT_FULL . "redcap_v" . REDCAP_VERSION .
            "/DataEntry/index.php?pid=" . $pid .
            "&id=" . urlencode($record) .
            ($eventId ? "&event_id=" . $eventId : "") .
            "&page=" . urlencode($form);

        if ($repeatInst !== '' && ctype_digit($repeatInst) && (int)$repeatInst > 1) {
            $dataEntry .= "&instance=" . $repeatInst;
        }
    }

    echo "<tr>";
    echo "<td><b>" . htmlspecialchars($record, ENT_QUOTES) . "</b></td>";
    echo "<td>" . htmlspecialchars($eventName, ENT_QUOTES) . "</td>";
    echo "<td>" . htmlspecialchars(trim($repeatInstr . ($repeatInst !== '' ? " #$repeatInst" : "")), ENT_QUOTES) . "</td>";
    echo "<td>" . htmlspecialchars($fieldLabel, ENT_QUOTES) . "</td>";
    echo "<td style='max-width:520px;word-break:break-word;'>" . htmlspecialchars($valueDisp, ENT_QUOTES) . "</td>";
    echo "<td><a class='btn btn-default btn-xs' href='" . htmlspecialchars($dataEntry, ENT_QUOTES) . "'>Ouvrir</a></td>";
    echo "</tr>";
}

echo "</tbody></table></div>";

echo "<div style='margin-top:10px;'>";
if ($page > 1) {
    $prev = $page - 1;
    echo "<a class='btn btn-default btn-xs' href='?pid=$pid&q=" . urlencode($q) . "&page=$prev&debug=" . ($debug ? "1":"0") . "'>← Précédent</a> ";
}
if ($hasMore) {
    $next = $page + 1;
    echo "<a class='btn btn-default btn-xs' href='?pid=$pid&q=" . urlencode($q) . "&page=$next&debug=" . ($debug ? "1":"0") . "'>Suivant →</a>";
}
echo "</div>";

echo "</div>";

require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
