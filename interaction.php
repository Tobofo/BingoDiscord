<?php
ini_set('display_errors', '0'); // Désactive l'affichage HTML des erreurs/warnings
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';

// ---------- 1. Outils & Helpers Firebase ----------

function obtnirPhrasesFirebase(array $config): array {
    $url = $config['firebase_url'];
    if (!empty($config['firebase_token'])) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'auth=' . urlencode($config['firebase_token']);
    }
    $json = @file_get_contents($url);
    $donnees = json_decode($json, true);
    $valeurs = is_array($donnees) ? $donnees : [];
    if (isset($valeurs['phrases']) && is_array($valeurs['phrases'])) {
        $valeurs = $valeurs['phrases'];
    }
    $phrases = [];
    foreach ($valeurs as $valeur) {
        if (is_array($valeur)) {
            $valeur = $valeur['texte'] ?? '';
        }
        if (is_string($valeur) && trim($valeur) !== '') {
            $phrases[] = trim($valeur);
        }
    }
    return array_values(array_unique($phrases));
}

function sauvegarderPhrasesFirebase(array $phrases, array $config): bool {
    $url = $config['firebase_url'];
    if (!empty($config['firebase_token'])) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'auth=' . urlencode($config['firebase_token']);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode(array_values($phrases), JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $reponse = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300);
}

function genererEmbedPhrases(array $phrases, int $page = 1, int $parPage = 10): array {
    $total = count($phrases);
    $totalPages = max(1, (int)ceil($total / $parPage));
    $page = max(1, min($page, $totalPages));
    
    $offset = ($page - 1) * $parPage;
    $phrasesPage = array_slice($phrases, $offset, $parPage, true);

    $description = "";
    $optionsSelect = [];

    foreach ($phrasesPage as $index => $texte) {
        $num = $index + 1;
        $description .= "▸ **#{$num}** — « " . htmlspecialchars($texte) . " »\n";

        $labelTexte = (mb_strlen($texte) > 90) ? mb_substr($texte, 0, 87) . '...' : $texte;

        $optionsSelect[] = [
            'label'       => "#{$num} — {$labelTexte}",
            'value'       => (string)$index,
            'description' => 'Sélectionner pour modifier ou supprimer'
        ];
    }

    if (empty($description)) {
        $description = "*Aucune phrase enregistrée dans la base de données.*";
    }

    $components = [];

    if (!empty($optionsSelect)) {
        $components[] = [
            'type' => 1,
            'components' => [
                [
                    'type'        => 3, // STRING_SELECT
                    'custom_id'   => 'select_phrase',
                    'placeholder' => '👇 Choisis une phrase à gérer...',
                    'options'     => $optionsSelect
                ]
            ]
        ];
    }

    if ($totalPages > 1) {
        $components[] = [
            'type' => 1,
            'components' => [
                [
                    'type'      => 2,
                    'style'     => 2,
                    'label'     => '◀ Précédent',
                    'custom_id' => 'page_phrases_' . ($page - 1),
                    'disabled'  => $page <= 1
                ],
                [
                    'type'      => 2,
                    'style'     => 2,
                    'label'     => 'Suivant ▶',
                    'custom_id' => 'page_phrases_' . ($page + 1),
                    'disabled'  => $page >= $totalPages
                ]
            ]
        ];
    }

    return [
        'type' => 4, // CHANNEL_MESSAGE_WITH_SOURCE
        'data' => [
            'embeds' => [
                [
                    'title'       => '📝 Gestion des phrases du Bingo',
                    'color'       => 0x5865F2,
                    'description' => $description,
                    'footer'      => [
                        'text' => "Page {$page}/{$totalPages} • Total : {$total} phrases"
                    ],
                    'timestamp'   => date('c')
                ]
            ],
            'components' => $components
        ]
    ];
}

function repondreSelectionPhrase(array $phrases, int $index): array {
    $texte = $phrases[$index] ?? 'Phrase introuvable';
    $num = $index + 1;

    return [
        'type' => 4,
        'data' => [
            'flags'  => 64, // EPHEMERAL
            'embeds' => [
                [
                    'title'       => "⚙️ Action sur la phrase #{$num}",
                    'color'       => 0xFEE75C,
                    'description' => "Phrase sélectionnée :\n> « **" . htmlspecialchars($texte) . "** »\n\nQue souhaites-tu faire ?",
                ]
            ],
            'components' => [
                [
                    'type' => 1,
                    'components' => [
                        [
                            'type'      => 2,
                            'style'     => 1, // PRIMARY (Bleu)
                            'label'     => '✏️ Modifier',
                            'custom_id' => "btn_mod_{$index}"
                        ],
                        [
                            'type'      => 2,
                            'style'     => 4, // DANGER (Rouge)
                            'label'     => '🗑️ Supprimer',
                            'custom_id' => "btn_supp_{$index}"
                        ]
                    ]
                ]
            ]
        ]
    ];
}

// ---------- 2. Vérification de la signature Discord ----------

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
$type = $data['type'] ?? 0;

// Ping de validation Discord (Type 1)
if ($type === 1) {
    echo json_encode(['type' => 1]);
    exit;
}

// ---------- 3. Traitement des Commandes Slash (Type 2) ----------

