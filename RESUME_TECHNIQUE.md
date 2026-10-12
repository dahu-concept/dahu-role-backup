# RESUME TECHNIQUE — Dahu Role Backup

Document interne, en français.

---

## Portage depuis la version Annad

### Version Annad d'origine

- Dépôt source Annad : `/Dev/dahu-security-connect/` (slug Annad : `dahu-security-connect`)
- Version de référence au moment du portage : `1.2.0`
- Date du portage : 2026-10-07
- Version publique de départ : `2.0.0`

### Une extension Annad, deux extensions publiques

`dahu-security-connect` contenait deux produits sans rapport. Cette extension
reprend **la partie sauvegarde des rôles** (sections 6 à 16 du fichier
d'origine, ~1 300 lignes) ; la partie anti-spam d'inscription est devenue
`dahu-signup-guard`.

Le commentaire du code Annad justifiait déjà la séparation : « aucune option,
aucun hook et aucune donnée ne sont partagés entre les deux. Seuls le fichier,
le menu et le cycle de vie du plugin leur sont communs ».

**Conséquence pour le suivi** : une évolution côté `dahu-security-connect`
devra être reportée ici *ou* dans `dahu-signup-guard` selon la partie touchée.

### Méthode d'extraction

Code **procédural conservé tel quel**, découpé par section dans `includes/`.
Convertir les 32 fonctions en classes aurait obligé à réécrire chaque appel,
pour aucun gain : des fonctions correctement préfixées sont parfaitement
acceptables en revue wordpress.org.

| Fichier | Sections d'origine | Contenu |
|---|---|---|
| `storage.php` | 6, 7, 9 | emplacement, journal dédié, rétention |
| `snapshot.php` | 8, 11 | écriture d'un instantané, lecture et validation |
| `restore.php` | 12 | restauration additive et intégrale |
| `schedule.php` | 10, 13, 14 | déclencheurs, cycle de vie, état de santé |
| `admin.php` | 15 | écran d'administration |
| `cli.php` | 16 | commandes WP-CLI |

Contrôle après découpage : **32 fonctions dans la source, 32 dans le
découpage**, aucune perte.

### Correspondance des identifiants

| Annad | Public |
|---|---|
| `dahu_sc_backup_*` | `dahu_role_backup_*` |
| `DAHU_SC_BACKUP_*` | `DAHU_ROLE_BACKUP_*` |
| `Dahu_SC_Backup_CLI` | `Dahu_Role_Backup_CLI` |
| `wp dahu-security-connect roles-backup …` | `wp dahu-role-backup …` |
| répertoire `wp-content/dahu-roles-backups` | `wp-content/dahu-role-backups` |
| chaîne générateur `dahu-security-connect <v>` | `dahu-role-backup <v>` |

Le renommage du générateur est sans risque : `check_origin()` compare le
**préfixe de table** et le **nom d'option**, jamais cette chaîne.

### Corrigé pendant le portage

**L'écran n'avait aucun menu.** C'était un onglet de la page de réglages de
l'extension parente. Il devient un écran à part entière sous **Outils** —
c'est de la maintenance, consultée quand quelque chose a mal tourné, pas un
réglage. Le `<div class="wrap">` qu'ouvrait la page parente est désormais
ouvert et fermé par `dahu_role_backup_render_page()`.

**Aucune internationalisation.** Les chaînes affichées étaient du français brut
dans des `echo`. Le portage a d'abord été fait à moitié : une partie seulement
des chaînes et des commentaires était passée à l'anglais. Reprise et terminée
depuis — voir « Internationalisation » ci-dessous.

**Le répertoire de stockage pouvait échouer en silence.** Sur un hébergement
où `wp-content/` est en lecture seule, `wp_mkdir_p()` échouait et plus aucun
instantané n'était écrit — sans que rien ne le signale. Repli explicite sur le
répertoire `uploads/`, et emplacement filtrable
(`dahu_role_backup_directory`). Un instantané protégé à un endroit moins idéal
vaut mieux que pas d'instantané du tout.

### Internationalisation

Terminée en une seconde passe. Règle de tri appliquée :

| Nature du texte | Traitement |
|---|---|
| Écran d'administration, notices, sortie WP-CLI | anglais **et** `__()` / `esc_html__()` / `esc_attr__()` / `_n()` |
| Messages de `dahu_role_backup_log()` | anglais, **non** encadrés |
| Commentaires et docblocks | anglais |

**88 appels** à une fonction de traduction (52 auparavant, dont plusieurs mal
placés), **un seul** text domain `dahu-role-backup`, **17 commentaires
`/* translators: */`** au-dessus des chaînes à substitution.

**Les messages de journal ne sont volontairement pas traduisibles.** Un journal
technique est relu pendant un incident, souvent par quelqu'un d'autre que
l'administrateur du site ; il est plus utile quand sa formulation ne dépend pas
de la locale active. Trois d'entre eux étaient encadrés en `__()` à tort
(`restore.php` ×2, `schedule.php` ×2, `storage.php` ×1) : les encadrements ont
été retirés.

**Pluriels.** `_n()` aux trois seuls endroits où un nombre précède un nom
fléchi : l'instantané protégé et l'en-tête d'aperçu (`admin.php`), le succès de
`wp dahu-role-backup snapshot` (`cli.php`). Les inventaires de journal gardent
une tournure sans flexion (`PURGE: snapshots deleted (%1$d): %2$s`), puisqu'un
message de journal n'est de toute façon pas traduit.

**Le balisage HTML est sorti des chaînes traduisibles.** Il y était auparavant :
`__( '<form … name="action" value="dahu_role_backup_snapshot"> …' )` confiait à
un traducteur l'`action` du formulaire et le `href` du bouton de restauration.
Les libellés sont désormais seuls dans les `__()`, le HTML reste dans la chaîne
de format de `printf()`.

**Durcissement de l'échappement**, rendu nécessaire par le fait que des
libellés deviennent traduisibles et peuvent donc contenir `&` ou une
apostrophe :

- `esc_attr__()` pour l'attribut `title` de l'étoile « protégé de la purge » ;
- `esc_js()` pour le message de `confirm()` du remplacement intégral — une
  traduction contenant une apostrophe fermait le littéral JavaScript et cassait
  le bouton ;
- `wp_die()` passe de `__()` à `esc_html__()` dans les deux gardes de capacité
  (`admin.php`), qui étaient les seules à ne pas échapper.

**Formats de date.** Les dates affichées passent par
`dahu_role_backup_datetime_format()`, qui renvoie
`get_option( 'date_format' ) . ' ' . get_option( 'time_format' )`, consommé par
`wp_date()` — réglage **et** fuseau du site. `d/m/Y` était codé en dur : pour un
lecteur américain `03/10/2026` se lit mars, pas octobre, et sur un écran dont le
sujet est « quel instantané a été pris quand », une date mal lue est un
contresens.

Trois sites d'affichage bascules, quatre horodatages machine laissés
**strictement** tels quels :

| Appel | Traitement |
|---|---|
| `admin.php` prochain instantané quotidien | → `wp_date()` + format du site |
| `admin.php` colonne Date du tableau | → `wp_date()` + format du site |
| `admin.php` aperçu, `date_utc` relu | → reformaté **à l'affichage seulement** |
| `snapshot.php:75` `gmdate( 'Ymd-His' )` | **laissé** — contrat du nom de fichier |
| `snapshot.php:186` `gmdate( 'c' )` | **laissé** — ISO 8601 du JSON |
| `cli.php:50` `gmdate( 'Y-m-d H:i:s' )` | **laissé** — colonne `date_utc`, sortie `--format=json` |
| `storage.php:135` `gmdate( 'Y-m-d H:i:s' )` | **laissé** — ligne de journal, doit rester triable |

Le nom de fichier est décodé par la purge et la recherche du « meilleur
instantané » sans ouvrir les fichiers : en changer le format rendrait les
instantanés existants invisibles. Round-trip `filename()` / `parse_filename()`
vérifié, y compris sur un nom antérieur.

La colonne du tableau était intitulée « Date (UTC) » : elle devient « Date »,
puisqu'elle affiche désormais l'heure locale du site. L'UTC exact reste lisible
dans le nom de fichier et dans le JSON de l'instantané.

**Identifiants internes laissés en français.** `manquants` / `nouveaux` /
`communs`, `restaures` / `echecs` / `supprimes`, `evenement`, `contexte`,
`generateur`, le champ POST `fichier`, l'argument d'URL `apercu` et les valeurs
de `dahu_msg`. Ce sont des clés de tableau, des noms de champs liés à un nonce
et des clés du JSON des instantanés : les renommer serait un changement de
contrat, pas une traduction. Aucune n'est jamais affichée — vérifié.

Les codes d'erreur `WP_Error` (`dahu_role_backup_vide`, `_ecriture`, `_nom`,
`_corrompu`, `_origine`) sont laissés tels quels pour la même raison : ils font
partie de la surface publique pour qui teste `get_error_code()`.

