<?php

/**
 * Helper methods for mongo stuff
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class Mongous
{
  
  /** 
  * Convert MongoId to string and MongoDate to timestamp (no more .sec)
  * @param string Some mongo data, probably from {* find}
  * @return string 
  * @key __world
  */ 
  static function sanitize($value)
  {
    if(is_object($value)) {
      if(is_a($value, 'MongoId')) {
        return (string) $value;
      }
      if(is_a($value, 'MongoDate')) {
        return $value->sec;
      }
      return $value; // THINK: what things would end up here?
    }
    
    if(is_array($value)) {
      foreach($value as $key => $item) {
        if(is_array($item) || is_object($item)) {
          $value[$key] = self::sanitize($item);
        }
      }
    }
    
    return $value;
  }
  
  
  /** 
  * Get a thing from mongo 
  * @param string A thing or a (:collection :id) pair
  * @return array 
  * @key __lens __exec __trigger
  */ 
  static function get_thing($from)
  {
    return MongoLib::getDBRef($from);
  }

  
  /** 
  * Extract seconds since the epoch from a MongoId object or MongoDate
  * @param string MongoId or MongoDate
  * @return int 
  * @key __world
  */ 
  static function extract_time($from)
  {
    return MongoLib::extract_time($from);
  }
  
  /** 
  * Change a string into a mongoid 
  * @param string A string representation of a mongoid 
  * @return mongoid 
  * @key __world
  */ 
  static function fix_id($id)
  {
    return MongoLib::fix_id($id);
  }
  
  
  /** 
  * DON'T EVER USE THIS!!!! 
  * @param string NOPE DON'T USE!
  * @param string REALLY DON'T DO IT!!!
  * @param string THIS IS VERY VERY BAD!!!!
  * @return string 
  */ 
  static function hack_value($thing, $path, $value, $secret)
  {
    if($secret != 'foo')
      return ErrorLib::set_error("BAD BAD YOU ARE BAD");
    
    $collection = $thing[0];
    $id = MongoLib::fix_id($thing[1]);
    
    if(!$db_thing = MongoLib::getDBRef($thing))
      return ErrorLib::set_error("That thing resists your feeble attempts to hack it");
    
    // all clear!
    
    $update[$path] = $value;
    MongoLib::set($collection, $id, $update);

    History::add($collection, $id, array('action' => 'hacken', 'path' => $path, 'value' => $value, 'was' => $db_thing));
    
    return $thing;
  }
  
  
}

// EOT