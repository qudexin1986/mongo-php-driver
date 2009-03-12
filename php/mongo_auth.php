<?php
/**
 *  Copyright 2009 10gen, Inc.
 * 
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 * 
 *  http://www.apache.org/licenses/LICENSE-2.0
 * 
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 *
 * PHP version 5 
 *
 * @category DB
 * @package  Mongo
 * @author   Kristina Chodorow <kristina@10gen.com>
 * @license  http://www.apache.org/licenses/LICENSE-2.0  Apache License 2
 * @version  CVS: 000000
 * @link     http://www.mongodb.org
 */


/**
 * Gets an authenticated database connection.
 *
 * A typical usage would be:
 * <code>
 * // initial login
 * $auth_connection = new MongoAuth("mydb", "joe", "mypass");
 * if (!$auth_connection->loggedIn) {
 *    return $auth_connection->error;
 * }
 * setcookie("username", "joe");
 * setcookie("password", MongoAuth::getHash("joe", "mypass"));
 * </code>
 *
 * Then, for subsequent sessions, the cookies can be used to log in:
 * <code>
 * $username = $_COOKIE['username'];
 * $password = $_COOKIE['password'];
 * $auth_connection = new MongoAuth("mydb", $username, $password, false);
 * </code>
 *
 * @category DB
 * @package  Mongo
 * @author   Kristina Chodorow <kristina@10gen.com>
 * @license  http://www.apache.org/licenses/LICENSE-2.0  Apache License 2
 * @link     http://www.mongodb.org
 */
class MongoAuth extends Mongo
{

    public $connection;
    private $db;

    /**
     * Communicates with the database to log in a user.
     * 
     * @param connection $conn     database connection
     * @param string     $db       db name
     * @param string     $username username
     * @param string     $password plaintext password
     *
     * @return array the database response
     */
    private static function _getUser($conn, $db, $username, $pwd) 
    {
        $ns = $db . ".system.users";

        // get the nonce
        $result = MongoUtil::dbCommand($conn, array(MongoUtil::$NONCE => 1 ), $db);
        if (!$result[ "ok" ]) {
            return false;
        }
        $nonce = $result[ "nonce" ];

        // create a digest of nonce/username/pwd
        $digest = md5($nonce . $username . $pwd);
        $data   = array(MongoUtil::$AUTHENTICATE => 1, 
                        "user" => $username, 
                        "nonce" => $nonce,
                        "key" => $digest);

        // send everything to the db and pray
        return MongoUtil::dbCommand($conn, $data, $db);
    }

    /**
     * Creates a hashed string from the username and password.
     * This string can be passed to MongoAuth->__construct as the password
     * with $plaintext set to false in order to login.
     *
     * @param string $username the username
     * @param string $password the password
     *
     * @return string the md5 hash of the username and password
     */
    public static function getHash($username, $password) {
        return md5("${username}:mongo:${password}");
    }

    /**
     * Attempt to create a new authenticated session.
     *
     * @param string $db         the name of the db
     * @param string $username   the username
     * @param string $password   the password
     * @param string $host       a database connection
     * @param string $port       a database connection
     *
     * @return MongoAuth an authenticated session or false if login was unsuccessful
     */
    public function __construct($db, $username, $password, $plaintext=true, $host=null, $port=null) 
    {
        parent::__construct($host, $port);

        $this->db = $this->selectDB("$db");
        if ($plaintext) {
            $hash = MongoAuth::getHash($username, $password);
        }
        else {
            $hash = $password;
        }

        $result = MongoAuth::_getUser($this->connection, $db, $username, $hash);

        if ($result[ "ok" ] != 1) {
          $this->error = "couldn't log in";
          $this->code = -3;
          $this->loggedIn = false;
          return;
        }

        $this->loggedIn = true;
    }


    /**
     * Creates a new user.
     * This will not overwrite existing users, use MongoAuth::changePassword
     * to change a user's password.
     *
     * @param string $username the new user's username
     * @param string $password the new user's password
     * 
     * @return boolean if the new user was successfully created
     */
    public function addUser($username, $password) {
        $c = $this->db->selectCollection("system.users");
        $exists = $c->findOne(array("user" => $username));
        if ($exists) {
          return false;
        }
        $newUser = array("user" => $username,
                         "pwd" => MongoAuth::getHash($username, $password));
        $c->insert($newUser);
        return true;
    }



