<?php

namespace Pronote;

use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\Random;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Math\BigInteger;
use phpseclib3\Crypt\RSA;

use Pronote\User;
use Pronote\Period;

/**
 * Classe principale
 */
class Pronote
{
    // L'url du serveur pronote
    private $server;

    // Cryptographie
    private $AES_iv;
    private $AES_key;
    private $RSA_modulus;
    private $RSA_exponent;

    // Paramètres liés à la session courante
    private $sessionID = null;
    private $requestCount = 1;
    private $espace = 3;

    // On donne un User-Agent connu et à jour afin de ne pas se prendre la page signalant que le navigateur n'est pas compatible
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/95.0.4638.69 Safari/537.36';

    // Infos fournit par l'utilisateur au début de la session
    public $username;
    public $password;
    public $cas;

    private $parametresUtilisateur;
    private $fonctionParametres;

    private $params;

    private $cookies = null;

    // L'objet User qui contient toutes les données de l'utilisateur
    public $user;

    public $periods = [];

    public const CAS_ILEDEFRANCE = 'https://ent.iledefrance.fr/auth/login';

    public function __construct(string $url, string $username, string $password, string $cas = null)
    {
        $this->username = $username;
        $this->password = $password;
        $this->server = $this->getServer($url);
        $this->AES_key = '';
        $this->cas = $cas;

        $this->user = new User;
    }

    /**
     * Connecte le client à la session Pronote
     */
    public function login()
    {
        if ($this->sessionID !== null) {
            throw new \Exception('A session is already connected with this client', 1);
        }

        $start = [];
        $cookies = null;
        if ($this->cas == self::CAS_ILEDEFRANCE) {

            // On construit les valeurs du POST pour l'ENT
            $callback = '/cas/login?service=' . $this->server;
            $postData = "email={$this->username}&password={$this->password}&callback={$callback}";

            // On récupère les cookies de la session ENT
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_URL => $this->cas,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER =>  ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_HEADER => 1
            ]);
            $response = curl_exec($curl);
            curl_close($curl);

            // On les extraits du header de la réponse
            $cookies = '';
            preg_match_all('/^set-cookie:\s*([^;]*)/mi', $response, $matches);
            foreach ($matches[1] as $item) {
                $cookies .= $item . ';';
            }

            // On récupère la page Pronote en suivant les redirections
            $result = $this->httpFollowRedirects('https://ent.iledefrance.fr' . $callback, ['Cookie: ' . $cookies]);

            $start = $this->extractStart($result['html']);
            $this->cookies = substr($result['cookies'], 0, strpos($result['cookies'], ';'));   

