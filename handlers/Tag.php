<?php

/**
 * The Tags Attribute is an array of strings and is permission based, usable by anyone with edit perms. 
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class Tag
{
  // NOTE: If you need taggability without editability you can add a new permission level in BL between edit and view, and then adjust permizer below.

  /** 
  * Set some tags!
  *
  * @example {tag push for @pony._id in :ponies value "equus ferus"}
  * @example {{* (:tags :fluffy)} | tag push in :ponies value (:comfy :shearme :wearable)}
  *
  * @param string Mongo filter (usually a single id)
  * @param string Collection name
  * @param string A set of tags (single word strings, underscores ok)
  * @return boolean
  * @key __exec __trigger __member
  */ 
  static function set($for, $in, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");

    $values = (array) $value;
    foreach($values as $value) {
      if(!($value && is_string($value) && (strlen($value) < 40) && preg_match('/^[\w -]+$/', $value))) {
        return ErrorLib::set_error("Invalid tag");
      }
    }

    // all clear!

    $filter = MongoLib::permizer($for, 'edit');
    $update = array("tags" => $values);
    
    MongoLib::set($in, $filter, $update, true);

    return $for;
  }
  
  
  /** 
  * Add tag(s) to thing(s)
  *
  * @example {tag push for @pony._id in :ponies value "equus ferus"}
  * @example {{* (:tags :fluffy)} | tag push in :ponies value (:comfy :shearme :wearable)}
  *
  * @param string Mongo filter (usually a single id)
  * @param string Collection name
  * @param string A tag (single word string, underscores ok) or array of tags
  * @return boolean 
  * @key __exec __trigger __member
  */ 
  static function push($for, $in, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");
    
    // all clear!

    $values = (array) $value;
    $filter = MongoLib::permizer($for, 'edit');
    
    foreach($values as $value) {
      if($value && is_string($value) && (strlen($value) < 40) && preg_match('/^[\w -]+$/', $value)) {
        MongoLib::addToSet($in, $filter, 'tags', (string) $value, true);
      }
    }
    
    return $for;
  }
  
  /** 
  * Remove a tag
  *
  * @example {tag pull for @pony._id in :ponies value :large}
  * @example {{* (:tags :fluffy)} | tag pull in :ponies value :smooth}
  *
  * @param string Mongo filter (usually a single id)
  * @param string Collection name
  * @param string A tag (single word string, underscores ok)
  * @return boolean 
  * @key __exec __trigger __member
  */ 
  static function pull($for, $in, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");
    
    // all clear!

    $values = (string) $value;
    $filter = MongoLib::permizer($for, 'edit');
    
    MongoLib::pull($in, $filter, 'tags', $value, true);
    
    return $for;
  }

}

// EOT
