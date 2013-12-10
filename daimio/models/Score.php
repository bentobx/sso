<?php

/**
 * Anonymous hotline for deviant aberrations 
 *
 * @package bonsai
 * @author dann toliver
 * @version 1.0
 */

class Score
{
  
  /** 
  * Get all the scores for something (make sure you check perms if you put this in a lens!)
  * @param string A thing is a collection and an id, like (:companies :1234)
  * @param string A set of score type ids
  * @param string A value
  * @return array 
  * @key __lens __exec __trigger __member
  */ 
  static function find($by_thing=NULL, $by_types=NULL, $by_value=NULL)
  {
    
    // TODO: REMOVE _MEMBER KEY FROM {SCORE FIND} !!!!
    // Modified command "bid find"
    // Modified command "protocol set_trigger"
    
    $query = array();
    
    if(isset($by_thing)) 
      $query['thing'] = MongoLib::resolveDBRef($by_thing);

    if(isset($by_types)) 
      $query['score_type'] = array('$in' => MongoLib::fix_ids($by_types));
    
    if(isset($by_value)) 
      $query['value'] = $by_value;

    return MongoLib::find('scores', $query);
  }
  
  
  /** 
  * Search for qualified candidate 
  * @param string The service to perform
  * @param string The region of performance
  * @param string Id of scoring mechanism to filter/sort over
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array
  * @key __member
  */ 
  static function search($service, $region, $mechanism)
  {
    if(!$mech = MongoLib::findOne('mechanisms', $mechanism))
      return ErrorLib::set_error("No such mechanism exists");
    
    // if(!$region = MongoLib::findOne('regions', $region))
    //   return ErrorLib::set_error('You must provide a valid region id');
    // 
    // if(!$service = MongoLib::findOne('companies', $service))
    //   return ErrorLib::set_error("You must provide a valid service");
    
    if(!$crss = Company::find_crss(NULL, $region, $service))
      return array(); // no matches
    
    foreach($crss as $crs)
      $company_ids[] = $crs['c'];
    
    // foreach($crss as $crs)
    //   $companies[$crs['c']]['_id'] = $crs['c'];

    // get the score type for the mech's root rule's rfam
    // THINK: why can't we use the mfam's score type instead?
    if(!$rule = MongoLib::findOne('rules', $mech['root_rule']))
      return ErrorLib::set_error("No such rule exists");
      
    if(!$score_type = MongoLib::findOne('score_types', array('thing.$id' => $rule['family'])))
      return ErrorLib::set_error("No such score type exists");
    
    $query['thing.$id'] = array('$in' => MongoLib::fix_ids($company_ids));
    $query['score_type'] = $score_type['_id'];
    
    /*
      TODO:
      - this won't work until we rerun all mechs over all companies, because the cert/mech/rr scores aren't being recorded (blargh!)
      - it seems to be getting all the matching companies appropriately... but test that
      - do the {company find} stuff re: service&region
      - 
    */
    
    return MongoLib::find('scores', $query);
    
    
    /*
      HOW THIS SHOULD BE DONE:
      -- store services, regions and industries in their own collections
      -- store SIRPs in their own collection: industry id, service id, region id, and company id
      -- search SIRPs for matching companies
      -- get mechanism's root rule's score type
      - search scores for matching companies ids and score type
      - return raw scores
      - in __data, use company ids from scores to collect more company data (like name)
      
      DATA MIGRATION ETC:
      -- move all existing company SRPs into the main SIRPs table, delete from company
      -- add some indices!
      - change scores.score_types to MongoIds...
      -- add SIRP administration suite (can postpone for now)
      - change all fail_on_failure rules to failure_threshold and adjust param values (or eliminate)
    */
    
    
    
    // wrap this, because it hits mongo directly
    // try {
      // compose Mongo query
      // $query = array("services" => array("service" => $service, "region" => $region));    
      // $cursor = MongoLib::$mongo_db->companies->find($query); // leave this alone, we need the cursor
      
      // sort query
      // $mechanism_id_string = (string) $mechanism;

      // FIXME: HACKHACKHACK!!!!
      
      // $sort_query = array("fail.{$mechanism_id_string}" => 1, "scores.{$mechanism_id_string}" => -1);
      // $cursor->sort($sort_query); // FIXME: HACKHACKHACK!!!!!!!!
      
      // $companies = iterator_to_array($cursor);
      // foreach($companies as $index => $company) {
      //   $name = $company['name'];
      //   $bool = strlen($name) > 25;
      //   $companies[$index]['fail'][$mechanism_id_string] = $bool;
      //   $companies[$index]['scores'][$mechanism_id_string] = 100 - strlen($name);
      //   $companies[$index]['score'] = 100 - strlen($name);
      // }
      // 
      // return $companies;
      
      // END FIXME: HACKHACKHACK!!!!
      
      
      // limit query
      
      // return company info from mongo
      // OPT: with large result sets this is memory destructor!
      // return iterator_to_array($cursor);
    
      // YAGNI: result slices
      // YAGNI: merge w/ mysql company info (push mysql info directly into mongo? -- YES! (NO! (MAYBE!)))
      // YAGNI: count results / result stats

    // } catch(Exception $e) {
    //   ErrorLib::set_error("There was an error in the Mongo query");
    //   ErrorLib::log_array(array($e));
    //   return false;
    // }
  }
  
