<?php

/**
 * The Locker Attribute holds pseudo-mechanical auto-generated data -- don't change it!
 *
 * The locker is identical to depot, but used for harder values.
 *
 * When building workflows you'll often need to allow users to push pseudo-mechanical data into items they don't own. 
 * Standard practice is to build an exec that runs a locker command via superdo.
 * Note that this bypasses the permission system, so BE VERY CAREFUL.
 * The exec needs to have very strict conditions on what can be edited, or it will open up a big security hole.
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class Locker
{

  /** 
  * The locker is for server-side "pseudo-mechanical" data only -- it's off limits!
  *
  * This command sets the value designated by path to value for any for in collection in. Tres straightforward, non?
  *
  * @example {locker set for @pony._id in :ponies path :wingspan value 12}
  * @example {locker set for {* (:_id @pony._id)} in :ponies path :senses.sight value "20/40"}
  * @example {{* (:tags :fluffy)} | locker set in :ponies path :stats.coziness value 11 | locker set in :ponies path :allies value (:bunnies :kittens :sheep)}
  *
  * @param string Mongo filter (usually a single id)
  * @param string Collection name
  * @param string Dot-delimited locker path: e.g. "stats.sublimity" would be accessed as @thing.locker.stats.sublimity
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
    $update = array("locker.$path" => $value);
    
    MongoLib::set($in, $filter, $update, true);

    return $for;
  }
  
  /** 
  * The locker is for server-side "pseudo-mechanical" data only -- it's off limits!
  *
  * Essentially the same as {locker set}, except you're pushing a single value into an array designated by path instead of setting the path's value.
  *
  * @example {@pony._id | locker push in :ponies path :backpack.contents value {* (:genus :fruit :species :banana)}}
  *
  * @param string Collection name
  * @param string Mongo filter (usually a single id)
  * @param string Dot-delimited locker path: the last thing pushed into "backpack.contents" would be accessed as @thing.locker.backpack.contents.#-1
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
    
    MongoLib::addToSet($in, $filter, "locker.$path", $value, true);

    return $for;
  }
  
  /** 
  * The locker is for server-side "pseudo-mechanical" data only -- it's off limits!
  *
  * Essentially the same as {locker set}, except you're pulling a value from an array designated by path instead of setting the path's value.
  *
  * @example {@pony._id | locker pull in :ponies path :backpack.contents value {* (:genus :fruit :species :banana)}}
  *
  * @param string Collection name
  * @param string Mongo filter (usually a single id)
  * @param string Dot-delimited locker path: pulling from "backpack.contents" would remove the matching value from @thing.locker.backpack.contents
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
    
    MongoLib::pull($in, $filter, "locker.$path", $value, true);

    return $for;
  }

}

// EOT