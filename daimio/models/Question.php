<?php

/**
 * This handler grants direct access to Bonsai questions -- use wisely!
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Question
{
  
  /** 
  * Returns unrestricted questions -- if you put this in a lens check for perms in its conditions!
  * @param array Question ids
  * @param string Some base Q id
  * @param string Some PQ ids
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find($by_ids=NULL, $by_base_q=NULL, $by_pq=NULL, $options=NULL)
  {
    // TODO: take this out of __member and add appropriate lenses
    
    if(isset($by_ids))
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_base_q))
      $query['locker.base_q'] = array('$in' => MongoLib::fix_ids($by_base_q));
    
    if(isset($by_pq))
      $query['pq'] = array('$in' => MongoLib::fix_ids($by_pq));
    
    return MongoLib::find('questions', $query, NULL, $options);
  }
  
  /** 
  * The protocol build action uses this to create tests
  * 
  * Note that the question's review protocol's build trigger action should push 'reviewed_q' into the locker, using {locker set}
  * 
  * @param string The test id
  * @param string The protoquestion id
  * @param string Protocol data and any extra extras
  * @param string The question's special handle string for vscores (needed if there's more than one Q per PQ)
  * @return string 
  * @key __trigger
  */ 
  static function add($test, $pq, $pdata, $handle=NULL)
  {
    // get the test
    if(!$test = MongoLib::findOne('tests', $test))
      return ErrorLib::set_error("That test is invalid");
    
    // check pq
    $pq = MongoLib::fix_id($pq);
    if(!MongoLib::check('protoquestions', $pq))
      return ErrorLib::set_error("That protoquestion is invalid");    
    
    // check for locked
    if($test['square'] == 'locked')
      return ErrorLib::set_error("That test is locked and can not be edited");
    
    // check the test's edit permits
    // if(!PermLib::i_can('edit', $test['perms']))
    //   return ErrorLib::set_error("You don't have permission to edit that test");
    // THINK: need to be able to edit other people's tests (like reviews) from P triggers... but we also want to limit trigger power.

    // fix pdata
    if(!$pdata || !is_array($pdata))
      $pdata = array();

    // all clear!
    
    // build question
    $question['test'] = $test['_id'];
    $question['pq'] = $pq;
    $question['protocol'] = $test['protocol'];
    $question['pdata'] = $pdata;
    if($handle)
      $question['handle'] = $handle;
    
    // insert question
    $id = MongoLib::insert('questions', $question);
    
    return $id;
  }
  
}

// EOT