<?php

namespace Helpers\Auth;

use Helpers\Database;
use Helpers\Cookie;

class Auth {

    protected $db;
    public $errormsg;
    public $successmsg;
    public $lang;

    public function __construct() {
        new \Helpers\Auth\Setup(); // loads Setup
        $this->lang = include 'Lang.php'; //language file messages
        $this->db = Database::get();
        $this->expireattempt(); //expire attempts
    }

    /**
     * Log user in via MySQL Database
     * @param string $username
     * @param string $password
     * @return boolean
     */
    public function login($username, $password) {
        if (!Cookie::get("auth_session")) {
            $attcount = $this->getattempt($_SERVER['REMOTE_ADDR']);

            if ($attcount[0]->count >= MAX_ATTEMPTS) {
                $this->errormsg[] = $this->lang['login_lockedout'];
                $waittime = preg_replace("/[^0-9]/", "", SECURITY_DURATION);
                $this->errormsg[] = sprintf($this->lang['login_wait'], $waittime);
                return false;
            } else {
                // Input verification :
                if (strlen($username) == 0) {
                    $this->errormsg[] = $this->lang['login_username_empty'];
                    return false;
                } elseif (strlen($username) > MAX_USERNAME_LENGTH) {
                    $this->errormsg[] = $this->lang['login_username_long'];
                    return false;
                } elseif (strlen($username) < MIN_USERNAME_LENGTH) {
                    $this->errormsg[] = $this->lang['login_username_short'];
                    return false;
                } elseif (strlen($password) == 0) {
                    $this->errormsg[] = $this->lang['login_password_empty'];
                    return false;
                } elseif (strlen($password) > MAX_PASSWORD_LENGTH) {
                    $this->errormsg[] = $this->lang['login_password_short'];
                    return false;
                } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
                    $this->errormsg[] = $this->lang['login_password_long'];
                    return false;
                } else {
                    // Input is valid
                    $query = $this->db->select('SELECT isactive,password FROM users WHERE username=:username', array(':username' => $username));
                    $count = count($query);
                    $hashed_db_password = $query[0]->password;
                    $verify_password = \Helpers\Password::verify($password, $hashed_db_password);
                    if ($count == 0 || $verify_password == 0) {
                        // Username or password are wrong
                        $this->errormsg[] = $this->lang['login_incorrect'];
                        $this->addattempt($_SERVER['REMOTE_ADDR']);
                        $attcount[0]->count = $attcount[0]->count + 1;
                        $remaincount = (int) MAX_ATTEMPTS - $attcount[0]->count;
                        $this->LogActivity("UNKNOWN", "AUTH_LOGIN_FAIL", "Username / Password incorrect - {$username} / {$password}");
                        $this->errormsg[] = sprintf($this->lang['login_attempts_remaining'], $remaincount);
                        return false;
                    } else {
                        // Username and password are correct
                        if ($query[0]->isactive == "0") {
                            // Account is not activated
                            $this->LogActivity($username, "AUTH_LOGIN_FAIL", "Account inactive");
                            $this->errormsg[] = $this->lang['login_account_inactive'];
                            return false;
                        } else {
                            // Account is activated
                            $this->newsession($username); //generate new cookie session
                            $this->LogActivity($username, "AUTH_LOGIN_SUCCESS", "User logged in");
                            $this->successmsg[] = $this->lang['login_success'];
                            return true;
                        }
                    }
                }
            }
        } else {
            // User is already logged in
            $this->errormsg[] = $this->lang['login_already']; // Is an user already logged in an error?
            return true; // its true because is logged in if not the function would not allow to log in
        }
    }

    /**
     * Logs out an user, deletes all sessions and destroys the cookies
     */
    public function logout() {
        $auth_session = Cookie::get("auth_session");
        if ($auth_session != '') {
            $this->deletesession($auth_session);
        }
    }

    /**
     * Checks if current user is logged or  not 
     * @return boolean
     */
    public function isLogged() {
        $auth_session = Cookie::get("auth_session"); //get hash from browser
        //check if session is valid
        if ($auth_session != '' && $this->sessionIsValid($auth_session)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Provides an associateve array with current user's info
     * @return array 
     */
    public function currentSessionInfo() {
        if ($this->isLogged()) {
            $auth_session = Cookie::get("auth_session"); //get hash from browser
            return $this->sessionInfo($auth_session);
        }
    }

    /**
     * Provides an associative array of user info based on session hash
     * @param string $hash
     * @return array $session
     */
    private function sessionInfo($hash) {
        $query = $this->db->select("SELECT uid, username, expiredate, ip FROM sessions WHERE hash=:hash", array(':hash' => $hash));
        $count = count($query);
        if ($count == 0) {
            // Hash doesn't exist
            $this->errormsg[] = $this->lang['sessioninfo_invalid'];
            setcookie("auth_session", $hash, time() - 3600, '/');
            //   \Helpers\Cookie::set("auth_session", $hash, time() - 3600, "/",$_SERVER["HTTP_HOST"]);
            return false;
        } else {
            // Hash exists
            $session["uid"] = $query[0]->uid;
            $session["username"] = $query[0]->username;
            $session["expiredate"] = $query[0]->expiredate;
            $session["ip"] = $query[0]->ip;
            return $session;
        }
    }

    /**
     * Checks if a hash session is valid on database
     * @param string $hash
     * @return boolean
     */
    private function sessionIsValid($hash) {
        //if hash in db
        $sql = "SELECT username, expiredate, ip FROM sessions WHERE hash=:hash";
        $session = $this->db->select($sql, array(":hash" => $hash));
        $count = count($session);
        if ($count == 0) {
            //hash did not exists deleting cookie
            setcookie("auth_session", $hash, time() - 3600, "/");
            $this->LogActivity('UNKNOWN', "AUTH_CHECKSESSION", "User session cookie deleted - Hash ({$hash}) didn't exist");
            return false;
        } else {
            $username = $session[0]->username;
            $db_expiredate = $session[0]->expiredate;
            $db_ip = $session[0]->ip;
            if ($_SERVER['REMOTE_ADDR'] != $db_ip) {
                //hash exists but ip is changed, delete session and hash
                $this->db->delete('sessions', array('username' => $username));
                setcookie("auth_session", $hash, time() - 3600, "/");
                $this->LogActivity($username, "AUTH_CHECKSESSION", "User session cookie deleted - IP Different ( DB : {$db_ip} / Current : " . $_SERVER['REMOTE_ADDR'] . " )");
                return false;
            } else {
                $expiredate = strtotime($db_expiredate);
                $currentdate = strtotime(date("Y-m-d H:i:s"));
                if ($currentdate > $expiredate) {
                    //session has expired delete session and cookies
                    $this->db->delete('sessions', array('username' => $username));
                    setcookie("auth_session", $hash, time() - 3600, "/");
                    $this->LogActivity($username, "AUTH_CHECKSESSION", "User session cookie deleted - Session expired ( Expire date : {$db_expiredate} )");
                } else {
                    //all ok
                    return true;
                }
            }
        }
    }

    /**
     * Provides amount of attempts already in database based on user's IP
     * @param string $ip
     * @return int $attempt_count
     */
    function getattempt($ip) {
        $attempt_count = $this->db->select("SELECT count FROM attempts WHERE ip=:ip", array(':ip' => $ip));
        $count = count($attempt_count);
        if ($count == 0) {
            $attempt_count[]->count = 0;
        }
        return $attempt_count;
    }

    /*
     * Adds a new attempt to database based on user's IP
     * @param string $ip
     */

    function addattempt($ip) {
        $query_attempt = $this->db->select("SELECT count FROM attempts WHERE ip=:ip", array(':ip' => $ip));
        $count = count($query_attempt);
        $attempt_expiredate = date("Y-m-d H:i:s", strtotime(SECURITY_DURATION));
        if ($count == 0) {
            // No record of this IP in attempts table already exists, create new
            $attempt_count = 1;
            $this->db->insert('attempts', array('ip' => $ip, 'count' => $attempt_count, 'expiredate' => $attempt_expiredate));
        } else {
            // IP Already exists in attempts table, add 1 to current count
            $attempt_count = intval($query_attempt[0]->count) + 1;
            $this->db->update('attempts', array('count' => $attempt_count, 'expiredate' => $attempt_expiredate), array('ip' => $ip));
        }
    }

    /**
     * Used to remove expired attempt logs from database
     * (Currently used in construct but need more testing)
     */
    function expireattempt() {
        $query_attempts = $this->db->select("SELECT ip, expiredate FROM attempts");
        $count = count($query_attempts);
        $curr_time = strtotime(date("Y-m-d H:i:s"));
        if ($count != 0) {
            foreach ($query_attempts as $attempt) {
                $attempt_expiredate = strtotime($attempt->expiredate);
                if ($attempt_expiredate <= $curr_time) {
                    $where = array('ip' => $attempt->ip);
                    $this->db->delete('attempts', $where);
                }
            }
        }
    }

    /**
     * Creates a new session for the provided username and sets cookie
     * @param string $username
     */
    private function newsession($username) {
        $hash = md5(microtime()); // unique session hash
        // Fetch User ID :		
        $queryUid = $this->db->select("SELECT id FROM users WHERE username=:username", array(':username' => $username));
        $uid = $queryUid[0]->id;
        // Delete all previous sessions :
        $this->db->delete('sessions', array('username' => $username));
        $ip = $_SERVER['REMOTE_ADDR'];
        $expiredate = date("Y-m-d H:i:s", strtotime(SESSION_DURATION));
        $expiretime = strtotime($expiredate);
        $this->db->insert('sessions', array('uid' => $uid, 'username' => $username, 'hash' => $hash, 'expiredate' => $expiredate, 'ip' => $ip));
        setcookie("auth_session", $hash, $expiretime, "/");
    }

    /**
     * Deletes a session based on a hash
     * @param string $hash
     */
    private function deletesession($hash) {

        $query_username = $this->db->select('SELECT username FROM sessions WHERE hash=:hash', array(':hash' => $hash));
        $count = count($query_username);
        if ($count == 0) {
            // Hash doesn't exist
            $this->LogActivity("UNKNOWN", "AUTH_LOGOUT", "User session cookie deleted - Database session not deleted - Hash ({$hash}) didn't exist");
            $this->errormsg[] = $this->lang['deletesession_invalid'];
            setcookie("auth_session", $hash, time() - 3600, "/");
        } else {
            $username = $query_username[0]->username;
            // Hash exists, Delete all sessions for that username :
            $this->db->delete('sessions', array('username' => $username));
            $this->LogActivity($username, "AUTH_LOGOUT", "User session cookie deleted - Database session deleted - Hash ({$hash})");
            setcookie("auth_session", $hash, time() - 3600, "/");
        }
    }

    /**
     * Directly register an user without sending email confirmation
     * @param string $username
     * @param string $password
     * @param string $verifypassword
     * @param string $email
     * @return boolean If succesfully registered true false otherwise
     */
    public function directRegister($username, $password, $verifypassword, $email) {
        if (!Cookie::get('auth_session')) {
            // Input Verification :
            if (strlen($username) == 0) {
                $this->errormsg[] = $this->lang['register_username_empty'];
            } elseif (strlen($username) > 30) {
                $this->errormsg[] = $this->lang['register_username_long'];
            } elseif (strlen($username) < 3) {
                $this->errormsg[] = $this->lang['register_username_short'];
            }
            if (strlen($password) == 0) {
                $this->errormsg[] = $this->lang['register_password_empty'];
            } elseif (strlen($password) > 30) {
                $this->errormsg[] = $this->lang['register_password_long'];
            } elseif (strlen($password) < 5) {
                $this->errormsg[] = $this->lang['register_password_short'];
            } elseif ($password !== $verifypassword) {
                $this->errormsg[] = $this->lang['register_password_nomatch'];
            } elseif (strstr($password, $username)) {
                $this->errormsg[] = $this->lang['register_password_username'];
            }
            if (strlen($email) == 0) {
                $this->errormsg[] = $this->lang['register_email_empty'];
            } elseif (strlen($email) > 100) {
                $this->errormsg[] = $this->lang['register_email_long'];
            } elseif (strlen($email) < 5) {
                $this->errormsg[] = $this->lang['register_email_short'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->errormsg[] = $this->lang['register_email_invalid'];
            }
            if (count($this->errormsg) == 0) {
                // Input is valid 
                $query = $this->db->select("SELECT * FROM users WHERE username=:username", array(':username' => $username));
                $count = count($query);
                if ($count != 0) {
                    //ya existe el usuario
                    $this->LogActivity("UNKNOWN", "AUTH_REGISTER_FAIL", "Username ({$username}) already exists");
                    $this->errormsg[] = $this->lang['register_username_exist'];
                    return false;
                } else {
                    //usuario esta libre
                    $query = $this->db->select('SELECT * FROM users WHERE email=:email', array(':email' => $email));
                    $count = count($query);
                    if ($count != 0) {
                        //ya existe el email
                        $this->LogActivity("UNKNOWN", "AUTH_REGISTER_FAIL", "Email ({$email}) already exists");
                        $this->errormsg[] = $this->lang['register_email_exist'];
                        return false;
                    } else {
                        //todo bien continua con registr
                        $password = $this->hashpass($password);
                        $activekey = $this->randomkey(15); //genera una randomkey para activacion enviar por email
                        $this->db->insert('users', array('username' => $username, 'password' => $password, 'email' => $email, 'activekey' => $activekey));
                        //$last_insert_id = $this->db->lastInsertId('id');
                        $this->LogActivity($username, "AUTH_REGISTER_SUCCESS", "Account created");
                        $this->successmsg[] = $this->lang['register_success'];
                        //activar usuario directamente
                        $this->activateAccount($username, $activekey); //se ignora la activekey ya que es directo
                        return true;
                    }
                }
            } else {
                return false; //algun error
            }
        } else {
            // User is logged in
            $this->errormsg[] = $this->lang['register_email_loggedin'];
            return false;
        }
    }

    /*
     * Register a new user into the database
     * @param string $username
     * @param string $password
     * @param string $verifypassword
     * @param string $email
     * @return boolean
     */

    function register($username, $password, $verifypassword, $email) {
        if (!Cookie::get('auth_session')) {
            // Input Verification :
            if (strlen($username) == 0) {
                $this->errormsg[] = $this->lang['register_username_empty'];
            } elseif (strlen($username) > 30) {
                $this->errormsg[] = $this->lang['register_username_long'];
            } elseif (strlen($username) < 3) {
                $this->errormsg[] = $this->lang['register_username_short'];
            }
            if (strlen($password) == 0) {
                $this->errormsg[] = $this->lang['register_password_empty'];
            } elseif (strlen($password) > 30) {
                $this->errormsg[] = $this->lang['register_password_long'];
            } elseif (strlen($password) < 5) {
                $this->errormsg[] = $this->lang['register_password_short'];
            } elseif ($password !== $verifypassword) {
                $this->errormsg[] = $this->lang['register_password_nomatch'];
            } elseif (strstr($password, $username)) {
                $this->errormsg[] = $this->lang['register_password_username'];
            }
            if (strlen($email) == 0) {
                $this->errormsg[] = $this->lang['register_email_empty'];
            } elseif (strlen($email) > 100) {
                $this->errormsg[] = $this->lang['register_email_long'];
            } elseif (strlen($email) < 5) {
                $this->errormsg[] = $this->lang['register_email_short'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->errormsg[] = $this->lang['register_email_invalid'];
            }
            if (count($this->errormsg) == 0) {
                // Input is valid
                $query = $this->db->select("SELECT * FROM users WHERE username=:username", array(':username' => $username));
                $count = count($query);
                if ($count != 0) {
                    // Username already exists
                    $this->LogActivity("UNKNOWN", "AUTH_REGISTER_FAIL", "Username ({$username}) already exists");
                    $this->errormsg[] = $this->lang['register_username_exist'];
                    return false;
                } else {
                    // Username is not taken
                    $query = $this->db->select('SELECT * FROM users WHERE email=:email', array(':email' => $email));
                    $count = count($query);
                    if ($count != 0) {
                        // Email address is already used
                        $this->LogActivity("UNKNOWN", "AUTH_REGISTER_FAIL", "Email ({$email}) already exists");
                        $this->errormsg[] = $this->lang['register_email_exist'];
                        return false;
                    } else {
                        // Email address isn't already used
                        $password = $this->hashpass($password);
                        $activekey = $this->randomkey(15);
                        $this->db->insert('users', array('username' => $username, 'password' => $password, 'email' => $email, 'activekey' => $activekey));
                        //EMAIL MESSAGE    
                        $message_from = EMAIL_FROM;
                        $message_subj = SITE_NAME;  //$auth_conf['site_name'] . " - Account activation required !";
                        $message_cont = "Hello {$username}<br/><br/>";
                        $message_cont .= "You recently registered a new account on " . SITE_NAME . "<br/>";
                        $message_cont .= "To activate your account please click the following link<br/><br/>";
                        $message_cont .= "<b><a href=\"" . BASE_URL . "?page=activate&username={$username}&key={$activekey}\">Activate my account</a></b>";
                        $message_head = "From: {$message_from}" . "\r\n";
                        $message_head .= "MIME-Version: 1.0" . "\r\n";
                        $message_head .= "Content-type: text/html; charset=iso-8859-1" . "\r\n";
                        mail($email, $message_subj, $message_cont, $message_head);
                        $this->LogActivity($username, "AUTH_REGISTER_SUCCESS", "Account created and activation email sent");
                        $this->successmsg[] = $this->lang['register_success'];
                        return true;
                    }
                }
            } else {
                return false;
            }
        } else {
            // User is logged in
            $this->errormsg[] = $this->lang['register_email_loggedin'];
            return false;
        }
    }

    /**
     * Activates an account 
     * @param string $username
     * @param string $key
     */
    public function activateAccount($username, $key) {
        $this->db->update('users', array('isactive' => 1, 'activekey' => $key), array('username' => $username));
        $this->LogActivity($username, "AUTH_ACTIVATE_SUCCESS", "Activation successful. Key Entry deleted.");
        $this->successmsg[] = $this->lang['activate_success'];
    }

    /**
     * Logs users actions on the site to database for future viewing
     * @param string $username
     * @param string $action
     * @param string $additionalinfo
     * @return boolean
     */
    public function LogActivity($username, $action, $additionalinfo = "none") {
        if (strlen($username) == 0) {
            $username = "GUEST";
        } elseif (strlen($username) < 3) {
            $this->errormsg[] = $this->lang['logactivity_username_short'];
            return false;
        } elseif (strlen($username) > 30) {
            $this->errormsg[] = $this->lang['logactivity_username_long'];
            return false;
        }
        if (strlen($action) == 0) {
            $this->errormsg[] = $this->lang['logactivity_action_empty'];
            return false;
        } elseif (strlen($action) < 3) {
            $this->errormsg[] = $this->lang['logactivity_action_short'];
            return false;
        } elseif (strlen($action) > 100) {
            $this->errormsg[] = $this->lang['logactivity_action_long'];
            return false;
        }
        if (strlen($additionalinfo) == 0) {
            $additionalinfo = "none";
        } elseif (strlen($additionalinfo) > 500) {
            $this->errormsg[] = $this->lang['logactivity_addinfo_long'];
            return false;
        }
        if (count($this->errormsg) == 0) {
            $ip = $_SERVER['REMOTE_ADDR'];
            $date = date("Y-m-d H:i:s");
            $this->db->insert('activitylog', array('date' => $date, 'username' => $username, 'action' => $action, 'additionalinfo' => $additionalinfo, 'ip' => $ip));
            return true;
        }
    }

    /**
     * Hash user's password with BCRYPT algorithm and non static salt !
     * @param string $password
     * @return string $hashed_password
     */
    private function hashpass($password) {
        // this options should be on 
        $options = [
            'cost' => COST,
            'salt' => mcrypt_create_iv(HASH_LENGTH, MCRYPT_DEV_URANDOM)
        ];
        $hashed_password = \Helpers\Password::make($password, PASSWORD_BCRYPT, $options);
        return $hashed_password;
    }

    /**
     * Returns a random string, length can be modified
     * @param int $length
     * @return string $key
     */
    private function randomkey($length = 10) {
        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890";
        $key = "";
        for ($i = 0; $i < $length; $i++) {
            $key .= $chars{rand(0, strlen($chars) - 1)};
        }
        return $key;
    }

    /*
     * Changes a user's password, providing the current password is known
     * @param string $username
     * @param string $currpass
     * @param string $newpass
     * @param string $verifynewpass
     * @return boolean
     */

    function changepass($username, $currpass, $newpass, $verifynewpass) {
        if (strlen($username) == 0) {
            $this->errormsg[] = $this->lang['changepass_username_empty'];
        } elseif (strlen($username) > 30) {
            $this->errormsg[] = $this->lang['changepass_username_long'];
        } elseif (strlen($username) < 3) {
            $this->errormsg[] = $this->lang['changepass_username_short'];
        }
        if (strlen($currpass) == 0) {
            $this->errormsg[] = $this->lang['changepass_currpass_empty'];
        } elseif (strlen($currpass) < 5) {
            $this->errormsg[] = $this->lang['changepass_currpass_short'];
        } elseif (strlen($currpass) > 30) {
            $this->errormsg[] = $this->lang['changepass_currpass_long'];
        }
        if (strlen($newpass) == 0) {
            $this->errormsg[] = $this->lang['changepass_newpass_empty'];
        } elseif (strlen($newpass) < 5) {
            $this->errormsg[] = $this->lang['changepass_newpass_short'];
        } elseif (strlen($newpass) > 30) {
            $this->errormsg[] = $this->lang['changepass_newpass_long'];
        } elseif (strstr($newpass, $username)) {
            $this->errormsg[] = $this->lang['changepass_password_username'];
        } elseif ($newpass !== $verifynewpass) {
            $this->errormsg[] = $this->lang['changepass_password_nomatch'];
        }

        if (count($this->errormsg) == 0) {
            $currpass = $this->hashpass($currpass);
            $newpass = $this->hashpass($newpass);
            $query = $this->db->select("SELECT password FROM users WHERE username=:username", array(':username' => $username));
            $count = count($query);
            
            if ($count == 0) {
                $this->LogActivity("UNKNOWN", "AUTH_CHANGEPASS_FAIL", "Username Incorrect ({$username})");
                $this->errormsg[] = $this->lang['changepass_username_incorrect'];
                return false;
            } else {
                $db_currpass = $query[0]->password;
                if ($currpass == $db_currpass) {
                    $this->db->update('users', array('password' => $newpass), array('username' => $username));
                    $this->LogActivity($username, "AUTH_CHANGEPASS_SUCCESS", "Password changed");
                    $this->successmsg[] = $this->lang['changepass_success'];
                    return true;
                } else {
                    $this->LogActivity($username, "AUTH_CHANGEPASS_FAIL", "Current Password Incorrect ( DB : {$db_currpass} / Given : {$currpass} )");
                    $this->errormsg[] = $this->lang['changepass_currpass_incorrect'];
                    return false;
                }
            }
        } else {
            return false;
        }
    }

}
