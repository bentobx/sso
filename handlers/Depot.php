<?php

/**
 * The Depot Attribute holds non-user editable (but still permission-based) data.
 *
 * The depot is used for things like a user's birthday, country, shoe size -- things set at registration and uneditable thereafter. You'd push those into the user's profile's depot.
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class Depot
{

  /** 
  * Set a depot item
  *
  * This command sets the value designated by path to value for any for in collection in. Tres straightforward, non?
  *
  * @example {depot set for @pony._id in :ponies path :wingspan value 12}
  * @example {depot set for {* (:_id @pony._id)} in :ponies path :senses.sight value "20/40"}
  * @example {{* (:tags :fluffy)} | depot set in :ponies path :stats.coziness value 11 | depot set in :ponies path :allies value (:bunnies :kittens :sheep)}
  *
  * @param string Mongo filter (usually a single id)
  * @param string Collection name
  * @param string Dot-delimited depot path: e.g. "stats.sublimity" would be accessed as @thing.depot.stats.sublimity
  * @param string Value (scalar, array, hash, whatever)
  * @return boolean 
  * @key __exec __trigger
  */ 
  static function set($for, $in, $path, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");

    if(!MongoLib::good_path($path))
      return false; // error inside

    // all clear!

    $filter = MongoLib::permizer($for, 'edit');
    $update = array("depot.$path" => $value);
    
    MongoLib::set($in, $filter, $update, true);

    return $for;
  }
  
  /** 
  * Push a depot value into something
  *
  * Essentially the same as {depot set}, except you're pushing a single value into an array designated by path instead of setting the path's value.
  *
  * @example {@pony._id | depot push in :ponies path :backpack.contents value {* (:genus :fruit :species :banana)}}
  *
  * @param string Collection name
  * @param string Mongo filter (usually a single id)
  * @param string Dot-delimited depot path: the last thing pushed into "backpack.contents" would be accessed as @thing.depot.backpack.contents.#-1
  * @param string Value (scalar, array, hash, whatever)
  * @return boolean 
  * @key __exec __trigger
  */ 
  static function push($for, $in, $path, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");

    if(!MongoLib::good_path($path))
      return false; // error inside

    // all clear!

    $filter = MongoLib::permizer($for, 'edit');
    
    MongoLib::addToSet($in, $filter, "depot.$path", $value, true);

    return $for;
  }
  
  /** 
  * Pull a depot value out of something
  *
  * Essentially the same as {depot set}, except you're pulling a value from an array designated by path instead of setting the path's value.
  *
  * @example {@pony._id | depot pull in :ponies path :backpack.contents value {* (:genus :fruit :species :banana)}}
  *
  * @param string Collection name
  * @param string Mongo filter (usually a single id)
  * @param string Dot-delimited depot path: pulling from "backpack.contents" would remove the matching value from @thing.depot.backpack.contents
  * @param string The value in the array to remove
  * @return boolean 
  * @key __exec __trigger
  */ 
  static function pull($for, $in, $path, $value)
  {
    if(!PermLib::permissible($in))
      return ErrorLib::set_error("Non-permissible collection");

    if(!MongoLib::good_path($path))
      return false; // error inside

    // all clear!

    $filter = MongoLib::permizer($for, 'edit');
    
    MongoLib::pull($in, $filter, "depot.$path", $value, true);

    return $for;
  }

}

// EOT