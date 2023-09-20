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
class BasicWithBearer extends \Sabre\DAV\Auth\Backend\AbstractBasic {
    /**
     * Reference to PDO connection.
     *
     * @var PDO
     */
    protected $pdo;

    /**
     * PDO table name we'll be using.
     *
     * @var string
     */
    protected $tableName;
    protected $principalsTableName;

    /**
     * Authentication realm.
     *
     * @var string
     */
    protected $authRealm;

    protected $oauthUrlValidateJwt;
    protected $oauthUsernameField;
    protected $oauthEmailField;

    protected $oauthUrlValidateCredentials;
    protected $oauthClientId;

    private $httpClient;

    /**
     * @var string
     */
    private $currentUser;
    





    /**
     * Creates the backend object.
     *
     * If the filename argument is passed in, it will parse out the specified file fist.
     *
     * @param PDO $pdo
     * @param string $tableName The PDO table name to use
     */
    function __construct(\PDO $pdo, $authRealm, $oauthUrlValidateJwt, $oauthUsernameField, $oauthEmailField, $oauthUrlValidateCredentials, $oauthClientId, $tableName = 'users') {
        $this->pdo = $pdo;
        $this->tableName = $tableName;
        $this->authRealm = $authRealm;
        $this->oauthUrlValidateJwt = $oauthUrlValidateJwt;
        $this->oauthUsernameField = $oauthUsernameField;
        $this->oauthEmailField = $oauthEmailField;
        $this->oauthUrlValidateCredentials = $oauthUrlValidateCredentials;
        $this->oauthClientId = $oauthClientId;
        $this->httpClient = HttpClient::create([
            'verify_peer' => false,
        ]);
    }



    public function check(RequestInterface $request, ResponseInterface $response)
    {
        $auth = new HTTP\Auth\Basic(
            $this->realm,
            $request,
            $response
        );

        $userpass = $auth->getCredentials();
        if (!$userpass) {
            $auth = new HTTP\Auth\Bearer(
                $this->realm,
                $request,
                $response
            );
            $bearerToken = $auth->getToken($request);
            if (!$bearerToken) {
                return [false, "No 'Authorization: Basic or Bearer' header found. Either the client didn't send one, or the server is misconfigured"];
            }
            $principalUrl = $this->validateBearerToken($bearerToken);
            if (!$principalUrl) {
                return [false, 'Bearer token was incorrect'];
            }
    
            return [true, $principalUrl];
            
            
        }
        if (!$this->validateUserPass($userpass[0], $userpass[1])) {
            return [false, 'Username or password was incorrect'];
        }

        return [true, $this->principalPrefix.$userpass[0]];
    }

    /**
     * Validates a username and password.
     *
     * This method should return true or false depending on if login
     * succeeded.
     *
     * @param string $username
     * @param string $password
     *
     * @return bool
     */
    function validateUserPass($username, $password) {

        $response = $this->httpClient->request('POST', $this->oauthUrlValidateCredentials, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded ',
            ],
            'body' => [
                'client_id' => $this->oauthClientId,
                'username' => $username,
                'password' => $password,
                'grant_type' => 'password'
            ]
        ]);

        if ($response->getStatusCode() === 200) {
            $this->currentUser = $username;

            return true;
        }

        return false;

    }




    function validateBearerToken($bearerToken) {
        $response = $this->httpClient->request('GET', $this->oauthUrlValidateJwt, [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
            ],
        ]);

        if ($response->getStatusCode() === 200) {
            $content = json_decode($response->getContent());

            $username = $content->{$this->oauthUsernameField};
            $email = $content->{$this->oauthEmailField};



            $stmt = $this->pdo->prepare('SELECT username FROM ' . $this->tableName . ' WHERE username = ?');
            $stmt->execute([$username]);
            $result = $stmt->fetchAll();

            if (!count($result)) {

                $stmt = $this->pdo->prepare("
                INSERT INTO users (username)
                VALUES (?);
            
                INSERT INTO principals (uri, email, displayname)
                VALUES (?, ?, ?);


                INSERT INTO addressbooks (principaluri, displayname, uri, description) 
                VALUES (?, 'Default Address Book', 'default', 'Default Address Book');


                INSERT INTO calendars (components) 
                VALUES ('VEVENT,VTODO');
                SET @calendar_id = LAST_INSERT_ID();


                INSERT INTO calendarinstances (calendarid, principaluri, access, displayname, uri, description, calendarorder, timezone, transparent, share_invitestatus)
                VALUES (@calendar_id, ?, 1, 'Default calendar', 'default', 'Default calendar', 0, 'Europe/Moscow' , 0, 2);
            ");

            $stmt->execute([
                $username,
                $this->principalPrefix . $username,
                $email,
                $username,
                $this->principalPrefix . $username,
                $this->principalPrefix . $username
            ]);   
            }


            return $this->principalPrefix . $username;
        } else {
         
            return false;
        }
    }
}