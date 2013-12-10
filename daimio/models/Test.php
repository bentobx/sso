<?php

/**
  * This handler grants direct access to Bonsai tests -- use wisely!
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Test
{
  
  /** 
  * Test filters stack, except by_audit
  * @param array Test ids
  * @param array An audit id
  * @param array A thing is a collection and an id, like (:companies :252) 
  * @param array Question ids
  * @param array Protocol id
  * @param array Accepts 'any' (any test regardless of sandbox), 'only' (just sandbox tests), or 'none' (no sandbox tests) -- defaults to none
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec __trigger
  */ 
  static function find($by_ids=NULL, $by_audit=NULL, $by_thing=NULL, $by_questions=NULL, $by_protocol=NULL, $sandbox=NULL, $options=NULL)
  {
    if(isset($by_ids)) {
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    }
    
    if(isset($by_audit)) {
      if(!$audit = MongoLib::findOne('audits', $by_audit, 'tests'))
        return ErrorLib::set_error("Invalid audit id");
        
      $test_ids = $audit['tests'] ? $audit['tests'] : array();
      $query['_id'] = array('$in' => $test_ids);
    }
    
    if(isset($by_thing)) {
      if(!$thing = MongoLib::resolveDBRef($by_thing))
        return ErrorLib::set_error("Invalid thing");

      $query['thing'] = $thing;
    }
    
    if(isset($by_questions)) {
      if(!$questions = MongoLib::findIn('questions', $question_ids))
        return ErrorLib::set_error("Invalid question ids");

      foreach($questions as $q)
        $test_ids[] = $q['test'];
      
      $query['_id'] = array('$in' => (array) $test_ids);
    }
    
    if(isset($by_protocol)) {
      $query['protocol'] = MongoLib::fix_id($by_protocol);
    }
    
    if($sandbox == 'only') {
      $query['sandbox'] = true;
    } elseif($sandbox != 'any') {
      $query['sandbox']['$ne'] = true;
    }
    
    return MongoLib::find_with_perms('tests', $query, $options);
  }


  /** 
  * Returns answers for a particular test
  * @param array Test id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member
  */ 
  static function find_answers($id, $options=NULL)
  {
    // get the test
    if(!$test = MongoLib::findOne_viewable('tests', $id, 'perms'))
      return ErrorLib::set_error("No such test found");
    
    // all clear!
    
    $query['test'] = $test['_id'];
    return MongoLib::find_with_perms('answers', $query, $options);
  }
  

  /** 
  * Returns questions for a particular test
  * @param array Test id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member
  */ 
  static function find_questions($id, $options=NULL)
  {
    // get the test
    if(!$test = MongoLib::findOne_viewable('tests', $id, 'perms'))
      return ErrorLib::set_error("No such test found");
    
    // all clear!
    
    $query['test'] = $test['_id'];
    return MongoLib::find_with_perms('questions', $query, $options);
  }
  
  /** 
  * Get vscores for a test 
  * @param string Test id
  * @return array 
  * @key __member
  */ 
  static function make_scores($id)
  {
    // get the test
    // THINK: i'm not sure who should be able to do this -- for now we'll stick with view perms
    if(!$test = MongoLib::findOne_viewable('tests', $id, 'perms'))
      return ErrorLib::set_error("No such test found");
    
    // all clear!
    
    return MechLib::get_test_vscores($test['_id']);
  }
  

  /** 
  * Protocol promotion within a test  
  * @param string Test id
  * @return boolean 
  * @key __member
  */ 
  static function promote($id)
  {
    // get the test
    if(!$test = MongoLib::findOne_editable('tests', $id))
      return ErrorLib::set_error("No such test found");
    
    // get the protocol
    if(!$protocol = MongoLib::findOne('protocols', $test['protocol']))
      return ErrorLib::set_error("No such protocol found");
    
    // check for locked
    if($test['square'] == 'locked')
      return ErrorLib::set_error("That test is locked and can not be promoted");
    
    // check for sandbox
//    if($test['sandbox'])
//      return ErrorLib::set_error("Sandbox tests can not be promoted");
    
    // check the protocol's promote permits
    $my_permits = Permit::get_mine();
    if(!array_intersect($my_permits, (array) $protocol['promopermits']))
      return ErrorLib::set_error("You do not have permission to promote tests for that protocol");
    
    // set up trigger data
    $data['protocol'] = $protocol;
    $data['test'] = $test;
    
    // run the protocol's promote conditions
    if(BonsaiLib::trigger_conditions_fail($protocol, 'promote', $data))
      return false;
    
    // all clear
    
    // add transaction to history
    History::add('tests', $id, array('action' => 'promote'));
  
    // proc the protocol's promote actions
    BonsaiLib::proc_trigger_actions($protocol, 'promote', $data);
    
    return true;
  }

  
  /** 
  * Lock a test so it can't be edited (and questions can't be answered) 
  * @param string Test id
  * @return boolean 
  * @key __exec __trigger
  */ 
  static function lock($id)
  {
    // get the test
    if(!$test = MongoLib::findOne_editable('tests', $id))
      return ErrorLib::set_error("No such test found");
    
    // get the protocol
    if(!$protocol = MongoLib::findOne('protocols', $test['protocol']))
      return ErrorLib::set_error("No such protocol found");
    
    // check for locked
    if($test['square'] == 'locked')
      return ErrorLib::set_error("That test is already locked");
    
    // all clear!

    // add transaction to history
    History::add('tests', $id, array('action' => 'lock'));
    
    // lock the test
    $update['square'] = 'locked';
    MongoLib::set('tests', $test['_id'], $update);
    
    return $test['_id'];
  }
  
  /** 
  * Unlock a test for editing 
  * @param string Test id
  * @return boolean 
  * @key __exec __trigger
  */ 
  static function unlock($id)
  {
    // get the test
    if(!$test = MongoLib::findOne_editable('tests', $id))
      return ErrorLib::set_error("No such test found");
    
    // get the protocol
    if(!$protocol = MongoLib::findOne('protocols', $test['protocol']))
      return ErrorLib::set_error("No such protocol found");
    
    // check for locked
    if($test['square'] != 'locked')
      return ErrorLib::set_error("That test is not locked");
    
    // all clear!
    
    // add transaction to history
    History::add('tests', $id, array('action' => 'unlock'));
    
    // unlock the test
    $update['square'] = 'open';
    MongoLib::set('tests', $test['_id'], $update);
    
    return $test['_id'];
  }
  
  
  /** 
  * Push the test's PQ scores into the system, run the mech and push its scores, with optional closing
  * @param string Test id
  * @param string Don't close the test after (don't do this, there's no reason!)
  * @return boolean 
  * @key __trigger __exec
  */ 
  static function finalize($id)
  {
    // get the test
    if(!$test = MongoLib::findOne_editable('tests', $id))
      return ErrorLib::set_error("No such test found");
    
    // get the protocol
    if(!$protocol = MongoLib::findOne('protocols', $test['protocol']))
      return ErrorLib::set_error("Faulty protocol");

    // check for locked
    if($test['square'] == 'locked')
      return ErrorLib::set_error("That test is already locked");
    
    // check for sandbox
    if($test['sandbox'])
      return ErrorLib::set_error("Sandbox tests can not be finalized");
    
    // all clear!
        
    // add transaction to history
    History::add('tests', $id, array('action' => 'finalize'));
    
    // get and push vscores
    $vscores = MechLib::push_test_vscores($test['_id']);
    
    // run the mech and push the results
    $mf_score = MechLib::run_mech($protocol['mech'], $vscores, $test['thing']);
    
    // close, lock, and save score
    $update['square'] = 'locked';
    $update['locker.finalized'] = true;
    $update['locker.score'] = $mf_score;
    MongoLib::set('tests', $test['_id'], $update);
    History::add('tests', $test['_id'], array('action' => 'close'));
    
    return $test['_id'];
  }
  
  
  //
  // TEST BUILDING AND MAINTENANCE
  //
  
  
  /** 
  * Build a test for a thing over a protocol
  * 
  * Note that the test's promote trigger action should push 'reviewed_test' into the locker when creating a review test, using the {locker set} command
  * 
  * @param array Protocol id
  * @param array A thing is a collection and an id, like (:companies :252) 
  * @param string Input array (data for the build action)
  * @param string Build test in sandbox mode (this is unundoable)
  * @return id 
  * @key __exec __trigger
  */ 
  static function build($protocol, $thing, $input=NULL, $sandbox=NULL)
  {
    // verify thing and create thing dbref
    if(!$thing = MongoLib::createDBRef($thing))
      return ErrorLib::set_error("Invalid thing");
    
    // verify protocol
    if(!$protocol = MongoLib::findOne_viewable('protocols', $protocol))
      return ErrorLib::set_error("Invalid protocol");

    // check protocol's square
    if($protocol['square'] != 'published')
      if(!$sandbox)
        return ErrorLib::set_error("That protocol is not currently published, and can only be activated in sandbox mode");
    
    // check the protocol's startlock
    // $free_pass = count(array_intersect($GLOBALS['X']['USER']['keychain'], array('__exec', '__trigger')));
    // if(!$sandbox && !$free_pass && $protocol['startlock'])
    //   return ErrorLib::set_error("That protocol can not be invoked directly");
    
    // check the protocol's promote permits
    // THINK: is it true that if you can't promote you can't start?
    // $my_permits = Permit::get_mine();
    // if(!array_intersect($my_permits, (array) $protocol['promopermits']))
    //   return ErrorLib::set_error("You do not have permission to build tests for that protocol");
    
    // set up test
    $test['protocol'] = $protocol['_id'];
    $test['thing'] = $thing;
    $test['square'] = 'open';
    if($sandbox)
      $test['sandbox'] = true;

    // set up data  
    $data['test'] = $test;
    $data['input'] = (array) $input;
    $data['protocol'] = $protocol;
    
    // THINK: this is a weird place for the complete count
    $test['locker']['complete'] = 0;
    
    // check the protocol's conditions
    if(BonsaiLib::trigger_conditions_fail($protocol, 'build', $data))
      return false;
    
    // all clear!
    
    // create test
    $test['_id'] = MongoLib::insert('tests', $test);
    
    // add root perms for the user (and company, if applicable) to this test
    PermLib::grant_user_root_perms('tests', $test['_id']);

    // THINK: this is a little weird...
    if($thing[0] == 'companies')
      PermLib::grant_company_root_perms('tests', $test['_id']);
    
    // run the protocol's build action (or do the default build action)
    $data['test'] = $test;
    BonsaiLib::proc_trigger_actions($protocol, 'build', $data);
    
    // add transaction to history
    // THINK: is there a reason this wasn't here before?
    History::add('tests', $test['_id'], array('action' => 'build'));
    
    return $test['_id'];
  }
  
  /** 
  * Answer questions for to-test based on from-test
  * @param string Test from which to copy answers
  * @param string Test whose questions are being answered
  * @return string 
  * @key __member
  */ 
  static function clone_answers($from, $to)
  {
    // get the 'from' test
    if(!$from_test = MongoLib::findOne_viewable('tests', $from))
      return ErrorLib::set_error("The from test could not be found");
    
    // get the 'to' test
    if(!$to_test = MongoLib::findOne_editable('tests', $to))
      return ErrorLib::set_error("The to test could not be found");
    
    // check for locked
    if($to_test['square'] == 'locked')
      return ErrorLib::set_error("The to test is currently locked");
    
    // get the 'to' test's protocol
    if(!$protocol = MongoLib::findOne('protocols', $to_test['protocol']))
      return ErrorLib::set_error("Faulty protocol");

    // get the 'from' questions
    if(!$from_questions = MongoLib::find('questions', array('test' => $from_test['_id']))) 
      return ErrorLib::set_error("The from test has no questions");
    
    // get the 'to' questions
    if(!$to_questions = MongoLib::find('questions', array('test' => $to_test['_id']))) 
      return ErrorLib::set_error("The to test has no questions");
    
    // get the 'from' answers
    if(!$from_answers = MongoLib::find('answers', array('test' => $from_test['_id'], 'invalid' => array('$ne' => true))))
      return ErrorLib::set_error("The from test has no answers");
    
    // get the 'to' answers
    $to_answers = MongoLib::find('answers', array('test' => $to_test['_id']));    
    
    // all clear!
    
    // add transaction to history
    History::add('tests', $to_test['_id'], array('action' => 'clone_answers', 'from' => $from_test['_id']));
    
    // rekey from_answers by fq_id
    foreach($from_answers as $fa) {
      $fa_hash[(string) $fa['question']][] = $fa;
    }
    
    // rekey to_answers by tq_id
    foreach($to_answers as $ta) {
      $ta_hash[(string) $ta['question']] = true; // we don't need the actual answer
    }
    
    // rekey from_questions by pq_id+pdata
    // THINK: matching on pq_id + pdata is (almost certainly) sufficient, but it might not be necessary
    foreach($from_questions as $fq) {
      $key = json_encode(array('pq' => $fq['pq'], 'pdata' => $fq['pdata']));
      $fq_hash[$key] = $fq; // TODO: switch to matching from_answer
      $pq_ids[(string) $fq['pq']] = $fq['pq'];
    }
    
    // get the pqs for from_questions
    if(!$pqs = MongoLib::findIn('protoquestions', MongoLib::fix_ids($pq_ids)))
      return ErrorLib::set_error("Invalid PQs");
    
    // try each to_question
    foreach($to_questions as $tq) {
      // YAGNI: make an override that invalidates existing answers (or adds the new one w/o invalidating, for multianswer Qtypes)
      
      if($ta_hash[(string) $tq['_id']])
        continue;
      
      // get the matching from_question
      $key = json_encode(array('pq' => $tq['pq'], 'pdata' => $tq['pdata']));
      if(!$matching_from_q = $fq_hash[$key])
        continue;
      
      // get the from_answers
      if(!$answers = $fa_hash[(string) $matching_from_q['_id']])
        continue;
      
      // add each answer
      // TODO: refactor this answer code (it's in four places currently), but be sure to allow override of pqs etc for efficiency
      
      foreach($answers as $from_answer) {
        // set pq
        $pq = $pqs[(string) $tq['pq']];

        // get the fake answer input
        $input = $from_answer['input'];

        // add the answer
        BonsaiLib::add_answer($input, $tq, $pq, $to_test, $protocol);

        $a_count++;
      }
      
      $q_count++;
    }
    
    ErrorLib::set_notice("Added $a_count answers to $q_count questions");
    return $to_test['_id'];
  }
  
  
  /** 
  * This attempts to fill a test with fake answers (use carefully!!!) 
  * @param string Test id
  * @return string 
  * @key admin
  */ 
  static function add_fake_answers($id)
  {
    // get the test
    if(!$test = MongoLib::findOne('tests', $id))
      return ErrorLib::set_error("No such test found");
    
    // get the protocol
    if(!$protocol = MongoLib::findOne('protocols', $test['protocol']))
      return ErrorLib::set_error("Faulty protocol");

    // get the questions
    if(!$questions = MongoLib::find('questions', array('test' => $test['_id']))) 
      return ErrorLib::set_error("That test has no questions");
    
    // get the pqs
    foreach($questions as $q)
      $pq_ids[(string) $q['pq']] = $q['pq'];
    if(!$pqs = MongoLib::findIn('protoquestions', MongoLib::fix_ids($pq_ids)))
      return ErrorLib::set_error("Invalid PQs");
    
    // all clear!
    
    // add transaction to history
    History::add('tests', $id, array('action' => 'add_fake_answers'));
    
    foreach($questions as $question) {
      if($question['complete'])
        continue;
      
      // set pq
      $pq = $pqs[(string) $question['pq']];
      
      // get the fake answer input
      $input = MixMaster::make_the_call('question_types', 'get_fake_answer', $pq['type']);
      
      // add the answer
      BonsaiLib::add_answer($input, $question, $pq, $test, $protocol);
    }
    
    return $test['_id'];
  }
  
  
  /** 
  * This attempts to set answers for a review test (use carefully!!!) 
  * @param string Test id
  * @param string Accepts 'accept_all', 'reject_all', or 'random' (default is random)
  * @return string 
  * @key admin
  */ 
  static function add_fake_review_answers($id, $type=NULL)
  {
    // get the test
    if(!$test = MongoLib::findOne('tests', $id))
      return ErrorLib::set_error("No such test found");
    
    // get the protocol
    if(!$protocol = MongoLib::findOne('protocols', $test['protocol']))
      return ErrorLib::set_error("Faulty protocol");

    // get the questions
    if(!$questions = MongoLib::find('questions', array('test' => $test['_id']))) 
      return ErrorLib::set_error("That test has no questions");
    
    // get the pqs
    foreach($questions as $q)
      $pq_ids[(string) $q['pq']] = $q['pq'];
    if(!$pqs = MongoLib::findIn('protoquestions', MongoLib::fix_ids($pq_ids)))
      return ErrorLib::set_error("Invalid PQs");
    
    // all clear!
    
    // add transaction to history
    History::add('tests', $id, array('action' => 'add_fake_review_answers'));
    
    foreach($questions as $question) {
      if($question['complete'])
        continue;
      
      // set pq
      $pq = $pqs[(string) $question['pq']];
      
      // create the fake answer
      if($type == 'accept_all')
        $input = 0;
      elseif($type == 'reject_all')
        $input = 1;
      else
        $input = rand(0,1);
      
      // add the answer
      BonsaiLib::add_answer($input, $question, $pq, $test, $protocol);
    }

    return $test['_id'];
  }
  

  /** 
  * This will SEVERELY bork things, so don't ever do it
  * @param string Test id
  * @return boolean 
  */ 
  static function destroy($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // get test
    if(!$test = MongoLib::findOne('tests', $id))
      return ErrorLib::set_error("No such test exists");
    
    // all clear!

    // add transaction to history
    History::add('tests', $id, array('action' => 'destroy', 'was' => $test));
    
    // THINK: there's no record of the questions and answers when you do this. we could use the Q+A built-in destroy functions and get a record that way. or somethin'.

    // destroy all the answers
    MongoLib::remove('answers', array('type' => $test['_id']));
    
    // destroy all the questions
    MongoLib::remove('questions', array('type' => $test['_id']));

    // destroy the test
    return MongoLib::removeOne('tests', $id);
  }

}

// EOT