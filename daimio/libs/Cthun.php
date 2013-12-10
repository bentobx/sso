<?php

/**
 * Controls AQ
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class Cthun
{
  
  // YAGNI: tags, times (finite repeat), priority
  
  /** 
  * Returns an AQ item of type, or false on failure 
  * @param string A type, like "daml" or "aqiri"
  * @return mixed 
  */ 
  static function fetch($type)
  {
    // find and remove
    $result = MongoLib::$mongo_db->command(
      array(
        'findandmodify' => 'aq',
        'query' => array('proc' => array('$lte' => new MongoDate(strtotime('now')))),
        'sort' => array('proc' => 1),
        'remove' => true
      )
    );
    
    if(!$result['ok'])
      return false;
      
    $item = $result['value'];
    
    // handle chain
    if($chain = $item['chain']) {
      if(!is_array($chain) || $chain['what'])
        $chain = array($chain);
      
      foreach($chain as $thing) {
        if($thing == '__self')
          $thing = $item;

        self::add($thing['what'], $thing['when'], $thing['type'], $thing['chain'], $thing['_id']);
      }
    }
    
    return $item['what'];
  }
  
  /** 
  * Add an AQ item 
  * 
  * The chain param can take an array. Elements are either __self or a hash. The hash looks like this:
  * {* (:what "be awesome" :when 10 :type :todo :chain (__self))}
  *
  * @param string Item payload
  * @param string Integer in minutes, or a daml string
  * @param string A type, like "daml" or "aqiri"
  * @param string '__self' creates a copy of this item; a hash creates a new different item.
  * @return hash 
  */ 
  static function add($what, $when, $type, $chain=NULL, $id=NULL)
  {
    // check type
    // check when
    // check chain
    
    $item['what'] = $what;
    $item['when'] = $when;
    $item['type'] = $type;
    $item['chain'] = $chain;
    if($id)
      $item['_id'] = $id; // THINK: this is weird.
    
    // sort out proc string
    if($when != intval($when)) {
      $proc_string = Processor::process_string($when);
    } else {
      $when = $when ? $when : 1;
      $proc_string = "now + $when minutes";
    }
      
    $item['proc'] = new MongoDate(strtotime($proc_string));
    
    return MongoLib::insert('aq', $item);
  }
  
  // THINK: How do we get a chainable representation of this item?

  /** 
  * Destroy a scheduled item 
  * @param string An item id
  * @return boolean 
  */ 
  static function destroy($id)
  {
    return MongoLib::removeOne('aq', MongoLib::fix_id($id));
  }
  

}

// EOT