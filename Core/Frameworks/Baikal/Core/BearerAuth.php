<?php

namespace Baikal\Core;
use Symfony\Component\HttpClient\HttpClient;



/**
 * This is an authentication backend that uses a database to manage passwords.
 *
 * Format of the database tables must match to the one of \Sabre\DAV\Auth\Backend\PDO
 *
 * @copyright Copyright (C) 2013 Lukasz Janyst. All rights reserved.
 * @author Lukasz Janyst <ljanyst@buggybrain.net>
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class BearerAuth extends \Sabre\DAV\Auth\Backend\AbstractBearer 
{
    /**
     * Reference to PDO connection.
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * Authentication realm.
     *
     * @var string
     */
    protected $oauthURL;

    private $httpClient;

    /**
     * Creates the backend object.
     *
     * If the filename argument is passed in, it will parse out the specified file fist.
     *
     * @param PDO $pdo
     * @param string $tableName The PDO table name to use
     */
    function __construct(\PDO $pdo, $oauthURL) {
        $this->pdo = $pdo;
        $this->oauthURL = $oauthURL;
        $this->httpClient = HttpClient::create([
            'verify_peer' => false,
        ]);
    }

    function validateBearerToken($bearerToken) {
        $response = $this->httpClient->request('GET', $this->oauthURL, [ // https://keycloak.com-dev.int.rolfcorp.ru/realms/COREAPP-DEV/protocol/openid-connect/userinfo
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
            ],
        ]);

        if ($response->getStatusCode() === 200) {
            $content = json_decode($response->getContent());
            // Обработка содержимого ответа
            $username = $content->preferred_username;
            $email = $content->email;
            return 'principals/' . $username;
        } else {
            // Обработка ошибки
            return false;
        }


       
        // if (!$user) {
        //     return [false, 'Error msg from keycloak'];
        // } 
        // return [true, 'principals/dmorkulev']

    }


    
}
