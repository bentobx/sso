<?php

/**
 * Text entry tied to a particular year; no score
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Question_typesYearly_text
{
  
  /** 
  * Add a new answer to this question
  * If this returns false the adding process fails
  * For array returns, the 'answer' key allows complete control over the Answer array
  * The 'question' key allows pushing things into the question's locker (like stats n' stuff) 
  * @param array The test
  * @param array The protoquestion
  * @param array The question
  * @param array The answer
  * @return mixed 
  */ 
  static function add_answer($test, $pq, $question, $answer)
  {
    // ensure this is the only valid answer
    if(MongoLib::check('answers', array('question' => $question['_id'], 'invalid' => array('$ne' => true))))
      return ErrorLib::set_error("This question type does not accept multiple valid answers");

    // could add stats info to question
    // could do cool stuff with the answer
    
    return NULL;
  }
  
  /** 
  * Return a set of virtual scores
  * This takes a SET of answers -- you need to handle all of them
  * @param array The protoquestion
  * @param array The question
  * @param array A set of answers
  * @return number 
  */ 
  static function get_vscores($pq, $question, $answers)
  {
    return NULL;
  }
  
  
  /** 
  * Returns true if the question is complete  
  * @param array The test
  * @param array The protoquestion
  * @param array The question
  * @return string 
  */ 
  static function is_complete($test, $pq, $question)
  {
    // get some answers
    $answers = MongoLib::find('answers', array('question' => $question['_id']));
    
    foreach($answers as $answer) {
      if(!$answer['invalid']) {
        return true;
      }
    }
    
    return false;
  }
  
  /** 
  * Returns a fake answer
  * @return string 
  */ 
  static function get_fake_answer()
  {
    return 'ipsum ipsum';
  }
  
}

// EOT