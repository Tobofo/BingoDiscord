<?php
// ---------------------------------------------------------------
// API de l'activité : ?action=token | etat | proposer | relancer | voter
//
// Le serveur est l'arbitre : il tire les grilles, gère les votes et calcule
// lui-même les cases cochées. Les joueurs ne peuvent donc pas se les cocher.
// L'état de chaque partie est dans data/<instance>.json
// ---------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';

const DUREE_VOTE = 30;   // secondes pour voter
const INACTIF    = 20;   // secondes sans nouvelles => joueur retiré de la partie
const PAUSE_VICTOIRE = 10;   // secondes d'affichage du gagnant avant la manche suivante

function repondre(array $donnees, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

// Corps JSON envoyé par le navigateur, sous forme de tableau
function corpsJson(): array
{
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Garde les 32 premiers caractères (UTF-8), sans dépendre de l'extension mbstring
function nomCourt(string $nom): string
{
    preg_match('/^.{0,32}/su', $nom, $m);
    return $m[0] ?? 'Joueur';
}

// Refus « normal » d'une règle du jeu (message affiché au joueur)
function refuser(string $message): never
{
    throw new RuntimeException($message, 409);
}

// ---------- 1) Connexion : échange du "code" Discord contre un access_token ----------
if ($action === 'token') {
    $contexte = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: BingoActivity (php)\r\n",
        'content'       => http_build_query([
            'client_id'     => $config['discord_client_id'],
            'client_secret' => $config['discord_client_secret'],
            'grant_type'    => 'authorization_code',
            'code'          => corpsJson()['code'] ?? '',
        ]),
        'ignore_errors' => true,
    ]]);
    $reponse = @file_get_contents('https://discord.com/api/oauth2/token', false, $contexte);
    if ($reponse === false) {
        repondre(['erreur' => "Discord injoignable depuis PHP : " . (error_get_last()['message'] ?? 'erreur inconnue')], 500);
    }
    $token = json_decode($reponse, true)['access_token'] ?? null;
    if (!$token) {
        repondre(['erreur' => "Discord a refusé l'authentification (Client ID / secret corrects ?)."], 401);
    }
    repondre(['access_token' => $token]);
}

// ---------- Outils du jeu ----------

// Lit la liste des phrases dans Firebase (nettoyée, sans doublons)
function chargerPhrases(array $config): array
{
    $url = $config['firebase_url'];   // adresse complète de la liste, terminée par .json
    if (!empty($config['firebase_token'])) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'auth=' . urlencode($config['firebase_token']);
    }
    $json = @file_get_contents($url);
    if ($json === false) {
        throw new RuntimeException("Impossible de lire Firebase : " . (error_get_last()['message'] ?? 'erreur inconnue'), 500);
    }
    $donnees = json_decode($json, true);
    $valeurs = is_array($donnees) ? $donnees : [];
    // Accepte aussi une liste rangée sous une clé « phrases »
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
    $phrases = array_values(array_unique($phrases));
    if (count($phrases) < 25) {
        throw new RuntimeException("Il faut au moins 25 phrases différentes dans la base (il y en a " . count($phrases) . ").", 500);
    }
    return $phrases;
}

