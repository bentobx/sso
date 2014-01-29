<?php

/**
 * Study: collection information over time.
 *
 * @package daimio
 * @author Cecily Carver
 * @version 1.0
 */
 
class Study
{
  // validate the study
  private static function validize($item)
  {
    $collection = 'studies';
    $fields = array('name', 'users');
    
    if(!$item) return false;
    if($item['valid']) return true;
    
    foreach($fields as $key)
      if($item[$key] == false) return false;
    
    // all clear!
    
    $update['valid'] = true;
    MongoLib::set($collection, $item['_id'], $update);
    
    return true;
  }
  
  /** 
  * Find the study you want
  * @param string Study ids
  * @param string Study name
  * @param string Study user
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return string Study ids
  * @key __member
  */ 
  static function find($by_ids=NULL, $by_name=NULL, $by_user=NULL, $options=NULL)
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_name))
      $query['name'] = new MongoRegex("/$by_name/i");
    
    if(isset($by_user)) 
      $query['users'] = array('$in' => MongoLib::fix_ids($by_user));
      
    return MongoLib::find_with_perms('studies', $query, $options);   
  }
  

  /** 
  * Add a new study  
  * @param string A protocol id
  * @return string study id   
  * @key admin __exec
  */ 
  static function add($protocol)
  {
    // verify protocol
    if(!$protocol = MongoLib::findOne_viewable('protocols', $protocol))
      return ErrorLib::set_error("Invalid protocol");

    // almost clear
    
    
    // make test
    if(!$test_id = Test::build($protocol['_id'], array('protocols', $protocol['_id'])))
      return ErrorLib::set_error("That test could not be built");
    
    
    // all clear!
    
    $study['name'] = false;
    $study['protocol'] = false;
    $study['users'] = false;
    $study['protocol'] = $protocol['_id'];
    $study['test'] = $test_id;
    
    $study_id = MongoLib::insert('studies', $study);
    
    PermLib::grant_user_root_perms('studies', $study_id);
    PermLib::grant_permission(array('studies', $study_id), "admin:*", 'root');
    
    History::add('studies', $study_id, array('action' => 'add', 'data' => array('protocol' => $protocol['_id'], 'test' => $test_id)));
    
    return $study_id;
  }
  
  
  /** 
  * Set the name of the study 
  * @param string Study id
  * @param string Study name
  * @return string Study id
  * @key admin __exec
  */ 
  static function set_name($id, $value)
  {
    if(!$study = MongoLib::findOne_editable('studies', $id))
      return ErrorLib::set_error("That study is not within your domain");
      
    $value = Processor::sanitize($value);
    
    if (!$value || strlen($value) < 3 || strlen($value) > 200)
      return ErrorLib::set_error("Invalid study name");
      
    if($study['name'] == $value)
      return $id;
    
    // all clear! 
    
    $update['name'] = $value;

    
    // NOTE TO CECILY:
    // the $id value is passed in by the user, so it's often a string (coming from a url, e.g.)
    // use $study['_id'] or eq instead, or MongoLib::fix_id($id) if that isn't available
    
    MongoLib::set('studies', $study['_id'], $update);  
    
    History::add('studies', $study['_id'], array('action' => 'set_name', 'value' => $value));
    
    // MongoLib::set('studies', $id, $update);  
    // 
    // History::add('studies', $id, array('action' => 'set_name', 'value' => $value));
    
    
    
    $study['name'] = $value;
    self::validize($study);
    
    return $id;
  }
  
  
  /** 
  * Set the list of users participating in the study  
  * @param string Study id
  * @param string List of user ids
  * @return string Study id 
  * @key admin __exec
  */ 
  static function set_users($id, $value)
  {
    if(!$study = MongoLib::findOne_editable('studies', $id))
      return ErrorLib::set_error("That study is not within your domain");
    
    // repeat?
    if($study['users'] == $value)
      return $id;
    
    // check user ids
    $users = MongoLib::findIn('members', $value);
    
    if(!count($users))
      return ErrorLib::set_error("You must provide at least one valid user id");

    // THINK: if they include some invalid ids we'll just look the other way
    // if(count($value) != count($users))
    //   return ErrorLib::set_error("Invalid user ids");
    
    // all clear! 
    
    foreach($users as $user) {
      if($user['_id'])
        $good_ids[] = $user['_id'];
    }
    
    $update['users'] = $good_ids;
    MongoLib::set('studies', $study['_id'], $update);
    
    History::add('studies', $study['_id'], array('action' => 'set_users', 'value' => $good_ids));
    
    $study['users'] = $good_ids;
    self::validize($study);
    
    // TODO: revoke current user view perms on edit (but not edit or root perms)
    
    // grant view perms to each user
    foreach($users as $user) {
      PermLib::grant_permission(array('studies', $study['_id']), "user:" . $user['_id'], 'view');
      PermLib::grant_permission(array('tests', $study['test']), "user:" . $user['_id'], 'view');
    }
    
    return $id;
  }  
  
  /** 
  * Special-purpose version of "distribute" for SSO. Distributes all questions in the study to a single user. 
  * @param string
  * @param string 
  * @return string 
  * @key __world
  */ 
  static function sso_distribute($id, $userid)
  {
    if(!$study = MongoLib::findOne_viewable('studies', $id))
      return ErrorLib::set_error("That study is not within your domain");
      
    if(!$protocol = MongoLib::findOne_viewable('protocols', $study['protocol']))
      return ErrorLib::set_error("Invalid protocol");
    
    $q_list = $protocol['q_list'];
    $users = $study['users'];
    
    // all clear! 
  
    // transform each pq into a question and assign it to the user
    foreach ($q_list as $q) {
      $q_id = Question::add($study['test'], $q['pq'], $q['pdata']);
      $thing = array('questions', $q_id);
      PermLib::grant_permission($thing, "user:$userid", 'root');
      PermLib::grant_permission($thing, "admin:*", 'root');
    }
    
    return $id;   
  }
  
  
  /** 
  * Distribute questions 
  * @param string 
  * @return string 
  * @key admin
  */ 
  static function distribute($id)
  {
    if(!$study = MongoLib::findOne_editable('studies', $id))
      return ErrorLib::set_error("That study is not within your domain");
      
    if(!$protocol = MongoLib::findOne_viewable('protocols', $study['protocol']))
      return ErrorLib::set_error("Invalid protocol");
    
    $q_list = $protocol['q_list'];
    $users = $study['users'];
    
    // all clear! 
    
    foreach($users as $user) {
      $q = $q_list[array_rand($q_list)];
      $q_id = Question::add($study['test'], $q['pq'], $q['pdata']);

      $thing = array('questions', $q_id);
      PermLib::grant_permission($thing, "user:$user", 'root');
      PermLib::grant_permission($thing, "admin:*", 'root');
    }
    
    return $id;
  }
  
  
  /** 
  * Returns an array of extended answer objects, containing PQ, user id, user depot, answer and time
  * @param array Study id
  * @return array 
  * @key __member
  */ 
  static function compose_answers($id)
  {
    // get the study
    if(!$study = MongoLib::findOne_viewable('studies', $id))
      return ErrorLib::set_error("No such study found");
    
    // get the answers
    if(!$answers = MongoLib::find_with_perms('answers', array('test' => $study['test'])))
      return ErrorLib::set_error("No valid answers were detected");
    
    // THINK: we're assuming that if you can see the answers, you can see the Q, PQ, and member... but that might not be true.
    
    // get the questions
    foreach($answers as $answer)
      $qids[] = $answer['question'];
    if(!$questions = MongoLib::findIn('questions', $qids))
      return ErrorLib::set_error("No valid questions were detected");
    
    // get the PQs
    foreach($questions as $question)
      $pqids[] = $question['pq'];
    if(!$pqs = MongoLib::findIn('protoquestions', $pqids))
      return ErrorLib::set_error("No valid protoquestions were detected");
    
    // get the members
    foreach($answers as $answer)
      $users[] = $answer['user'];
    if(!$members = MongoLib::findIn('profiles', $users))
      return ErrorLib::set_error("No valid members were detected");
    
    // all clear!
    
    foreach($answers as $answer) {
      $composite = $members[$answer['user']]['my'];

      $composite['answer'] = $answer['input'];
      $composite['time'] = $answer['date']->sec;
      $composite['user'] = $answer['user']; 
        
      $pq = $pqs[(string) $questions[(string) $answer['question']]['pq']];

      $composite['pq'] = (string) $pq['_id'];
      $composite['question'] = $pq['public']['text'];
      $composite['choices'] = $pq['public']['choices'];

      $composites[] = $composite;
    }
    
    return $composites;
  }
  
  /** 
  * Like compose_answers but with some extra munging
  * @param array Study id
  * @return array 
  * @key __member
  */ 
  static function decompose_answers($id)
  {
    $answers = Study::compose_answers($id);
    $new_list = array();
    foreach($answers as $key => $answer) {
      $new_list[$key]['time'] = date('r', $answer['time']);
      $new_list[$key]['question'] = $answer['question'];
      $new_list[$key]['answer'] = $answer['answer'] ? 'No' : 'Yes';
      
      $new_list[$key]['background'] = $answer['background'];
      $new_list[$key]['birth_year'] = $answer['birth_year'];
      $new_list[$key]['city']    = $answer['city'];
      $new_list[$key]['lgbtq']   = $answer['lgbtq'];
      $new_list[$key]['ontario'] = $answer['ontario'];
      $new_list[$key]['pronoun'] = $answer['pronoun'];
      $new_list[$key]['details'] = $answer[$answer['pq']];
    }
    
    return $new_list;
  }
  
  
  /** 
  * Returns a list of yes and no answer totals for each PQ
  * @param array Study id
  * @return array 
  * @key __world
  */ 
  static function yesno($id)
  {
    // get the study
    if(!$study = MongoLib::findOne('studies', $id))
      return ErrorLib::set_error("No such study found");
    
    // get the answers
    if(!$answers = MongoLib::find('answers', array('test' => $study['test'])))
      return ErrorLib::set_error("No valid answers were detected");
    
    // get the questions
    foreach($answers as $answer)
      $qids[] = $answer['question'];
    if(!$questions = MongoLib::findIn('questions', $qids))
      return ErrorLib::set_error("No valid questions were detected");
    
    // get the PQs
    foreach($questions as $question)
      $pqids[] = $question['pq'];
    if(!$pqs = MongoLib::findIn('protoquestions', $pqids))
      return ErrorLib::set_error("No valid protoquestions were detected");
    
    // all clear!
    
    foreach($pqids as $pqid)
      $totals[(string) $pqid] = array('yes' => 0, 'no' => 0, 'total' => 0);
    
    foreach($answers as $answer) {
      $pqid = (string) $pqs[(string) $questions[(string) $answer['question']]['pq']]['_id'];
      
      $index = $answer['input'] ? 'yes' : 'no';
      
      $totals[$pqid][$index]++;
      $totals[$pqid]['total']++;
    }
    
    return $totals;
  }
  
  
  /** 
  * Returns your answers for a particular study
  * @param array Study id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member
  */ 
  static function find_answers($id, $options=NULL)
  {
    // get the study
    if(!$study = MongoLib::findOne_viewable('studies', $id))
      return ErrorLib::set_error("No such study found");
    
    // all clear!
    
    $query['test'] = $study['test'];
    return MongoLib::find_with_perms('answers', $query, $options);   
  }

  /** 
  * Returns your questions for a particular study
  * @param array Study id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member
  */ 
  static function find_questions($id, $options=NULL)
  {
    // get the study
    if(!$study = MongoLib::findOne_viewable('studies', $id))
      return ErrorLib::set_error("No such study found");
    
    // all clear!
    
    $query['test'] = $study['test'];
    
    return MongoLib::find_with_perms('questions', $query, $options);   
  }
  
  
  /** 
  * This will SEVERELY bork things, so don't ever do it
  * @param string Study id
  * @return boolean 
  */ 
  static function destroy($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // get study
    if(!$study = MongoLib::findOne('studies', $id))
      return ErrorLib::set_error("No such study exists");
    
    // all clear!

    // add transaction to history
    History::add('studies', $id, array('action' => 'destroy', 'was' => $study));
    
    // THINK: there's no record of the questions and answers when you do this. we could use the Q+A built-in destroy functions and get a record that way. or somethin'.

    // destroy all the answers
    MongoLib::remove('answers', array('type' => $study['_id']));
    
    // destroy all the questions
    MongoLib::remove('questions', array('type' => $study['_id']));

    // destroy the study
    return MongoLib::removeOne('studies', $id);
  }
  
}