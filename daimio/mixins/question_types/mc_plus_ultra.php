<?php

/**
 * Multiple choices and special stuff
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Question_typesMc_plus_ultra
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
    // limit choice to digits
    if(!ctype_digit((string) $answer['input']['choice']))
      return ErrorLib::set_error("You must submit a valid numeric value");
    
    // prevent 'false' answers from leaking through
    $answer['input']['choice'] = (int) $answer['input']['choice'];
    
    // ensure this is the only valid answer
    if(MongoLib::check('answers', array('question' => $question['_id'], 'invalid' => array('$ne' => true))))
      return ErrorLib::set_error("This question type does not accept multiple valid answers");
    
    // ensure answer includes a choice
    if(!isset($pq['private']['scores'][$answer['input']['choice']]))
      return ErrorLib::set_error("You must select a choice");
    
    // could add stats info to question
    // could do cool stuff with the answer
    
    $result['answer']['input'] = $answer['input'];
    
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
    // score is the average of the mc answers
    foreach($answers as $answer) {
      $value += self::get_score($pq, $answer);
      $count++;
    }
    
    if(!$count)
      return 0;
    
    $value = $value / $count;
    
    return $value;
  }
  
  
  /** 
  * Score a single answer 
  * @param array The protoquestion
  * @param array The answer
  * @return number
  */ 
  static function get_score($pq, $answer)
  {
    $scores = $pq['private']['scores'];
    $choice = $answer['input']['choice'];
    
    return $scores[$choice] + 0;
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
    $x['choice'] = 0;
    $x['extra'] = 'lorem ipsum';
    return $x;
  }
  
}

// EOT