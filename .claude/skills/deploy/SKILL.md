---
name: deploy
description: Déploie les fichiers modifiés du Trombinoscope vers le serveur Strato en SFTP (gère les placeholders OneDrive), puis vérifie les tailles distantes. À utiliser quand l'utilisateur dit "synchro sftp", "déploie", "mets en ligne", "push le site".
---

# Déploiement SFTP — Trombinoscope Touba Lyon

Déploie le site sur l'hébergement Strato via WinSCP. **Ne jamais écrire le mot de passe
dans un fichier versionné** : il est lu à l'exécution depuis `.vscode/sftp.json`
(exclu de git).

## Contexte

- Config SFTP (host, username, password, remotePath) : `.vscode/sftp.json`.
- Outil : `%LOCALAPPDATA%\Programs\WinSCP\WinSCP.com`.
- ⚠️ Le dossier projet est synchronisé OneDrive : les fichiers sont des placeholders
  (`ReparsePoint`). WinSCP peut les lire à **0 octet** s'ils ne sont pas matérialisés.
  → Toujours **hydrater** chaque fichier (copie du contenu octet par octet dans un
  dossier temp réel) avant l'envoi.
- Site en ligne : https://toubalyon.com/Dahira

## Procédure

1. **Déterminer les fichiers à envoyer.**
   - Si l'utilisateur en précise, utiliser ceux-là.
   - Sinon, utiliser les fichiers modifiés/non suivis (hors ignorés) :
     `git -C "<projet>" status --porcelain` (ignorer `.vscode/`, `.claude/`, `uploads/`, secrets).
   - Toujours **exclure** de l'envoi : `config.secret.php`, `.vscode/`, `.claude/`, `uploads/`, `*.log`.

2. **Lire la config** SFTP depuis `.vscode/sftp.json` (host, username, password, remotePath)
   avec PowerShell `ConvertFrom-Json`. Ne pas afficher le mot de passe.

3. **Hydrater** chaque fichier vers un dossier temp réel :
   `[IO.File]::WriteAllBytes($tempFile, [IO.File]::ReadAllBytes($sourceFile))`.

4. **Envoyer** via un script WinSCP (fichier temp, jamais inline dans une commande
   contenant `Remove-Item` — le garde du shell bloque `Remove-Item` + chemin distant
   dans la même commande) :
   ```
   option batch abort
   option confirm off
   open sftp://<username>@<host>:<port>/ -password="<password>" -hostkey="*"
   put -neweronly=off "<tempFile>" "<remotePath>/<name>"   # une ligne par fichier
   ls "<remotePath>/"
   exit
   ```

5. **Vérifier** : comparer la taille distante (sortie du `ls`) à la taille locale de
   chaque fichier. Signaler tout écart (notamment un 0 octet = placeholder non hydraté).

6. **Nettoyer** les fichiers temporaires (script + copies hydratées) dans une commande
   séparée référençant uniquement `%TEMP%` (jamais le chemin distant).

7. **Confirmer** en HTTP que le site répond (ex. `login.php` → 200) si des pages
   publiques ont changé.

## Notes
- Pour PowerShell 5.1 + HTTPS : `[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12`.
- Une migration de base s'applique en chargeant n'importe quelle page (db_setup.php).
