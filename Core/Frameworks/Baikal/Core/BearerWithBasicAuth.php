<?php

namespace Baikal\Core;


use Sabre\HTTP;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
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
class BearerWithBasicAuth extends \Sabre\DAV\Auth\Backend\AbstractBearer 
{
    /**
     * Reference to PDO connection.
     *
     * @var PDO
     */
    protected $pdo;


    protected $realm;

    protected $tableName;

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
    function __construct(\PDO $pdo, $realm, $oauthURL, $tableName = 'users') {
        $this->pdo = $pdo;
        $this->oauthURL = $oauthURL;
        $this->realm = $realm;
        $this->tableName = $tableName;
        $this->httpClient = HttpClient::create([
            'verify_peer' => false,
        ]);
    }


    public function check(RequestInterface $request, ResponseInterface $response)
    
    {
        $auth = new HTTP\Auth\Bearer(
            $this->realm,
            $request,
            $response
        );

        $bearerToken = $auth->getToken($request);
        if (!$bearerToken) {
            $authBasic = new HTTP\Auth\Basic(
                $this->realm,
                $request,
                $response
            );

            $userpass = $authBasic->getCredentials();
            if (!$userpass) {
                return [false, "No 'Authorization: Bearer or Basic' header found. Either the client didn't send one, or the server is misconfigured"];
            }
            $principalUrl = $this->validateUserPass($userpass[0], $userpass[1]);
            if (!$principalUrl) {
                return [false, 'Username or password was incorrect'];
            }
            return [true, $principalUrl];
            
        }
        $principalUrl = $this->validateBearerToken($bearerToken);
        if (!$principalUrl) {
            return [false, 'Bearer token was incorrect'];
        }

        return [true, $principalUrl];
    }




    function validateBearerToken($bearerToken) {
        $response = $this->httpClient->request('GET', $this->oauthURL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
            ],
        ]);

        if ($response->getStatusCode() === 200) {
            $content = json_decode($response->getContent());
            // Обработка содержимого ответа
            $username = $content->preferred_username;
            $email = $content->email;



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
            // Обработка ошибки
            return false;
        }
    }



    function validateUserPass($username, $password) {
        $stmt = $this->pdo->prepare('SELECT username, digesta1 FROM ' . $this->tableName . ' WHERE username = ?');
        $stmt->execute([$username]);
        $result = $stmt->fetchAll();

        if (!count($result)) {
            return false;
        }

        $hash = md5($username . ':' . $this->realm . ':' . $password);
        if ($result[0]['digesta1'] === $hash) {
            return 'principals/' . $username;;
        }

        return false;
    }


    
}
