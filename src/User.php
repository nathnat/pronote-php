<?php

namespace Pronote;

class User extends DataClass
{
    /** Nom de l'élève */
    public $name;

    /** Classe de l'élève */
    public $class;

    /** Nom de l'établissement de l'élève */
    public $establishment;

    /** Url de la photo de profil de l'élève */
    public $profilePicture;
    
    /** Adresse email de l'élève */
    public $email;
    
    /** Numéro de téléphone de l'élève au format  +[code pays][numéro de téléphone] */
    public $phone;
    
    /** Numéro INE de l'élève */
    public $numberINE;

    /** Adresse de l'élève */
    public $address;
}
