# Trombinoscope Touba Lyon

Application web de la communauté **Touba Lyon** : annuaire illustré des membres
(trombinoscope), jeu « Ki Kan La », inscriptions au Dahira et espace
d'administration complet.

> Application PHP / MySQL, sans framework ni dépendance externe.

---

## ✨ Fonctionnalités

- **Trombinoscope** — annuaire photo des membres validés, avec recherche et filtre (Goor Yalla / Sokhna).
- **Ki Kan La** — jeu « qui est-ce ? » basé sur les photos des membres, avec score et classement.
- **Inscription membre** (`register.php`) — création de compte avec photo et mot de passe.
- **Inscription au Dahira** (`adhesion.php`) — formulaire complet (reproduction du formulaire Google) :
  - charte à **lecture paginée obligatoire** avant acceptation,
  - photo de profil obligatoire, mot de passe,
  - validation côté client et serveur,
  - e-mail de confirmation à l'adhérent (avec la charte) + notification au secrétariat.
- **Espace administrateur** :
  - tableau de bord (membres en attente / validés, statistiques),
  - gestion des inscriptions Dahira,
  - fiche membre détaillée (`membre.php`),
  - gestion des comptes administrateurs (ajout / suppression),
  - e-mail de validation au membre (avec les fonctions et activités du Dahira).
- **Mot de passe oublié** — pour les membres **et** les administrateurs (jeton haché, lien par e-mail, expiration 1 h).
- **Interface responsive**, optimisée pour mobile.

---

## 🧱 Pile technique

- **PHP 7+** (PDO, requêtes préparées)
- **MySQL / MariaDB**
- HTML / CSS / JavaScript (vanilla, sans build)
- Envoi d'e-mails via un **client SMTP autonome** (`send_mail.php`, sans PHPMailer)

---

## 🚀 Installation

1. **Cloner le dépôt**
   ```bash
   git clone https://github.com/assanehome/ToubaLyonTrombinoscope.git
   ```

2. **Créer le fichier de secrets** (obligatoire, absent du dépôt) :
   ```bash
   cp config.secret.example.php config.secret.php
   ```
   Puis éditez `config.secret.php` avec vos identifiants **base de données** et **mot de passe SMTP**.

3. **Configurer le SMTP** (paramètres non sensibles) dans `mail_config.php`
   (serveur, port, adresse d'expéditeur, adresse du secrétariat).

4. **Initialiser la base** : ouvrez n'importe quelle page du site dans un navigateur.
   `db_setup.php` (inclus partout) crée et met à jour automatiquement les tables
   (`membres`, `admins`) et le dossier `uploads/`.

5. **Créer le premier administrateur** : ouvrez `admin_login.php` — au premier
   lancement (aucun admin en base), le **mode Configuration initiale** vous invite
   à créer un compte avec un mot de passe fort.

### Prérequis serveur
- PHP 7+ avec extensions PDO MySQL, `fileinfo`, `openssl`.
- Apache (les fichiers `.htaccess` protègent la config et le dossier `uploads/`).
  Sous **nginx**, reportez ces protections dans la configuration du serveur.
- Dossier `uploads/` accessible en écriture.

---

## 🔐 Sécurité

- **Aucun identifiant dans le code** : tout est dans `config.secret.php` (exclu du dépôt)
  ou en variables d'environnement.
- Mots de passe **hachés** (`password_hash` / bcrypt).
- **Protection CSRF** sur les formulaires sensibles (`csrf.php`).
- **Requêtes préparées** PDO partout (pas d'injection SQL).
- Sorties échappées (`htmlspecialchars`) — pas de XSS.
- Messages d'erreur génériques côté utilisateur, détails journalisés (`error_log`).
- Uploads : validation extension + type MIME réel, nom de fichier aléatoire,
  exécution de scripts bloquée dans `uploads/`.
- Scripts de maintenance (`db_update.php`, générateurs) protégés par
  authentification admin (`admin_guard.php`).

> ⚠️ Le fichier `config.secret.php` et `.vscode/sftp.json` ne doivent **jamais**
> être versionnés (déjà couverts par `.gitignore`).

---

## 📂 Fichiers clés

| Fichier | Rôle |
|---|---|
| `index.php` | Trombinoscope (accueil membre) |
| `register.php` / `login.php` | Inscription / connexion membre |
| `adhesion.php` | Formulaire d'adhésion au Dahira |
| `kikanla.php` / `play.php` | Jeu Ki Kan La |
| `profile.php` | Profil membre |
| `admin_login.php` / `admin_dashboard.php` | Espace admin |
| `admin_adhesions.php` / `membre.php` / `admin_admins.php` | Gestion adhésions / fiches / admins |
| `db_config.php` / `db_setup.php` | Connexion & schéma de base |
| `csrf.php` / `admin_guard.php` / `admin_redirect.php` | Sécurité (CSRF, accès admin) |
| `send_mail.php` / `mail_config.php` / `dahira_emails.php` | E-mails |
| `config.secret.php` | **Secrets (non versionné)** |

---

## ☁️ Déploiement

Le code est déployé sur l'hébergement via **SFTP** (le dépôt GitHub sert de
sauvegarde / gestion de versions, indépendamment du site en ligne).

> Après un `git clone`, pensez à recréer `config.secret.php` sur le serveur cible :
> il n'est volontairement pas inclus dans le dépôt.

---

## 📄 Licence

Projet privé — © Touba Lyon. Tous droits réservés.
