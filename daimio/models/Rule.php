<?php

/**
 * Rules control scores from within mechanisms
 *
 * @package bonsai
 * @author dann toliver
 * @version 1.0
 */

class Rule
{
  
  /** 
  * Get the list of rules: requires exactly one param and uses mech perms
  * @param string A rule id
  * @param string A mechanism id (returns rules OWNED by the mech: to get the list of rules IN the mech use mech.rule_list)
  * @param string A rule family id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find($by_id=NULL, $by_mech=NULL, $by_family=NULL, $options=NULL)
  {
    if(isset($by_mech)) {
      if(!$mech = reset(Mechanism::find($by_mech)))
        return ErrorLib::set_error("Invalid mechanism id"); // this checks perms
      
      $query['mech'] = $mech['_id'];
      return MongoLib::find('rules', $query, NULL, $options);
    }
    
    if(isset($by_id)) {
      if(!$rule = MongoLib::findOne('rules', $by_id))
        return ErrorLib::set_error("Invalid rule id");
      
      $by_family = $rule['family'];
    }
    
    if(isset($by_family)) {
      if(!$rfam = MongoLib::findOne('rule_families', $by_family))
        return ErrorLib::set_error("Invalid rule family id");
      
      if(!$mfam = reset(Mechanism::find_families($rfam['mech_family'])))
        return ErrorLib::set_error("Invalid mechanism family id"); // this checks perms
      
      $query['family'] = $rfam['_id'];
      return MongoLib::find('rules', $query, NULL, $options);
    }
    
    // THINK: can this please find by_ids?

    return ErrorLib::set_warning("One of by_id, by_mech or by_family is required");
  }
  
  
  //
  // FAMILY FUNCTIONS
  //
  
  
  /** 
  * Get a list of rule families
  * @param string Rule family ids
  * @param string A mech family id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find_families($by_ids=NULL, $by_mech_family=NULL, $options=NULL)
  {
    $query = array();
    
    // THINK: this doesn't use mech perms, which means you may be able to see RFs for MFs that haven't been sandboxed (if you can get the MF id). Is that terrible? {rule find} does check mech perms, so you won't be able to see RF's actual rules...
    
    if(isset($by_ids))
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
      
    if(isset($by_mech_family))
      $query['mech_family'] = MongoLib::fix_id($by_mech_family);
      
    return MongoLib::find('rule_families', $query, NULL, $options);
  }
  
  
  /** 
  * Add a new rule family 
  * @param string Mech family id
  * @param string Rule surname
  * @param string Short name (truncated at 20 characters)
  * @return id 
  * @key __member
  */ 
  static function add_family($mech_family, $name, $shortname=NULL)
  {
    // get MF
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $mech_family))
      return ErrorLib::set_error("Invalid mechanism family");
    
    // NOTE: requiring MF edit perms to add an RF means that if you can edit one mech in an MF you can also add new Ms to it.
    // NOTE: you can add a rule family to a published MF, since you might need to add rules to a draft mech in that family.

    // ensure MF isn't deprecated
    if($mfam['square'] == 'deprecated')
      return ErrorLib::set_error("Deprecated mechanisms can not be edited");
    
    // verify name
    if(!$name = trim($name))
      return ErrorLib::set_error("A valid name is required");
    
    // verify and fix shortname
    if(!$shortname = substr(trim($shortname ? $shortname : $name), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
        
    // ensure unique name within rule families
    if(MongoLib::check('rule_families', array('mech' => $mfam['_id'], 'name' => $name)))
      return ErrorLib::set_error("A rule family with that name already exists within that mechanism family");
    
    // ensure unique shortname within rule families
    if(MongoLib::check('rule_families', array('mech' => $mfam['_id'], 'shortname' => $shortname)))
      return ErrorLib::set_error("A rule family with that shortname already exists within that mechanism family");
    
    // all clear!
    
    // add rule family
    $rfam['mech_family'] = $mfam['_id'];
    $rfam['name'] = $name;
    $rfam['shortname'] = $shortname;
    $rf_id = MongoLib::insert('rule_families', $rfam);
    
    // add the corresponding score type
    $thing = array('rule_families', $rf_id);
    $score_type_id = Score::add_type($shortname, 'RF:' . $mfam['shortname'], $thing);
    
    // add ST to RF
    $update['score_type'] = $score_type_id;
    MongoLib::set('rule_families', $rf_id, $update);
    
    // add transaction to history
    History::add('rule_families', $rf_id, array('action' => 'add'));
    
    return $rf_id;
  }
  
  
  /** 
  * Change a rule family's name 
  * @param string Rule family id
  * @param string Rule surname
  * @param string Short name (truncated at 20 characters)
  * @return boolean 
  * @key __member
  */ 
  static function change_name($id, $name, $shortname=NULL)
  {
    // get rule family
    if(!$rfam = MongoLib::findOne('rule_families', $id)) 
      return ErrorLib::set_error("Invalid rule family");
    
    // get MF
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $rfam['mech_family'])) 
      return ErrorLib::set_error("Faulty mechanism family id");
    
    // ensure MF isn't deprecated
    if($mfam['square'] != 'deprecated')
      return ErrorLib::set_error("Deprecated mechanisms can not be edited");
    
    // verify name
    if(!$name = trim($name))
      return ErrorLib::set_error("A valid name is required");
    
    // verify and fix shortname
    if(!$shortname = substr(trim($shortname ? $shortname : $name), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
        
    // all clear!
    
    // add transaction to history
    History::add('rule_families', $id, array('action' => 'change_name', 'was' => array('name' => $rfam['name'], 'shortname' => $rfam['shortname'])));

    // update the score type
    Score::change_name($rfam['score_type'], $shortname, 'RF:' . $mfam['shortname']);
    
    // update the RF
    $update['name'] = $name;
    $update['shortname'] = $shortname;
    return MongoLib::set('rule_families', $rfam['_id'], $update);
  }
  
  
  /** 
  * Destroy a rule family completely (generally a *really* bad idea) 
  * @param string Rule family id
  * @return boolean
  */ 
  static function destroy_family($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // THINK: should probably only do this in the context of an unpublished mech, or called from {mechanism destroy}
    
    // get rule family
    if(!$rfam = MongoLib::findOne('rule_families', $id))
      return ErrorLib::set_error("No such rule family exists");    
    
    // all clear!
    
    // add transaction to history
    History::add('rule_families', $id, array('action' => 'destroy', 'was' => $rfam));
    
    // NOTE: not clearing mech caches here, because this should only be called by things further upstream
    
    // get all rules
    $rules = MongoLib::find('rules', array('family' => $rfam['_id']));
    
    // destroy all rules
    foreach($rules as $rule) {
      History::add('rules', $rule['_id'], array('action' => 'destroy', 'was' => $rule));
      MongoLib::removeOne('rules', $rule['_id']);
    }
    
    // destroy RF's ST
    Score::destroy_type($rfam['score_type']);
    
    // destroy rule family
    return MongoLib::removeOne('rule_families', $rfam['_id']);
  }
  
  
  //
  // REGULAR FUNCTIONS
  //
  
  
  /** 
  * Create a new rule -- note that you'll need to run the individual change commands to add details after
  * @param string Rule family
  * @param string Mechanism id
  * @return string
  * @key __member
  */ 
  static function add($family, $mechanism)
  {
    // get rule family
    if(!$rfam = MongoLib::findOne('rule_families', $family)) 
      return ErrorLib::set_error("Invalid rule family");
    
    // get mechanism
    if(!$mech = MongoLib::findOne_editable('mechanisms', $mechanism)) 
      return ErrorLib::set_error("Faulty mechanism id");
    
    // check mech square
    if($mech['square'] != 'draft')
      return ErrorLib::set_error("Only draft mechanisms can be edited");
    
    // ensure mech is in RF's MF
    if($mech['family'] != $rfam['mech_family'])
      return ErrorLib::set_error("That mechanism is not within the rule family's purview");
    
    // ensure RF is not already in mech
    if($mech['rule_list'][(string) $rfam['_id']])
      return ErrorLib::set_error("That rule family already has a member in this mechanism; you must edit the existing rule instead of adding a new one");
    
    // all clear!
    
    return MechLib::add_rule($rfam['_id'], $mech['_id'], $rfam['score_type']);
  }
  

  /** 
  * Change a rule's joiner function for a particular mechanism
  * @param string Rule id
  * @param string Like {* (:keyword :function_name :params {* (:name :value)})}
  * @return id 
  * @key __member
  */ 
  static function change_joiner($id, $joiner)
  {
    // do some basic checking and fetching
    if(!$stuff = MechLib::rule_changing_basics($id))
      return false;
    list($rule, $rfam, $mech) = $stuff;
    
    // check joiner
    if(!$joiner || !$joiner['keyword'])
      return ErrorLib::set_error("No valid joiner found");
    $good_joiners = self::get_joiners();
    foreach($good_joiners as $j) {
      if($j['keyword'] == $joiner['keyword']) {
        $good_joiner_flag = true;
        break;
      }
    }
    if(!$good_joiner_flag)
      return ErrorLib::set_error("Invalid joiner type");
    
    // all clear!
    
    // add transaction to history
    History::add('rules', $id, array('action' => 'change_joiner', 'was' => $rule['joiner']));
    
    // clone rule if needed
    $rule_id = MechLib::maybe_clone_rule($rule, $mech);
    
    // update the rule
    $update['joiner'] = $joiner;
    MongoLib::set('rules', $rule_id, $update);
    
    return $rule_id;
  }
  
  
  /** 
  * Change a rule's modifiers for a particular mechanism
  * @param string Rule id
  * @param string Like ({* (:keyword :modifier_name :params {* (:name :value)})} {* (:keyword :modifier_name :params {* (:name :value)})})
  * @return id 
  * @key __member
  */ 
  static function change_modifiers($id, $modifiers)
  {
    // do some basic checking and fetching
    if(!$stuff = MechLib::rule_changing_basics($id))
      return false;
    list($rule, $rfam, $mech) = $stuff;
    
    // mod precheck
    if(!$modifiers)
      $modifiers = array();
    if(!is_array($modifiers))
      return ErrorLib::set_error("Modifiers must be an array");
    
    // check modifiers
    $good_mods = self::get_modifiers();
    foreach($modifiers as $mod) {
      if(!$mod['keyword'])
        return ErrorLib::set_error("Invalid modifier detected: no keyword found");

      if(!$good_mods[$mod['keyword']])
        return ErrorLib::set_error("Invalid modifier detected: invalid keyword detected");
      
      $this_mod['keyword'] = $mod['keyword'];
      $this_mod['params'] = $mod['params'];
      $valid_mods[] = $this_mod;
    }
    
    // all clear!
    
    // add transaction to history
    History::add('rules', $id, array('action' => 'change_modifiers', 'was' => $rule['modifiers']));
    
    // clone rule if needed
    $rule_id = MechLib::maybe_clone_rule($rule, $mech);
    
    // update the rule
    $update['modifiers'] = $valid_mods;
    MongoLib::set('rules', $rule_id, $update);
    
    return $rule_id;
  }
  
  /** 
  * Add a single new handle 
  * @param string Rule id
  * @param string A ham handle or array of same
  * @return string 
  * @key __member
  */ 
  static function add_ham($id, $ham)
  {
    if(!$ham)
      return ErrorLib::set_error("Improper ham provided");
      
    if(!$rule = MongoLib::findOne('rules', $id))
      return ErrorLib::set_error("Invalid rule id");
    
    // all clear!
    
    $new_hams = $ham;
    if(!is_array($new_hams))
      $new_hams = array($new_hams);
      
    $ham = $rule['ham'];
    foreach($new_hams as $new_ham)
      if($new_ham && !is_array($new_ham))
        $ham[] = (string) $new_ham;

    $ham = array_unique($ham);    
    Rule::change_ham($id, $ham);
  }
  
  /** 
  * Remove a single handle 
  * @param string Rule id
  * @param string A ham handle or array of same
  * @return string 
  * @key __member
  */ 
  static function remove_ham($id, $ham)
  {
    if(!$ham)
      return ErrorLib::set_error("Improper ham provided");
      
    if(!$rule = MongoLib::findOne('rules', $id))
      return ErrorLib::set_error("Invalid rule id");
    
    // all clear!
    
    $bad_hams = $ham;
    if(!is_array($bad_hams))
      $bad_hams = array($bad_hams);

    $ham = $rule['ham'];
    $ham = array_diff($ham, $bad_hams);
    
    Rule::change_ham($id, $ham);
  }
    
  /** 
  * Change a rule's HAM (Handle Array for Matching: a set of string handles for matching against vscores)
  * @param string Rule id
  * @param string An array of handles, like (:12345 :b4dc4b)
  * @return id 
  * @key __member
  */ 
  static function change_ham($id, $ham)
  {
    // do some basic checking and fetching
    if(!$stuff = MechLib::rule_changing_basics($id))
      return false;
    list($rule, $rfam, $mech) = $stuff;

    // check ham basics
    if(!is_array($ham))
      $ham = array($ham);
    // THINK: maybe there's a better solution than null HAM
    // if(!$ham)
    //   return ErrorLib::set_error("Invalid HAM"); 
    
    // gather fundamentals, kill non-strings and indices
    foreach($ham as $handle) {
      $handle = (string) $handle;
      $new_scores[] = $handle;
      $fundamentals[] = strpos($handle, ':') ? substr($handle, 0, strpos($handle, ':')) : $handle;
      // THINK: depending on how we enter match strings, not all of these fundamentals will be score_type ids. that's probably fine, since we're only concerned with matches in the db query below, but we might want to think of a reliable way of designating base score_type ids in the HAM
    }
    
    $ham = $new_scores;
    $fundamentals = array_unique($fundamentals);
    
    // check fundamentals and get matching STs
    $fundamentals = MongoLib::fix_ids($fundamentals);
    $st_query['_id']['$in'] = $fundamentals;
    $db_score_types = MongoLib::find('score_types', $st_query);      
    
    // ensure everything referenced is published
    // NOTE: this doesn't apply to pmechs, as any new PQs in P and all new rules in M will have unpublished STs.
    // THINK: also doesn't apply to any ST for RF in M's MF, but probably does apply to external STs.
    // $st_pub_query['_id']['$in'] = $fundamentals;
    // $st_pub_query['square'] = 'published';
    // $st_pub_count = MongoLib::count('score_types', $st_pub_query);
    // if(count($db_score_types) != $st_pub_count)
    //   return ErrorLib::set_error("Not all of those score types are published");
    
    // if it's a pmech, ensure all fundamental scores are either RFs in this MF or PQs in the P
    if($mech['pfam']) {
      foreach($db_score_types as $st) {
        if($st['thing']['$ref'] == 'protoquestions')
          $pq_ids[] = $st['thing']['$id'];
        elseif($st['thing']['$ref'] == 'rule_families')
          $rf_ids[] = $st['thing']['$id'];
        else
          return ErrorLib::set_error("Protocol mechanisms can only accept score types for rules within the mech or PQs within the protocol");
      }
      
      if($pq_ids) {
        $pq_query['q_list']['pq']['$in'] = $pq_ids;
        $pq_query['_id']['$ne'] = $mech['protocol'];
        if(MongoLib::check('protoquestions', $pq_query))
          return ErrorLib::set_error("All referenced PQs must be in the mech's protocol");
      }
      
      if($rf_ids) {
        $rf_query['_id']['$in'] = $rf_ids;
        $rf_query['mfam']['$ne'] = $mech['mfam'];
        if(MongoLib::check('rule_families', $rf_query))
          return ErrorLib::set_error("All referenced rules must live within the mechanism");
      }
    }
    
    // all clear!
    
    // add transaction to history
    History::add('rules', $id, array('action' => 'change_ham', 'was' => $rule['ham']));
    
    // clone rule if needed
    $rule_id = MechLib::maybe_clone_rule($rule, $mech);
    
    // get the score types from the HAM fundamentals
    foreach($db_score_types as $st)
      $score_types[] = $st['_id'];
    
    // update the rule
    $update['ham'] = $ham;
    $update['score_types'] = $score_types;
    MongoLib::set('rules', $rule_id, $update);
    
    // set the mech's new rule list and score types
    MechLib::set_rule_list_and_score_types($mech['_id']);
    
    // NOTE: don't worry about changing MF's downstream here, because you can't edit a published mech
    
    return $rule_id;
  }
  
  
  /** 
  * Get all the available score joiners  
  * @return array 
  * @key __member
  */ 
  static function get_joiners()
  {
    $joiner_names = MixMaster::get_mixins('joiners');
    
    foreach($joiner_names as $joiner) {
      $joiners[$joiner]['keyword'] = $joiner;
      $joiners[$joiner]['name'] = MixMaster::make_the_call('joiners', 'get_name', $joiner);
      $joiners[$joiner]['description'] = MixMaster::make_the_call('joiners', 'get_description', $joiner);
      $joiners[$joiner]['params'] = MixMaster::make_the_call('joiners', 'get_params', $joiner);
    }
    
    return $joiners;
  }
  
  
  /** 
  * Get all the available score modifiers  
  * @return array 
  * @key __member
  */ 
  static function get_modifiers()
  {
    $modifier_names = MixMaster::get_mixins('modifiers');
    
    foreach($modifier_names as $modifier) {
      $modifiers[$modifier]['keyword'] = $modifier;
      $modifiers[$modifier]['name'] = MixMaster::make_the_call('modifiers', 'get_name', $modifier);
      $modifiers[$modifier]['description'] = MixMaster::make_the_call('modifiers', 'get_description', $modifier);
      $modifiers[$modifier]['params'] = MixMaster::make_the_call('modifiers', 'get_params', $modifier);
    }
    
    return $modifiers;
  }
  
  
}

// EOT