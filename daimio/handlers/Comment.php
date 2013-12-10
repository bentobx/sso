<?php

/**
 * Add a comment to just about anything
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class Comment
{
  
  /** 
  * Get some comments 
  * @param array A thing is a collection and an id, like (:albums :no_depression) 
  * @param string A user id
  * @param string A grouping string
  * @param string Supports sort, limit, skip, fields, nofields, and count: {* (:limit 5 :skip "30" :sort {* (:name 1)} :nofields (:pcache :scores) :fields :name)} or {* (:count :true)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find($by_thing=NULL, $by_user=NULL, $by_group=NULL, $options=NULL)
  {
    if(isset($by_thing)) {
      if(!$thing = MongoLib::resolveDBRef($by_thing))
        return ErrorLib::set_error("Invalid thing");

      $query['thing'] = $thing;
    }
    
    if(isset($by_user)) {
      $query['user'] = $by_user;
    }
    
    if(isset($by_group)) {
      $query['group'] = $by_group;
    }
    
    return MongoLib::find_with_perms('comments', $query, $options);
  }
  
  
  /** 
  * Comment on something 
  * @param string A thing is a collection and an id, like (:albums :kraanerg)
  * @param string The comment you want to make 
  * @param string A string for grouping (makes selecting things easier)
  * @return id 
  * @key __member __exec
  */ 
  static function add($thing, $value, $group=NULL)
  {
    // get thing
    if(!$db_thing = MongoLib::getDBRef($thing))
      return ErrorLib::set_error("Invalid thing detected");
    
    // check value
    if(!$value || !is_string($value))
      return ErrorLib::set_error("That is an inappropriate value");
    
    // check view perms
    if(!PermLib::i_can('view', $db_thing['perms']))
      return ErrorLib::set_error("You do not have permission to comment on that thing");
    
    // all clear!
    
    // fix value
    $value = Processor::sanitize($value);
    
    // add new comment
    $comment['thing'] = MongoLib::createDBRef($thing);
    $comment['value'] = $value;
    $comment['group'] = $group;
    $comment['date'] = new MongoDate();
    $comment['user'] = $GLOBALS['X']['USER']['id'];
    $comment['username'] = $GLOBALS['X']['USER']['username'];
    $id = MongoLib::insert('comments', $comment);
    
    // add user and members perms
    PermLib::grant_user_root_perms('comments', $id);
    PermLib::grant_members_view_perms('comments', $id);
    
    // add transaction to history
    History::add('comments', $id, array('action' => 'add'));
    
    return $id;
  }
  
  
  /** 
  * Rephrase your opinions
  * @param string Comment id
  * @param string Your new thoughts on the subject
  * @param string Change the group to something else
  * @return id 
  * @key __member
  */ 
  static function edit($id, $value, $group=NULL)
  {
    // get comment
    if(!$comment = MongoLib::findOne('comments', $id))
      return ErrorLib::set_error("That comment could not be found");
    
    // check value
    if(!$value || !is_string($value))
      return ErrorLib::set_error("That is an inappropriate value");
    
    // check edit perms
    if(!PermLib::i_can('edit', $comment['perms']))
      return ErrorLib::set_error("You do not have permission to edit that comment");
    
    // all clear!
    
    // fix value
    $value = Processor::sanitize($value);
    
    // edit the comment
    if(isset($group))
      $update['group'] = $group;
    $update['value'] = $value;
    MongoLib::set('comments', $comment['_id'], $update);
    
    // add transaction to history
    History::add('comments', $id, array('action' => 'edit', 'was' => $comment['value']));
    
    return $id;
  }

}

// EOT