<?php

class CinExistant extends \Exception {
    public function __construct() {
        parent::__construct("Le Cin que vous avez entré existe déjà.");
    }
}

class CinIntrouvable extends \Exception {
    public function __construct() {
        parent::__construct("Le cin que tu as tenté de trouver ne se trouve pas dans la base de donnée");
    }
}

class SuppressionClientEnLocation extends \Exception {
    public function __construct() {
        parent::__construct("Impossible de supprimer le client, car il est enregistré dans une location active");
    }
}

?>
