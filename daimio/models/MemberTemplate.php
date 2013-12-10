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

  /** 
  * Get some members
  * @param string User ids (the user id and the member id are the same)
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
    
    // Profile
    
    // add entry
    $profile['_id'] = $user_id;
    $profile['type'] = 'member';
    $profile['square'] = 'approved';
    MongoLib::insert('profiles', $profile);
    
    // add appropriate perms
    PermLib::grant_user_root_perms('profiles', $user_id, $user_id);
    PermLib::grant_members_view_perms('profiles', $user_id); // NOTE: might want to add world view
    
    // NOTE: add custom keys here
    // User::add_key($username, 'whatever');

    // add transactions to history
    History::add('profiles', $user_id, array('action' => 'add', 'type' => 'member'));
    
    return $user_id;
  }
  

  /** 
  * Permanently destroy a company (this can *really* mess things up!)
  * @param string Company id
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
    MongoLib::removeOne('profiles', $id); // TODO: make this Profile::destroy, so we get history
    return QueryLib::delete_row_by_id('users', $id); // TODO: make this User::destroy
  }
  
  
}

// EOT