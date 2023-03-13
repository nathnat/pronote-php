<?php

namespace Pronote;

/**
 * La classe qui gére le login via les cas
 */
class PronoteCas
{
    // Toutes les interfaces qui fonctionne comme ent.iledefrance.fr
    public const ILE_DE_FRANCE = 'ent.iledefrance.fr';
    public const PARIS_CLASSE_NUMERIQUE = 'ent.parisclassenumerique.fr';
    public const NEOCONNECT_GUADELOUPE = 'neoconnect.opendigitaleducation.com';
    public const SEINE_ET_MARNE = 'ent77.seine-et-marne.fr';
    public const ESSONNE = 'www.moncollege-ent.essonne.fr';
    public const MAYOTTE = 'mayotte.opendigitaleducation.com';
    public const LYCEECONNECTE_AQUITAINE = 'mon.lyceeconnecte.fr';

    public const ENT_INTERFACES = [
        self::ILE_DE_FRANCE,
        self::PARIS_CLASSE_NUMERIQUE,
        self::NEOCONNECT_GUADELOUPE,
        self::SEINE_ET_MARNE,
        self::ESSONNE,
        self::MAYOTTE,
        self::LYCEECONNECTE_AQUITAINE,
    ];

    public static function openENT($url, $username, $password, $target)
    {
        // On construit les valeurs du POST pour l'ENT
        $callback = '/cas/login?service=' . $url;
        $postData = "email={$username}&password={$password}&callback={$callback}";

        // On récupère les cookies de la session ENT
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => Utils::USER_AGENT,
            CURLOPT_URL => "https://$target/auth/login",
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
        return self::httpFollowRedirects('https://ent.iledefrance.fr' . $callback, ['Cookie: ' . $cookies]);
    }

    /**
     * Execute les requêtes HTTP en suivant les redirections. Nécessaire pour le login avec cas.
     * @param string $url L'url à request
     * @param array $httpHeader Des headers à rajouter pour la requête
     * @return string La page finale après tous les redirects
     */
    private static function httpFollowRedirects(string $url, array $httpHeader = [])
    {
        $headers = [];
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => Utils::USER_AGENT,
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
            return self::httpFollowRedirects($headers['location']);
        }

        return $response;
    }
}