            // Remplace le username et le password par ce donné dans par le login via l'ENT
            $this->username = $start['e'];
            $this->password = $start['f'];
        } else {
            // On récupère des données dans le HTML de la page de connexion
            $start = $this->getStart();
        }

        if (isset($start['status'])) {
            echo $start['message'];
            return 0;
        }

        // On alimente la classe avec les données du start
        $this->sessionID = (int)$start['h'];
        $this->espace  = $start['a'];
        $this->RSA_modulus = $start['MR'];
        $this->RSA_exponent = $start['ER'];

        // L'IV fournit doit être random et est maintenant utilisé pour tous le reste des encryptions
        $this->AES_iv = bin2hex(Random::string(16));

        // On charge les keys RSA
        $key = PublicKeyLoader::load([
            'e' => new BigInteger($this->RSA_exponent, 16),
            'n' => new BigInteger($this->RSA_modulus, 16)
        ]);

        // On encrypte notre nouvelle clé avec l'algo PKCS1
        $Uuid = $key->withPadding(RSA::ENCRYPTION_PKCS1)
            ->encrypt($this->AES_iv);

        // On le b64 encode
        $Uuid = base64_encode($Uuid);

        // Première requête : On donne au serveur notre IV AES
        $this->fonctionParametres = $this->makeRequest('FonctionParametres', [
            'donnees' => [
                'Uuid' => $Uuid,
                'identifiantNav' => null
            ]
        ]);

        // On extrait les périodes utilisés pour récupèrer pleins d'autres données
        foreach ($this->fonctionParametres['donneesSec']['donnees']['General']['ListePeriodes'] as $period) {
            $this->periods[] = new Period([
                'client' => $this,
                'id' => $period['N'],
                'name' => $period['L'],
                'start' => $period['dateDebut']['V'],
                'end' => $period['dateFin']['V']
            ]);
        }

        $this->params = [
            'navigatorId' => $this->fonctionParametres['donneesSec']['donnees']['identifiantNav'],
            'firstDay' => $this->fonctionParametres['donneesSec']['donnees']['General']['PremiereDate']['V'],
        ];

        // Deuxième requête : L'identification où on fournit le nom d'utilisateur au serveur
        $json = $this->makeRequest('Identification', [
            'donnees' => [
                'genreConnexion' => 0,
                'genreEspace' => $this->espace,
                'identifiant' => $this->username,
                'pourENT' => true,
                'enConnexionAuto' => false,
                'demandeConnexionAuto' => false,
                'demandeConnexionAppliMobile' => false,
                'demandeConnexionAppliMobileJeton' => false,
                'uuidAppliMobile' => '',
                'loginTokenSAV' => ''
            ]
        ]);
        
        $challenge = $json['donneesSec']['donnees']['challenge'];

        // On génère la clé AES pour décrypter le challenge envoyé par Pronote
        $key = null;
        
        // Sans cas la clé est plus complexe
        if ($this->cas == null) {
            $username = $this->username;
            $password = $this->password;
            if ($json['donneesSec']['donnees']['modeCompLog'])
                $username = strtolower($username);
    
            if ($json['donneesSec']['donnees']['modeCompMdp'])
                $password = strtolower($password);
    
            $alea = $json['donneesSec']['donnees']['alea'];
            
            // On encrypte en sha256 la concatenation de l'alea + le password
            $encrypted = hash('sha256', $alea . $password);

            // On concatene le username et le encrypted en uppercase
            $key = $username . strtoupper($encrypted);
        }
        // Avec cas la clé est plus simple
        else {
            $key = strtoupper(hash('sha256', $this->password));
        }

        // On décrypte le challenge
        $key = hex2bin(md5($key));
        $iv = hex2bin(md5($this->AES_iv));

        $cipher = new AES('cbc');

        $cipher->setKey($key);
        $cipher->setIV($iv);

        $challengeDecrypted = $cipher->decrypt(hex2bin($challenge));

        // On enlève un charactère sur deux
        $removeSecondCharacter = '';
        $i = 0;
        foreach (mb_str_split($challengeDecrypted) as $char) {
            if ($i % 2 == 0) {
                $removeSecondCharacter .= $char;
            }
            $i++;
        }

        $solvedChallenge = bin2hex($cipher->encrypt($removeSecondCharacter));

        // Troisième requête, on "prouve" qu'on a le bon mot de passe en décryptant la chaîne envoyée avec et en la recryptant.
        $json = $this->makeRequest('Authentification', [
            'donnees' => [
                'connexion' => 0,
                'challenge' => $solvedChallenge,
                'espace' => $this->espace
            ]
        ]);

        // On reçoit la nouvelle clé qui sera la clé d'ecnryption AES à partir de maintenant
        $newKeyEncrypted = $json['donneesSec']['donnees']['cle'];

        // On la décrypte
        $newKeyDecrypted = $cipher->decrypt(hex2bin($newKeyEncrypted));

        // Puis on transforme les bytes obtenu en hex
        $this->AES_key = join(
            array_map(
                "chr",
                explode(',', $newKeyDecrypted)
            )
        );

        // On récupère les données Pronote de l'utilisateur qui peuvent être requise pour certaine requête plus tard
        $this->parametresUtilisateur = $this->makeRequest('ParametresUtilisateur', []);

        // Le corps de l'information se trouve dans ressource
        $ressource = $this->parametresUtilisateur['donneesSec']['donnees']['ressource'];

        // On récupère les informations personnelles de l'élève
        $informations = $this->getPersonalInformation();

        // On hydrate les infos de l'utilisateurs
        $this->user->hydrate([
            'name' => $ressource['L'],
            'class' => $ressource['classeDEleve']['L'],
            'establishment' => $ressource['Etablissement']['V']['L'],
            'email' => $informations['eMail'],
            'phone' => '+' . $informations['indicatifTel'] . $informations['telephonePortable'],
            'numberINE' => $informations['numeroINE'],
            'profilePicture' => $this->getFileUrl($ressource['N'], 'photo.jpg'),
            'address' => [
                'info' => [
                    $informations['adresse1'],
                    $informations['adresse2'],
                    $informations['adresse3'],
                    $informations['adresse4'],
                ],
                'postalCode' => $informations['codePostal'],
                'city' => $informations['ville'],
                'province' => $informations['province'],
                'country' => $informations['pays']
            ]
        ]);
    }

    /**
     * Fabrique l'url des fichiers par rapport à la session
     * @param string $userID L'ID de l'utilisateur
     * @param string $fileName Le nom du fichier dont on veut récupèrer l'URL
     * @return string L'URL du fichier
     */
    private function getFileUrl($userID, $fileName)
    {
        $userID = json_encode(['N' => $userID]);
        $userID = $this->encrypt($userID);
        return $this->server . 'FichiersExternes/' . $userID . '/' . $fileName . '?Session=' . $this->sessionID;
    }

    /**
     * Encrypte en AES CBC 256
     * @param string $data La string à encrypter
     * @param string|null $key La clé AES
     * @param string|null $iv L'IV AES
     * @return string La string encryptée au format héxadécimal
     */
    private function encrypt($data, $key = null, $iv = null)
    {
        // Si les valeurs sont null on encrypte avec les valeurs par défaut
        if (is_null($key))
            $key = hex2bin(md5($this->AES_key));
        
        if (is_null($iv))
            $iv = hex2bin(md5($this->AES_iv));

        // Le mode d'encryption est AES CBC
        $cipher = new AES('cbc');

        $cipher->setKey($key);
        $cipher->setIV($iv);

        return bin2hex($cipher->encrypt($data));
    }

    /**
     * Récupère les informations personnelles de l'utilisateur dans le bon onglet
     * @return array Les informations personnelles de l'utilisateur
     */
    private function getPersonalInformation() {
        return $this->makeRequest('PageInfosPerso', [
            '_Signature_' => [
                'onglet' => 16
            ]
        ])['donneesSec']['donnees']['Informations'];
    }

    /**
     * Extrait les valeurs nécessaires à la connexion depuis la page Pronote
     * @param string $pronotePage La page Pronote dans laquelle se trouve les données
     * @return array Les données dans la page
     */
    private function extractStart($pronotePage)
    {
        if (preg_match('/{h:.*?}/', $pronotePage, $matches)) {
            $toDecode = $matches[0];

            /* Encapsulate keys with quotes */
            $toDecode = preg_replace('/([a-z_]+)\:/ui', '"{$1}":', $toDecode);
            $toDecode = str_replace('"{', '"', $toDecode);
            $toDecode = str_replace('}"', '"', $toDecode);
            $toDecode = str_replace('\'', '"', $toDecode);

            return json_decode($toDecode, true);
        } else {
            preg_match_all('/<div class="texte" style="font-size:12px;">(.*?)<\/div>/', $pronotePage, $matches);

            return [
                'status' => true,
                'message' => $matches[1][0]
            ];
        }
    }

    /**
     * Récupère les devoirs de l'élève entre deux dates
     * @param \DateTime $from La date de début
     * @param \DateTime $to La date de fin, si non précisé un jour de plus que la date de de début
     * @return array Les devoirs de l'éléve
     */
    public function homework(\DateTime $from, \DateTime $to = null)
    {
        // Si to est nul on rajoute un jour pour récupèrer un seul jour
        if ($to == null) {
            $to = (clone $from)->modify('+1 day');
        }

        // On créée l'objet des données qui ne change pas
        $response = $this->makeRequest('PageCahierDeTexte', [
            'donnees' => [
                'domaine' => [
                    '_T' => 8,
                    'V' => '[' . $this->toPronoteWeek($from) . '..' . $this->toPronoteWeek($to) . ']',
                ]
            ],
            '_Signature_' => [
                'onglet' => 88
            ]
        ]);

        $homeworks = [];

        foreach ($response['donneesSec']['donnees']['ListeTravauxAFaire']['V'] as $homework) {
            $date = $homework['PourLe']['V'];
            
            // Comme on peut seulement récupèrer les cours en semaine on fait la précision en jour
            $date = \DateTime::createFromFormat('d/m/Y', $date);
            if (
                $date->getTimestamp() < $from->getTimestamp() ||
                $date->getTimestamp() > $to->getTimestamp()
            ) {
                continue;
            }
            
            $homeworks[] = [
                'subject' => $homework['Matiere']['V']['L'],
                'description' => $homework['descriptif']['V'],
                'backgroundColor' => $homework['CouleurFond'],
                'isDone' => $homework['TAFFait'],
                'date' => $date->format('d/m/Y')
            ];
        }

        return $homeworks;
    }
    
    /**
     * Récupère l'emploi du temps de l'élève entre deux dates
     * @param \DateTime $from La date de début
     * @param \DateTime $to La date de fin, si non précisé un jour de plus que la date de de début
     * @return array Les cours de l'élève triés par ordre chronologique
     */
    public function timetable(\DateTime $from, \DateTime $to = null)
    {
        // Si to est nul on rajoute un jour pour récupèrer un seul jour
        if ($to == null) {
            $to = (clone $from)->modify('+1 day');
        }

        // On met les heures à minuit pour les comparaisons
        $from->setTime(0, 0);
        $to->setTime(0, 0);

        $output = [];

        // On créée l'objet des données qui ne change pas
        $ressource = $this->parametresUtilisateur['donneesSec']['donnees']['ressource'];
        $data = [
            'donnees' => [
                'ressource' => $ressource,
                'Ressource' => $ressource,
                'avecAbsencesEleve' => false,
                'avecConseilDeClasse' => true,
                'estEDTPermanence' => false,
                'avecAbsencesRessource' => true,
                'avecDisponibilites' => true,
                'avecInfosPrefsGrille' => true
            ],
            '_Signature_' => [
                'onglet' => 16
            ]
        ];

        // On récupère les semaines Pronote correspondantes
        $firstWeek = $this->toPronoteWeek($from);
        $lastWeek = $this->toPronoteWeek($to);

        // On parcourt les semaines que l'utilisateur veut récupèrer
        for ($week = $firstWeek; $week <= $lastWeek; $week++) {

            // On alimente l'array des données transmises à Pronote avec le numéro de la semaine
            $data['donnees']['NumeroSemaine'] = $data['donnees']['numeroSemaine'] = $week;

            // On effectue la requête
            $response = $this->makeRequest('PageEmploiDuTemps', $data);

            // On récupère la liste des cours de la semaine
            $lessonList = $response['donneesSec']['donnees']['ListeCours'];

            // On parcourt la liste des cours
            foreach ($lessonList as $lesson) {

                // Comme on peut seulement récupèrer les cours en semaine on fait la précision en jour
                $lessonStart = \DateTime::createFromFormat('d/m/Y H:i:s', $lesson['DateDuCours']['V']);
                if (
                    $lessonStart->getTimestamp() < $from->getTimestamp() ||
                    $lessonStart->getTimestamp() > $to->getTimestamp()
                ) {
                    continue;
                }

                // On calcule l'heure de fin du cours
                $duration = $lesson['duree'] / 2;
                $lessonEnd = (clone $lessonStart)->add(new \DateInterval("PT{$duration}H"));

                $lessonValue = [
                    'start' => $lessonStart->format('d/m/Y H:i:s'),
                    'end' => $lessonEnd->format('d/m/Y H:i:s'),
                    'color' => $lesson['CouleurFond'],
                    'status' => $lesson['Statut'] ?? null
                ];

                // On alimente le sujet du cours, le prof et la classe
                foreach ($lesson['ListeContenus']['V'] as $info) {
                    if ($info['G'] == 3)
                        $lessonValue['teacher'] = $info['L'];
                    if ($info['G'] == 17)
                        $lessonValue['room'] = $info['L'];
                    if ($info['G'] == 16)
                        $lessonValue['subject'] = $info['L'];
                }

                if (!isset($lessonValue['room']))
                    $lessonValue['room'] = null;

                if (!isset($lessonValue['teacher']))
                    $lessonValue['teacher'] = null;

                if (!isset($lessonValue['subject']))
                    $lessonValue['subject'] = null;

                $output[] = $lessonValue;
            }
        }

        // Triage des cours par ordre chronologique
        usort($output, function ($a, $b) {
            return \DateTime::createFromFormat('d/m/Y H:i:s', $a['start']) <=> \DateTime::createFromFormat('d/m/Y H:i:s', $b['start']);
        });

        return $output;
    }

    /**
     * Renvoie le numéro de la semaine pronote correspondant à une date.
     * @param \DateTime $date La date dont on  cherche la semaine
     * @return int La semaine correspondante
     */
    private function toPronoteWeek(\DateTime $date)
    {
        $startDay = \DateTime::createFromFormat('d/m/Y', $this->params['firstDay']);
        return 1 + round($date->diff($startDay)->format("%a") / 7);
    }

    /**
     * Execute les requêtes HTTP en suivant les redirections. Nécessaire pour le login avec cas.
     * @param string $url L'url à request
     * @param array $httpHeader Des headers à rajouter pour la requête
     * @return string La page finale après tous les redirects
     */
    private function httpFollowRedirects(string $url, array $httpHeader = [])
    {
        $headers = [];
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_URL => $url,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER =>  $httpHeader,
            CURLOPT_HEADER => 1,
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$headers) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                    return $len;

                $headers[strtolower(trim($header[0]))] = trim($header[1]);

                return $len;
            }
        ]);
        $response = curl_exec($curl);
        curl_close($curl);

        if (isset($headers['location'])) {
            return $this->httpFollowRedirects($headers['location']);
        }

        return [
            'cookies' => $headers['set-cookie'],
            'html' => $response
        ];
    }

    /**
     * Renvoie l'url du serveur Pronote au bon format.
     * @param string $url L'url du serveur
     * @return string L'url du serveur au bon format
     */
    private function getServer(string $url)
    {
        if (strrpos($url, '.html')) {
            return substr($url, 0, strrpos($url, '/') + 1);
        }

        if (substr($url, -1) != '/') {
            $url .= '/';
        }

        return $url;
    }

    /**
     * Charge les données nécessaires a la connexion qui sont sur le HTML de la page d'accueil de Pronote
     * 
     * @return array Les données nécessaires a la connexion
     */
    private function getStart(): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_URL => $this->server . 'eleve.html',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);

        return $this->extractStart(curl_exec($curl));
    }

    /**
     * Fonction qui génère le numeroOrdre, l'encryption en AES CBC 256 du requestCount
     * @return string Le numeroOrdre
     */
    private function generateNumeroOrdre()
    {
        $key = hex2bin(md5($this->AES_key));
        $iv = hex2bin(md5($this->AES_iv));

        // Pour la première requête l'IV est rempli de zéro
        if ($this->requestCount == 1) {
            $iv = hex2bin('00000000000000000000000000000000');
        }

        return $this->encrypt($this->requestCount, $key, $iv);
    }

    /**
     * Formatte et envoit une requête donnée vers le serveur pronote.
     * @param string $nom Le nom de la fonction appelée
     * @param array $post Les arguments à lui passer, sous la forme d'un array
     * @param  $AESNumeroOrdre, array(cle, iv) utilisé pour la génération du numéro d'ordre
     * @param  $AESArgs, array(cle, iv) utilisé pour le cryptage des arguments
     * @param  $AESDonnees, array(cle, iv) utilisé pour le décryptage des données
     * @return array Le résultat de la requête en JSON, 1 en cas d'échec.
     */
    public function makeRequest($nom, $post, $AESNumeroOrdre = null, $AESArgs = null, $AESDonnees = null)
    {

        // if ($AESNumeroOrdre == null) $AESNumeroOrdre = array($this->AES_key, $this->AESIV);
        // if ($AESArgs == null) $AESArgs = array($this->AES_key, $this->AESIV);
        // if ($AESDonnees == null) $AESDonnees = array($this->AES_key, $this->AESIV);

        $numeroOrdre = $this->generateNumeroOrdre();

        $url = $this->server . 'appelfonction/' . $this->espace . '/' . $this->sessionID . '/' . $numeroOrdre;

        $post = [
            'nom' => $nom,
            'numeroOrdre' => $numeroOrdre,
            'session' => $this->sessionID,
            'donneesSec' => $post
        ];

        $httpHeader = [
            'Content-Type: application/json'
        ];

        if ($this->cookies !== null)
            $httpHeader[] = 'cookie: ' . $this->cookies;

        // if (isset($DEBUG) && $DEBUG == true) echo json_encode($post, JSON_UNESCAPED_SLASHES) . "\n";
        // $post = Crypto::encryptAESWithMD5WithGzip(json_encode($post, JSON_UNESCAPED_SLASHES), $AESArgs[0], $AESArgs[1]);
        // $post["donneesSec"] = $post;

        $post = str_replace('\\/', '/', json_encode($post));
        $post = str_replace('\\\\', '\\', $post);
        $post = str_replace('[]', '{}', $post);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => $httpHeader
        ]);

        $response = curl_exec($ch);

        // Si la requête est effectué on augmente le requestCount
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) $this->requestCount += 2;

        curl_close($ch);

        // $json = json_decode($response, true);
        // if ($json != null) {
        // 	if (isset($json["donneesSec"]) && $json["donneesSec"] != null) {
        // 		$json["donneesSec"] = json_decode(Crypto::decryptAESWithMD5WithGzip($json["donneesSec"], $AESDonnees[0], $AESDonnees[1]), true);		
        // 	}
        // }

        // if (isset($DEBUG) && $DEBUG == true) print_r($json);

        // if ($json != null && isset($json["Erreur"])) {
        // 	return array("erreur" => var_export($json["Erreur"], true));
        // }

        return json_decode($response, true);
    }
}
