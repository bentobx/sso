<?php

/**
 * Permissions are awesome!
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class PermLib
{
  
  //
  // Permission stuff (uses association lib...(???))
  //
  
  /** 
  * Returns all the valid levels and their worth 
  * @return array 
  */ 
  static function get_levels()
  {
    return array('root' => 0, 'edit' => 10, 'view' => 20, 'mirror' => 30);
  }
  
  /** 
  * Returns all my permits  
  * @return array
  */ 
  static function get_mine()
  {
    static $permits;
    
    $user_id = $GLOBALS['X']['USER']['id'];
    
    if(isset($permits[$user_id]))
      return $permits[$user_id];
    
    // NOTE: the user_id complexity ensures proper permits when we do weird user-switching things (like for bots)
    $permits[$user_id] = PermLib::get_permits($user_id);
    
    return $permits[$user_id];
  }
  
  
  /** 
  * Check a collection for permissibility  
  * @param string String
  * @return boolean 
  */ 
  static function permissible($collection)
  {
    if(!in_array($collection, $GLOBALS['X']['SETTINGS']['permission']['collections']))
      return false; // MongoLib::find checks this for every query, so no error here

    return true;
  }
  
  
  /** 
  * Get all permits for a given user
  * @param string User id
  * @return array
  */ 
  static function get_permits($user_id)
  {
    $permits[] = 'world:*';
    
    if(!$user_id)
      return $permits;
    
    $permits[] = "user:*";
    $permits[] = "user:$user_id";
    
    // HACK FOR ADMIN
    if(in_array('admin', $GLOBALS['X']['USER']['keychain']))
      $permits[] = "admin:*";
    
    if(!$profile = $GLOBALS['X']['VARS']['MY']['profile'])
      return $permits;
      
    $permits[] = "{$profile['type']}:*";
    $permits[] = "{$profile['type']}:{$profile[$profile['type']]}";
    
    return $permits;
  }
  
  
  /** 
  * Get the personal permit for the active user  
  * @return string 
  */ 
  static function get_user_permit()
  {
    if(!$GLOBALS['X']['USER']['id'])
      return false;

    $user_id = $GLOBALS['X']['USER']['id'];
    return "user:$user_id";
  }
  
  
  /** 
  * True if I can action with the perms, false otherwise 
  * @param string A valid permit level
  * @param array A thing is a collection and an id, like (:albums :the_cat_and_the_cobra)
  * @return boolean 
  */ 
  static function i_can($action, $perms)
  {
    // override for magic perm key (used by {permit superdo})
    if(in_array('__perm', $GLOBALS['X']['USER']['keychain']))
      return true;
    
    // override for wizards
    if($GLOBALS['X']['USER']['wizard'])
      return true;
    
    $levels = PermLib::get_levels();
    $target_level = $levels[$action];
    if($target_level === NULL)
      return ErrorLib::set_error("Inappropriate action");
    
    // get my permits
    $my_permits = Permit::get_mine();
    
    // check the perms against my permits
    foreach($my_permits as $permit) {
      if(isset($perms[$permit]) && ($levels[$perms[$permit]] <= $target_level))
        return true;
    }
    
    return false;
  }
  
  
  /** 
  * Grant a permission to a thing
  * @param array A thing is a collection and an id, like (:albums :the_handbag_memoirs)
  * @param string A permit is a string representing type and id, like "auditors:64" or "auditors:*"
  * @param string A valid permit level
  * @return string 
  */ 
  static function grant_permission($thing, $permits, $level)
  {
    // check level
    $levels = PermLib::get_levels();
    if(!isset($levels[$level]))
      return ErrorLib::set_error("That is an inappropriate level");
    
    // check thing
    list($collection, $id) = array_values(MongoLib::resolveDBRef($thing));
    if(!MongoLib::check($collection, $id)) {
      return ErrorLib::set_error("Invalid thing");
    }
    
    // check permit
    if(!$permits) {
      return ErrorLib::set_error("Invalid permit");
    } elseif(is_string($permits)) {
      $permits = array($permits);
    } elseif(is_array($permits)) {
      foreach($permits as $permit) 
        if(!is_string($permit))
          return ErrorLib::set_error("Invalid permits");
    } else {
      return ErrorLib::set_error("Unrecognized permits");
    }
    
    // all clear!
    
    // add transaction to history
    History::add($collection , $id, array('action' => 'grant_perm', 'perms' => $permits, 'level' => $level));
    
    $level_value = $levels[$level];
    foreach($permits as $permit) {
      // set thing.perms.permit to level 
      MongoLib::update($collection, $id, array('$set' => array("perms.$permit" => $level)));

      // add permit to thing.pcache
      foreach($levels as $key => $value)
        if($level_value <= $value)
          MongoLib::addToSet($collection, $id, "pcache.$key", $permit);
    }
    
    return true;
  }
  
  
  /** 
  * Remove a permission from a thing
  * @param array A thing is a collection and an id, like (:albums :critical_beatdown)
  * @param string A permit is a string representing type and id, like "auditors:64" or "auditors:*"
  * @return string 
  */ 
  static function revoke_permission($thing, $permit)
  {
    // get thing
    list($collection, $id) = $thing;
    if(!$thing = MongoLib::getDBRef($thing, 'perms'))
      return ErrorLib::set_error("Invalid thing");
    
    // check permit
    if(!$permit || !is_string($permit))
      return ErrorLib::set_error("Invalid permit");
    
    // all clear!
    
    // add transaction to history
    History::add($collection , $id, array('action' => 'revoke_perm', 'was' => array('target' => $thing, 'permit' => $permit)));

    // remove thing.perms.permit
    unset($thing['perms'][$permit]);
    $update['perms'] = $thing['perms'] ? $thing['perms'] : (object) array();
    $output = MongoLib::set($collection, $id, $update);
    
    // remove [permit] from thing.pcache
    $level_names = array_keys(PermLib::get_levels());
    foreach($level_names as $level)
      MongoLib::pull($collection, $id, "pcache.$level", $permit);
    
    return $output;
  }
  
  //
  // SUGAR
  //
  
  /** 
  * Give root perms to the current user (or a new user)
  * @param string Thing collection
  * @param string Thing id
  * @param string Optional user id
  * @return boolean 
  */ 
  static function grant_user_root_perms($collection, $id, $user_id='')
  {
    if(!$user_id)
      $user_id = $GLOBALS['X']['USER']['id'];
    
    if(!$user_id)
      return false;
      
    $permit = "user:$user_id";
    $thing = array($collection, $id);
    
    return PermLib::grant_permission($thing, $permit, 'root');
  }
  
  /** 
  * Give root perms to the current user's entire company
  * @param string Thing collection
  * @param string Thing id
  * @param string Optional user id
  * @return boolean 
  */ 
  static function grant_company_root_perms($collection, $id)
  {
    $my_company_id = $GLOBALS['X']['VARS']['MY']['profile']['company'];
    
    if(!$my_company_id)
      return false;
      
    $permit = "company:$my_company_id";
    $thing = array($collection, $id);
    
    return PermLib::grant_permission($thing, $permit, 'root');
  }
  
  /** 
  * Give view perms to every user 
  * @param string Thing collection
  * @param string Thing id
  * @return boolean 
  */ 
  static function grant_members_view_perms($collection, $id)
  {
    $permit = "user:*";
    $thing = array($collection, $id);
    return PermLib::grant_permission($thing, $permit, 'view');
  }
  
  /** 
  * Give view perms to every user 
  * @param string Thing collection
  * @param string Thing id
  * @return boolean 
  */ 
  static function revoke_members_view_perms($collection, $id)
  {
    $permit = "user:*";
    $thing = array($collection, $id);
    return PermLib::revoke_permission($thing, $permit, 'view');
  }
  


  //
  // Association stuff
  //
  
  

}

// EOT