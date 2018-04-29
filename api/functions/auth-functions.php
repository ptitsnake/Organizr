<?php
function authRegister($username, $password, $defaults, $email)
{
    $defaults = defaultUserGroup();
    if (createUser($username, $password, $defaults, $email)) {
        writeLog('success', 'Registration Function - A User has registered', $username);
        if ($GLOBALS['PHPMAILER-enabled']) {
            $emailTemplate = array(
                'type' => 'registration',
                'body' => $GLOBALS['PHPMAILER-emailTemplateRegisterUser'],
                'subject' => $GLOBALS['PHPMAILER-emailTemplateRegisterUserSubject'],
                'user' => $username,
                'password' => null,
                'inviteCode' => null,
            );
            $emailTemplate = phpmEmailTemplate($emailTemplate);
            $sendEmail = array(
                'to' => $email,
                'user' => $username,
                'subject' => $emailTemplate['subject'],
                'body' => phpmBuildEmail($emailTemplate),
            );
            phpmSendEmail($sendEmail);
        }
        if (createToken($username, $email, gravatar($email), $defaults['group'], $defaults['group_id'], $GLOBALS['organizrHash'], 7)) {
            writeLoginLog($username, 'success');
            writeLog('success', 'Login Function - A User has logged in', $username);
            return true;
        }
    } else {
        writeLog('error', 'Registration Function - An error occured', $username);
        return 'username taken';
    }
}
function checkPlexUser($username)
{
    try {
        if (!empty($GLOBALS['plexToken'])) {
            $url = 'https://plex.tv/pms/friends/all';
            $headers = array(
                'X-Plex-Token' => $GLOBALS['plexToken'],
            );
            $response = Requests::get($url, $headers);
            if ($response->success) {
                libxml_use_internal_errors(true);
                $userXML = simplexml_load_string($response->body);
                if (is_array($userXML) || is_object($userXML)) {
                    $usernameLower = strtolower($username);
                    foreach ($userXML as $child) {
                        if (isset($child['username']) && strtolower($child['username']) == $usernameLower || isset($child['email']) && strtolower($child['email']) == $usernameLower) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    } catch (Requests_Exception $e) {
        writeLog('success', 'Plex User Check Function - Error: '.$e->getMessage(), $username);
    };
}
function plugin_auth_plex($username, $password)
{
    try {
        $usernameLower = strtolower($username);
        if ((!empty($GLOBALS['plexAdmin']) && strtolower($GLOBALS['plexAdmin']) == $usernameLower) || checkPlexUser($username)) {
            //Login User
            $url = 'https://plex.tv/users/sign_in.json';
            $headers = array(
                'Accept'=> 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'X-Plex-Product' => 'Organizr',
                'X-Plex-Version' => '2.0',
                'X-Plex-Client-Identifier' => '01010101-10101010',
            );
            $data = array(
                'user[login]' => $username,
                'user[password]' => $password,
            );
            $response = Requests::post($url, $headers, $data);
            if ($response->success) {
                $json = json_decode($response->body, true);
                if ((is_array($json) && isset($json['user']) && isset($json['user']['username'])) && strtolower($json['user']['username']) == $usernameLower || strtolower($json['user']['email']) == $usernameLower) {
                    //writeLog("success", $json['user']['username']." was logged into organizr using plex credentials");
                    return array(
                        'username' => $json['user']['username'],
                        'email' => $json['user']['email'],
                        'image' => $json['user']['thumb'],
                        'token' => $json['user']['authToken']
                    );
                }
            }
        }
        return false;
    } catch (Requests_Exception $e) {
        writeLog('success', 'Plex Auth Function - Error: '.$e->getMessage(), $username);
    };
}
if (function_exists('ldap_connect')) {
    // Pass credentials to LDAP backend
    function plugin_auth_ldap($username, $password)
    {
        if (!empty($GLOBALS['authBaseDN']) && !empty($GLOBALS['authBackendHost'])) {
            $ldapServers = explode(',', $GLOBALS['authBackendHost']);
            foreach ($ldapServers as $key => $value) {
                // Calculate parts
                $digest = parse_url(trim($value));
                $scheme = strtolower((isset($digest['scheme'])?$digest['scheme']:'ldap'));
                $host = (isset($digest['host'])?$digest['host']:(isset($digest['path'])?$digest['path']:''));
                $port = (isset($digest['port'])?$digest['port']:(strtolower($scheme)=='ldap'?389:636));
                // Reassign
                $ldapServers[$key] = $scheme.'://'.$host.':'.$port;
            }
            $ldap = ldap_connect(implode(' ', $ldapServers));
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            $bind = @ldap_bind($ldap, sprintf($GLOBALS['authBaseDN'], $username), $password);
            return ($bind) ? true : false;
        }
        return false;
    }
} else {
    // Ldap Auth Missing Dependancy
    function plugin_auth_ldap_disabled()
    {
        return 'LDAP - Disabled (Dependancy: php-ldap missing!)';
    }
}
// Pass credentials to FTP backend
function plugin_auth_ftp($username, $password)
{
    // Calculate parts
    $digest = parse_url($GLOBALS['authBackendHost']);
    $scheme = strtolower((isset($digest['scheme'])?$digest['scheme']:(function_exists('ftp_ssl_connect')?'ftps':'ftp')));
    $host = (isset($digest['host'])?$digest['host']:(isset($digest['path'])?$digest['path']:''));
    $port = (isset($digest['port'])?$digest['port']:21);
    // Determine Connection Type
    if ($scheme == 'ftps') {
        $conn_id = ftp_ssl_connect($host, $port, 20);
    } elseif ($scheme == 'ftp') {
        $conn_id = ftp_connect($host, $port, 20);
    } else {
        return false;
    }
    // Check if valid FTP connection
    if ($conn_id) {
        // Attempt login
        @$login_result = ftp_login($conn_id, $username, $password);
        ftp_close($conn_id);
        // Return Result
        if ($login_result) {
            return true;
        } else {
            return false;
        }
    } else {
        return false;
    }
    return false;
}

// Pass credentials to Emby Backend
function plugin_auth_emby_local($username, $password)
{
    try {
        $url = qualifyURL($GLOBALS['embyURL']).'/Users/AuthenticateByName';
        $headers = array(
            'Authorization'=> 'MediaBrowser UserId="e8837bc1-ad67-520e-8cd2-f629e3155721", Client="None", Device="Organizr", DeviceId="xxx", Version="1.0.0.0"',
            'Content-Type' => 'application/json',
        );
        $data = array(
            'Username' => $username,
            'Password' => sha1($password),
            'PasswordMd5' => md5($password),
        );
        $response = Requests::post($url, $headers, json_encode($data));
        if ($response->success) {
            $json = json_decode($response->body, true);
            if (is_array($json) && isset($json['SessionInfo']) && isset($json['User']) && $json['User']['HasPassword'] == true) {
                // Login Success - Now Logout Emby Session As We No Longer Need It
                $headers = array(
                    'X-Mediabrowser-Token' => $json['AccessToken'],
                );
                $response = Requests::post(qualifyURL($GLOBALS['embyURL']).'/Sessions/Logout', $headers, array());
                return true;
            }
        }
        return false;
    } catch (Requests_Exception $e) {
        writeLog('error', 'Emby Local Auth Function - Error: '.$e->getMessage(), $username);
    };
}
// Authenicate against emby connect
function plugin_auth_emby_connect($username, $password)
{
    try {
        // Get A User
        $connectId = '';
        $url = qualifyURL($GLOBALS['embyURL']).'/Users?api_key='.$GLOBALS['embyToken'];
        $response = Requests::get($url);
        if ($response->success) {
            $json = json_decode($response->body, true);
            if (is_array($json)) {
                foreach ($json as $key => $value) { // Scan for this user
                    if (isset($value['ConnectUserName']) && isset($value['ConnectUserId'])) { // Qualifty as connect account
                        if ($value['ConnectUserName'] == $username || $value['Name'] == $username) {
                            $connectId = $value['ConnectUserId'];
                            writeLog('success', 'Emby Connect Auth Function - Found User', $username);
                            break;
                        }
                    }
                }
                if ($connectId) {
                    $connectURL = 'https://connect.emby.media/service/user/authenticate';
                    $headers = array(
                        'Accept'=> 'application/json',
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    );
                    $data = array(
                        'nameOrEmail' => $username,
                        'rawpw' => $password,
                    );
                    $response = Requests::post($connectURL, $headers, $data);
                    if ($response->success) {
                        $json = json_decode($response->body, true);
                        if (is_array($json) && isset($json['AccessToken']) && isset($json['User']) && $json['User']['Id'] == $connectId) {
                            return array(
                                'email' => $json['User']['Email'],
                                'image' => $json['User']['ImageUrl'],
                            );
                        }
                    }
                }
            }
        }
        return false;
    } catch (Requests_Exception $e) {
        writeLog('error', 'Emby Connect Auth Function - Error: '.$e->getMessage(), $username);
        return false;
    };
}
// Authenticate Against Emby Local (first) and Emby Connect
function plugin_auth_emby_all($username, $password)
{
    $localResult = plugin_auth_emby_local($username, $password);
    if ($localResult) {
        return $localResult;
    } else {
        return plugin_auth_emby_connect($username, $password);
    }
}
