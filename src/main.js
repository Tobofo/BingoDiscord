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

const montrerErreur = (e) => {
  messagePoll = false;
  $("message").textContent = e.message;
};

// ---------- Affichage ----------

function afficher() {
  afficherMaGrille();
  afficherJoueurs();
  afficherPhrases();
  afficherVote();
  afficherAnnonce();
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
      zone.appendChild(div);
    });
  }
  etat.grille.forEach((numero, i) => {
    zone.children[i].classList.toggle("cochee", etat.valides.includes(numero));
  });
  // On ne peut plus changer de grille une fois la partie commencée
  $("nouvelle").disabled = etat.valides.length > 0;
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

// Toutes les phrases de la base : un clic lance un vote, les validées sont grisées
function afficherPhrases() {
  const zone = $("phrases");
  if (zone.children.length !== etat.phrases.length) {
    zone.innerHTML = "";
    etat.phrases.forEach((texte, i) => {
      const div = document.createElement("div");
      div.className = "phrase";
      div.textContent = texte;
      div.addEventListener("click", () => proposer(i));
      zone.appendChild(div);
    });
  }
  [...zone.children].forEach((div, i) => {
    div.classList.toggle("validee", etat.valides.includes(i));
    div.classList.toggle("vote-en-cours", !!etat.vote && etat.vote.phrase === i);
  });
}

// Fenêtre de vote
function afficherVote() {
  const v = etat.vote;
  $("vote").hidden = !v;
  if (!v) return;
  $("vote-texte").textContent = etat.phrases[v.phrase];
  $("vote-compteur").textContent =
    `Proposé par ${v.parNom} · ${v.oui}/${v.total} d'accord · ${v.restant} s`;
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

  const d = etat.dernier;
  const texte = etat.phrases[d.phrase];
  const zone = $("annonce");
  zone.className = "annonce " + (d.resultat === "ok" ? "ok" : "ko");
  zone.textContent =
    d.resultat === "ok" ? `✔ Validée : « ${texte} »`
    : d.resultat === "refuse" ? `✘ Vote refusé : « ${texte} »`
    : `⏱ Temps écoulé : « ${texte} »`;
  zone.hidden = false;
  clearTimeout(minuteurAnnonce);
  minuteurAnnonce = setTimeout(() => (zone.hidden = true), 5000);
}

// ---------- Actions ----------

function proposer(numero) {
  if (etat.valides.includes(numero)) return;
  $("message").textContent = "";
  if (etat.vote) {
    montrerErreur(new Error("Un vote est déjà en cours."));
    return;
  }
  jeu("proposer", { phrase: numero }).catch(montrerErreur);
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
    if (messagePoll) {
      $("message").textContent = "";
      messagePoll = false;
    }
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
  } catch (e) {
    montrerErreur(e);
  }
}

$("nouvelle").addEventListener("click", () => {
  $("message").textContent = "";
  jeu("nouvelle").catch(montrerErreur);
});
$("oui").addEventListener("click", () => voter(true));
$("non").addEventListener("click", () => voter(false));

main();