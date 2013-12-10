<?php

/**
 * Scoring mechanisms fulfill qualification metric criteria
 *
 * @package bonsai
 * @author dann toliver
 * @version 1.0
 */

class Mechanism
{
  
  /** 
  * Get mechanisms 
  * @param string Mechanism ids
  * @param string Mech square (draft, sandbox, published, or deprecated)
  * @param string Mech family
  * @param string Score type id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find($by_ids=NULL, $by_square=NULL, $by_family=NULL, $by_score_type=NULL, $options=NULL)
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));

    if(isset($by_square))
      $query['square'] = $by_square;
    
    if(isset($by_family))
      $query['family'] = MongoLib::fix_id($by_family);
      
    if(isset($by_score_type))
      $query = array('rule_list' => $by_score_type);
    
    return MongoLib::find_with_perms('mechanisms', $query, $options);
  }
  
  
  //
  // MECH FAMILIES
  //
  
  
  /** 
  * Get mechanism families 
  * @param string Mechanism family ids
  * @param string A name (supports regex)
  * @param string A thing is a collection and an id, like (:companies :252)
  * @param string MF square (draft, sandbox, active, retired)
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find_families($by_ids=NULL, $by_name=NULL, $by_thing=NULL, $by_square=NULL, $options=NULL)
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));

    if(isset($by_name))
      $query['name'] = new MongoRegex("/$by_name/i");

    if(isset($by_thing)) {
      if(!$thing = MongoLib::resolveDBRef($by_thing))
        return ErrorLib::set_error("Invalid thing");

      $query['thing'] = $thing;
    }

    if(isset($by_square))
      $query['square'] = $by_square;
    
    return MongoLib::find_with_perms('mechanism_families', $query, $options);
  }
  
  
  /** 
  * Create a new scoring mechanism family
  * @param string Mechanism surname (must be unique systemwide)
  * @param string Short name (truncated at 20 characters)
  * @return string 
  * @key __member
  */ 
  static function add_family($name, $shortname=NULL)
  {
    // NOTE: in MechLib because we need to force protocol in other contexts
    return MechLib::add_family($name, $shortname);
  }
  
  
  /** 
  * A mechanism family's name can only be changed in the draft state -- once sandboxed, it is permanently set
  * @param string Mechanism family id
  * @param string New family name
  * @param string Short name (truncated at 20 characters)
  * @return id 
  * @key __member
  */ 
  static function change_name($family, $name, $shortname)
  {
    // get mechanism family
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $family))
      return ErrorLib::set_error("That mechanism family was not found");

    // check mfam.square
    if($mfam['square'] != 'draft')
      return ErrorLib::set_error("That mechanism family has been published and can not be edited");

    // check for name uniqueness 
    if(MongoLib::check('mechanism_families', array('name' => $name))) 
      return ErrorLib::set_error("That mechanism family name is taken");

    // check shortname
    $shortname = substr($shortname, 0, 20);
    if(!$shortname || !is_string($shortname))
      return ErrorLib::set_error("A valid shortname is required");

    // THINK: maybe prevent pmech's name from changing unless the protocol is changing...?

    // all clear!

    // add transaction to history
    History::add('mechanism_families', $mfam['_id'], array('action' => 'change_name', 'was' => $mfam['name']));

    // update score type
    $this_ref = array('mechanism_families', $mfam['_id']);
    $filter['thing'] = $this_ref;
    $st_update['shortname'] = $shortname;
    MongoLib::set('score_types', $filter, $st_update);
    
    // update the mechanism family
    $update['name'] = $name;
    return MongoLib::set('mechanism_families', $mfam['_id'], $update);
  }
  
  
  /** 
  * Retire a mechanism family, permanently 
  * @param string Mechanism family
  * @return boolean 
  * @key __member
  */ 
  static function retire_family($id)
  {
    // get mechanism family
    if(!$mfam = MongoLib::findOne_rootable('mechanism_families', $id))
      return ErrorLib::set_error("That mechanism family was not found");

    // check mfam.square
    if($mfam['square'] != 'active')
      return ErrorLib::set_error("Only active mechanism families can be retired");

    // all clear!

    // add transaction to history
    History::add('mechanism_families', $id, array('action' => 'retire'));
    
    // deprecate all mechanisms for this family
    $filter['family'] = $mfam['_id'];
    $dep_update['square'] = 'deprecated';
    MongoLib::set('mechanisms', $filter, $dep_update, true);
    
    // deprecate RFs 
    $rf_ids = MongoLib::fix_ids(array_keys($mech['rule_list']));
    $filter['_id'] = array('$in' => $rf_ids);
    MongoLib::set('rule_families', $filter, $dep_update, true);
    
    // deprecated their STs
    $query['thing.$id'] = array('$in' => $rf_ids);
    MongoLib::set('score_types', $query, $dep_update, true);

    // deprecate MF ST
    $mf_st_filter['thing.$id'] = $mfam['_id'];
    MongoLib::set('score_types', $mf_st_filter, $dep_update);

    // fix upstream MFs, removing our current downstream
    MechLib::update_upstream_downstreams($mfam, array());
    
    // remove ourselves from all upstream downstreams
    $mf_minus_filter['downstream']['$in'] = array($mfam['score_type']);
    $mf_minus_update['$pull']['downstream'] = $mfam['score_type'];
    MongoLib::set('mechanism_families', $mf_minus_filter, $mf_minus_update, true);

    // update pmech and smrf proc lists
    MechLib::update_proc_lists($mfam, array());
    
    // update the mechanism family
    $update['square'] = 'retired';
    $update['downstream'] = array(); // remove the downstream
    
    return MongoLib::set('mechanism_families', $mfam['_id'], $update);
  }
  
  
  /** 
  * Destroy a mech family (only for testing purposes!)
  * @param string 
  * @return boolean 
  */ 
  static function destroy_family($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // get MF
    if(!$mfam = MongoLib::findOne('mechanism_families', $id))
      return ErrorLib::set_error("No such mechanism exists");
    
    // all clear!
    
    // add transaction to history
    History::add('mechanism_families', $id, array('action' => 'destroy', 'was' => $mech));

    // get all the RFs
    $rfams = MongoLib::find('rule_families', array('mech_family' => $mfam['_id']));

    // destroy all the RFs
    foreach($rfams as $rfam)
      Rule::destroy_family($rfam['_id']);

    // get all the mechs
    $mechs = MongoLib::find('mechanisms', array('family' => $mfam['_id']));

    // destroy all mechs
    foreach($mechs as $mech) {
      History::add('mechanisms', $mech['_id'], array('action' => 'destroy', 'was' => $mech));
      MongoLib::removeOne('mechanisms', $mech['_id']);
    }
    
    // destroy MF's ST
    Score::destroy_type($mfam['score_type']);
    
    // destroy mech family
    return MongoLib::removeOne('mechanism_families', $mfam['_id']);
  }
  
  
  
  //
  // REGULAR MECHS
  //
  

  
  /** 
  * Create a new scoring mechanism 
  * @param string Mechanism family
  * @param string A nickname for internal use
  * @return string 
  * @key __member
  */ 
  static function add($family, $nickname)
  {
    // NOTE: in MechLib because we need to force protocol in other contexts
    return MechLib::add_mech($family, $nickname);
  }

  
  /** 
  * Replicate a mech into another mech in the same family
  * Note to future self: this is named 'replicate' because it can't be named 'clone'.
  * @param string Mechanism id
  * @param string A nickname for internal use
  * @return string 
  * @key __member
  */ 
  static function replicate($id, $nickname)
  {
    // get the mech
    if(!$mech = MongoLib::findOne('mechanisms', $id))
      return ErrorLib::set_error("That mechanism was not found");
    
    // get the mech family
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $mech['family']))
      return ErrorLib::set_error("That mechanism family was not found");
    
    // check for nickname uniqueness within the family
    if(MongoLib::check('mechanisms', array('family' => $mfam['_id'], 'nickname' => $nickname))) 
      return ErrorLib::set_error("A mechanism in that family already has that nickname");
    
    // all clear!
    
    // add transaction to history
    History::add('mechanisms', $id, array('action' => 'replicate'));
    
    return MechLib::replicate_mech($id, $nickname, $protocol);
  }
  
    
  /** 
  * Push a mechanism into the sandbox
  * @param string Mech id
  * @param string Publication date (leave blank to sandbox a mech without scheduling it for publication)
  * @return boolean 
  * @key __member
  */ 
  static function sandbox($id, $pubdate=NULL)
  {
    // get the mechanism
    if(!$mech = MongoLib::findOne_editable('mechanisms', $id))
      return ErrorLib::set_error("That mechanism was not found");
    
    // get the mfam
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $mech['family']))
      return ErrorLib::set_error("That mechanism family was not found");
    
    // check mech square
    if($mech['square'] != 'draft')
      return ErrorLib::set_error("Only draft mechanisms can be sandboxed");
    
    // fix the pubdate and make sure there's nothing else scheduled around then
    if($pubdate) 
      if(!$timestamp = MechLib::fix_mech_pubdate($mech, $pubdate))
        return false;
    
    // all clear!
    
    return MechLib::sandbox($id);
  }
  
  
  /** 
  * Set the publication date of a mechanism in the sandbox 
  * @param string Mechanism id
  * @param string Publication date (must be at least two weeks in the future; can't be within two weeks of any other scheduled mechanism in this family.)
  * @return string 
  * @key __member
  */ 
  static function change_pubdate($id, $pubdate)
  {
    // get the mechanism
    if(!$mech = MongoLib::findOne_editable('mechanisms', $id))
      return ErrorLib::set_error("That mechanism was not found");
    
    // get the mfam
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $mech['family']))
      return ErrorLib::set_error("That mechanism family was not found");
      
    // check mech square
    if($mech['square'] != 'sandbox')
      return ErrorLib::set_error("Only sandboxed mechanisms can have their publication date changed");
    
    // fix the pubdate and make sure there's nothing else scheduled around then
    if($pubdate)
      if(!$timestamp = MechLib::fix_mech_pubdate($mech, $pubdate))
        return false;
    
    // all clear!
    
    // add transaction to history
    History::add('mechanisms', $id, array('action' => 'change_pubdate', 'was' => $mech['pubdate']));
    
    // update mechanism's pubdate
    $update['pubdate'] = $pubdate ? new MongoDate($timestamp) : '';

    return MongoLib::set('mechanisms', $mech['_id'], $update);
  }
  
  
  /** 
  * Pull a mechanism out of the sandbox 
  * @param string Mechanism id
  * @return boolean 
  * @key __member
  */ 
  static function redraft($id)
  {
    // get the mechanism
    if(!$mech = MongoLib::findOne_editable('mechanisms', $id))
      return ErrorLib::set_error("That mechanism was not found");
    
    // get the mfam
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $mech['family']))
      return ErrorLib::set_error("That mechanism family was not found");
    
    // check mech square
    if($mech['square'] != 'sandbox')
      return ErrorLib::set_error("Only sandboxed mechanisms can be redrafted");
    
    // all clear!
    
    // add transaction to history
    History::add('mechanisms', $id, array('action' => 'draft'));
    
    // revoke open visibility
    // NOTE: we leave mfam in the open, regardless of its publication status (once in sandbox, it never goes back to draft)
    PermLib::revoke_members_view_perms('mechanisms', $mech['_id']);

    // move mechanism's square and unset pubdate
    $update['square'] = 'draft';
    $update['pubdate'] = false;
    
    return MongoLib::set('mechanisms', $mech['_id'], $update);
  }
  

  /** 
  * Publish a mechanism
  * @param string Mechanism id
  * @return boolean
  * @key __member
  */ 
  static function publish($id)
  {
    // get mech
    if(!$mech = MongoLib::findOne('mechanisms', $id))
      return ErrorLib::set_error("No such mechanism exists");
    
    // get the mfam
    if(!$mfam = MongoLib::findOne_editable('mechanism_families', $mech['family']))
      return ErrorLib::set_error("The mechanism family was not found");
    
    // check the mfam square
    if($mfam['square'] == 'draft')
      return ErrorLib::set_error("That mechanism's family is still in draft");
    if($mfam['square'] == 'retired')
      return ErrorLib::set_error("That mechanism's family has been retired");

    // ensure mech is in the sandbox
    if($mech['square'] != 'sandbox')
      return ErrorLib::set_error("Only sandboxed mechs can be published");
    
    // check the pubdate
    // TODO: fix this!!!
    // if(!$mech['protocol'] && (!$mech['pubdate'] || (MongoLib::extract_time($mech['pubdate']) > time())))
    //   return ErrorLib::set_error("The publication date for that mechanism is unset or in the future");
        
    // all clear!
    
    return MechLib::publish($id);
  }

  
  /** 
  * Get the mechanism score from some vscores 
  * @param string Mech id
  * @param string Virtual scores, like {* (:pq_id {* (:value 12 :fail 1)} :handle {* (:value 3)})}
  * @param string Accepts mfam or scores. Defaults to 'mfam' which returns the mech family score only. Returns a hash of stid->score for 'scores'.
  * @return array 
  * @key __member
  */ 
  static function vrun($rules, $vscores, $return=NULL)
  {
    return MechLib::run_virtual_mech($rules, $vscores);
  }
  
  
  /** 
  * Get the mechanism score from some vscores 
  * @param string Mech id
  * @param string Virtual scores, like {* (:pq_id {* (:value 12 :fail 1)} :handle {* (:value 3)})}
  * @param string Accepts mfam or scores. Defaults to 'mfam' which returns the mech family score only. Returns a hash of stid->score for 'scores'.
  * @return float 
  * @key __member
  */ 
  static function run($id, $vscores, $return=NULL)
  {
    return MechLib::run_mech($id, $vscores, false, $return);
  }
  
  
  /** 
  * Takes some scores from the DB and changes them into vscores
  * Note that this removes the *thing*, so you only want to pass scores from one *thing* or they'll get mushed together. 
  * @param string Array of DB scores
  * @return array 
  * @key __member
  */ 
  static function virtualize($scores)
  {
    return MechLib::scores_to_vscores($scores);
  }
  
  
  
  /** 
  * Visualize a mechanism's rule structure
  * @param string Mechanism id
  * @param string Overwrite the cache (defaults to false)
  * @return string 
  * @key __member
  */ 
  static function visualize($id, $overwrite=NULL)
  {
    // check for mech
    if(!MongoLib::check('mechanisms', $id))
      return ErrorLib::set_error("No such mechanism exists");
    
    $site_dir = $GLOBALS['X']['SETTINGS']['site_directory'];
    $site_url = $GLOBALS['X']['VARS']['SITE']['path'];
    $filepath = "viz/mech_$id.svg";
    
    // check for cached viz
    if(!$overwrite && file_exists("$site_dir/$filepath"))
      return "$site_url/$filepath";
    
    // if(!$url = ATLib::mech_viz($id))
    //   return false;
    // 
    return $url;
  }


}

// EOT