### Généralisé

| Élément | Avant | Après |
|---|---|---|
| Langue source | Français | Anglais, i18n complète |
| Licence | GPL v2 or later | MIT |
| Structure | Sous-système d'un fichier de 63 Ko | Extension autonome, 6 fichiers |
| Désinstallation | Aucune | `uninstall.php`, mono-site et multisite |

### Décision : la désinstallation ne supprime PAS les instantanés

Contrairement à toutes les autres extensions du lot. Les instantanés **sont**
la raison d'être de l'extension : un filet gardé précisément pour le jour où
les rôles cassent. Une désinstallation est souvent une étape vers une
réinstallation ou un changement d'outil, et une routine qui les effacerait
serait destructrice exactement au moment où le site a un problème de rôles à
régler.

Le répertoire est laissé en place, à supprimer sciemment. Seuls l'option
`dahu_role_backup_last` et la tâche planifiée partent.

---

## Reste à faire avant soumission

- [ ] **Vérification fonctionnelle sur un site réel** : rien n'a été exécuté.
      Parcours à tester — activation (instantané immédiat), suppression d'un
      rôle puis instantané automatique, comparaison, restauration additive,
      restauration intégrale, refus d'un instantané d'une autre installation.
- [ ] Vérifier le comportement du repli `uploads/` sur un hébergement où
      `wp-content/` est réellement en lecture seule.
