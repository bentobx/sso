<?php

/**
 * A user class for all classes of users
 *
 * Member data is typically protected -- this is where non-critical personal data goes, stuff that might be shared with other trusted members. World-viewable data goes in the profile, private data goes in the user.
 * 
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class Member
{
  /*
  Generates a token for a specific user and returns the token
  */
  private static function generate_token($username, $length=20) 
  { 
    // check user
    if (!$user = UserLib::get_clean_user($username))
      return ErrorLib::set_error("There is no user with that username");

    // get member
    if(!$member = MongoLib::findOne('members', $user['id'])) 
      return ErrorLib::set_error("No such member exists");
    
    if(!$member['locker']['tokens'])
      $member['locker']['tokens'] = array();
               
    // generate token
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $num_chars = strlen($chars);
    
    $token['user'] = $user['id'];
    $token['value'] = "";
    $token['valid'] = true;
    
    for ($i=0; $i < $length; $i++) { 
      $position = mt_rand(0, $num_chars - 1);
      $token['value'] .= $chars[$position];
    }
    
    $token_pointer = SecretsLib::add($token);    
    
    $member['locker']['tokens'][] = $token_pointer;
    $update['locker.tokens'] = $member['locker']['tokens'];
    MongoLib::set('members', $member['_id'], $update);
    
    return $token['value'];
  }

  /** 
  * Get some members
  * @param string User ids (the user id and the member id are the same)
  * @param string The member's plan (accepts 1x, 3x, unlimited, cohort and never)
  * @param string The member's status (accepts pending, rejected, trialing, paid or inbreach)
  * @param string Supports sort, limit, skip, fields, nofields, count and attrs: {* (:limit 5 :skip "30" :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find($by_ids=NULL, $options=NULL) 
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    return MongoLib::find_with_perms('members', $query, $options);
  }
  
  /** 
  * Emails the user matching the given username with a token that can be used to authenticate. 
  * @param string Username  
  * @return string 
  * @key __world
  */ 
  static function request_login($username)
  {
    $token = self::generate_token($username);
    
    $basedir = $GLOBALS['X']['VARS']['SITE']['path'];
    
    $message = sprintf("To log in to sso: sound off, click this link: %s/login?token=%s", $basedir, $token);
    
    $subject = "SSO login";
      
    mail($member['depot']['email'], $subject, $message, 'From:concierge@soba.com');
    
    History::add('members', $user['id'], array('action' => 'request_login'));
    
    return $username;
  }
  
  /** 
  * Generate a token that will allow me to log in  
  * @return string 
  * @key __member
  */ 
  static function tokenize_myself()
  {
      return self::generate_token($GLOBALS['X']['USER']['username']);
  }
  
  
  /**
  * Authenticate the user with a temporary token
  * @param string Username
  * @param string Password
  * @param int Length of login (defaults to just this session)
  * @return int 
  * @link http://www.paulsrichards.com/2008/07/29/persistent-sessions-with-php/
  * @key __world
  */ 
  static function authenticate_token($username, $token)
  {
    // get_users scrubs the user for us, and splits the keychain
    $user = reset(UserLib::get_users("username = '$username'"));
    
    // THINK: u and p errors provide the same message for security purposes, but it might be nice to be nice to our users...
    if(!$user)
      return ErrorLib::set_error("Invalid authentication credentials");

    if($user['disabled'])
      return ErrorLib::set_error("Account disabled");
    
    // get member
    if(!$member = MongoLib::findOne('members', $user['id'])) 
      return ErrorLib::set_error("No such member exists");
    
    // check if the token is associated with the user
    $query['value'] = $token;
    $query['user'] = intval($user['id']);
    
    $result = MongoLib::findOne('secrets', $query);
        
    if(!$result || !$result['valid'])
      return ErrorLib::set_error("Invalid token");
    
    // token checks out
    SessionLib::set_user_session($user);
    SessionLib::add_user_to_globals($user);
    
    __build_commands();
    
    // invalidate the token for that user
    $update['valid'] = false;
    MongoLib::set('secrets', $results[0]['_id'], $update);
    
    History::add('members', $user['id'], array('action' => 'request_reset completed'));
    
    return $username;
  } 
  
  /** 
  * Creates a new user based on an email address 
  * @param string Email address
  * @return string User id
  * @key __member
  */ 
  static function create_from_email($email)
  {
    $email = trim($email);
    
    // check that this username does not already exist 
    if($user = reset(UserLib::get_users_from_usernames(array($email))))
      return $user['id'];
    
    // verify that $email looks like an email address
    if(!is_string($email) || !preg_match('#\b[A-Z0-9+._%-]+@[A-Z0-9.-]+\.[A-Z]{2,4}\b#i', $email))
      return ErrorLib::set_error("Invalid email address");

    // set password length
    $length = 8;
    
    // generate garbage password
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $num_chars = strlen($chars);

    $pass = "";
    
    for ($i=0; $i < $length; $i++) { 
      $position = mt_rand(0, $num_chars - 1);
      $pass .= $chars[$position];
    }
      
    // create the member
    $id = self::register($email,$pass);
    
    // set the email address
    $update['depot.email'] = $email;
    MongoLib::set('members', $id, $update);
    
    return $id;
  }
  
  
  /** 
  * Register a new member 
  * @param string Username
  * @param string Password
  * @return id 
  * @key __world
  */ 
  static function register($username, $password)
  {
    $username = trim($username);
    $password = trim($password);
    
    if(!$user_id = UserLib::add_user($username, $password))
      return false; // error inside

    // all clear!
    
    // Members
    
    // add entry
    $member['_id'] = $user_id;
    $member['cron'] = new MongoDate();
    MongoLib::insert('members', $member);
    
    // add appropriate perms
    PermLib::grant_user_root_perms('members', $user_id, $user_id);
    PermLib::grant_members_view_perms('members', $user_id); // NOTE: are you sure this is right?
    
    // Profile
    
    // add entry
    $profile['_id'] = $user_id;
    $profile['type'] = 'member';
    MongoLib::insert('profiles', $profile);
    
    // add appropriate perms
    PermLib::grant_user_root_perms('profiles', $user_id, $user_id);
    PermLib::grant_permission(array('profiles', $user_id), "world:*", 'view');
    
    // NOTE: add custom keys here
    // User::add_key($username, 'whatever');

    // add transactions to history
    History::add('profiles', $user_id, array('action' => 'add', 'type' => 'member'));
    
    // TODO: put email here
    // TODO: move this to a hippo!
        
    return $user_id;
  }

  /** 
  * Send an email to myself
  * 
  * @param string The message subject
  * @param string The message body
  * @return string 
  * @key __exec
  */ 
  static function sendmail($subject, $body)
  {
    // get myself
    if(!$member = MongoLib::findOne('members', $GLOBALS['X']['USER']['id'])) 
      return ErrorLib::set_error("No such member exists");
    
    // all clear!
    
    mail($member['depot']['email'], $subject, $body, 'From:concierge@bentomiso.com');
  }
  

  /** 
  * Permanently destroy a member (this can *really* mess things up!)
  * @param string Member id
  * @return string 
  */ 
  static function destroy($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");
    
    // get member
    if(!$member = MongoLib::findOne('members', $id))
      return ErrorLib::set_error("No such member exists");
    
    // all clear!
    
    // add transaction to history
    History::add('members', $id, array('action' => 'destroy', 'was' => $member));
    
    // destroy the member
    MongoLib::removeOne('members', $id); 
    MongoLib::removeOne('profiles', $id); // TODO: make this Profile::destroy, so we get history
    return QueryLib::delete_row_by_id('users', $id); // TODO: make this User::destroy
  }
  
}

// EOT
