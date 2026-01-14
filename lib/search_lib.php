<?php
// lib/search_lib.php

/**
 * Petit module utilitaire, parce que la vie est courte et les bugs sont nombreux.
 * Dépend des fonctions REDCap: db_query, db_fetch_assoc, db_escape, db_num_rows, db_error
 */

function rs_log(string $msg, array $ctx = []): void
{
    $base = "[RecordSearch] " . $msg;
    if (!empty($ctx)) {
        $base .= " | " . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log($base);
}

function rs_user_dag_group_id(int $pid, string $username): ?int
{
    $usernameEsc = db_escape($username);
    $sql = "select group_id
            from redcap_data_access_groups_users
            where project_id = " . intval($pid) . "
              and username = '$usernameEsc'
            limit 1";
    $q = db_query($sql);
    if ($q === false) return null;
    $row = db_fetch_assoc($q);
    return $row ? (int)$row['group_id'] : null;
}

function rs_table_exists(string $table): bool
{
    $t = db_escape($table);
    $res = db_query("SHOW TABLES LIKE '$t'");
    return ($res !== false) && (db_num_rows($res) > 0);
}

/**
 * Détermine quels champs servent de "label" (ou identifiant secondaire) pour la recherche user-friendly.
 * - secondary_pk (champ unique secondaire)
 * - custom_record_label (parse des [field_name])
 * - fallback optionnel : pseudonymisation (utile chez toi)
 */
function rs_get_label_fields(int $pid, bool $debug = false): array
{
    $sql = "select secondary_pk, custom_record_label
            from redcap_projects
            where project_id = " . intval($pid) . "
            limit 1";
    $q = db_query($sql);
    $row = $q ? db_fetch_assoc($q) : null;

    $fields = [];

    if (!empty($row['secondary_pk'])) {
        $fields[] = (string)$row['secondary_pk'];
    }

    if (!empty($row['custom_record_label'])) {
        if (preg_match_all('/\[([a-zA-Z0-9_]+)\]/', (string)$row['custom_record_label'], $m)) {
            foreach ($m[1] as $f) $fields[] = $f;
        }
    }

    // Fallback pragmatique (optionnel mais utile dans ton cas)
    $fields[] = 'pseudonymisation';

    $fields = array_values(array_unique(array_filter($fields)));

    if ($debug) rs_log("label fields", ["pid" => $pid, "fields" => $fields]);

    return $fields;
}

/**
 * Suggestions "patient/record" :
 * - match sur record id (redcap_data.record)
 * - ET match sur des champs label (secondary_pk / custom_record_label / pseudonymisation)
 * Retourne record_id + label + url.
 */
function rs_patient_suggestions(int $pid, string $query, int $max = 12, bool $debug = false): array
{
    $q = trim($query);
    $qEsc = db_escape($q);
    $like = "%" . $qEsc . "%";

    $dagGroupId = defined('USERID') ? rs_user_dag_group_id($pid, USERID) : null;

    $labelFields = rs_get_label_fields($pid, $debug);
    $primaryLabelField = $labelFields[0] ?? '';

    $labelIn = "";
    if (!empty($labelFields)) {
        $labelIn = implode(",", array_map(function ($f) {
            return "'" . db_escape($f) . "'";
        }, $labelFields));
    }

    // DAG au niveau record
    $dagJoin = "";
    $dagWhere = "";
    if ($dagGroupId !== null) {
        $dagJoin  = " inner join redcap_data_access_groups_records gr
                      on gr.project_id = " . intval($pid) . "
                     and gr.record = r.record ";
        $dagWhere = " and gr.group_id = " . intval($dagGroupId) . " ";
    }

    // Sous-requête label (évite les doublons multi-events/instances)
    $labelJoin = "";
    if ($primaryLabelField !== '') {
        $pf = db_escape($primaryLabelField);
        $labelJoin = "
            left join (
                select record, max(value) as label
                from redcap_data
                where project_id = " . intval($pid) . "
                  and field_name = '$pf'
                  and value <> ''
                group by record
            ) lbl on lbl.record = r.record
        ";
    }

    // Records candidats = (record like) UNION (label field value like)
    $sql = "
        select
            r.record,
            coalesce(nullif(lbl.label,''), r.record) as label
        from (
            select distinct d.record
            from redcap_data d
            where d.project_id = " . intval($pid) . "
              and lower(d.record) like lower('$like')
            " . (!empty($labelIn) ? "
            union
            select distinct d.record
            from redcap_data d
            where d.project_id = " . intval($pid) . "
              and d.field_name in ($labelIn)
              and lower(d.value) like lower('$like')
            " : "") . "
        ) r
        $dagJoin
        $labelJoin
        where 1=1
        $dagWhere
        order by label
        limit " . intval($max) . "
    ";

    if ($debug) rs_log("patient_suggestions SQL", ["pid" => $pid, "q" => $q, "sql" => $sql, "dag" => $dagGroupId]);

    $res = db_query($sql);
    if ($res === false) {
        throw new Exception("SQL error (suggestions): " . db_error());
    }

    $out = [];
    while ($row = db_fetch_assoc($res)) {
        $rid = (string)$row['record'];
        $label = (string)($row['label'] ?? $rid);

        $url = APP_PATH_WEBROOT_FULL . "redcap_v" . REDCAP_VERSION .
            "/DataEntry/record_home.php?pid=" . $pid . "&id=" . urlencode($rid);

        $out[] = [
            'record_id' => $rid,
            'label' => $label,
            'url' => $url
        ];
    }

    return $out;
}

/**
 * Full text (paginé) :
 * record OR value OR field label/name.
 * NOTE: Le join sur la table repeat est optionnel (ne doit pas crasher si absente).
 */
function rs_fulltext_search(int $pid, string $query, int $limit, int $offset, bool $debug = false): array
{
    $q = trim($query);
    $qEsc = db_escape($q);
    $like = "%" . $qEsc . "%";

    $dagGroupId = defined('USERID') ? rs_user_dag_group_id($pid, USERID) : null;

    // DAG au niveau record
    $dagJoin = "";
    $dagWhere = "";
    if ($dagGroupId !== null) {
        $dagJoin  = " inner join redcap_data_access_groups_records gr
                      on gr.project_id = d.project_id
                     and gr.record = d.record ";
        $dagWhere = " and gr.group_id = " . intval($dagGroupId) . " ";
    }

    // Table repeat (varie selon installations)
    $repeatTable = null;
    if (rs_table_exists('redcap_repeat_instance')) $repeatTable = 'redcap_repeat_instance';
    elseif (rs_table_exists('redcap_repeat_instances')) $repeatTable = 'redcap_repeat_instances';

    $repeatJoin = "";
    $repeatSelect = "'' as repeat_instrument, d.instance as repeat_instance";
    if ($repeatTable) {
        $repeatJoin = " left join $repeatTable r
                        on r.project_id = d.project_id
                       and r.event_id = d.event_id
                       and r.record = d.record
                       and r.repeat_instance = d.instance ";
        $repeatSelect = "r.repeat_instrument, r.repeat_instance";
    }

    $sql = "
        select
            d.record,
            d.event_id,
            e.descrip as event_name,
            $repeatSelect,
            d.instance,
            d.field_name,
            m.form_name,
            m.field_label,
            d.value
        from redcap_data d
        left join redcap_events_metadata e
               on e.event_id = d.event_id
        $repeatJoin
        left join redcap_metadata m
               on m.project_id = d.project_id
              and (
                    m.field_name = d.field_name
                 or (m.element_type = 'checkbox' and d.field_name like concat(m.field_name,'___%'))
              )
        $dagJoin
        where d.project_id = " . intval($pid) . "
          $dagWhere
          and d.value <> ''
          and (
                lower(d.record) like lower('$like')
             or lower(d.value) like lower('$like')
             or lower(d.field_name) like lower('$like')
             or lower(coalesce(m.field_label,'')) like lower('$like')
          )
        order by d.record, d.event_id, d.field_name
        limit " . intval($limit) . " offset " . intval($offset) . "
    ";

    if ($debug) rs_log("fulltext SQL", [
        "pid" => $pid,
        "q" => $q,
        "limit" => $limit,
        "offset" => $offset,
        "sql" => $sql,
        "dag" => $dagGroupId,
        "repeat_table" => $repeatTable
    ]);

    $res = db_query($sql);
    if ($res === false) {
        throw new Exception("SQL error (fulltext): " . db_error());
    }

    $rows = [];
    while ($row = db_fetch_assoc($res)) {
        $rows[] = $row;
    }

    return $rows;
}
