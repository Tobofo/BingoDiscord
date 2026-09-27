<?php
$config = require __DIR__ . '/config.php';

$url = "https://discord.com/api/v10/applications/" . $config['discord_client_id'] . "/commands";

$payload = [
    'name' => 'ajouter-phrase',
    'description' => 'Ajoute une nouvelle phrase au Bingo',
    'options' => [
        [
            'type' => 3, // Type 3 = STRING
            'name' => 'phrase',
            'description' => 'La phrase à ajouter dans la base Firebase',
            'required' => true
        ]
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bot ' . $config['discord_bot_token'],
        'Content-Type: application/json'
    ]
]);

$reponse = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 200 && $code < 300) {
    echo "✔ Commande /ajouter-phrase enregistrée avec succès !\n";
} else {
    echo "✘ Erreur ($code) : $reponse\n";
}