  /** 
  * Get some score types 
  * @param string A set of score type ids
  * @param string A group name
  * @param array A thing is a collection and an id, like (:rules :123) 
  * @param array Score type square (draft or published)
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find_types($by_ids=NULL, $by_group=NULL, $by_thing=NULL, $by_square=NULL, $options=NULL)
  {
    $query = array();
    
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));

    if(isset($by_group))
      $query['group'] = $by_group;

    if(isset($by_thing)) {
      if(!$thing = MongoLib::resolveDBRef($by_thing))
        return ErrorLib::set_error("Invalid thing");
      $query['thing'] = $thing;
    }
 
    if(!$query)
      $query['square'] = 'published';
    
    return MongoLib::find('score_types', $query, NULL, $options);
  }
  
  
  /** 
  * Add a score to a company 
  * @param string Id of the score type
  * @param array A thing is a collection and an id, like (:companies :252) 
  * @param string Value of the score (or the whole score)
  * @param string Failure of the score
  * @return string 
  * @key __exec __trigger
  */ 
  static function add($score_type, $thing, $value, $fail)
  {
    // NOTE: this gets called from *Bonsai
    
    // fix value
    if(is_array($value)) {
      $fail = $value['fail'];
      $value = $value['value'];
    }
    
    // ensure score type exists
    if(!MongoLib::check('score_types', $score_type))
      return ErrorLib::set_error("That score type could not be found");
    
    // verify thing and create thing dbref
    if(!$thing = MongoLib::createDBRef($thing))
      return ErrorLib::set_error("Invalid thing");
    
    // all clear!
    
    // add or update the score for the thing
    return MechLib::push_score_without_checks($score_type, $thing, $value, $fail);
  }
  
  /** 
  * Add a new score type 
  * @param string Short name (truncated at 20 characters)
  * @param string Group name (truncated at 20 characters)
  * @param array A thing is a collection and an id, like (:rules :123) or (:protoquestions :456)
  * @return id
  * @key __exec __trigger
  */ 
  static function add_type($shortname, $group, $thing)
  {
    // THINK: would we ever reject a score type for shortname/group/thing non-uniqueness?

    // verify and fix shortname
    if(!$shortname = substr(trim($shortname), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
    
    // verify and fix group
    if(!$group = substr(trim($group), 0, 20))
      return ErrorLib::set_error("A valid group is required");
    
    // verify thing and create thing dbref
    if(!$thing = MongoLib::createDBRef($thing))
      return ErrorLib::set_error("Invalid thing");
    
    // all clear!
    
    $score_type['shortname'] = $shortname;
    $score_type['group'] = $group;
    $score_type['thing'] = $thing;
    $score_type['square'] = 'draft';
    
    return MongoLib::insert('score_types', $score_type);
  }
  
  /** 
  * Change a score type's shortname and group 
  * @param string Score type id
  * @param string Short name (truncated at 20 characters)
  * @param string Group name (truncated at 20 characters)
  * @return boolean 
  */ 
  static function change_name($type, $shortname, $group)
  {
    // THINK: would we ever reject a score type for shortname/group/thing non-uniqueness?
    
    // get score_type
    if(!$score_type = MongoLib::findOne('score_types', $type))
      return ErrorLib::set_error("No such score type exists");    
    
    // verify and fix shortname
    if(!$shortname = substr(trim($shortname), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
    
    // verify and fix group
    if(!$group = substr(trim($group), 0, 20))
      return ErrorLib::set_error("A valid group is required");
    
    // all clear!
    
    // add transaction to history
    History::add('score_types', $score_type['_id'], array('action' => 'change_name', 'was' => array('shortname' => $score_type['shortname'], 'group' => $score_type['group'])));
    
    // update score type
    $update['shortname'] = $shortname;
    $update['group'] = $group;
    return MongoLib::set('score_types', $score_type['_id'], $update);
  }
  
  
  /** 
  * Set a score type's square 
  * @param string Score type id
  * @param string Square (draft, active or deprecated)
  * @return boolean 
  * @key __exec __trigger
  */ 
  static function set_square($type, $square)
  {
    // get score_type
    if(!$score_type = MongoLib::findOne('score_types', $type))
      return ErrorLib::set_error("No such score type exists");    
    
    // check square
    if(!in_array($square, array('draft', 'active', 'deprecated')))
      return ErrorLib::set_error("That is an invalid score type square");
    
    // all clear!
    
    // add transaction to history
    History::add('score_types', $score_type['_id'], array('action' => 'set_square', 'was' => $score_type['square']));
    
    // update score type
    $update['square'] = $square;
    return MongoLib::set('score_types', $score_type['_id'], $update);
  }
  
  
  /**
  * Destroy a score type and all related scores (you'd have to be out of your mind!)
  * @param string Score type id
  * @return boolean
  */
  static function destroy_type($type_id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // get score_type
    if(!$score_type = MongoLib::findOne('score_types', $type_id))
      return ErrorLib::set_error("No such score type exists");    
    
    // all clear!
    
    // add transaction to history
    History::add('score_types', $score_type['_id'], array('action' => 'destroy', 'was' => $score_type));
    
    // destroy its scores
    MongoLib::remove('scores', array('type' => $score_type['_id']));
    
    // THINK: considering adding the scores to history
    // NOTE: not clearing mech caches here, because this should only be called by things further upstream
    
    // destroy score type
    return MongoLib::removeOne('score_types', $score_type['_id']);
  }
  
}

// EOT