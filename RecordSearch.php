<?php
namespace RecordSearch;

use ExternalModules\AbstractExternalModule;

class RecordSearch extends AbstractExternalModule
{
    public function redcap_every_page_top($project_id)
    {
        if (empty($project_id)) return;

        static $done = false;
        if ($done) return;
        $done = true;

        $this->injectSearchBar((int)$project_id);
    }

    private function injectSearchBar(int $project_id)
    {
        $jsUrl  = $this->getUrl('js/search.js');
        $cssUrl = $this->getUrl('css/search.css');

        // Important : pages/ ... (sinon erreur de préfixe)
        $ajaxUrl     = $this->getUrl('pages/search_ajax.php');
        $fulltextUrl = $this->getUrl('pages/fulltext_results.php');

        // Au cas où getUrl() ne mettrait pas pid
        if (strpos($ajaxUrl, 'pid=') === false)     $ajaxUrl     .= "&pid=" . $project_id;
        if (strpos($fulltextUrl, 'pid=') === false) $fulltextUrl .= "&pid=" . $project_id;




        // Debug activé (pour toi). Tu pourras le passer à false après.
        $debug = true;

        ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES); ?>">
        <script src="<?php echo htmlspecialchars($jsUrl, ENT_QUOTES); ?>"></script>

        <script>
            $(function () {
                var html =
                    '<div id="record-search-container">' +
                        '<div class="rs-row">' +
                            '<input type="text" id="record-search-input" placeholder="🔍 Rechercher un patient…" autocomplete="off">' +
                            '<button type="button" id="record-search-btn" title="Rechercher">↩</button>' +
                        '</div>' +
                        '<label class="rs-fulltext">' +
                            '<input type="checkbox" id="record-search-fulltext"> Texte intégral' +
                        '</label>' +
                        '<div id="record-search-results" class="rs-dropdown"></div>' +
                        '<div id="record-search-hint" class="rs-hint" style="display:none;">Texte intégral : appuie sur Entrée</div>' +
                    '</div>';

                var $projectLogo = $('#project-menu-logo');
                if ($projectLogo.length) $projectLogo.after(html);
                else $('#west').prepend(html);

                var cfg = {
                    ajaxUrl: <?php echo json_encode($ajaxUrl); ?>,
                    fulltextUrl: <?php echo json_encode($fulltextUrl); ?>,
                    minChars: 2,
                    maxSuggestions: 12,
                    debug: <?php echo $debug ? 'true' : 'false'; ?>,
                    pid: <?php echo (int)$project_id; ?>
                };

                // Expose la configuration dans la console
                window.RecordSearchCfg = cfg;

                if (cfg.debug) {
                    console.log("[RecordSearch] configuration initiale :", cfg);
                    console.log("[RecordSearch] redcap_csrf_token présent ?", !!(window.redcap_csrf_token || (typeof redcap_csrf_token !== "undefined")));
                }

                window.RecordSearchInit(cfg);
            });
        </script>
        <?php
    }
}
