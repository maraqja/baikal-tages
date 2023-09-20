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
        $response = $this->httpClient->request('GET', $this->oauthURL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
            ],
        ]);

        if ($response->getStatusCode() === 200) {
            $content = json_decode($response->getContent());
            $username = $content->preferred_username;


            $stmt = $this->pdo->prepare('SELECT username FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $result = $stmt->fetchAll();
    
            if (!count($result)) {
                $email = $content->email;
                $stmt = $this->pdo->prepare('
                INSERT INTO users (username)
                VALUES (?);
            
                INSERT INTO principals (uri, email, displayname)
                SELECT ?, ?, ?
                WHERE NOT EXISTS (SELECT 1 FROM principals WHERE uri = ?);
            ');
            
            $stmt->execute([
                $username,
                'principals/'.$username,
                $email,
                $username,
                'principals/'.$username,
            ]);   
            }
            
            return 'principals/' . $username;
        } else {
            return false;
        }


    }


    
}
