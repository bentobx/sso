<?php

/**
 * Bonsai library
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class BonsaiLib
{

  
  /** 
  * Get the timestamp for a pubdate and ensure validity 
  * @param string A protocol from the db
  * @param string A proposed pubdate (a palatable datestring)
  * @return int 
  */ 
  static function get_protocol_pubdate_timestamp($protocol, $pubdate)
  {
    // convert pub date to timestamp
    if(ctype_digit((string) $pubdate))
      $timestamp = $pubdate;
    else
      $timestamp = strtotime($pubdate);
  
    // check pub date (> 2 weeks in future)
    $two_weeks = 1209600;
    if($timestamp - $two_weeks < time()) {
      $timestamp = time() + $two_weeks;
      ErrorLib::set_warning("Publication date has been corrected to two weeks in the future");
    }  
    
    // ensure there's nothing already queued in this MF's sandbox for that date range
    $pub_filter['pubdate']['$lt'] = new MongoDate($timestamp + $two_weeks); // anything within two weeks after
    $pub_filter['pubdate']['$gt'] = new MongoDate($timestamp - $two_weeks); // or within two weeks before
    $pub_filter['family'] = $protocol['family']; // in this PF
    $pub_filter['_id']['$ne'] = $protocol['_id']; // not this protocol
    if(MongoLib::check('protocols', $pub_filter))
      return ErrorLib::set_error("There is already a protocol scheduled for publication within two weeks of that publication date");
    
    return $timestamp;
  }

  /** 
  * This requires DB ITEMS, not just ids
  * @param string The answer
  * @param string The question
  * @param string The PQ
  * @param string The Test
  * @return string 
  */ 
  static function completeness_modulations($answer, $question, $pq, $test)
  {
    // is Q complete?
    $data = array('test' => $test, 'pq' => $pq, 'question' => $question);
    $complete = MixMaster::make_the_call('question_types', 'is_complete', $pq['type'], $data);

    // quick double-check for Q completeness
    $q = MongoLib::findOne('questions', $question['_id'], array('complete'));

    // test.complete tweaks
    if($q['complete'] != $complete) {
      $inc_value = $complete ? 1 : -1;
      MongoLib::update('tests', $test['_id'], array('$inc' => array('locker.complete' => $inc_value)));
      
      // set Q.complete
      $q_update['complete'] = $complete;
      MongoLib::set('questions', $question['_id'], $q_update);
    }
    
    return $complete;
  }
  

  
  //
  // TRIGGERS
  //
  
  
  /** 
  * if conditions returns a string this function returns false and sets the return as an error 
  * @param string A protocol array fresh from mongo
  * @param string Conditions type (build, add_answer or promote)
  * @param string A data array
  * @return string 
  */ 
  static function trigger_conditions_fail($protocol, $type, $data=array())
  {
    // convert from id
    if(!$protocol['_id'])
      $protocol = MongoLib::findOne('protocols', $protocol);
    
    if($protocol['triggers'] && is_array($protocol['triggers'][$type]))
      $conditions = $protocol['triggers'][$type]['conditions'];
    else
      return false;
    
    $conditions = trim($conditions);
    
    if(!$conditions)
      return false;
    
    if(!$data['protocol'])
      $data['protocol'] = $protocol;
    
    $error = trim(Processor::process_with_enhancements($conditions, '__trigger', $data));
    
    if($error)
      ErrorLib::set_error($error);
    
    return $error;
  }
  
  
  /** 
  * proc trigger actions and returns the results
  * @param string A protocol array fresh from mongo
  * @param string Action type (build, add_answer or promote)
  * @param string A data array
  * @return string 
  */ 
  static function proc_trigger_actions($protocol, $type, $data=array())
  {
    // convert from id
    if(!$protocol['_id'])
      $protocol = MongoLib::findOne('protocols', $protocol);
    
    if($protocol['triggers'] && is_array($protocol['triggers'][$type]))
      $action = $protocol['triggers'][$type]['actions'];
    else
      return false; // ErrorLib::set_warning("That protocol has no trigger for $type"); // THINK: is this really necessary?
    
    $action = trim($action);
    
    if(!$action)
      return false;
    
    if(!$data['protocol'])
      $data['protocol'] = $protocol;

    return trim(Processor::process_with_enhancements($action, '__trigger', $data));
  }
    
  
  /** 
  * This requires DB ITEMS, not just ids. There's also no perm checking. Use carefully!!! 
  * @param string The input value
  * @param string Database question hash
  * @param string Database pq hash
  * @param string Database test hash
  * @param string Database protocol hash
  * @return string 
  * @key __member
  */ 
  static function add_answer($input, $question, $pq, $test, $protocol)
  {
    // set up answer and data
    $answer = array('test' => $question['test'], 'question' => $question['_id'], 'input' => $input);
    $data = array('test' => $test, 'pq' => $pq, 'question' => $question, 'answer' => $answer);

    // check protocol conditions
    if(BonsaiLib::trigger_conditions_fail($protocol, 'answer', $data))
      return false;

    // QT conditions + actions
    
    // the QT can decide it's an invalid answer and throw an error
    $qt_call = MixMaster::make_the_call('question_types', 'add_answer', $pq['type'], $data);
    if($qt_call === false) 
      return false; 

    // affect answer input or locker
    if($qt_call['answer'] !== NULL) {
      $answer = array_merge($answer, $qt_call['answer']); 
      $data['answer'] = $answer;
    }

    // add to question locker, like for stats n' reviews n' stuff
    if(is_array($qt_call['question'])) {
      $data['question']['locker'] = array_merge($question['locker'], $qt_call['question']);
      foreach($qt_call['question'] as $key => $value) {
        MongoLib::set('questions', $question['_id'], array("locker.$key" => $value)); 
      }
    }

    // all clear! (as of above)

    // sanitize input, add user and date
    $answer['input'] = Processor::recursive_sanitize($answer['input']);
    $answer['user'] = $GLOBALS['X']['USER']['id'];
    $answer['date'] = new MongoDate();

    // add answer
    $answer_id = MongoLib::insert('answers', $answer);

    PermLib::grant_user_root_perms('answers', $answer_id);
    PermLib::grant_permission(array('answers', $answer_id), "admin:*", 'root');

    // proc protocol actions
    $data['answer'] = $answer_id;
    BonsaiLib::proc_trigger_actions($protocol, 'answer', $data);

    // handle completeness
    BonsaiLib::completeness_modulations($answer, $question, $pq, $test);
    
    return $answer_id;
  }
  
  
  // weird stuff
  
  
  /** 
  * Do some crazy stuff 
  * @param string pfam id
  * @return string 
  */ 
  static function get_score_type_from_pfam_throw_errors($pfam_id)
  {
    // get pfam
    if(!$pfam = MongoLib::findOne('protocol_families', $pfam_id))
      return ErrorLib::set_error("No such protocol family found");

    // get mfam
    if(!$mfam = MongoLib::findOne('mechanism_families', $pfam['mech_family']))
      return ErrorLib::set_error("Invalid mechanism family");

    // ensure it's published
    if($pfam['square'] != 'active')
      return ErrorLib::set_error("That protocol family has not been published");

    // check the pfam's root perms (to ensure I'm the owner...)
    // THINK: there's probably a way of doing this without hacking root perms
    if(!PermLib::i_can('root', $pfam['perms']))
      return ErrorLib::set_error("That protocol family is not yours to certify");
    
    return $mfam['score_type'];
  }
  
  
}

// EOT