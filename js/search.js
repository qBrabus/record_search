// js/search.js - logique de la barre de recherche
(function () {
  function debounce(fn, delay) {
    let t = null;
    return function () {
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#039;"
    }[m]));
  }

  function getCsrfToken() {
    if (window.redcap_csrf_token) return window.redcap_csrf_token;
    if (typeof redcap_csrf_token !== "undefined") return redcap_csrf_token;
    return "";
  }

  window.RecordSearchInit = function (cfg) {
    const $input = $("#record-search-input");
    const $results = $("#record-search-results");
    const $full = $("#record-search-fulltext");
    const $hint = $("#record-search-hint");
    const $btn = $("#record-search-btn");

    let lastXhr = null;

    // Log conditionnel pour le mode debug
    function log() {
      if (!cfg.debug) return;
      console.log.apply(console, arguments);
    }

    function clearDropdown() {
      $results.hide().empty();
    }

    function showError(msg, extra) {
      const text = (extra ? (msg + " | " + extra) : msg);
      $results.html("<div class='rs-error'>❌ " + escHtml(text) + "</div>").show();
    }

    function showDropdown(items) {
      if (!items || !items.length) {
        $results.html("<div class='rs-empty'>Aucun patient</div>").show();
        return;
      }
      const html = items.map(it =>
        "<div class='rs-item' data-url='" + escHtml(it.url) + "'>" +
          "<div class='rs-title'>" + escHtml(it.label || it.record_id) + "</div>" +
          "<div class='rs-sub'>" + escHtml(it.record_id) + "</div>" +
        "</div>"
      ).join("");
      $results.html(html).show();
    }

    function doPatientSearch() {
      const q = $input.val().trim();
      if (q.length < (cfg.minChars || 2)) { clearDropdown(); return; }

      if (lastXhr && lastXhr.readyState !== 4) lastXhr.abort();

      const payload = {
        query: q,
        mode: "record",
        debug: cfg.debug ? 1 : 0,
        redcap_csrf_token: getCsrfToken()
      };

      log("[RecordSearch] Démarrage AJAX", { url: cfg.ajaxUrl, payload });

      $results.html("<div class='rs-loading'>Recherche…</div>").show();

      const t0 = performance.now();
      lastXhr = $.ajax({
        method: "POST",
        url: cfg.ajaxUrl,
        dataType: "json",
        data: payload
      }).done(function (res) {
        const dt = Math.round(performance.now() - t0);
        log("[RecordSearch] AJAX terminé en " + dt + "ms", res);

        if (!res) { showError("Réponse vide"); return; }
        if (!res.success) { showError("Erreur backend", res.error || "inconnue"); return; }

        // Debug côté serveur si fourni
        if (cfg.debug && res.debug) log("[RecordSearch] debug serveur :", res.debug);

        showDropdown(res.results);
      }).fail(function (xhr, status, err) {
        const dt = Math.round(performance.now() - t0);
        log("[RecordSearch] AJAX en échec en " + dt + "ms", { status, err, xhr });

        let details = "";
        try { details = (xhr && xhr.responseText) ? xhr.responseText.slice(0, 600) : ""; } catch (e) {}
        showError("AJAX en échec : " + status, details);
      });
    }

    const debounced = debounce(doPatientSearch, 250);

    $full.on("change", function () {
      clearDropdown();
      $hint.toggle($full.is(":checked"));
      log("[RecordSearch] Texte intégral activé :", $full.is(":checked"));
    });

    $input.on("input", function () {
      if ($full.is(":checked")) { clearDropdown(); return; }
      debounced();
    });

    $input.on("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        const q = $input.val().trim();
        if (q.length < (cfg.minChars || 2)) return;

        if ($full.is(":checked")) {
          const url = cfg.fulltextUrl + "&q=" + encodeURIComponent(q) + "&debug=" + (cfg.debug ? "1" : "0");
          log("[RecordSearch] redirection texte intégral :", url);
          window.location.href = url;
        } else {
          doPatientSearch();
        }
      }
    });

    $btn.on("click", function () {
      const q = $input.val().trim();
      if (q.length < (cfg.minChars || 2)) return;

      if ($full.is(":checked")) {
        const url = cfg.fulltextUrl + "&q=" + encodeURIComponent(q) + "&debug=" + (cfg.debug ? "1" : "0");
        log("[RecordSearch] clic redirection texte intégral :", url);
        window.location.href = url;
      } else {
        doPatientSearch();
      }
    });

    $results.on("click", ".rs-item", function () {
      const url = $(this).data("url");
      log("[RecordSearch] clic suggestion =>", url);
      if (url) window.location.href = url;
    });

    $(document).on("click", function (e) {
      if (!$(e.target).closest("#record-search-container").length) clearDropdown();
    });

    log("[RecordSearch] Prêt. Essaie de taper : imagine");
  };
})();
