<?php

require 'vendor/autoload.php';

use Pronote\Pronote;

// Initialise le client
$client = new Pronote('https://demo.index-education.net/pronote/eleve.html', 'demonstration', 'pronotevs', /*, cas*/);

// Connecte le client à Pronote
$client->login();

echo "Connecté en tant que {$client->user->name}\n";

$today = new DateTime();
$timetable = $client->timetable($today); // Récupére l'emploi du temps du jour

foreach ($client->periods as $period) {
    $grades = $period->grades();
    foreach ($grades as $grade) { // Parcourt toutes les notes
        echo "{$grade['value']}/{$grade['scale']}\n"; // Affiche la note dans ce style : 15/20
    }
}