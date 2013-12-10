<?php

/**
 * Sum all the scores
 *
 * @package audittree
 * @author dann toliver
 * @version 1.0
 */

class JoinersSum
{
  
  /** 
  * Add the scores
  * @param array Set of scores
  * @param array Set of params
  * @return number 
  */ 
  static function activate($scores, $params)
  {
    $new_score = array('value' => 0);

    foreach($scores as $score) {
      $new_score['value'] += $score['value'];
    }
    
    return $new_score;
  }
  
  /** 
  * Returns an array of params, like ({* (:keyword :keyword1 :name "Keyword 1" :type :numeric)} {* (:keyword :keyword2 :name "Keyword 2" :type :multiple_choice :choices {* (:key1 "value 1" :key2 "value 2")})})
  * @return array 
  */ 
  static function get_params()
  {
    return array();
  }

  /** 
  * Get the name. It's a string.  
  * @return string 
  */ 
  static function get_name()
  {
    return "Sum";
  }

  /** 
  * Get the description. It's also a string.  
  * @return string 
  */ 
  static function get_description()
  {
    return "Sum all the scores.";
  }
  
}

// EOT