    /**
     * Changes a user's password.
     *
     * @param string $username the username
     * @param string $oldpass the old password
     * @param string $newpass the new password
     *
     * @return array whether the change was successful
     */
    public function changePassword($username, $oldpass, $newpass) {
        $c = $this->db->selectCollection("system.users");
        $user = $c->findOne(array("user" => $username));
        if (!$user) {
            return array("ok" => -2.0,
                         "errmsg" => "no user with username $username found");
        }
        if ($user['pwd'] == MongoAuth::getHash($username, $oldpass)) {
          $user['pwd'] = MongoAuth::getHash($username, $newpass);
          $c->update(array("user"=>$username), $user);
          return array("ok" => 1.0);
        }
        return array("ok" => -1.0,
                     "errmsg" => "incorrect old password");
    }


    /**
     * Delete a user.
     *
     * @param string $username the user to delete
     *
     * @return boolean if the user was deleted
     */
    public function deleteUser($username) {
      $c = $this->db->selectCollection("system.users");
      return $c->remove(array("user" => "$username"), true);
    }


    /**
     * Ends authenticated session.
     *
     * @return boolean if successfully ended
     */
    public function logout() 
    {
        $data   = array(MongoUtil::$LOGOUT => 1);
        $result = MongoUtil::dbCommand($this->connection, $data, $this->db);

        if (!$result[ "ok" ]) {
            // trapped in the system forever
            return false;
        }

        return true;
    }

}

/**
 * Gets an admin database connection.
 * 
 * @category DB
 * @package  Mongo
 * @author   Kristina Chodorow <kristina@10gen.com>
 * @license  http://www.apache.org/licenses/LICENSE-2.0  Apache License 2
 * @link     http://www.mongodb.org
 */
class MongoAdmin extends MongoAuth
{

    /**
     * Creates a new admin session.
     * 
     * @param string $username  username
     * @param string $password  password
     * @param bool   $plaintext in plaintext, vs. encrypted
     * @param string $host      hostname
     * @param string $port      port
     */
  public function __construct($username, $password, $plaintext=true, $host=null, $port=null) 
    {
      parent::__construct("admin", $username, $password, $plaintext, $host, $port);
    }

    /** 
     * Lists all of the databases.
     *
     * @return Array each database with its size and name
     */
    public function listDBs() 
    {
        $data   = array(MongoUtil::$LIST_DATABASES => 1);
        $result = MongoUtil::dbCommand($this->connection, $data, $this->db);
        if ($result) {
            return $result[ "databases" ];
        } else {
            return false;
        }
    }

    /**
     * Shuts down the database.
     *
     * @return bool if the database was successfully shut down
     */
    public function shutdown() 
    {
        $result = MongoUtil::dbCommand($this->connection, 
                                       array(MongoUtil::$SHUTDOWN => 1 ), 
                                       $this->db);
        return $result[ "ok" ];
    }

    /**
     * Turns logging on/off.
     *
     * @param int $level logging level
     *
     * @return bool if the logging level was set
     */
    public function setLogging($level ) 
    {
        $result = MongoUtil::dbCommand($this->connection, 
                                       array(MongoUtil::$LOGGING => (int)$level ), 
                                       $this->db);
        return $result[ "ok" ];
    }

    /**
     * Sets tracing level.
     *
     * @param int $level trace level
     *
     * @return bool if the tracing level was set
     */
    public function setTracing($level ) 
    {
        $result = MongoUtil::dbCommand($this->connection, 
                                       array(MongoUtil::$TRACING => (int)$level ), 
                                       $this->db);
        return $result[ "ok" ];
    }

    /**
     * Sets only the query tracing level.
     *
     * @param int $level trace level
     *
     * @return bool if the tracing level was set
     */
    public function setQueryTracing($level ) 
    {
        $result = MongoUtil::dbCommand($this->connection, 
                                       array(MongoUtil::$QUERY_TRACING => (int)$level ), 
                                       $this->db);
        return $result[ "ok" ];
    }

}

define("MONGO_LOG_OFF", 0);
define("MONGO_LOG_W", 1);
define("MONGO_LOG_R", 2);
define("MONGO_LOG_RW", 3);

define("MONGO_TRACE_OFF", 0);
define("MONGO_TRACE_SOME", 1);
define("MONGO_TRACE_ON", 2);


?>