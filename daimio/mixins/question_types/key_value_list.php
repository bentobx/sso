<?php

/**
 * Serialized key/value entry, scored by count
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Question_typesKey_value_list
{
  
  /** 
  * Add a new answer to this question
  * If this returns false the adding process fails
  * For array returns, the 'answer' key allows complete control over the Answer array
  * The 'question' key allows pushing things into the question's depot (like stats n' stuff) 
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
    
    return NULL;
  }
  
  /** 
  * Score a question 
  * This takes a SET of answers -- you need to handle all of them
  * @param array The protoquestion
  * @param array The question
  * @param array A set of answers
  * @return number 
  */ 
  static function score($pq, $question, $answers)
  {
    return count($answers);
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
    $x['key'] = 'the key';
    $x['value'] = 'Yes';
    return $x;
  }
  
}

// EOT