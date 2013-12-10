<?php

/**
 * Date entry, scored by present day present time
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Question_typesDate
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
    // could add stats info to question
    // could do cool stuff with the answer
    
    if(!$date = strtotime($answer['input']))
      return ErrorLib::set_error("A valid date is required");
    
    $result['answer']['input'] = new MongoDate($date);
    
    return $result;
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
    $count = 0;
    $time = time();

    // score 1 for the future, -1 for the past, and sum over all dates
    foreach($answers as $answer)
      $count += $answer['input']->sec > $time ? 1 : -1;
    
    return $count;
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
    $sign = rand(0,1) ? '-' : '+';
    $length = rand(1,7);
    $term = rand(0,1) ? 'days' : 'weeks';
    $date = strtotime($sign . "$length $term");
    
    return new MongoDate($date);
  }
  
  
}

// EOT