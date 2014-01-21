<?php

/**
 * You can put stuff in stuff.
 *
 * @package daimio
 * @author Cecily Carver
 * @version 1.0
 */

class Stuff
{
  // validates the item
  private static function validize($item) {
    $collection = 'stuff';
    $fields = array('type');
    
    if(!$item) return false;
    
    if($item['valid']) return true;
    
    foreach($fields as $key) 
      if($item[$key] === false) return false;
    
    // all clear!
    
    $update['valid'] = true;
    MongoLib::set($collection, $item['_id'], $update);
    
    return true;
  }
  
  /** 
  * Find your stuff. 
  * @param string Id of the stuff to find
  * @param string Type of stuff to find
  * @return string 
  * @key __member
  */ 
  static function find($by_ids=NULL, $by_type=NULL, $options=NULL)
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_type))
      $query['type'] = new MongoRegex("/$by_type/i");
    
    ErrorLib::log_array(array('$query', $query));
      
    return MongoLib::find_with_perms('stuff', $query, $options);    
  }
  
  /** 
  * Add something to Stuff. 
  * @param string Type of stuff to add
  * @return string 
  * @key __member
  */ 
  static function add($type)
  {
    $stuff['type'] = $type;
    $stuff['valid'] = false;
    $id = MongoLib::insert('stuff', $stuff);
    
    PermLib::grant_permission(array('stuff', $id), "admin:*", 'root');
    PermLib::grant_permission(array('stuff', $id), "user:" . $GLOBALS['X']['USER']['id'], 'edit');
    PermLib::grant_permission(array('stuff', $id), "user:*", 'view');
 
    self::validize($stuff);
    
    History::add('stuff', $id, array('action' => 'add'));
    
    return $id; 
  }
  
}