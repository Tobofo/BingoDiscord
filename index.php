<?php
$version = 'v1.0';
$commit  = 'local';
$date    = '1970-01-01';

$versionFile = __DIR__ . '/version.json';
if (file_exists($versionFile)) {
    $versionData = json_decode(file_get_contents($versionFile), true);
    if (is_array($versionData)) {
        $version = $versionData['version'] ?? $version;
        $commit  = $versionData['commit']  ?? $commit;
        $date    = $versionData['date']    ?? $date;
    }
}

$cacheKey = urlencode($version . '-' . $commit);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Le Client ID est lu par le JavaScript (pas de script inline dans une activité) -->
    <meta name="client-id" content="<?= htmlspecialchars($config['discord_client_id']) ?>">
    <title>Bingo</title>
    <link rel="stylesheet" href="bingo.css?v=<?php echo urlencode($version); ?>">
</head>
<body>

<div class="page">

    <!-- Gauche : les autres participants, et le bouton « Nouvelle partie » tout en bas -->
    <aside class="colonne-gauche">
        <h2>Participants</h2>
        <div id="joueurs" class="joueurs">Connexion à Discord…</div>
        <button id="relancer" type="button" class="secondaire">Nouvelle partie</button>
    </aside>

    <!-- Milieu : ma grille (on ne peut pas cocher soi-même) -->
    <main class="colonne-centre">
        <h2>Ma grille</h2>
        <div id="alerte" class="erreur" hidden>
            <span id="message"></span>
            <button id="fermer-alerte" class="fermer" type="button" aria-label="Fermer">✕</button>
        </div>
        <div id="grille" class="grille"></div>
    </main>

    <!-- Droite : toutes les phrases, un clic lance un vote -->
    <aside class="colonne-droite">
        <h2>Phrases</h2>
        <input id="recherche" class="recherche" type="search" placeholder="Rechercher une phrase…" autocomplete="off">
        <div id="phrases" class="phrases"></div>
        <p id="aucune" class="vide" hidden>Aucune phrase trouvée.</p>
    </aside>

</div>

<!-- Fenêtre de vote -->
<div id="vote" class="vote" hidden>
    <p id="vote-titre" class="vote-titre">Tout le monde a vu / entendu ?</p>
    <p id="vote-texte" class="vote-texte"></p>
    <p id="vote-compteur" class="vote-compteur"></p>
    <div id="vote-boutons" class="vote-boutons">
        <button id="oui" type="button">Oui</button>
        <button id="non" type="button" class="secondaire">Non</button>
    </div>
    <p id="vote-attente" class="vote-compteur" hidden>En attente des autres joueurs…</p>
</div>

<!-- Résultat du dernier vote -->
<div id="annonce" class="annonce" hidden></div>

<!-- Fenêtre de victoire -->
<div id="victoire" class="victoire" hidden>
    <div class="victoire-carte">
        <p class="victoire-icone">🏆</p>
        <p id="victoire-titre" class="victoire-titre"></p>
        <p id="victoire-sous-titre" class="victoire-sous-titre"></p>
        <p id="victoire-compte" class="victoire-compte"></p>
    </div>
</div>

<p id="version" class="version" title="Publié le <?php echo htmlspecialchars($date); ?>">
    <?php echo htmlspecialchars($version); ?> · <?php echo htmlspecialchars($commit); ?>
</p>

<script src="app.js?v=<?php echo urlencode($version); ?>"></script>
</body>
</html>