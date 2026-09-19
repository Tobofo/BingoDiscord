<?php $config = require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Le Client ID est lu par le JavaScript (pas de script inline dans une activité) -->
    <meta name="client-id" content="<?= htmlspecialchars($config['discord_client_id']) ?>">
    <title>Bingo</title>
    <link rel="stylesheet" href="bingo.css">
</head>
<body>

<div class="page">

    <!-- Gauche : les autres participants -->
    <aside class="colonne-gauche">
        <h2>Participants</h2>
        <div id="joueurs" class="joueurs">Connexion à Discord…</div>
    </aside>

    <!-- Milieu : ma grille (on ne peut pas cocher soi-même) -->
    <main class="colonne-centre">
        <h2>Ma grille</h2>
        <p id="message" class="erreur"></p>
        <div id="grille" class="grille"></div>
        <button id="nouvelle" type="button">Nouvelle grille</button>
    </main>

    <!-- Droite : toutes les phrases, un clic lance un vote -->
    <aside class="colonne-droite">
        <h2>Phrases</h2>
        <div id="phrases" class="phrases"></div>
    </aside>

</div>

<!-- Fenêtre de vote -->
<div id="vote" class="vote" hidden>
    <p class="vote-titre">Tout le monde a vu / entendu ?</p>
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

<script src="app.js"></script>
</body>
</html>