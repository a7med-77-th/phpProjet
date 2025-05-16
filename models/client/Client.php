<?php


namespace models\client;

use models\client\CinExistant;
use models\client\CinIntrouvable;
use models\client\SuppressionClientEnLocation;
use PDO;
use models\Database;

class Client {
    /**
 * Cette classe modélise un client avec le strict minimum des informations nécessaires.
 * Elle stocke les clients enregistrés dans une collection statique (HashMap équivalent PHP : tableau associatif).
 * On utilise le tableau pour des raisons d'optimisation de recherche.
 * Tous les clients existent dans cette collection même s'ils n'ont pas réalisé une demande de location.
 * Le hash se base sur le CIN (qui doit être unique).
 * Lors de la création de l'instance, il teste si le CIN se trouve déjà ou pas.
 */
    private static array $listeClients = [];
    private string $nomComplet;
    private string $cin;
    private string $anneeNaissance; // Format: YYYY-MM-DD
    private int $age;
    private array $permisTypes = [];
    private ?int $id = null;
    private PDO $db;

  
    public function __construct(string $nomComplet, string $cin, string $anneeNaissance, array $permisPossede) {
        $this->db = Database::getInstance();
        if (!preg_match('/^[a-zA-Z0-9 ]+$/', $nomComplet . $cin)) {
            throw new \Exception('Seuls les caractères alphanumériques sont autorisés');
        }
        $cin = strtoupper($cin);
        // Vérifier si le client existe déjà
        $stmt = $this->db->prepare('SELECT id FROM utilisateurs WHERE UPPER(email) = ?');
        $stmt->execute([$cin]);
        if ($stmt->fetch()) {
            throw new CinExistant();
        }
        // Insertion du client
        $stmt = $this->db->prepare('INSERT INTO utilisateurs (nom, prenom, email, age) VALUES (?, ?, ?, ?)');
        $nomParts = explode(' ', $nomComplet, 2);
        $nom = $nomParts[0];
        $prenom = $nomParts[1] ?? '';
        $age = date('Y') - intval(substr($anneeNaissance, 0, 4));
        $stmt->execute([$nom, $prenom, $cin, $age]);
        $this->id = $this->db->lastInsertId();
        $this->nomComplet = $nomComplet;
        $this->cin = $cin;
        $this->anneeNaissance = $anneeNaissance;
        $this->age = $age;
        $this->permisTypes = $permisPossede;
        // Associer les permis
        foreach ($permisPossede as $permis) {
            $stmtPermis = $this->db->prepare('SELECT id FROM permis_types WHERE libelle = ?');
            $stmtPermis->execute([$permis]);
            $permisRow = $stmtPermis->fetch();
            if ($permisRow) {
                $permisId = $permisRow['id'];
                $stmtAssoc = $this->db->prepare('INSERT INTO utilisateur_permis (utilisateur_id, permis_id) VALUES (?, ?)');
                $stmtAssoc->execute([$this->id, $permisId]);
            }
        }
    }

    // Retourne le nom complet du client
    public function getNomComplet(): string {
        return $this->nomComplet;
    }
    // Retourne le CIN du client
    public function getCin(): string {
        return $this->cin;
    }
    // Retourne l'année de naissance du client (format YYYY-MM-DD)
    public function getAnneeNaissance(): string {
        return $this->anneeNaissance;
    }
    // Retourne l'âge du client
    public function getAge(): int {
        return $this->age;
    }
    // Retourne la liste des types de permis du client
    public function getTypesPermis(): array {
        return $this->permisTypes;
    }
    
    // Deux clients sont les mêmes s'ils ont le même CIN
    
    public function equals($o): bool {
        if (!($o instanceof Client)) return false;
        return strtoupper($o->getCin()) === $this->cin;
    }
    
    // Affichage formaté du client
    
    public function __toString(): string {
        return "Nom: {$this->nomComplet}\nCIN: {$this->cin}\nAnnée de naissance: {$this->anneeNaissance}\nage: {$this->age}";
    }
    // Supprimer un client par CIN dans la base de données
    public static function supprimerClient(string $cin) {
        $db = Database::getInstance();
        $cin = strtoupper($cin);
        $stmt = $db->prepare('SELECT id FROM utilisateurs WHERE UPPER(email) = ?');
        $stmt->execute([$cin]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new CinIntrouvable();
        }
        // TODO: Vérifier si le client a une location active (CarnetLocations)
        $stmtDel = $db->prepare('DELETE FROM utilisateurs WHERE id = ?');
        $stmtDel->execute([$row['id']]);
    }
    // Récupérer un client par CIN depuis la base de données
    public static function getClientFromCin(string $cin): Client {
        $db = Database::getInstance();
        $cin = strtoupper($cin);
        $stmt = $db->prepare('SELECT * FROM utilisateurs WHERE UPPER(email) = ?');
        $stmt->execute([$cin]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new CinIntrouvable();
        }
        // Récupérer les permis
        $stmtPermis = $db->prepare('SELECT pt.libelle FROM utilisateur_permis up JOIN permis_types pt ON up.permis_id = pt.id WHERE up.utilisateur_id = ?');
        $stmtPermis->execute([$row['id']]);
        $permis = $stmtPermis->fetchAll(PDO::FETCH_COLUMN);
        $client = new self($row['nom'] . ' ' . $row['prenom'], $row['email'], ($row['age'] ? (date('Y') - $row['age']) . '-01-01' : '2000-01-01'), $permis);
        $client->id = $row['id'];
        return $client;
    }
    // Sauvegarder tous les clients dans un fichier
    
    public static function sauvegarderClients(string $filePath) {
        $file = fopen($filePath, 'w');
        foreach (self::$listeClients as $cl) {
            $saveLine = $cl->getNomComplet() . ";;" . $cl->getCin() . ";;" . $cl->getAnneeNaissance() . ";;" . implode(',', $cl->getTypesPermis());
            fwrite($file, $saveLine . "\n");
        }
        fclose($file);
    }
    // Restaurer les clients depuis un fichier
    
    public static function restaurerClients(string $filePath) {
        if (!file_exists($filePath)) return;
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            list($nom, $cin, $annee, $permis) = explode(';;', $line);
            $permisArray = $permis ? explode(',', $permis) : [];
            try {
                new Client($nom, $cin, $annee, $permisArray);
            } catch (CinExistant $e) {
                // Ignorer les doublons
            } catch (\Exception $e) {
                // Ignorer les erreurs
            }
        }
    }
}


?>