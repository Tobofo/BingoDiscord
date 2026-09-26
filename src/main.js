import { DiscordSDK } from "@discord/embedded-app-sdk";

const CLIENT_ID = document.querySelector('meta[name="client-id"]').content;
const $ = (id) => document.getElementById(id);

let sdk = null;
let moi = null;                 // l'utilisateur Discord
let etat = null;                // dernier état reçu du serveur
let enCours = false;            // évite deux rafraîchissements en même temps
let messagePoll = false;        // le message affiché vient-il d'un rafraîchissement ?
let signatureGrille = "";
let dernierVu = null;           // numéro du dernier résultat de vote déjà annoncé
let minuteurAnnonce = null;
let finVictoire = null;         // heure de fin d'affichage du gagnant (compte à rebours)

// Appel de notre API PHP (POST avec un corps JSON)
async function api(action, corps) {
  const reponse = await fetch(`/api.php?action=${action}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(corps),
  });
  const donnees = await reponse.json();
  if (!reponse.ok) throw new Error(donnees.erreur || "Erreur serveur");
  return donnees;
}

// Envoie une action de jeu et affiche le nouvel état renvoyé
async function jeu(action, extra = {}) {
  etat = await api(action, {
    instance: sdk.instanceId,
    id: moi.id,
    nom: moi.global_name || moi.username,
    ...extra,
  });
  afficher();
}

// ---------- Alerte (avec une croix pour la fermer) ----------

function afficherMessage(texte) {
  $("message").textContent = texte;
  $("alerte").hidden = false;
}

function effacerMessage() {
  $("alerte").hidden = true;
  $("message").textContent = "";
  messagePoll = false;
}

const montrerErreur = (e) => {
  afficherMessage(e.message);
  messagePoll = false;
};

// ---------- Affichage ----------

function afficher() {
  afficherMaGrille();
  afficherJoueurs();
  afficherPhrases();
  afficherVote();
  afficherAnnonce();
  afficherVictoire();
  // Nouvelle partie : bloquée pendant l'affichage du gagnant ou un vote sur une phrase
  // (pendant un vote « nouvelle partie », un clic compte comme un « oui »)
  $("relancer").disabled = !!etat.victoire || (!!etat.vote && etat.vote.type !== "partie");
}

// Ma grille : les cases se cochent toutes seules quand un vote réussit
function afficherMaGrille() {
  const zone = $("grille");
  const signature = etat.grille.join(",");
  if (signature !== signatureGrille) {
    signatureGrille = signature;
    zone.innerHTML = "";
    etat.grille.forEach((numero) => {
      const div = document.createElement("div");
      div.className = "case";
      div.textContent = etat.phrases[numero];
      div.addEventListener("click", () => proposer(numero));
      zone.appendChild(div);
    });
  }
  etat.grille.forEach((numero, i) => {
    zone.children[i].classList.toggle("cochee", etat.valides.includes(numero));
  });
}

// Une mini-grille 5x5 par autre joueur : cases rouges = cases cochées
function afficherJoueurs() {
  const zone = $("joueurs");
  zone.innerHTML = "";
  etat.joueurs
    .filter((j) => j.id !== String(moi.id))
    .forEach((j) => {
      const carte = document.createElement("div");
      carte.className = "joueur";

      const mini = document.createElement("div");
      mini.className = "mini";
      for (let i = 0; i < 25; i++) {
        const c = document.createElement("span");
        if (j.masque[i] === "1") c.className = "on";
        mini.appendChild(c);
      }

      const nom = document.createElement("small");
      nom.textContent = j.nom;

      carte.append(mini, nom);
      zone.appendChild(carte);
    });
  if (!zone.children.length) zone.textContent = "En attente d'autres joueurs…";
}

// Sans accents ni majuscules, pour que la recherche soit tolérante
const normaliser = (texte) =>
  texte.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

// Toutes les phrases de la base : un clic lance un vote, les validées sont grisées
function afficherPhrases() {
  const zone = $("phrases");
  if (zone.children.length !== etat.phrases.length) {
    zone.innerHTML = "";
    etat.phrases.forEach((texte, i) => {
      const div = document.createElement("div");
      div.className = "phrase";
      div.textContent = texte;
      div.dataset.recherche = normaliser(texte);
      div.addEventListener("click", () => proposer(i));
      zone.appendChild(div);
    });
    appliquerRecherche();
  }
  [...zone.children].forEach((div, i) => {
    const rang = etat.valides.indexOf(i);
    div.classList.toggle("validee", rang !== -1);
    // Les phrases validées descendent en bas de la liste (la dernière validée tout en bas)
    div.style.order = rang === -1 ? "0" : String(rang + 1);
    div.classList.toggle(
      "vote-en-cours",
      !!etat.vote && etat.vote.type === "phrase" && etat.vote.phrase === i
    );
  });
}

// Cache les phrases qui ne correspondent pas à la recherche
function appliquerRecherche() {
  const mot = normaliser($("recherche").value.trim());
  const phrases = [...$("phrases").children];
  let visibles = 0;
  phrases.forEach((div) => {
    const visible = div.dataset.recherche.includes(mot);
    div.hidden = !visible;
    if (visible) visibles++;
  });
  $("aucune").hidden = visibles > 0 || phrases.length === 0;
}

// Fenêtre de vote (une phrase, ou une nouvelle partie)
function afficherVote() {
  const v = etat.vote;
  $("vote").hidden = !v;
  if (!v) return;
  const partie = v.type === "partie";
  $("vote-titre").textContent = partie ? "Nouvelle partie ?" : "Tout le monde a vu / entendu ?";
  $("vote-texte").textContent = partie ? "Nouvelle grille pour tout le monde" : etat.phrases[v.phrase];
  $("vote-compteur").textContent =
    `Proposé par ${v.parNom} · ${v.oui}/${v.requis} pour valider · ${v.restant} s`;
  const peutVoter = v.electeur && v.monVote === null;
  $("vote-boutons").hidden = !peutVoter;
  $("vote-attente").hidden = peutVoter;
}

// Résultat du dernier vote, affiché quelques secondes
function afficherAnnonce() {
  const numero = etat.dernier ? etat.dernier.numero : 0;
  if (dernierVu === null) {          // premier affichage : on ignore un ancien résultat
    dernierVu = numero;
    return;
  }
  if (numero === dernierVu) return;
  dernierVu = numero;
  if (etat.victoire) return;         // la fenêtre de victoire prend le relais

  const d = etat.dernier;
  const partie = d.type === "partie";
  const sujet = partie ? "nouvelle partie" : `« ${etat.phrases[d.phrase]} »`;
  const zone = $("annonce");
  zone.className = "annonce " + (d.resultat === "ok" ? "ok" : "ko");
  zone.textContent =
    d.resultat === "ok" ? (partie ? "✔ Nouvelle partie lancée !" : `✔ Validée : ${sujet}`)
    : d.resultat === "refuse" ? `✘ Vote refusé : ${sujet}`
    : `⏱ Temps écoulé : ${sujet}`;
  zone.hidden = false;
  clearTimeout(minuteurAnnonce);
  minuteurAnnonce = setTimeout(() => (zone.hidden = true), 5000);
}

// Fenêtre « X a gagné ! » avec compte à rebours avant la manche suivante
function afficherVictoire() {
  const v = etat.victoire;
  $("victoire").hidden = !v;
  if (!v) {
    finVictoire = null;
    return;
  }
  if (finVictoire === null) {        // la fenêtre vient d'apparaître : son + compte à rebours
    finVictoire = Date.now() + v.restant * 1000;
    jouerSon("sons/victoire.mp3");
  }
  const noms = v.gagnants.map((g) => g.nom);
  const liste = noms.length > 1
    ? `${noms.slice(0, -1).join(", ")} et ${noms[noms.length - 1]}`
    : noms[0];
  $("victoire-titre").textContent = noms.length > 1 ? `${liste} ont gagné !` : `${liste} a gagné !`;
  $("victoire-sous-titre").textContent = v.gagnants.some((g) => g.id === String(moi.id))
    ? "Bravo, c'est toi ! 🎉"
    : "Une ligne complète !";
  majCompteVictoire();
}

function majCompteVictoire() {
  if (finVictoire === null) return;
  const s = Math.max(0, Math.ceil((finVictoire - Date.now()) / 1000));
  $("victoire-compte").textContent = `Nouvelle partie dans ${s} s`;
}

// ---------- Son : coupé / activé, mémorisé sur cet appareil ----------

const CLE_SON = "bingo-son-coupe";
let sonCoupe = localStorage.getItem(CLE_SON) === "1";

function afficherBoutonSon() {
  $("son").textContent = sonCoupe ? "🔇" : "🔊";
  $("son").title = sonCoupe ? "Réactiver le son" : "Couper le son";
  $("son").setAttribute("aria-label", $("son").title);
}

function basculerSon() {
  sonCoupe = !sonCoupe;
  localStorage.setItem(CLE_SON, sonCoupe ? "1" : "0");
  afficherBoutonSon();
}

// Joue un son du dossier sons/ (ignoré sans erreur si le fichier manque,
// si le son est coupé, ou si le navigateur bloque le son)
function jouerSon(fichier) {
  if (sonCoupe) return;
  const audio = new Audio(fichier);
  audio.volume = 0.6;               // de 0 (muet) à 1 (fort)
  audio.play().catch(() => {});
}

// ---------- Actions ----------

function proposer(numero) {
  if (etat.valides.includes(numero)) return;
  effacerMessage();
  // Cliquer sur la phrase déjà soumise au vote compte comme un « oui » (géré par le serveur)
  const memeVote = etat.vote && etat.vote.type === "phrase" && etat.vote.phrase === numero;
  if (etat.vote && !memeVote) {
    montrerErreur(new Error("Un vote est déjà en cours."));
    return;
  }
  jeu("proposer", { phrase: numero }).catch(montrerErreur);
}

// Lance un vote pour recommencer avec de nouvelles grilles
function relancer() {
  effacerMessage();
  jeu("relancer").catch(montrerErreur);
}

function voter(choix) {
  if (!etat.vote) return;
  jeu("voter", { vote: etat.vote.id, choix }).catch(montrerErreur);
}

// Rafraîchit l'état toutes les 2 secondes (grilles des autres, votes…)
async function rafraichir() {
  if (enCours) return;
  enCours = true;
  try {
    await jeu("etat");
    if (messagePoll) effacerMessage();
  } catch (e) {
    montrerErreur(e);
    messagePoll = true;
  } finally {
    enCours = false;
  }
}

async function main() {
  try {
    sdk = new DiscordSDK(CLIENT_ID);
    await sdk.ready();

    // Connexion du joueur : Discord donne un "code", PHP l'échange contre un token
    const { code } = await sdk.commands.authorize({
      client_id: CLIENT_ID,
      response_type: "code",
      state: "",
      prompt: "none",
      scope: ["identify"],
    });
    const { access_token } = await api("token", { code });
    const auth = await sdk.commands.authenticate({ access_token });
    moi = auth.user;

    await jeu("etat");
    setInterval(rafraichir, 2000);
    setInterval(majCompteVictoire, 250);
  } catch (e) {
    montrerErreur(e);
  }
}

$("fermer-alerte").addEventListener("click", effacerMessage);
$("relancer").addEventListener("click", relancer);
$("oui").addEventListener("click", () => voter(true));
$("non").addEventListener("click", () => voter(false));
$("recherche").addEventListener("input", appliquerRecherche);
$("son").addEventListener("click", basculerSon);
afficherBoutonSon();

// Numéro de version (bas droite), mis à jour par GitHub Actions à chaque push
fetch("version.json")
  .then((r) => r.json())
  .then((v) => {
    $("version").textContent = `${v.version} · ${v.commit}`;
    $("version").title = `Publié le ${v.date}`;
  })
  .catch(() => {});   // pas grave si le fichier est absent

main();