// Ouvre le fichier de la partie (verrouillé), exécute $traitement, puis sauvegarde
function avecPartie(string $instance, callable $traitement): array
{
    @mkdir(__DIR__ . '/data');
    $h = fopen(__DIR__ . '/data/' . $instance . '.json', 'c+');
    flock($h, LOCK_EX);

    $partie = json_decode(stream_get_contents($h), true);
    if (!is_array($partie) || !isset($partie['joueurs'], $partie['valides'])) {
        $partie = ['phrases' => [], 'joueurs' => [], 'valides' => [], 'vote' => null, 'dernier' => null, 'numero' => 0, 'victoire' => null];
    }

    try {
        return $traitement($partie);
    } finally {
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($partie, JSON_UNESCAPED_UNICODE));
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// Tire 25 phrases (leurs numéros) parmi celles qui ne sont pas déjà validées
function tirerGrille(array $p): array
{
    $dispo = array_values(array_diff(array_keys($p['phrases']), $p['valides']));
    if (count($dispo) < 25) {
        refuser("Il ne reste pas assez de phrases pour te faire une grille.");
    }
    shuffle($dispo);
    return array_slice($dispo, 0, 25);
}

// Une ligne complète ? (5 lignes, 5 colonnes ou 2 diagonales de la grille 5x5)
function aUneLigne(string $masque): bool
{
    if (strlen($masque) < 25) {
        return false;
    }
    $lignes = [[0, 6, 12, 18, 24], [4, 8, 12, 16, 20]];
    for ($i = 0; $i < 5; $i++) {
        $ligne = [];
        $colonne = [];
        for ($j = 0; $j < 5; $j++) {
            $ligne[]   = $i * 5 + $j;
            $colonne[] = $j * 5 + $i;
        }
        $lignes[] = $ligne;
        $lignes[] = $colonne;
    }
    foreach ($lignes as $cases) {
        $complete = true;
        foreach ($cases as $c) {
            if ($masque[$c] !== '1') {
                $complete = false;
                break;
            }
        }
        if ($complete) {
            return true;
        }
    }
    return false;
}

// Nouvelle manche : plus aucune phrase validée, nouvelle grille pour chaque joueur
function nouvellePartie(array &$p): void
{
    $p['valides']  = [];
    $p['vote']     = null;
    $p['victoire'] = null;
    foreach ($p['joueurs'] as $id => $_) {
        $p['joueurs'][$id]['grille'] = tirerGrille($p);
    }
}

// Retire les joueurs inactifs et conclut le vote s'il est terminé
function actualiser(array &$p): void
{
    $maintenant = time();

    foreach ($p['joueurs'] as $id => $j) {
        if ($j['vu'] < $maintenant - INACTIF) {
            unset($p['joueurs'][$id]);
        }
    }

    // Un joueur vient de gagner : on attend la fin de l'affichage, puis nouvelle manche
    if (!empty($p['victoire'])) {
        if ($maintenant >= $p['victoire']['fin']) {
            nouvellePartie($p);
        }
        return;
    }

    if (empty($p['vote'])) {
        return;
    }
    $v = $p['vote'];

    // Électeurs = joueurs présents au début du vote et toujours connectés
    $electeurs = array_values(array_filter($v['electeurs'], fn($x) => isset($p['joueurs'][$x])));
    $oui = 0;
    $non = 0;
    foreach ($electeurs as $x) {
        $choix = $v['votes'][$x] ?? null;
        if ($choix === true) {
            $oui++;
        } elseif ($choix === false) {
            $non++;
        }
    }

    // Majorité stricte des électeurs présents (ex. 2 sur 3, 3 sur 4, 3 sur 5)
    $total    = count($electeurs);
    $requis   = intdiv($total, 2) + 1;
    $resultat = null;
    if ($total > 0 && $oui >= $requis) {
        $resultat = 'ok';                                   // la majorité est atteinte
    } elseif ($total > 0 && $total - $non < $requis) {
        $resultat = 'refuse';                               // la majorité n'est plus possible
    } elseif ($total === 0 || $maintenant - $v['debut'] > DUREE_VOTE) {
        $resultat = 'expire';                               // temps écoulé
    }

    if ($resultat !== null) {
        $type = $v['type'] ?? 'phrase';
        if ($resultat === 'ok' && $type === 'partie') {
            nouvellePartie($p);                 // nouvelle grille pour tout le monde
        } elseif ($resultat === 'ok') {
            $p['valides'][] = $v['phrase'];

            // Tous les joueurs qui viennent de compléter une ligne gagnent (égalité possible)
            $gagnants = [];
            foreach ($p['joueurs'] as $jid => $j) {
                if (aUneLigne(masque($j, $p['valides']))) {
                    $gagnants[] = ['id' => (string)$jid, 'nom' => $j['nom']];
                }
            }
            if ($gagnants) {
                $p['victoire'] = ['gagnants' => $gagnants, 'fin' => $maintenant + PAUSE_VICTOIRE];
            }
        }
        $p['numero']++;
        $p['dernier'] = ['numero' => $p['numero'], 'type' => $type, 'phrase' => $v['phrase'] ?? null, 'resultat' => $resultat];
        $p['vote'] = null;
    }
}

// Lance un vote (sur une phrase, ou sur une nouvelle partie) ; celui qui le lance vote « oui »
function lancerVote(array &$p, string $id, string $type, ?int $phrase = null): void
{
    if (!empty($p['victoire'])) {
        refuser("La partie est terminée : une nouvelle manche démarre dans quelques secondes.");
    }
    if (!empty($p['vote'])) {
        $v = $p['vote'];
        $memeVote = ($v['type'] ?? 'phrase') === $type && ($v['phrase'] ?? null) === $phrase;
        if (!$memeVote) {
            refuser("Un vote est déjà en cours.");
        }
        // Même vote déjà lancé : le clic compte comme un « oui » de ce joueur (pas d'erreur)
        if (in_array($id, $v['electeurs'], true) && !isset($v['votes'][$id])) {
            $p['vote']['votes'][$id] = true;
            actualiser($p);
        }
        return;
    }
    $p['vote'] = [
        'id'        => bin2hex(random_bytes(4)),
        'type'      => $type,
        'phrase'    => $phrase,
        'par'       => $id,
        'debut'     => time(),
        'electeurs' => array_map('strval', array_keys($p['joueurs'])),
        'votes'     => [$id => true],
    ];
    actualiser($p);   // s'il est seul, le vote est conclu tout de suite
}

// Crée le joueur au besoin, note qu'il est présent et lui donne une grille
function assurerJoueur(array &$p, string $id, string $nom, array $config): void
{
    if (empty($p['phrases'])) {
        $p['phrases'] = chargerPhrases($config);
    }
    if (!isset($p['joueurs'][$id])) {
        $p['joueurs'][$id] = ['nom' => $nom, 'grille' => null, 'vu' => time()];
    }
    $p['joueurs'][$id]['nom'] = $nom;
    $p['joueurs'][$id]['vu']  = time();
    if (empty($p['joueurs'][$id]['grille'])) {
        $p['joueurs'][$id]['grille'] = tirerGrille($p);
    }
}

// Cases cochées d'un joueur : "0"/"1" pour chacune de ses 25 cases
function masque(array $joueur, array $valides): string
{
    $s = '';
    foreach ($joueur['grille'] ?? [] as $numero) {
        $s .= in_array($numero, $valides, true) ? '1' : '0';
    }
    return $s;
}

// Ce que le navigateur du joueur $id a besoin de savoir
function etatPour(array $p, string $id): array
{
    $joueurs = [];
    foreach ($p['joueurs'] as $jid => $j) {
        $joueurs[] = ['id' => (string)$jid, 'nom' => $j['nom'], 'masque' => masque($j, $p['valides'])];
    }

    $vote = null;
    if (!empty($p['vote'])) {
        $v = $p['vote'];
        $electeurs = array_values(array_filter($v['electeurs'], fn($x) => isset($p['joueurs'][$x])));
        $oui = 0;
        foreach ($electeurs as $x) {
            if (($v['votes'][$x] ?? null) === true) {
                $oui++;
            }
        }
        $vote = [
            'id'      => $v['id'],
            'type'    => $v['type'] ?? 'phrase',
            'phrase'  => $v['phrase'] ?? null,
            'parNom'  => $p['joueurs'][$v['par']]['nom'] ?? '',
            'oui'     => $oui,
            'total'   => count($electeurs),
            'requis'  => intdiv(count($electeurs), 2) + 1,
            'electeur' => in_array($id, $electeurs, true),
            'monVote' => $v['votes'][$id] ?? null,
            'restant' => max(0, DUREE_VOTE - (time() - $v['debut'])),
        ];
    }

    $victoire = null;
    if (!empty($p['victoire'])) {
        $victoire = [
            'gagnants' => $p['victoire']['gagnants'],
            'restant'  => max(0, $p['victoire']['fin'] - time()),
        ];
    }

    return [
        'phrases' => $p['phrases'],
        'grille'  => $p['joueurs'][$id]['grille'],
        'joueurs' => $joueurs,
        'valides' => $p['valides'],
        'vote'    => $vote,
        'dernier' => $p['dernier'],
        'victoire' => $victoire,
    ];
}

// ---------- 2) Actions de jeu ----------
if (in_array($action, ['etat', 'proposer', 'relancer', 'voter'], true)) {
    $e        = corpsJson();
    $instance = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($e['instance'] ?? ''));
    $id       = preg_replace('/[^0-9]/', '', (string)($e['id'] ?? ''));
    if ($instance === '' || $id === '') {
        repondre(['erreur' => 'Paramètres manquants.'], 400);
    }
    $nom = nomCourt(trim((string)($e['nom'] ?? '')) ?: 'Joueur');

    try {
        $etat = avecPartie($instance, function (array &$p) use ($action, $e, $id, $nom, $config): array {
            actualiser($p);
            assurerJoueur($p, $id, $nom, $config);

            switch ($action) {
                case 'proposer':
                    $phrase = (int)($e['phrase'] ?? -1);
                    if (!isset($p['phrases'][$phrase])) {
                        refuser("Phrase inconnue.");
                    }
                    if (in_array($phrase, $p['valides'], true)) {
                        refuser("Cette phrase est déjà validée.");
                    }
                    lancerVote($p, $id, 'phrase', $phrase);
                    break;

                case 'relancer':
                    lancerVote($p, $id, 'partie');
                    break;

                case 'voter':
                    $v = $p['vote'] ?? null;
                    if ($v && $v['id'] === (string)($e['vote'] ?? '') && in_array($id, $v['electeurs'], true)) {
                        $p['vote']['votes'][$id] = (bool)($e['choix'] ?? false);
                        actualiser($p);
                    }
                    break;
            }

            return etatPour($p, $id);
        });
    } catch (RuntimeException $ex) {
        repondre(['erreur' => $ex->getMessage()], $ex->getCode() ?: 500);
    }
    repondre($etat);
}

repondre(['erreur' => 'Action inconnue.'], 404);