- [ ] Captures d'écran `screenshot-1..2.png`.
- [ ] Vérifier la disponibilité du slug `dahu-role-backup` sur wordpress.org.
- [ ] Traductions françaises `.po`/`.mo`.
- [ ] **Point de revue probable** : l'écriture de fichiers hors `uploads/`.
      La justification est dans le code et dans le `README.txt` ; si un
      relecteur insiste, basculer le défaut sur `uploads/` est une ligne.

---

## Décisions d'architecture

- **Deux déclencheurs, pas un.** Le cron quotidien seul laisserait passer une
  disparition survenue juste après. Le surveillant sur l'écriture de l'option
  capture le changement au moment où il se produit.
- **Rien n'est écrit si les rôles sont identiques** au dernier instantané,
  sinon la liste devient illisible et la rétention tourne pour rien.
- **La rétention protège le meilleur instantané récent** en plus du quota.
  Sans cette exception, une série de disparitions répétées viderait le filet
  exactement quand il sert.
- **Restauration additive par défaut.** L'intégrale existe mais se demande
  explicitement : écraser des rôles existants pour en récupérer un disparu est
  rarement ce qu'on veut.
- **Refus d'un instantané d'origine différente**, sur le préfixe de table et le
  nom d'option. Les rôles n'ont pas le même sens d'un site à l'autre.
- **Journal dédié avec rotation à 2 Mo**, pas le journal PHP général : sur un
  site chargé en extensions, une disparition de rôle y passerait inaperçue.

## Pièges connus

- Le répertoire reçoit un `.htaccess` **et** un `index.php` : le premier couvre
  Apache, le second empêche au moins le listage là où `.htaccess` est ignoré
  (nginx).
- `dahu_role_backup_activate()` prend un instantané **immédiatement**, pas au
  premier cron : l'extension doit être utile dès la seconde qui suit son
  activation.
- La tâche quotidienne est planifiée avec 300 secondes de décalage, pour ne pas
  s'aligner sur les autres tâches quotidiennes du site.
