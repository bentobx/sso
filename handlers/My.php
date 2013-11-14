<?php

/**
 * The My Attribute holds user-editable data.
 *
 * This attribute is used for names, descriptions, and generally any user-editable content. Permissions are checked, so users can only edit things on which they already have edit permissions. Also, values are recursively sanitized.
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class My
{

  /** 
  * Set stuff for your things!
  *
  * This command sets the value designated by path to value for any for in collection in. Tres straightforward, non?
  *
  * @example {my set for @pony._id in :ponies path :wingspan value 12}
  * @example {my set for {* (:_id @pony._id)} in :ponies path :senses.sight value "20/40"}
  * @example {{* (:tags :fluffy)} | my set in :ponies path :stats.coziness value 11 | my set in :ponies path :allies value (:bunnies :kittens :sheep)}
  *
  * @param string Mongo filter (usually a single id)
  * @param string Collection name
  * @param string Dot-delimited my path: e.g. "stats.sublimity" would be accessed as @thing.my.stats.sublimity
  * @param string Value (scalar, array, hash, whatever)
  * @return boolean 
  * @key __exec __trigger __member
  */ 
  static function set($for, $in, $path, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");

    if(!MongoLib::good_path($path))
      return false; // error inside

    // all clear!

    $value = Processor::recursive_sanitize($value);
    $filter = MongoLib::permizer($for, 'edit');
    $update = array("my.$path" => $value);
    
    MongoLib::set($in, $filter, $update, true);

    return $for;
  }
  
  /** 
  * Push a value into things!
  *
  * Essentially the same as {my set}, except you're pushing a single value into an array designated by path instead of setting the path's value.
  *
  * @example {@pony._id | my push in :ponies path :backpack.contents value {* (:genus :fruit :species :banana)}}
  *
  * @param string Collection name
  * @param string Mongo filter (usually a single id)
  * @param string Dot-delimited my path: the last thing pushed into "backpack.contents" would be accessed as @thing.my.backpack.contents.#-1
  * @param string Value (scalar, array, hash, whatever)
  * @return boolean 
  * @key __exec __trigger __member
  */ 
  static function push($for, $in, $path, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");

    if(!MongoLib::good_path($path))
      return false; // error inside

    // all clear!

    $value = Processor::recursive_sanitize($value);
    $filter = MongoLib::permizer($for, 'edit');
    
    MongoLib::addToSet($in, $filter, "my.$path", $value, true);

    return $for;
  }
  
  /** 
  * Pull a value from things!
  *
  * Essentially the same as {my set}, except you're pulling a value from an array designated by path instead of setting the path's value.
  *
  * @example {@pony._id | my pull in :ponies path :backpack.contents value {* (:genus :fruit :species :banana)}}
  *
  * @param string Collection name
  * @param string Mongo filter (usually a single id)
  * @param string Dot-delimited my path: pulling from "backpack.contents" would remove the matching value from @thing.my.backpack.contents
  * @param string The value in the array to remove
  * @return boolean 
  * @key __exec __trigger __member
  */ 
  static function pull($for, $in, $path, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");

    if(!MongoLib::good_path($path))
      return false; // error inside

    // all clear!

    $value = Processor::recursive_sanitize($value);
    $filter = MongoLib::permizer($for, 'edit');
    
    MongoLib::pull($in, $filter, "my.$path", $value, true);

    return $for;
  }

}

// EOT