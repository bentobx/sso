<?php

/**
 * If we don't carefully learn from this model we could easily end up in an infinite recursive loop
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class History
{
  
  /** 
  * Sift through the history file, looking for things 
  * @param string A thing, like (:train :pelham123)
  * @param string An array of stuff info, like {* (:square :circle)}
  * @param string A set of user ids
  * @param string Supports sort, limit, skip, fields, nofields, count and attrs: {* (:limit 5 :skip "30" :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)}
  * @return string 
  * @key __exec __lens
  */ 
  static function find($by_thing=NULL, $by_stuff=NULL, $by_user_ids=NULL, $options=NULL)
  {
    if(isset($by_stuff))
      $query = $by_stuff;
    
    if(isset($by_thing)) {
      if(!$thing = MongoLib::resolveDBRef($by_thing))
        return ErrorLib::set_error("Invalid thing");

      $query['thing'] = $thing;
    }

    if($by_user_ids) {
      $query['user']['$in'] = MongoLib::fix_ids($by_user_ids);
    }
    
    return MongoLib::find('history', $query, NULL, $options);
  }
  

  /** 
  * Add a history item
  * @param string Collection name
  * @param string Thing id
  * @param string A hash of stuff, like {* (:type :fancy :square :upsidedown)}
  * @return string 
  * @key __exec
  */ 
  static function add($collection, $id, $stuff)
  {
    if(is_array($stuff))
      $item = $stuff;

    if(!$thing = MongoLib::createDBRef($collection, $id))
      return ErrorLib::set_error("Invalid thing: ('$collection', '$id')");

    $item['date'] = new MongoDate();
    $item['user'] = $GLOBALS['X']['USER']['id'];
    $item['thing'] = $thing;
    $item['new'] = true;
    MongoLib::insert('history', $item);
    
    return true;
  }

}

// EOT