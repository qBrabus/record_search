<?php
header('Content-Type: application/json; charset=UTF-8');

$debug = isset($_POST['debug']) && (string)$_POST['debug'] === '1';

try {
    // Charge REDCap (chemin correct depuis pages/)
    if (!defined('APP_PATH_DOCROOT')) {
        require_once dirname(__FILE__, 4) . '/redcap_connect.php';
    }

    require_once __DIR__ . '/../lib/search_lib.php';

    if (!defined('USERID') || USERID === '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Non authentifié'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : (int)($_POST['pid'] ?? 0);
    if ($pid <= 0) throw new Exception("pid manquant");

    $query = isset($_POST['query']) ? trim((string)$_POST['query']) : '';
    $mode  = isset($_POST['mode']) ? (string)$_POST['mode'] : 'record';

    if (mb_strlen($query, 'UTF-8') < 2) {
        echo json_encode(['success' => true, 'results' => [], 'debug' => $debug ? ['note'=>'query<2'] : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $t0 = microtime(true);

    rs_log("AJAX request", [
        "pid" => $pid,
        "user" => USERID,
        "mode" => $mode,
        "query" => $query,
        "uri" => $_SERVER['REQUEST_URI'] ?? ''
    ]);

    if ($mode === 'record') {
        $results = rs_patient_suggestions($pid, $query, 12, $debug);
        $dt = (int)round((microtime(true) - $t0) * 1000);

        echo json_encode([
            'success' => true,
            'results' => $results,
            'debug' => $debug ? [
                'mode' => $mode,
                'pid' => $pid,
                'user' => USERID,
                'query' => $query,
                'count' => count($results),
                'time_ms' => $dt
            ] : null
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    // mode full (pas utilisé par défaut, mais dispo si tu veux)
    $rows = rs_fulltext_search($pid, $query, 50, 0, $debug);
    $dt = (int)round((microtime(true) - $t0) * 1000);

    echo json_encode([
        'success' => true,
        'results' => $rows,
        'debug' => $debug ? [
            'mode' => $mode,
            'pid' => $pid,
            'user' => USERID,
            'query' => $query,
            'count' => count($rows),
            'time_ms' => $dt
        ] : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    if (function_exists('rs_log')) {
        rs_log("AJAX ERROR", ["err"=>$e->getMessage(), "file"=>$e->getFile(), "line"=>$e->getLine()]);
    } else {
        error_log("[RecordSearch] AJAX ERROR: " . $e->getMessage());
    }

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => $debug ? [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => substr($e->getTraceAsString(), 0, 2000)
        ] : null
    ], JSON_UNESCAPED_UNICODE);
}
