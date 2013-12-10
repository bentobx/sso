<?php

/**
 * This handler grants direct access to Bonsai answers -- use wisely!
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Answer
{

  /** 
  * Returns unrestricted answers -- if you put this in a lens check for perms in its conditions!
  * @param array Answer ids
  * @param array Question ids
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __lens __exec __trigger
  */ 
  static function find($by_ids=NULL, $by_questions=NULL, $options=NULL)
  {
    if(isset($by_ids))
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_questions))
      $query['question'] = array('$in' => MongoLib::fix_ids($by_questions));
    
    return MongoLib::find('answers', $query, NULL, $options);
  }
  
  
  /** 
  * Answer a question! 
  * @param string Question id
  * @param string The answer's input
  * @return id 
  * @key __member
  */ 
  static function add($question, $input)
  {
    ErrorLib::log_array(array('$question, $input', $question, $input));
    
    // get question
    if(!$question = MongoLib::findOne('questions', $question))
      return ErrorLib::set_error("Invalid question id");

    // get pq
    if(!$pq = MongoLib::findOne('protoquestions', $question['pq']))
      return ErrorLib::set_error("Invalid question");

    // get test
    // if(!$test = MongoLib::findOne_editable('tests', $question['test']))
    if(!$test = MongoLib::findOne('tests', $question['test']))
      return ErrorLib::set_error("Invalid test");

    // check for locked
    if($test['square'] == 'locked')
      return ErrorLib::set_error("That test is locked and can not be edited");
    
    // get protocol
    if(!$protocol = MongoLib::findOne('protocols', $test['protocol']))
      return ErrorLib::set_error("Invalid protocol");

    return BonsaiLib::add_answer($input, $question, $pq, $test, $protocol);
  }
  
  
  /** 
  * Invalidate an answer 
  * @param string Answer id
  * @return boolean 
  * @key __member __lens __exec __trigger
  */ 
  static function invalidate($id)
  {
    // get answer
    if(!$answer = MongoLib::findOne('answers', $id))
      return ErrorLib::set_error("Invalid answer id");

    // get question
    if(!$question = MongoLib::findOne('questions', $answer['question']))
      return ErrorLib::set_error("Invalid question id");

    // get pq
    if(!$pq = MongoLib::findOne('protoquestions', $question['pq']))
      return ErrorLib::set_error("Invalid question");
    
    // get test
    if(!$test = MongoLib::findOne_editable('tests', $answer['test']))
      return ErrorLib::set_error("Invalid test");

    // check for locked
    if($test['square'] == 'locked')
      return ErrorLib::set_error("That test is locked and can not be edited");

    // all clear!

    // set invalid
    $answer['invalid'] = true;
    MongoLib::update('answers', $id, $answer);
    
    // handle completeness
    BonsaiLib::completeness_modulations($answer, $question, $pq, $test);

    return $answer['_id'];
  }

  /** 
  * This will SEVERELY bork things, so don't ever do it
  * @param string Answer id
  * @return boolean
  */ 
  static function destroy_answer($answer_id)
  {
    // destroy the answer
    return MongoLib::removeOne('answers', $answer_id);
  }
  
}

// EOT