if ($type === 2) {
    $commandName = $data['data']['name'] ?? '';

    // Commande /ajouter-phrase
    if ($commandName === 'ajouter-phrase') {
        $nouvellePhrase = trim($data['data']['options'][0]['value'] ?? '');

        if ($nouvellePhrase === '') {
            echo json_encode([
                'type' => 4,
                'data' => [
                    'content' => '⚠️ La phrase ne peut pas être vide.',
                    'flags' => 64
                ]
            ]);
            exit;
        }

        $phrases = obtnirPhrasesFirebase($config);

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

        $phrases[] = $nouvellePhrase;
        $succes = sauvegarderPhrasesFirebase($phrases, $config);

        $message = $succes 
            ? '✔ La phrase « **' . htmlspecialchars($nouvellePhrase) . '** » a été ajoutée à la base de données !'
            : '✘ Erreur lors de l\'écriture dans Firebase.';

        echo json_encode([
            'type' => 4,
            'data' => ['content' => $message]
        ]);
        exit;
    }

    // Commande /cooldown-flash
    if ($commandName === 'cooldown-flash') {
        $secondes = (int)($data['data']['options'][0]['value'] ?? 1);
        $secondes = max(0, min(5, $secondes));

        $fichierCooldown = __DIR__ . '/data/cooldown.json';
        file_put_contents($fichierCooldown, json_encode(['cooldown' => $secondes]));

        echo json_encode([
            'type' => 4,
            'data' => [
                'content' => "⏱️ Le cooldown des flashs est désormais fixé à **{$secondes}s** !"
            ]
        ]);
        exit;
    }

    // Commande /list-phrases
    if ($commandName === 'list-phrases') {
        $phrases = obtnirPhrasesFirebase($config);
        echo json_encode(genererEmbedPhrases($phrases, 1));
        exit;
    }
}

// ---------- 4. Composants Interactifs : Menu & Boutons (Type 3) ----------

if ($type === 3) {
    $customId = $data['data']['custom_id'] ?? '';

    // Sélection dans le menu déroulant
    if ($customId === 'select_phrase') {
        $indexSelect = (int)($data['data']['values'][0] ?? -1);
        $phrases = obtnirPhrasesFirebase($config);
        echo json_encode(repondreSelectionPhrase($phrases, $indexSelect));
        exit;
    }

    // Pagination (Précédent / Suivant)
    if (str_starts_with($customId, 'page_phrases_')) {
        $page = (int)str_replace('page_phrases_', '', $customId);
        $phrases = obtnirPhrasesFirebase($config);
        
        $embedData = genererEmbedPhrases($phrases, $page);
        $embedData['type'] = 7; // UPDATE_MESSAGE
        echo json_encode($embedData);
        exit;
    }

    // Clic sur "Supprimer"
    if (str_starts_with($customId, 'btn_supp_')) {
        $index = (int)str_replace('btn_supp_', '', $customId);
        $phrases = obtnirPhrasesFirebase($config);

        if (isset($phrases[$index])) {
            unset($phrases[$index]);
            sauvegarderPhrasesFirebase($phrases, $config);

            echo json_encode([
                'type' => 7,
                'data' => [
                    'embeds' => [[
                        'title'       => '🗑️ Phrase supprimée',
                        'color'       => 0xED4245,
                        'description' => 'La phrase #' . ($index + 1) . ' a été retirée de la BDD.'
                    ]],
                    'components' => []
                ]
            ]);
        } else {
            echo json_encode([
                'type' => 4,
                'data' => ['content' => '❌ Phrase introuvable.', 'flags' => 64]
            ]);
        }
        exit;
    }

    // Clic sur "Modifier" (Ouvre la Modale)
    if (str_starts_with($customId, 'btn_mod_')) {
        $index = (int)str_replace('btn_mod_', '', $customId);
        $phrases = obtnirPhrasesFirebase($config);
        $ancienTexte = $phrases[$index] ?? '';

        echo json_encode([
            'type' => 9, // MODAL
            'data' => [
                'title'     => "Modifier la phrase #" . ($index + 1),
                'custom_id' => "modal_mod_{$index}",
                'components' => [
                    [
                        'type' => 1,
                        'components' => [
                            [
                                'type'        => 4, // TEXT_INPUT
                                'custom_id'   => 'nouveau_texte',
                                'label'       => 'Texte de la phrase',
                                'style'       => 2, // PARAGRAPH
                                'value'       => $ancienTexte,
                                'required'    => true,
                                'max_length'  => 200
                            ]
                        ]
                    ]
                ]
            ]
        ]);
        exit;
    }
}

// ---------- 5. Soumission des Modales (Type 5) ----------

if ($type === 5) {
    $customId = $data['data']['custom_id'] ?? '';

    if (str_starts_with($customId, 'modal_mod_')) {
        $index = (int)str_replace('modal_mod_', '', $customId);
        $nouveauTexte = trim($data['data']['components'][0]['components'][0]['value'] ?? '');

        if ($nouveauTexte === '') {
            echo json_encode([
                'type' => 4,
                'data' => ['content' => '⚠️ La phrase ne peut pas être vide.', 'flags' => 64]
            ]);
            exit;
        }

        $phrases = obtnirPhrasesFirebase($config);

        if (isset($phrases[$index])) {
            $phrases[$index] = $nouveauTexte;
            sauvegarderPhrasesFirebase($phrases, $config);

            echo json_encode([
                'type' => 4,
                'data' => [
                    'content' => "✅ La phrase #" . ($index + 1) . " a bien été modifiée : « **" . htmlspecialchars($nouveauTexte) . "** »",
                    'flags'   => 64
                ]
            ]);
        } else {
            echo json_encode([
                'type' => 4,
                'data' => ['content' => '❌ Phrase introuvable.', 'flags' => 64]
            ]);
        }
        exit;
    }
}

// Action par défaut
echo json_encode(['type' => 4, 'data' => ['content' => 'Interaction non reconnue.']]);