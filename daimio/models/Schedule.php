<?php

/**
 * Schedule: representing an individual date for a study
 *
 * @package daimio
 * @author Cecily Carver
 * @version 1.0
 */

class Schedule {
  
  // validate the item
  private function validize($item) 
  {
    $collection = 'schedules';
    $fields = array('date', 'study', 'square');

    if(!$item) return false;
    if($item['valid']) return true;

    foreach($fields as $key)
      if($item[$key] == false) return false;

    // all clear!

    $update['valid'] = true;
    MongoLib::set($collection, $item['_id'], $update);

    return true;  
  }
  
  /** 
  * Find the schedule items you need 
  * @param string Schedule ids
  * @param string Study id
  * @param string Search a start date range -- accepts (:yesterday :tomorrow) or (1349504624 1349506624)
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return string 
  * @key __member
  */ 
  static function find($by_ids=NULL, $by_study=NULL, $by_date_range=NULL, $options=NULL)
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_study))
      $query['study'] = array('$in' => MongoLib::fix_ids($by_study));
      
    if(isset($by_date_range)) {
      $begin_date = $by_date_range[0];
      $begin_date = ctype_digit((string) $begin_date) ? $begin_date : strtotime($begin_date);

      $end_date = $by_date_range[1];
      $end_date = ctype_digit((string) $end_date) ? $end_date : strtotime($end_date);

      $query['date']['$gte'] = new MongoDate($begin_date);
      $query['date']['$lte'] = new MongoDate($end_date);
    }

    return MongoLib::find_with_perms('schedules', $query, $options);
  }


  /** 
  * Add a schedule item  
  * @param string Study id
  * @return string 
  * @key __member
  */ 
  static function add($study)
  {
    // check that the study exists
    
    if(!$study = MongoLib::findOne_editable('studies', $study))
      return ErrorLib::set_error("That study is not within your domain");
    
    $schedule['date'] = false;
    $schedule['study'] = $study['_id'];

    $id = MongoLib::insert('schedules', $schedule);

    PermLib::grant_user_root_perms('schedules', $id);
    PermLib::grant_permission(array('schedules', $id), "admin:*", 'root');

    History::add('schedules', $id, array('action' => 'add'));

    return $id;     
  }
  
  /** 
  * Set the date for the Schedule 
  * @param string Schedule id
  * @param string Schedule date
  * @return string Schedule id
  * @key admin __exec
  */ 
  static function set_date($id, $value)
  {
    if(!$schedule = MongoLib::findOne_editable('schedules', $id))
      return ErrorLib::set_error("That schedule is not within your domain");

    if(!$value = new MongoDate(ctype_digit((string) $value) ? $value : strtotime($value)))
      return ErrorLib::set_error("That is not a valid date");

    if($schedule['date'] == $value)
      return $id;

    // all clear!

    $update['date'] = $value;
    MongoLib::set('schedules', $id, $update);

    History::add('schedules', $id, array('action' => 'set_date', 'value' => $value));

    $schedule['date'] = $value;
    self::validize($schedule);
    
    return $id;
  }  
  
  /** 
  * Removes the schedule from the study 
  * @param string 
  * @return string 
  * @key __member
  */ 
  static function remove($id)
  {
    if(!$schedule = MongoLib::findOne_editable('schedules', $id))
      return ErrorLib::set_error("That schedule is not within your domain");
    
    if(!$schedule['study'])    
      return $id;
    
    $update['study'] = false;
    MongoLib::set('schedules', $id, $update);

    History::add('schedules', $id, array('action' => 'remove', 'study' => $schedule['study']));

    $schedule['study'] = false;
    
    // this should invalidate the schedule
    self::validize($schedule);

    return $id;
  }
  
  /** 
  * This will SEVERELY bork things, so don't ever do it
  * @param string Schedule id
  * @return boolean 
  */ 
  static function destroy($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // get schedule
    if(!$schedule = MongoLib::findOne('schedules', $id))
      return ErrorLib::set_error("No such schedule exists");
    
    // all clear!

    // add transaction to history
    History::add('schedules', $id, array('action' => 'destroy', 'was' => $schedule));
    
    // THINK: there's no record of the questions and answers when you do this. we could use the Q+A built-in destroy functions and get a record that way. or somethin'.

    // destroy all the answers
    MongoLib::remove('answers', array('type' => $schedule['_id']));
    
    // destroy all the questions
    MongoLib::remove('questions', array('type' => $schedule['_id']));

    // destroy the schedule
    return MongoLib::removeOne('schedules', $id);
  }
  
}