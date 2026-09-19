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

<h1>🎯 Bingo</h1>

<div id="joueurs" class="joueurs">Connexion à Discord…</div>
<button id="nouvelle" type="button">Nouvelle grille</button>
<p id="message" class="erreur"></p>
<div id="grille" class="grille"></div>

<script src="app.js"></script>
</body>
</html>
