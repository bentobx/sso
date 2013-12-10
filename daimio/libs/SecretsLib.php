<?php

/**
 * Library for storing secret data accessible by access codes only.
 *
 * @package daimio
 * @author Cecily Carver
 * @version 1.0
 */

class SecretsLib {
  
  /** 
  * Inserts new secret data and returns the id to access 
  * @param string Data to insert
  * @return string 
  */ 
  static function add($data)
  {
    if(!is_array($data)) {
      $data = array('value'=> $data);
    }
    
    return MongoLib::insert('secrets', $data);    
  }
  

  /** 
  * Retrieves a data item by id 
  * @param string Secret id
  * @return string 
  */ 
  static function get($id)
  {
    return MongoLib::findOne('secrets', $id);
  }
}

// EOT