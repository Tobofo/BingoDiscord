<?php
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';

// ---------- 1. Vérification de la signature Discord ----------

$signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';
$corpsBrut = file_get_contents('php://input');

if (!$signature || !$timestamp || !$corpsBrut) {
    http_response_code(401);
    exit(json_encode(['erreur' => 'Requête non autorisée']));
}

$clePublique = hex2bin($config['discord_public_key']);
$signatureBin = hex2bin($signature);

$valide = sodium_crypto_sign_verify_detached($signatureBin, $timestamp . $corpsBrut, $clePublique);

if (!$valide) {
    http_response_code(401);
    exit(json_encode(['erreur' => 'Signature invalide']));
}

$data = json_decode($corpsBrut, true);

// Ping de validation Discord (Type 1)
if (($data['type'] ?? 0) === 1) {
    echo json_encode(['type' => 1]);
    exit;
}

// ---------- 2. Traitement de la commande /ajouter-phrase ----------

if (($data['type'] ?? 0) === 2 && ($data['data']['name'] ?? '') === 'ajouter-phrase') {
    $nouvellePhrase = trim($data['data']['options'][0]['value'] ?? '');

    if ($nouvellePhrase === '') {
        echo json_encode([
            'type' => 4,
            'data' => [
                'content' => '⚠️ La phrase ne peut pas être vide.',
                'flags' => 64 // Message éphémère (visible uniquement par la personne)
            ]
        ]);
        exit;
    }

    // Récupération des phrases actuelles dans Firebase
    $urlFirebase = $config['firebase_url'];
    if (!empty($config['firebase_token'])) {
        $urlFirebase .= (str_contains($urlFirebase, '?') ? '&' : '?') . 'auth=' . urlencode($config['firebase_token']);
    }

    $jsonActuel = @file_get_contents($urlFirebase);
    $phrases = json_decode($jsonActuel, true);
    if (!is_array($phrases)) {
        $phrases = [];
    }

    // Vérification des doublons
    if (in_array($nouvellePhrase, $phrases, true)) {
        echo json_encode([
            'type' => 4,
            'data' => [
                'content' => '⚠️ La phrase « **' . htmlspecialchars($nouvellePhrase) . '** » existe déjà dans le Bingo.',
                'flags' => 64
            ]
        ]);
        exit;
    }

    // Ajout et sauvegarde dans Firebase
    $phrases[] = $nouvellePhrase;

    $ch = curl_init($urlFirebase);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode(array_values($phrases), JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $reponse = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $message = '✔ La phrase « **' . htmlspecialchars($nouvellePhrase) . '** » a été ajoutée à la base de données !';
    } else {
        $message = '✘ Erreur lors de l\'écriture dans Firebase (code HTTP ' . $code . ').';
    }

    // Réponse au serveur Discord (Type 4 = CHANNEL_MESSAGE_WITH_SOURCE)
    echo json_encode([
        'type' => 4,
        'data' => [
            'content' => $message
        ]
    ]);
    exit;
}