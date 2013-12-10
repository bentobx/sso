<?php

/**
 * Users have permits, things have permissions -- when the two align stuff happens.
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class Permit
{
  
  /** 
  * Get my permits
  * @return array
  * @key __world
  */ 
  static function get_mine()
  {
    return PermLib::get_mine();
  }
    
  
  /** 
  * Grant a permission to a thing
  * @param array A thing is a collection and an id, like (:albums :space_age_bachelor_pad_music)
  * @param string A permit is a string representing type and id, like "auditor:64" or "auditor:*"
  * @param string Currently the view, edit, and root levels are supported
  * @return string 
  * @key __member
  */ 
  static function grant($thing, $permit, $level)
  {
    // get thing
    if(!$db_thing = MongoLib::getDBRef($thing, 'perms'))
      return ErrorLib::set_error("Invalid thing");
    
    // ensure permissible collection
    if(!PermLib::permissible($thing[0]))
      return ErrorLib::set_error("Non-permissible collection");
    
    // check my perms
    if(!PermLib::i_can('root', $db_thing['perms']))
      return ErrorLib::set_error("You don't have permission to affect that thing");
    
    // all clear!
    
    return PermLib::grant_permission($thing, $permit, $level);
  }
  
  
  /** 
  * Remove a user's permission to view or edit a test
  * @param array A thing is a collection and an id, like (:albums :congotronics)
  * @param string A permit is a string representing type and id, like "auditor:64" or "auditor:*"
  * @return string 
  * @key __member
  */ 
  static function revoke($thing, $permit)
  {
    // get thing
    if(!$db_thing = MongoLib::getDBRef($thing, 'perms'))
      return ErrorLib::set_error("Invalid thing");
    
    // ensure permissible collection
    if(!PermLib::permissible($thing[0]))
      return ErrorLib::set_error("Non-permissible collection");
    
    // check my perms
    if(!PermLib::i_can('root', $db_thing['perms']))
      return ErrorLib::set_error("You don't have permission to affect that thing");
    
    // all clear!
    
    return PermLib::revoke_permission($thing, $permit);
  }
  
  
  /** 
  * True if I can 'to' the 'has', false otherwise 
  * @param array A thing or an object -- a thing is a collection and an id, like (:albums :gling_glo), an object has perms
  * @param string Currently supported actions are view, edit, and root
  * @return boolean 
  * @key __member
  */ 
  static function i_can($has, $to)
  {
    // quick check for permed object
    if($perms = $has['perms'])
      return PermLib::i_can($to, $perms);
    
    // get the thing
    if(!$db_thing = MongoLib::getDBRef($has, 'perms'))
      return ErrorLib::set_error("Invalid thing");
    
    // ensure permissible collection
    if(!PermLib::permissible($has[0]))
      return ErrorLib::set_error("Non-permissible collection");
    
    // all clear!
    
    return PermLib::i_can($to, $db_thing['perms']);
  }


  /** 
  * Do something, ignoring the permission system (be very careful!!!)
  * @param string The command you'd like to process
  * @return string 
  * @key admin __trigger __exec __lens
  */ 
  static function superdo($command)
  {
    return Processor::process_with_enhancements($command, '__perm', array(), 'arrayable');
  }

}

// EOT