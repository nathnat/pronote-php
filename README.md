# Pronote PHP

## Introduction

Une librairie PHP pour accéder aux données de PRONOTE depuis un compte élève. La librairie exploite l'API interne de Pronote avec PHP.

## Données récupérables

* Infos Pronote, établissement et utilisateur
* Emploi du temps
* Devoirs
* Notes
* Absences/punitions/retards

## Installation

Cette librairie peut être installé avec Composer et est disponible sur Packagist
[nathnat/pronote-php](https://packagist.org/packages/nathnat/pronote-php) :

```bash
$ composer require nathnat/pronote-php
```

## Utilisation

Commencez par inclure au début de votre code la librairie via Composer :

```php
require 'vendor/autoload.php';

use Pronote\Pronote;
```

L'utilisation de la librairie est très simple et intuitif, pour se connecter, récupèrer les notes, les emplois du temps, etc.

## Exemple

Si vous souhaiter vous lancer directement voici un exemple simple d'utilisation :

```php
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
```

Je vous propose ci-dessous une documentation qui présente la plupart des fonctions principales.

## Initialiser le client

Le client correspond à une instance de la classe Pronote. Un client ne peut se connecter qu'à une seule session Pronote à la fois.

```php
// Initialise le client
$client = new Pronote('https://demo.index-education.net/pronote/eleve.html', 'demonstration', 'pronotevs' /*, cas*/);

// Connecte le client à Pronote
$client->login();
```

## Comptes région supportés

**Uniquement dans le cas où vous ne pouvez PAS vous connecter directement par Pronote, mais devez passer par une interface régionale spéciale.**

**Si vous pouvez vous connecter directement sur l'interface de Pronote, l'API devrait fonctionner PEU IMPORTE VOTRE ACADÉMIE.**

Pour l'instant peu de comptes régions sont supportés.

Voici la listes des académies supportées pour l'instant :

<details>
  <summary>Cas liste</summary>

| Académie                          | Syntaxe du cas dans l'API           |
| ---------------------------------- | ----------------------------------- |
| Mayotte                            | PronoteCas::MAYOTTE                 |
| Guadeloupe                         | PronoteCas::NEOCONNECT_GUADELOUPE   |
| Essone                             | PronoteCas::ESSONNE                 |
| Lycée Connecte Nouvelle-Aquitaine | PronoteCas::LYCEECONNECTE_AQUITAINE |
| Seine-et-Marne                     | PronoteCas::SEINE_ET_MARNE          |
| Île de France                     | PronoteCas::ILE_DE_FRANCE           |
| Paris Classe Numérique            | PronoteCas::PARIS_CLASSE_NUMERIQUE  |

</details>

Le cas doit être donné à lors de l'initialisation du client. Tous les cas sont accessibles depuis la classe `PronoteCas` :

```php
// On inclut la classe
use Pronote\PronoteCas;

// Initialise le client avec ici l'interface de l'Ile de France
$client = new Pronote(
    'https://demo.index-education.net/pronote/eleve.html',
    'demonstration',
    'pronotevs',
    PronoteCas::ILE_DE_FRANCE
);
```

## Récupèrer l'emploi du temps

La fonction `timetable()` renvoie les cours de l'élève entre 2 dates, classés par ordre chronologique. Si la deuxième date n'est pas fournit, seulement les cours de la première date sont renvoyés.

**Attention :** Les dates doivent absolument être des instances de la classe native de PHP [DateTime](https://www.php.net/manual/fr/class.datetime.php)

```php
$today = new DateTime();
$timetable = $client->timetable($today); // Récupére l'emploi du temps du jour

// Récupère les cours entre le 25 février 2023 et le 5 mars 2023
$timetable = $client->timetable(
    DateTime::createFromFormat('d/m/Y', '25/02/2023'),
    DateTime::createFromFormat('d/m/Y', '5/03/2023'),
);
```

La fonction renvoit un tableau de cette forme :

```php
Array
(
    [0] => Array
        (
            [start] => 27/02/2023 09:00:00 // L'heure de début du cours
            [end] => 27/02/2023 10:00:00   // L'heure de fin du cours
            [color] => #2338BB             // La couleur de la case du cours sur Pronote
            [status] => 'Cours annulé'     // Le statut du cours (Cours annulé, Prof. absent, etc.)
            [subject] => FRANCAIS          // La matière étudiée
            [teacher] => GALLET B.         // Le nom du professeur
            [room] => 105                  // La classe où a lieu le cours
        )
    ...
```

## Récupèrer les devoirs

Similaire a la fonction `->timetable()`, la fonction qui permet de récupèrer les devoirs `->homework()` renvoit les devoirs entre 2 dates.

```php
$today = new DateTime();
$timetable = $client->homework($today); // Récupére les devoirs du jour

// Récupère les devoirs entre le 25 février 2023 et le 5 mars 2023
$timetable = $client->homework(
    DateTime::createFromFormat('d/m/Y', '25/02/2023'),
    DateTime::createFromFormat('d/m/Y', '5/03/2023'),
);
```

La fonction renvoit un tableau de cette forme :

```php
Array
(
    [0] => Array
        (
            [subject] => FRANCAIS                            // La matière du devoir
            [description] => <div>Exercice 36 page 132</div> // La description du devoir
            [backgroundColor] => #FEA7FC                     // La couleur de fond du devoir
            [isDone] => false                                // Si le devoir est fait
            [date] => 15/03/2023                             // La date de rendu du devoir
        )
    ...
```

## Les périodes

Les périodes sont les périodes de l'année (Trimestre 1, Semestre 2, Brevet Blanc, etc.) fournit par l'établissement. Les périodes sont stockées dans le tableau  `$client->periods`. Chaque période permet d'accéder aux données qui lui sont rattachées comme les notes, les absences et les retards.

## Récupèrer les notes de l'élève

Les notes sont récupèrables via la période.

Il existe trois fonctions :

* `$period->grades()` permet de récupérer un tableau de note sous cette forme :

  ```php
  Array
  (
      [0] => Array
          (
              [title] => DS1 Civilisations     // La description de la note
              [value] => 13                    // La note de l'élève
              [scale] => 20                    // Le barème
              [subject] => HISTOIRE-GEOGRAPHIE // La matière de la note
              [average] => 11,89               // La moyenne de la classe
              [max] => 15                      // La note la plus basse dans la classe
              [min] => 10                      // La note la plus haute dans la classe
              [coefficient] => 2
              [date] => 26/09/2022             // La date de la note
              [isBonus] => false               // Si la note est bonus : seul les points au dessus de 10 comptent
              [isOptionnal] => false           // Si la note est optionnel : elle compte seulement si elle augmente la moyenne
              [isScaledTo20] => false          // Si la note est ramené sur 20
          )
      ...
  ```
* `$period->gradesBySubject()` permet récupèrer les notes classées par matière :

  ```php
  Array ()
      [0] => Array
          (
              [name] => ANGLAIS LV1    // Le nom de la matière
              [average] => Array
                  (
                      [student] => 17  // La moyenne de l'élève dans la matière
                      [class] => 13,79 // La moyenne de la classe dans la matière
                      [min] => 6,5     // La plus basse moyenne de la classe
                      [max] => 18      // La plus haute moyenne de la classe
                      [scale] => 20    // Le barème des moyennes
                  )

              [color] => #B76AFD       // La couleur de fond de la matière dans l'emploi du temps
              [grades] => Array ()     // Un tableau contenant toutes les notes de la matière au même format que celles renvoyées par la fonction `->grades()`
          )
      ...
  ```
* Enfin, `$period->overallAverage()` permet de récupèrer la moyenne générale de l'élève :

  ```php
  $moyennes = $client->periods[1]->overallAverage();

  echo 'Moyennes du ' . $client->periods[1]->name . " : \n";
  print_r($moyennes);
  ```

  ```php
  Moyennes du Trimestre 2 : 
  Array
  (
      [student] => 14,33 // La moyenne de l'élève
      [class] => 11,54   // La moyenne de la classe
      [scale] => 20      // Le barème des moyennes
  )
  ```

## Récupèrer les absences/retards/punitions de l'élève

* Les notes sont récupèrables via la période :

```php
$absences = $client->periods[1]->absences(); // Fonction pour récupérer les absences renvoit :

Array
(
    [0] => Array
        (
            [from] => 02/02/2023 09:00:00 // La date de début de l'absence
            [to] => 02/02/2023 10:00:00   // La date de fin de l'absence
            [isJustified] => true         // Si l'absence est justifiée
            [hoursMissed] => 1h00         // Le nombre d'heure ratée
            [daysMissed] => 1             // Le nombre de jour raté
            [reasons] => Array            // La liste des raisons de l'absene
                (
                    [0] => L-Rendez-vous médical
                )
        )
    ...
```

* Les retards sont récupèrables via la période :

```php
$retards = $client->periods[1]->delays(); // Fonction pour récupérer les retards renvoit :

Array
(
    [0] => Array
        (
            [date] => 27/01/2023 09:00:00 // La date du retard
            [minutesMissed] => 5          // Le nombre de minute ratée
            [justified] => false          // Si l'absence est justifié
            [justification] =>            // La justification du retard
            [reasons] => Array            // La liste des raisons du retard
                (
                    [0] => PROBLEME DE REVEIL
                )

        )
    ...
```

* Les punitions sont récupèrables via la période :

```php
$punitions = $client->periods[1]->punishments(); // Fonction pour récupérer les punitions renvoit :

Array
(
    [0] => Array
        (
            [given] => 01/09/2022                              // La date à laquelle la punition a été donnée
            [isExclusion] => false                             // Si la punition est une exclusion
            [isDuringLesson] => true                           // Si la punition est durant un cours
            [homework] => Exercices 1 à 18 p283-284            // Les devoirs donnés par la punition
            [homeworkDocuments] => Array()                     // Les documents attachés pour ces devoirs
            [circonstances] => Insultes suite à une réprimande // Les circonstances de la punition
            [circonstancesDocuments] => Array()                // Les documents pouvant accompagné les circonstances
            [nature] => Retenue                                // La nature de la punition
            [reasons] => Array                                 // La liste des raisons de la punition
                (
                    [0] => Violence verbale
                )

            [giver] => M. PROFESSEUR M.                        // Le nom de la personne ayant donné la punition
            [isSchedulable] => true                            // Si la punition est programmable
            [schedule] => Array                                // Liste des dates pour lesquelles la punition est programmé
                (
                    [0] => 07/09/2022
                )
            [duration] => 60                                   // La durée de la punition
        )
    ...
```
