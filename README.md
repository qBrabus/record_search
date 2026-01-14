# Record Search (REDCap)

Module externe REDCap qui ajoute une barre de recherche compacte dans la colonne de gauche.

## Fonctionnalités

- Suggestions instantanées sur l'identifiant d'enregistrement et sur les champs « label ».
- Recherche « texte intégral » avec résultats paginés.
- Respect des Data Access Groups (DAG).
- Compatible avec les variations de schéma REDCap (libellés de champs).

## Installation

1. Copier ce dossier dans `modules/record_search_v0.0.1/`.
2. Activer le module dans *External Modules* (REDCap Control Center).
3. Activer le module pour le projet cible.

## Configuration

Dans `RecordSearch.php`, vous pouvez ajuster :

- `minChars` : nombre minimal de caractères avant recherche (par défaut 2).
- `maxSuggestions` : nombre maximum de suggestions (par défaut 12).
- `debug` : logs console et logs serveur.

Les champs utilisés comme « label » sont déterminés automatiquement :

- `secondary_pk` du projet.
- `custom_record_label` (extraction des champs entre `[ ]`).
- Champ de secours : `pseudonymisation`.

## Utilisation

- Tapez au moins 2 caractères pour obtenir des suggestions.
- Cochez **Texte intégral** puis validez (Entrée ou bouton) pour ouvrir la page dédiée.

## Dépannage

- **Aucun résultat** : vérifiez que les champs de label existent et contiennent bien les valeurs attendues.
- **Erreur SQL sur le libellé** : le module détecte automatiquement la colonne `field_label` ou `element_label` selon la version REDCap.
- **DAG** : les résultats sont filtrés selon le groupe d'accès de l'utilisateur.

## Développement

- `lib/search_lib.php` : fonctions SQL et logique métier.
- `pages/search_ajax.php` : endpoint AJAX.
- `pages/fulltext_results.php` : page de résultats texte intégral.
- `js/search.js` : logique front (debounce, appels AJAX).
- `css/search.css` : styles.
