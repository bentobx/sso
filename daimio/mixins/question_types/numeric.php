<?php

/**
 * Numbers go in -- they don't come out
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Question_typesNumeric
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
    $number .= $answer['input']; // convert to string
    
    if(!is_numeric($number[0]) && !is_numeric($number[1])) // NOTE: $number[1] handles $1,234
      return ErrorLib::set_error("The answer must be a numeric value");
    
    $new_number += preg_replace('/[^0-9.-]/', '', $number); // sucks out non-numeric chars and casts to number
    // NOTE: some bozo might try 2.3.4.5..7 -- which would be pretty stupid.
    // NOTE: also probably fails with eurotrash style numbers.
    
    // ensure this is the only valid answer
    if(MongoLib::check('answers', array('question' => $question['_id'], 'invalid' => array('$ne' => true))))
      return ErrorLib::set_error("This question type does not accept multiple valid answers");
    
    // could add stats info to question
    // could do cool stuff with the answer
    
    $result['answer']['input'] += $new_number; // convert to number
    
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
    // value is the sum of all numeric answers
    $value = 0;
    foreach($answers as $answer)
      $value += $answer['input'];
    
    return $value;
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
    return rand(1,1024);
  }
  
}

// EOT