<?php

/**
 * Bonsai's mechanism functions live here, along with a few helper functions. 
 * The helper functions should return fairly quickly, but you'll probably want to fork the others when you call them, or do it from a separate process (like a cron job). Some of the mech updating procedures can take awhile.
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class MechLib
{
  
    
  /** 
  * Add a new mechanism family
  * @param string Mechanism surname (must be unique systemwide)
  * @param string Short name (truncated at 20 characters)
  * @param string A thing is like (:protocol_families 12)
  * @return id 
  */ 
  static function add_family($name, $shortname='', $thing=NULL)
  {
    // verify name
    if(!$name = trim($name))
      return ErrorLib::set_error("A valid name is required");
    
    // verify shortname
    if(!$shortname = substr(trim($shortname ? $shortname : $name), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
    
    // check for shortname uniqueness 
    if(MongoLib::count('mechanism_families', array('shortname' => $shortname))) 
      return ErrorLib::set_error("The mechanism family shortname must be unique");
    
    if($thing) {
      // get thing
      if(!$thing_ref = MongoLib::createDBRef($thing))
        return ErrorLib::set_error("Invalid mech fam thing");
      
      // is there already a mech for this thing?
      $thing_query['thing'] = $thing_ref;
      if(MongoLib::check('mechanism_families', $thing_query))
        return ErrorLib::set_error("That thing already has a mechanism family");
    }

    // all clear!
    
    // add the mech family
    $mfam['shortname'] = $shortname;
    $mfam['square'] = 'draft';
    $mfam['name'] = $name;
    
    if($thing_ref) {
      // $mfam['pfam'] = $pfam['_id'];
      $mfam['thing'] = $thing_ref;
      $mfam['type'] = 'fixed';
    } else {
      $mfam['type'] = 'free';
    }
    
    $id = MongoLib::insert('mechanism_families', $mfam);
    
    // add score type
    $this_ref = array('mechanism_families', $id);
    $st_id = Score::add_type($shortname, 'MF', $this_ref);

    // push ST into MF
    $st_update['score_type'] = $st_id;
    MongoLib::set('mechanism_families', $id, $st_update);

    // add root perms for this user
    PermLib::grant_user_root_perms('mechanism_families', $id);
    
    // add transaction to history
    History::add('mechanism_families', $id, array('action' => 'add'));

    return $id;    
  }
  
  
  /** 
  * Add a new mechanism 
  * @param string Mech family
  * @param string Mech nickname
  * @param string Protocol id
  * @param string Rule family
  * @return id 
  */ 
  static function add_mech($family, $nickname, $protocol=NULL, $rfam=NULL)
  {
    // get the mech family
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $family))
      return ErrorLib::set_error("That mechanism family was not found");

    // verify nickname
    if(!$nickname = trim($nickname))
      return ErrorLib::set_error("A valid nickname is required");
    
    // check for nickname uniqueness within the family
    if(MongoLib::check('mechanisms', array('family' => $mfam['_id'], 'nickname' => $nickname))) 
      return ErrorLib::set_error("A mechanism in that family already has that nickname");
    
    // if MF has a PF, make sure there's a P
    // TODO: not every fixed mfam needs new things for each mech -- certs don't. but protocols do, and others might. find a way to generalize the below protocol block, and add the guard back in.
    // if($mfam['thing'] && !$protocol)
    //   return ErrorLib::set_error("That mechanism family requires a thing");
    
    if($protocol) {
      // get protocol
      if(!$protocol = MongoLib::findOne_editable('protocols', $protocol))
        return ErrorLib::set_error("Invalid protocol");
      
      // check protocol's mech situation
      if($protocol['mech'])
        return ErrorLib::set_error("That protocol already has a mechanism");
      
      // ensure P in MF's PF
      // TODO: take out the second condition after data migration
      if($protocol['family'] != $mfam['thing']['$id'] &&
         $protocol['family'] != $mfam['pfam'])
        return ErrorLib::set_error("That protocol is not in the mechanism family's protocol family");
        
      $mech['protocol'] = $protocol['_id'];
      $mech['pfam'] = $protocol['family'];
    }
    
    // all clear!
    
    // add the mech
    $mech['nickname'] = $nickname;
    $mech['family'] = $mfam['_id'];
    $mech['square'] = 'draft';
    $id = MongoLib::insert('mechanisms', $mech);
    
    // add root perms for this user
    PermLib::grant_user_root_perms('mechanisms', $id);
    
    // add the rule family, if needed
    if(!$rfam)
      $rfam = Rule::add_family($mfam['_id'], $mfam['name'], $mfam['shortname'] . ':RR');
    
    // add a new rule and assign as root
    $joiner = array('keyword' => 'sum');
    $rule_id = Rule::add($rfam, $id, array(), $joiner, array());
    MechLib::assign_root($id, $rule_id);
    
    // add transaction to history
    History::add('mechanisms', $id, array('action' => 'add'));

    return $id;    
  }
  
  /** 
  * Add a rule to an rfam in a mech  
  * @param string Rule family id
  * @param string Mechanism id
  * @return string 
  */ 
  static function add_rule($rfam_id, $mech_id, $score_type)
  {
    // add the rule
    $rule['family'] = $rfam_id;
    $rule['mech'] = $mech_id;
    $rule['score_type'] = $score_type; // a cache of the rfam's st -- makes processing a lot simpler
    $rule['joiner']['keyword'] = 'sum';
    $rule_id = MongoLib::insert('rules', $rule);

    // assign to mech
    $rfam_id_string = (string) $rfam_id;
    $m_rl_update["rule_list.$rfam_id_string"] = $rule_id;
    MongoLib::set('mechanisms', $mech_id, $m_rl_update);

    // add transaction to history
    History::add('rules', $rule_id, array('action' => 'add'));
    
    return $rule_id;
  }
  
  /** 
  * Assign a root rule to a mechanism 
  * @param string Mechanism id
  * @param string Rule id
  * @return string 
  */ 
  static function assign_root($id, $rule)
  {    
    // get mechanism
    if(!$mech = MongoLib::findOne('mechanisms', $id))
      return ErrorLib::set_error("No such mechanism exists");
    
    // ensure mech isn't published
    if($mech['square'] == 'published')
      return ErrorLib::set_error("That mechanism has already been published and can not be edited");
    
    // get rule
    if(!$rule = MongoLib::findOne('rules', $rule))
      return ErrorLib::set_error("No such rule exists");
    
    // all clear!
    
    // set mechanism root to rule
    $update['root_rule'] = $rule['_id'];
    MongoLib::set('mechanisms', $id, $update);
    
    // set the new rule_list for the mechanism
    MechLib::set_rule_list_and_score_types($mech['_id']);
    
    // add transaction to history
    History::add('mechanisms', $id, array('action' => 'assign_root', 'was' => $mech['root_rule']));

    return $id;
  }
  
  
  
  /** 
  * Sandbox a mechanism 
  * @param string A mechanism id
  * @return string 
  */ 
  static function sandbox($id)
  {
    // get the mechanism
    if(!$mech = MongoLib::findOne('mechanisms', $id))
      return ErrorLib::set_error("That mechanism was not found");
    
    // get the mfam
    if(!$mfam = MongoLib::findOne('mechanism_families', $mech['family']))
      return ErrorLib::set_error("That mechanism family was not found");
    
    // all clear!
    
    // add transaction to history
    History::add('mechanisms', $id, array('action' => 'sandbox'));
    
    // if the mfam is in draft, move it to sandbox
    if($mfam['square'] == 'draft') {
      $mfam_update['square'] = 'sandbox';
      MongoLib::set('mechanism_families', $mfam['_id'], $mfam_update);

      // and grant open visibility
      PermLib::grant_members_view_perms('mechanism_families', $mfam['_id']);
    }
    
    PermLib::grant_members_view_perms('mechanisms', $mech['_id']);

    // move mechanism's square and set pubdate
    $update['square'] = 'sandbox';
    $update['pubdate'] = $pubdate ? new MongoDate($timestamp) : '';

    return MongoLib::set('mechanisms', $mech['_id'], $update);
  }
  
  /** 
  * Publish a mechanism with no safety checks 
  * @param string A mechanism id
  * @return string 
  */ 
  static function publish($id)
  {
    // get mechanism
    if(!$mech = MongoLib::findOne('mechanisms', $id))
      return ErrorLib::set_error("No such mechanism exists");
    
    // get the mech family
    if(!$mfam = MongoLib::findOne('mechanism_families', $mech['family']))
      return ErrorLib::set_error("The mechanism family was not found");
    
    // all clear!
    
    // add transaction to history
    History::add('mechanisms', $id, array('action' => 'publish'));
    
    // if the mfam is in sandbox, move it to active
    if($mfam['square'] == 'sandbox') {
      $mfam_update['square'] = 'active';
      MongoLib::set('mechanism_families', $mfam['_id'], $mfam_update);
    } 

    // get the currently published mech (we'll use this later)
    $opm_filter['square'] = 'published';
    $opm_filter['family'] = $mfam['_id'];
    $old_pub_mech = MongoLib::findOne('mechanisms', $opm_filter);

    // deprecate the currently published mechanism for this family
    if($old_pub_mech) {
      $dep_update['square'] = 'deprecated';
      MongoLib::set('mechanisms', $old_pub_mech['_id'], $dep_update, true);      
    }

    // publish mech's RFs 
    // THINK: this re-publishes any deprecated RFs also, but we're not deprecating RFs independent of their MF
    $pub_update['square'] = 'published';
    $rf_ids = MongoLib::fix_ids(array_keys($mech['rule_list']));
    $rf_filter['_id'] = array('$in' => $rf_ids);
    MongoLib::set('rule_families', $rf_filter, $pub_update, true);
    
    // publish RFs' STs
    $st_filter['thing.$id'] = array('$in' => $rf_ids);
    MongoLib::set('score_types', $st_filter, $pub_update, true);
        
    // publish MF ST
    $mf_st_filter['thing.$id'] = $mech['family'];
    MongoLib::set('score_types', $mf_st_filter, $pub_update);
    
    
    // TODO: if we prevent old mechs from referencing anything newer while being edited, that could solve this problem. i.e., if you want to update an old mech to use a newer mech's scores, you have to actually create a brand new MF. So your view of available scores is date-based -- this is pretty simple to explain to users also. kind of. maybe.
    
    
    // the tricky bit:
    
    // if this is a free mfam
    if($mfam['type'] == 'free') {

      // and the new M's STs are different than the previously published STs
      if($mech['score_types'] != $old_pub_mech['score_types']) {

        // then calculate the new downstream STs for this mech
        $downstream = MechLib::calculate_downstream($mech);

        // if the new downstream is different
        if($downstream != $mfam['downstream']) {

          // update each upstream MF's downstream
          MechLib::update_upstream_downstreams($mfam, $downstream);
          
          // update MF's pmech and smrf proc_list
          MechLib::update_proc_lists($mfam, $downstream);
          
          // and push new downstream into the MF
          $d_update['downstream'] = $downstream;
          MongoLib::set('mechanism_families', $mfam['_id'], $d_update);
        }
      }
    }
    
    
    
    // move mechanism's square, set pubdate, and handle versioning
    $update['square'] = 'published';
    $update['pubdate'] = new MongoDate(time());
    $update['version'] = $old_pub_mech ? $old_pub_mech['version'] + 1 : 1; // NOTE: little bit of a race condition here, but the system enforces a two-week lag between publishings
    
    return MongoLib::set('mechanisms', $mech['_id'], $update);
  }
  


  /** 
  * Get the timestamp for a pubdate and ensure validity 
  * @param string A mechanism from the db
  * @param string A proposed pubdate (a palatable datestring)
  * @return int 
  */ 
  static function fix_mech_pubdate($mech, $pubdate)
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
    $pub_filter['pubdate']['$lt'] = new MongoDate($timestamp + $two_weeks); // nothing within two weeks after
    $pub_filter['pubdate']['$gt'] = new MongoDate($timestamp - $two_weeks); // nothing within two weeks before
    $pub_filter['family'] = $mech['family']; // in this MF
    $pub_filter['_id']['$ne'] = $mech['_id']; // not this mech
    if(MongoLib::check('mechanisms', $pub_filter))
      return ErrorLib::set_error("There is already a mechanism scheduled for publication within two weeks of that publication date");
    
    return $timestamp;
  }
  

  /** 
  * Replicate a mechanism
  * @param string A mechanism id
  * @param string The new nickname (must be unique within family)
  * @param string A protocol id, if this is a protocol mech
  * @return string 
  */ 
  static function replicate_mech($id, $nickname, $protocol=NULL)
  {
    // get the mech
    if(!$mech = MongoLib::findOne('mechanisms', $id))
      return ErrorLib::set_error("That mechanism was not found");
    
    // get the rules
    $rquery['mech'] = $mech['_id'];
    $rules = MongoLib::find('rules', $rquery);
    
    // all clear!
    
    // root rule stuff
    $old_root_rule_id = (string) $mech['root_rule'];
    $old_root_rule = $rules[$old_root_rule_id];
    $old_rfam = $old_root_rule['family'];
    
    // insert the new mech
    $new_mech_id = MechLib::add_mech($mech['family'], $nickname, $protocol, $old_rfam);
    $new_mech = MongoLib::findOne('mechanisms', $new_mech_id);
    
    // uh-oh... this happens with bad protocols. so don't use those!
    if(!$new_mech)
      return ErrorLib::set_error("That's a bad protocol there");
    
    // push in 'parent' -- this tracks the originating mech
    $update['parent'] = $mech['_id'];
    MongoLib::set('mechanisms', $new_mech_id, $update);

    // change root rule
    $new_root_rule_id = $new_mech['root_rule'];

    Rule::change_joiner($new_root_rule_id, $old_root_rule['joiner']);
    Rule::change_modifiers($new_root_rule_id, $old_root_rule['modifiers']);
    Rule::change_ham($new_root_rule_id, $old_root_rule['ham']);
    
    // add non-root rules
    foreach($rules as $rule) {
      if($rule['_id'] == $mech['root_rule']) continue;
      $new_rule_id = MechLib::add_rule($rule['family'], $new_mech_id, $rule['score_type']);
      Rule::change_joiner($new_rule_id, $rule['joiner']);
      Rule::change_modifiers($new_rule_id, $rule['modifiers']);
      Rule::change_ham($new_rule_id, $rule['ham']);
    }
    
    return $new_mech_id;
  }
  
  /** 
  * Clone a rule if needed and return the rule id [REQUIRES DB VALUES, NOT IDS!]
  * @param string Rule (from db)
  * @param string Mechanism (from db)
  * @return id 
  */ 
  static function maybe_clone_rule($rule, $mech)
  {
    // default rule id
    $rule_id = $rule['_id'];
    
    // is this the rule's parent mech?
    if($rule['mech'] == $mech['_id']) {
      $rfam_id_string = (string) $rule['family'];
      
      // get other mechs 
      $filter['rule_list'][$rfam_id_string] = $rule['_id']; // that point to this rule 
      $filter['_id']['$ne'] = $mech['_id']; // are not this mech
      $filter['family'] = $mech['family']; // and are in the mech family (in case we index over family)
      $other_mechs = MongoLib::find('mechanisms', $filter);
      
      // if other mechs use this rule
      if($other_mechs) {
        // clone the rule and assign to this mech
        $rule_id = MechLib::clone_rule($rule, $mech);
        
        // re-assign the original rule to one of the other mechs
        $other_mech = reset($other_mechs);
        $rule_update['mech'] = $other_mech['_id'];
        MongoLib::set('rules', $rule['_id'], $rule_update);
      }
    } else {
      // clone the rule and assign to this mech
      $rule_id = MechLib::clone_rule($rule, $mech);
    }
    
    return $rule_id;
  }
  
  /** 
  * Clone a rule into a new mechanism and return id 
  * @param string Rule from the DB
  * @param string Mechanism from the DB
  * @return id 
  */ 
  static function clone_rule($rule, $mech)
  {
    // THINK: right now this isn't doing ANY checking, so make sure you do it before you call this.
    // THINK: if you start requiring the old rule to already be in the mech, you'll end up limiting the use cases for this a bit -- but if you start using those use cases, you'll have to change the NOTE line below.
    
    // clone the rule
    $new_rule = $rule;
    $new_rule['mech'] = $mech['_id'];
    unset($new_rule['_id']);
    $rule_id = MongoLib::insert('rules', $new_rule);
        
    // have mech use the clone
    $rfam_id_string = (string) $rule['family'];
    $m_rl_update["rule_list.$rfam_id_string"] = $rule_id;
    MongoLib::set('mechanisms', $mech['_id'], $m_rl_update);
    
    // refresh the mech's rule list and score types
    // MechLib::set_rule_list_and_score_types($mech['_id']);
    // NOTE: if we're not pushing this into a mech that didn't have the old rule (which we're not atm), then we don't need to call ML::srlast here. which saves some time.
    
    return $rule_id;
  }
  
  
  /** 
  * Get some cool stuff and check a bunch of things
  * @param string Rule id
  * @return array 
  */ 
  static function rule_changing_basics($rule_id)
  {
    // get rule
    if(!$rule = MongoLib::findOne('rules', $rule_id))
      return ErrorLib::set_error("Invalid rule id");
    
    // get rule family
    if(!$rfam = MongoLib::findOne('rule_families', $rule['family']))
      return ErrorLib::set_error("Faulty rule family");
    
    // THINK: there's a reason the two above aren't 'view' checked... maybe.
    
    // get mechanism
    if(!$mech = MongoLib::findOne_editable('mechanisms', $rule['mech'])) 
      return ErrorLib::set_error("Invalid mechanism");
    
    // check mech square
    if($mech['square'] != 'draft')
      return ErrorLib::set_error("Only rules in draft mechanisms can be edited");
    
    // ensure mech is in RF's MF
    if($mech['family'] != $rfam['mech_family'])
      return ErrorLib::set_error("That mechanism is not within the rule family's purview");
    
    // ensure rule is in mech
    $rfam_id_string = (string) $rfam['_id'];
    if($mech['rule_list'][$rfam_id_string] != $rule['_id'])
      return ErrorLib::set_error("That rule does not exist in that mechanism");
    
    return array($rule, $rfam, $mech);
  }
  
  
  //
  // NASTIES
  //
  
  
  /** 
  * build a rule list for the mech in proper execution order 
  * @param string Mechanism id
  * @return array 
  */ 
  static function set_rule_list_and_score_types($mech_id)
  {
    // NOTES:
    // the rule_list is a hash of rfam_id => rule_id
    // this function orders it so they can be run sequentially, but also requires all rules in the mech to be represented in the rule_list before processing begins (or it picks a random rule in the RF, which is really not what you want)
    // score_types contains all ST required by the mech as input, including its own RF STs.
    // downstream contains... essentially the same thing.
    
    // get the mech
    if(!$mech = MongoLib::findOne('mechanisms', $mech_id))
      return ErrorLib::set_error("That mechanism was not found");

    // set up some values
    $score_types = array();
    $ordered_list = array();
    $root_rule = $mech['root_rule'];
    $rule_list = $mech['rule_list'];
    $todo_list[] = $root_rule;
    
    // ensure the root rule is in rule list (mostly for bootstrapping)
    if(!in_array($root_rule, $rule_list))
      $rule_list[$root_rule['family']] = $root_rule;  
      
      
    // TODO:
    // - is family right on 609?
    // - why are tests failing?
    // - add more mech and cert/opfam tests
    
    
    // get the known rules, STs, RFs in the MF
    $db_rules = MongoLib::findIn('rules', $rule_list);
    $rule_families = MongoLib::fix_ids(array_keys($rule_list));
    
    
    if(!$db_score_types = MongoLib::find('score_types', array('thing.$id' => array('$in' => $rule_families))))
      return ErrorLib::set_error("Invalid score type");
      
    if(!$db_rule_families = MongoLib::find('rule_families', array('mech_family' => $mech['family']), array('_id', 'score_type')))
      return ErrorLib::set_error("Invalid rule family");
    
    // push root rule's st into score_types
    $score_types[] = $db_rule_families[(string) $db_rules[(string) $root_rule]['family']]['score_type'];

    while($todo_list) {
      // get the first rule in the todo list
      $rule_id = (string) array_shift($todo_list);
      
      if(!$rule_id) {
        ErrorLib::set_error("Invalid rule in todo list");
        continue;
      }
      
      // put rule in db_rules
      if(!$db_rules[$rule_id])
        $db_rules[$rule_id] = MongoLib::findOne('rules', $rule_id);

      // put rule at the end of the ordered list
      $rf_id = (string) $db_rules[$rule_id]['family'];
      unset($ordered_list[$rf_id]);
      $ordered_list[$rf_id] = $db_rules[$rule_id]['_id'];
      
      // add score types to mech's ST list
      $score_types = array_merge($score_types, (array) $db_rules[$rule_id]['score_types']);
      
      // add subordinate rules to todo_list
      foreach($db_rules[$rule_id]['score_types'] as $st_id) {
        $st_id = (string) $st_id;
        
        // put score_type in db_score_types
        // OPT: probably want to query these en masse instead of individually inside this foreach loop
        if(!$db_score_types[$st_id])
          $db_score_types[$st_id] = MongoLib::findOne('score_types', $st_id);
        
        // ensure it's an RF ST
        if($db_score_types[$st_id]['thing']['$ref'] != 'rule_families')
          continue;
        
        // ensure RF is in our MF
        $rf_id = (string) $db_score_types[$st_id]['thing']['$id'];
        if(!$db_rule_families[$rf_id])
          continue;
        
        // if ST's RF isn't already in our rule list, things get messy
        if(!$subrule_id = $rule_list[$rf_id]) {
          // try finding a rule in this mech
          $subrule = MongoLib::findOne('rules', array('family' => MongoLib::fix_id($rf_id), 'mech' => $mech['_id']), array('family', 'score_types'));
          
          // pick a rule in RF at random
          if(!$subrule)
            $subrule = MongoLib::findOne('rules', array('family' => MongoLib::fix_id($rf_id)), array('family', 'score_types'));
          
          if(!$subrule) {
            ErrorLib::set_error("Subrule not found");
            continue;
          }
          
          // push the new subrule into rule_list and db_rules
          $subrule_id = $subrule['_id'];
          $rule_list[$rf_id] = $subrule_id;
          $db_rules[$subrule_id] = $subrule;
        }
        
        // put rule at the end of the todo list
        if($key = array_search($subrule_id, $todo_list))
          unset($todo_list[$key]);
        $todo_list[] = $subrule_id;
      }
      
      // cycles in our rule graph create an infinite loop. they also repeat state, so we check for that here.
      // $ol_string = md5(serialize($ordered_list));
      // if(in_array($ol_string, $ol_array)) {
      //   ErrorLib::set_error("Repeat state detected in mechanism {$mech['_id']}");
      //   break;
      //   // THINK: maybe we should check the todo_list instead of the ordered_list?
      // }
      
      // record current ordered list
      $ol_array[] = $ol_string;
    }
    
    // any rules in the old rule list from this MF should stick around, as they're most likely new rules that haven't been linked in to the mech's rule tree yet
    foreach($rule_list as $rf_id => $r_id) {
      // if it's already in ol we don't need it
      if($ordered_list[$rf_id])
        continue;
      
      // if R's RF isn't in M's MF, then it's a foreign rule that has been removed
      // THINK: this can't happen, can it?
      if(!$db_rule_families[$rf_id])
        continue;
      
      // add rule to OL 
      // NOTE: we don't add score type to STs because it's not required as it's not in the tree
      $ordered_list[$rf_id] = $r_id;
    }
    
    // update the mech
    $update['score_types'] = array_unique($score_types);
    $update['rule_list'] = array_reverse($ordered_list);
    
    return MongoLib::set('mechanisms', $mech['_id'], $update);
  }


  /** 
  * Calculate an MF's downstream from a child mech
  * @param string A mechanism (from the db!)
  * @return array 
  */ 
  static function calculate_downstream($mech)
  {
    // NOTES:
    // The downstream is an unordered set of STs.
    // If MF1 includes MF2 in its downstream set, then MF1 is said to be upstream of MF2.
    // STs for any MF or SMRF the mechanism touches are added, except STs for upstream MFs.
    
    
    // all clear!
    
    // set vars
    $SMRFIDs = $MFIDs = $RFIDs = $PQIDs = $UPIDs = $PMECHIDs = $PIDS = $PMFIDS = array();

    // get M's STs
    $score_types = MongoLib::findIn('score_types', $mech['score_types'], array('thing'));
    
    // get MF's ST
    $mf_score_type = MongoLib::findOne('score_types', array('thing.$id' => $mech['family']), array('thing'));
    
    // sort relevant score types
    foreach($score_types as $st) {
      // if it's a SMRF, smurf it
      if($st['thing'] == 'protocol_families') {
        $SMRFIDs[] = $st['thing']['$id'];
        $downstream[] = $st['_id'];
      }
      
      // if it's an MF, muff it
      if($st['thing'] == 'mechanism_families') {
        $MFIDs[] = $st['thing']['$id'];
        $downstream[] = $st['_id'];
      }
      
      // if it's an RF, rule it
      if($st['thing']['$ref'] == 'rule_families')
        $RFIDs[] = $st['thing']['$id'];
      
      // if it's a PQ, pique it
      if($st['thing']['$ref'] == 'protoquestions')
        $PQIDs[] = $st['thing']['$id'];
    }
    
    // calculate downstream
    
    // find all the MFs for the RFs my rules eat
    $rfs = MongoLib::find('rule_families', $RFIDs, array('mech_family'));
    foreach($rfs as $rf)
      $MFIDs[] = $rf['mech_family'];

    // get the downstreams for all directly descendent MFs that don't have my MF's ST in their downstream
    $direct_descendent_filter['_id']['$in'] = array_unique($MFIDs);
    $direct_descendent_filter['downstream']['$nin'] = array($mf_score_type['_id']);
    $mfams = MongoLib::find('mechanism_families', $direct_descendent_filter, array('downstream', 'pfam'));
    foreach($mfams as $mfam)
      $downstream += $mfam['downstream'];
    
    // find Ps for PQs
    $pq_filter['q_list']['pq']['$in'] = $PQIDs;
    $pq_filter['square'] = 'published';
    $protocols = MongoLib::find('protocols', $pq_filter, array('_id'));
    foreach($protocols as $protocol)
      $PIDS[] = $pmech['_id'];
    
    // find MFs for Ps
    $p_filter['protocol']['$in'] = $PIDS;
    $pmechs = MongoLib::find('mechanisms', $p_filter, array('family'));
    foreach($pmechs as $pmech)
      $PMFIDS[] = $pmech['family'];
    
    // find STs for MFs for Ps
    $pmf_st_filter['thing.$id']['$in'] = $PMFIDS;
    $pmsts = MongoLib::find('score_types', $pmf_st_filter, array('_id'));
    foreach($pmsts as $pants)
      $downstream[] = $pants['_id'];
    
    // uniquify the downstream
    $downstream = array_unique($downstream);
    
    // remove our own ST
    if($index = array_search($mf_score_type, $downstream))
      unset($downstream[$index]);
    
    return $downstream;
  }
  

  /** 
  * Updates all published upstream MFs
  * @param string MF (from the db!)
  * @param string New downstream
  * @return boolean 
  */ 
  static function update_upstream_downstreams($mfam, $downstream)
  {
    // all clear!
    
    // get old and new downstreams
    $old_D = $mfam['downstream'];
    $new_D = $downstream;
    
    // compare downstreams
    $plus_sts = array_diff($new_D, $old_D);
    $minus_sts = array_diff($old_D, $new_D);
    
    // set my score type
    $my_st = $mfam['score_type'];
    
    
    // THINK: in theory, we may have removed the rule an upstream mech pointed to from our newly published mech, invalidating the link between them. in practice that will be rare (and a notice should be tripped to alert the UM owner to fix their mech), and in general all upstream mechs will continue to have this mech in their downstreams, so we don't need to adjust that...
    
    
    
    // add new STs to every upstream MF (UMSTs are removed in calculate_downstream, so this won't cause cycles)
    if($plus_sts) {
      $plus_filter['downstream'] = $my_st;
      $plus_update['$addToSet']['downstream']['$each'] = $plus_sts;
      MongoLib::set('mechanisms', $plus_filter, $plus_update, true);      
    }
    
    // remove dead STs from appropriate upstream published MFs (this is pretty brutal...)
    foreach($minus_sts as $st) {
      // get MFs upstream of ST's MF that DON'T have our MF downstream
      $mf_ups_filter['downstream']['$in'] = array($st['_id']);
      $mf_ups_filter['downstream']['$nin'] = array($my_st);
      $mf_ups = MongoLib::find('mechanism_families', $mf_ups_filter, array('score_type'));
      foreach($mf_ups as $mf_up)
        $up_st_ids[] = $mf_up['score_type'];
      
      // remove the ST from all MFs where my mech is on every contributing path
      $mf_minus_filter['downstream']['$nin'] = $up_st_ids;
      $mf_minus_filter['downstream']['$in'] = array($my_st);
      $mf_minus_update['$pull']['downstream'] = $st['_id'];
      MongoLib::set('mechanism_families', $mf_minus_filter, $mf_minus_update, true);
    }
  }

  
  /** 
  * Updates all published upstream MFs
  * @param string MF (from the db!)
  * @param string New downstream
  * @return boolean 
  */ 
  static function update_proc_lists($mfam, $downstream)
  {
    // all clear!
    
    // get old and new downstreams
    $old_D = $mfam['downstream'];
    $new_D = $downstream;
    
    // compare downstreams
    $plus_sts = array_diff($new_D, $old_D);
    $minus_sts = array_diff($old_D, $new_D);
    $diff_sts = $plus_sts + $minus_sts;
    
    // get changed score_types
    $score_types = MongoLib::findIn('score_types', $diff_sts, array('thing'));
    
    // sort them into SMRFs and MFs
    foreach($score_types as $st) {
      if($st['thing']['$ref'] == 'protocol_families')
        $update_list[] = $st['_id'];
      if($st['thing']['$ref'] == 'mechanism_families')
        $MFIDS[] = $st['thing']['$id'];
    }
    
    // get MFs that are pmechs
    if($MFIDS) {
      $mf_filter['_id']['$in'] = $MFIDS;
      $mf_filter['pfam']['$exists'] = true;
      $pmechs = MongoLib::find('mechanism_families', $mf_filter, array('score_type'));
      foreach($pmechs as $pmech)
        $update_list[] = $pmech['score_type'];
    }
    
    foreach($update_list as $st_id)
      self::update_proc_list($st_id);
  }
  
  
  /** 
  * Update the proc_list for a base-level ST (a SMRF or pmech) 
  * @param string An ST id
  * @return boolean 
  */ 
  static function update_proc_list($st_id)
  {
    // get everything upstream of this ST
    $up_filter['downstream'] = $st_id;
    $mfams = MongoLib::find('mechanism_families', $up_filter, array('downstream'));
    
    // get the MFs' ST ids
    foreach($mfams as $mfam)
      $STIDS[(string) $mfam['score_type']] = $mfam['score_type'];
    
    // put the MFs in the proper order
    while($mfams) {
      $least_mfams = array();
      
      // find MFs that don't have remaining MFs in their downstream
      // this finds at least one on every scan because the downstreams form a poset/DAG (and hence have a least remaining element)
      foreach($mfams as $mfam)
        if(!array_intersect($mfam['downstream'], $STIDS)) 
          $least_mfams[] = $mfam;
      
      // remove the good MFs from STIDS and $mfams and add to proc_list
      foreach($least_mfams as $mfam) {
        unset($STIDS[(string) $mfam['score_type']]);
        unset($mfams[(string) $mfam['_id']]);
        $proc_list[] = $mfam['score_type'];
      }
    }
    
    // update the proc list for our ST
    $proc_list['_id'] = $st_id;
    $proc_list['value'] = $proc_list;
    return MongoLib::upsert('proc_lists', $st_id, $proc_list);
  }
  

  
  //
  // VSCORES
  //
  
  
  // a score is a hash with a value key and a fail key (value is numeric, fail is boolean)
  // a DB score is a value, fail key, thing, and score type
  // vscores is an array of scores with string keys, called handles
  // a handle is a string containing an ST id, possibly followed by a colon and extra data
  // a PQ's vscore handle is the PQ's ST id, followed by string data from the question's 'handle' attribute, followed by extra data from the PQ's Qtype.
  
  
  /** 
  * Takes some scores from the DB and changes them into vscores
  * Note that this removes the *thing*, so you only want to pass scores from one *thing* or they'll get mushed together. 
  * @param string Array of DB scores
  * @return string 
  */ 
  static function scores_to_vscores($scores)
  {
    foreach($scores as $score) 
      $vscores[(string) $score['score_type']] = array('value' => $score['value'], 'fail' => $score['fail']);
    
    return $vscores;
  }
  
  /** 
  * Filter some vscores
  * @param string Array of vscores
  * @param string Array filter strings
  * @return string 
  */ 
  static function filter_vscores($vscores, $filter)
  {
    foreach($vscores as $key => $score)
      foreach($filter as $handle)
        if(strpos($key, (string) $handle) !== false)
          $scores[$key] = $score;
    
    return $scores;
  }
  
  
  /** 
  * Return the vscores from a question 
  * @param string A question or question id
  * @return number
  */ 
  static function get_q_vscores($question)
  {
    // get question
    if(!is_array($question) || is_a($question, 'MongoId'))
      if(!$question = MongoLib::findOne('questions', $question))
        return ErrorLib::set_error("Invalid question id");

    // get pq
    // OPT: maybe optionally pass in the pq as well?
    if(!$pq = MongoLib::findOne('protoquestions', $question['pq']))
      return ErrorLib::set_error("Invalid protoquestion");
    
    // get valid answers
    // OPT: maybe optionally pass in the answers too?
    $answers = MongoLib::find('answers', array('question' => $question['_id'], 'invalid' => array('$ne' => true)));
    if(!$answers)
      return ErrorLib::set_warning("That question has no valid answers");
    
    // all clear!
    
    // QT MM
    $data['pq'] = $pq;
    $data['question'] = $question;
    $data['answers'] = $answers;
    $raw_vscores = MixMaster::make_the_call('question_types', 'get_vscores', $pq['type'], $data);

    // the QT can decide it's an invalid request and throw an error
    if($raw_vscores === false) 
      return false; // THINK: consider propagating this error upwards further (through get_test_vscores, for example)

    // numeric values get translated into a vscore array
    if(is_numeric($raw_vscores))
      $raw_vscores = array(array('value' => $raw_vscores));
    
    // empty vscore array?
    if(!$raw_vscores)
      return array();

    // add extra handle data to the vscores
    $vscores = array();
    $handle = (string) $pq['score_type'];
    $handle .= $question['handle'] ? ':' . $question['handle'] : '';
    foreach($raw_vscores as $key => $vscore) {
      $my_key = $handle . ($key ? ':' . $key : '');
      $vscores[$my_key] = $vscore;
    }

    return $vscores;    
  }
  
  
  /** 
  * Get all the vscores for a test 
  * @param string Test id
  * @return array
  */ 
  static function get_test_vscores($test_id)
  {
    // get all the questions
    if(!$questions = MongoLib::find('questions', array('test' => MongoLib::fix_id($test_id))))
      return ErrorLib::set_error("No questions exist for that test");
    
    // all clear!
    
    // get all their vscores
    $vscores = array();
    foreach($questions as $question) {
      if($score = MechLib::get_q_vscores($question))
        $vscores = array_merge($vscores, $score); // TODO: check that this merges keys correctly
    }
    
    return $vscores;
  }
  
  
  /** 
  * Push a test's vscores into the system; defaults to PQ scores from get_test_vscores 
  * @param string Array of virtual scores
  * @return array 
  */ 
  static function push_test_vscores($test_id, $vscores='')
  {
    // get the test
    if(!$test = MongoLib::findOne('tests', $test_id, array('thing')))
      return ErrorLib::set_error("No such test found");

    // get the vscores
    if(!$vscores)
      $vscores = MechLib::get_test_vscores($test_id);

    // all clear!
    
    // sort out vscores by their fundamental
    foreach($vscores as $handle => $vscore) {
      if(!strpos($handle, ':')) {
        $scores[$handle] = $vscore;
      } else {
        list($fhandle) = explode(':', $handle, 2);
        $scores[$fhandle]['vscores'][$handle] = $vscore;
        // THINK: if the vscore set has an overtone but no matching fundamental, this will get weird
      }
    }
        
    // push scores and their vscores into the system
    foreach($scores as $handle => $score)
      MechLib::push_score_without_checks($handle, $test['thing'], $score['value'], $score['fail'], $score['vscores']); 
    
    return $vscores;
  }
  
  
  /** 
  * Push a score into mongo with no safety checks 
  * @param string Score type id
  * @param string Thing (requires a real mongo thing!!)
  * @param string Value (numeric)
  * @param string Fail (boolean)
  * @param string Vscores, for things that have 'em
  * @return string 
  */ 
  static function push_score_without_checks($st_id, $thing, $value, $fail=false, $vscores=array())
  {
    $filter['score_type'] = MongoLib::fix_id($st_id);
    $filter['thing'] = $thing;
    
    $update['score_type'] = MongoLib::fix_id($st_id);
    $update['thing'] = $thing;
    $update['value'] = $value;
    $update['fail'] = $fail ? true : false;
    
    $update['vscores'] = $vscores; // erm...
    
    return MongoLib::upsert('scores', $filter, $update);
  }
  
  
  
  //
  // ACTIVATE MECHS AND RULES
  //
  
  
  
  /** 
  * Run a mechanism and return its score 
  * @param string Mechanism id
  * @param string A set of vscores (you're responsible for providing the appropriate scores!)
  * @param string If this is a thing, push each RF score and the MF score into the db for it
  * @param string Accepts mfam or scores. Defaults to 'mfam' which returns the mech family score only. Returns a hash of stid->score for 'scores'.
  * @return array
  */ 
  static function run_mech($mech_id, $vscores, $push_thing=false, $return_type=false)
  {
    // get mech
    if(!$mech = MongoLib::findOne('mechanisms', $mech_id))
      return ErrorLib::set_error("No matching mechanism found");

    // get rules
    $rules = MongoLib::findIn('rules', $mech['rule_list']);

    // get MF's ST
    $mfst_filter['thing.$id'] = $mech['family'];
    $mfst = MongoLib::findOne('score_types', $mfst_filter, '_id');
    $mfstid = (string) $mfst['_id'];
    $good_stids[] = $mfstid;
    
    // all clear!

    // TODO: if we're getting PQ vscores from the db (like for a freemech that eats a PQ), we'll need to pull the vscores for that PQ out of their slot in the score. [move this somewhere else]
    
    // sort the rules and add STs
    foreach($mech['rule_list'] as $rule_id) {
      $rule = $rules[(string) $rule_id];
      if(!$rule) continue;
      $good_stids[] = (string) $rule['score_type'];
      $ordered_rules[] = $rule;
    }
    
    // THINK: prior to this change the MF score was sometimes missing. I'm not sure why, or if that was intentional...
    $vscores = MechLib::run_virtual_mech($ordered_rules, $vscores, $mfstid);

    // push mech and rule scores
    if($push_thing)
      foreach($vscores as $handle => $score)
        if(in_array($handle, $good_stids))
          MechLib::push_score_without_checks($handle, $push_thing, $score['value'], $score['fail'], $score['vscores']);
    
    if($return_type == 'scores') return $vscores;
    return $vscores[$mfstid];
  }
  
  
  /** 
  * Run a mech without touching MongoDB  
  * @return string 
  */ 
  static function run_virtual_mech($rules, $vscores, $mfstid=NULL)
  {
    // proc rules in the order dictated by rule_list
    foreach($rules as $rule) {
      // THINK: we're getting back a single score from the rule. We might want to upgrade that to allow vscores at some point...
      
      if(!$rule || !$rule['joiner'])
        continue;
      
      $scores = MechLib::filter_vscores($vscores, $rule['ham']);
      $score = self::run_virtual_rule($scores, $rule['joiner'], $rule['modifiers']);
      $vscores[(string) $rule['score_type']] = $score;
    }
    
    // add MF's ST (the last rule run is the root rule)
    if($mfstid)
      $vscores[(string) $mfstid] = $score;

    return $vscores;
  }
  
  /** 
  * Run a rule without touching MongoDB 
  * @param string Input scores
  * @param string Joiner data
  * @param string Modifiers data
  * @return array 
  */ 
  static function run_virtual_rule($scores, $joiner, $modifiers=array())
  {
    // prepare for failure
    $new_score = array('value' => 0);
    
    // THINK: we removed score_count because we have vscores now, and fail_on_null is gone as a consequence. Come up with a different way of differentiating null scores from zero scores.
    // or maybe we don't need it... it could be that null and zero are really the same, but zero+fail is different.
    // NOTE: don't fail due to lack of ham or vscores or joiner, because there might be a fixed modifier
    
    // run the joiner (defaults to sum)
    if($joiner['params']) 
      $joiner_params = $joiner['params'];
    else 
      $joiner_params = array();
    
    // run joiner over score values
    $params = array('scores' => $scores, 'params' => $joiner_params);
    $new_score = MixMaster::make_the_call('joiners', 'activate', $joiner['keyword'], $params);    
    
    // need to invoke the input failings here, after the joiner (which always returns unfailing scores)
    foreach($scores as $score)
      if($score['fail']) 
        $new_score['fail'] = true; // NOTE: always fail on input score failures -- change per rule with failure_threshold mod if needed
          
          
    // run the modifiers
    if($modifiers) {
      foreach($modifiers as $mod) {
        if(!$mod['keyword'])
          continue;

        // set up modifier params
        if($mod['params'])
          $modifier_params = $mod['params'];
        else
          $modifier_params = array();

        // run modifier on new_score
        $params = array('new_score' => $new_score, 'scores' => $scores, 'params' => $modifier_params);
        $new_score = MixMaster::make_the_call('modifiers', 'activate', $mod['keyword'], $params);
      }
    }
    
    return $new_score;
  }
  
  
  
  
  
  //
  // UNUSED STUFF
  //
  
  
  
  /** 
  * Set the maximum for a rule 
  * @param string Rule id
  * @return string 
  */ 
  // UNUSED
  static function set_max($rule_id)
  {
    if(!$db_rule = MongoLib::findOne('rules', $rule_id))
      return ErrorLib::set_error("No such rule found");
    
    $scores = array();
    $score_types = MongoLib::findIn('score_types', $db_rule['score_types']);
    
    // get the downrule maximums
    foreach($score_types as $stype) {
      $value = $stype['maximum'] ? floatval($stype['maximum']) : 0;
      $scores[] = array('value' => $value);
    }
    
    // run the rule
    // TODO: fix this, it uses the old run_rule
    $new_score = self::run_rule($db_rule, $scores);
    $max = floatval($new_score['value']);
    
    // update the rule's score type
    MongoLib::set('score_types', $db_rule['score_type'], array('maximum' => $max));
    
    return true;
  }
  
  
}

// EOT