<?php

/**
 * La familia protocolo
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Protocol
{
  
  /** 
  * Returns a set of protocols 
  * @param array An array of protocol ids
  * @param array A protocol family id
  * @param array Square (draft, sandbox, published, deprecated)
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __world
  */ 
  static function find($by_ids=NULL, $by_family=NULL, $by_square=NULL, $options=NULL)
  {
    if(isset($by_ids))
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
      
    if(isset($by_family))
      $query['family'] = array('$in' => MongoLib::fix_ids($by_family));
      
    if(isset($by_square))
      $query['square'] = $by_square;
    
    return MongoLib::find_with_perms('protocols', $query, $options);
  }
  
  
  //
  // Family functions
  //
  
  
  /** 
  * Get a set of protocol families 
  * @param string Protocol family ids
  * @param string A name (supports regex)
  * @param string Protocol ids
  * @param string Square (draft, sandbox, active, retired)
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __exec __lens
  */ 
  static function find_families($by_ids=NULL, $by_name=NULL, $by_protocols=NULL, $by_square=NULL, $options=NULL)
  {
    if(isset($by_ids))
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_name))
      $query['name'] = new MongoRegex("/$by_name/i");

    if(isset($by_protocols)) {
      if(!$protocols = MongoLib::findIn('protocols', $by_protocols, 'family'))
        return ErrorLib::set_error("Invalid protocol id");
        
      foreach($protocols as $p)
        $pfam_ids[] = $p['family'];
        
      $query['_id'] = array('$in' => $pfam_ids);
    }
    
    if(isset($by_square))
      $query['square'] = $by_square;
    
    return MongoLib::find_with_perms('protocol_families', $query, $options);
  }
  
  
  /** 
  * Add a new protocol family 
  * @param string Surname (must be unique)
  * @param string A unique short name (truncated at 20 characters)
  * @return id 
  * @key admin
  */ 
  static function add_family($name, $shortname=NULL)
  {
    // verify name
    if(!$name = trim($name))
      return ErrorLib::set_error("A valid name is required");
    
    // verify and fix shortname
    if(!$shortname = substr(trim($shortname ? $shortname : $name), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
        
    // check for name uniqueness 
    if(MongoLib::check('protocol_families', array('name' => $name)))
      return ErrorLib::set_error("The protocol family name must be unique");
    
    // check for shortname uniqueness 
    if(MongoLib::check('protocol_families', array('shortname' => $shortname))) 
      return ErrorLib::set_error("The protocol family shortname must be unique");
    
    // // ensure i'm a certifier
    // if(!$certifier_id = $GLOBALS['X']['VARS']['MY']['profile']['certifier'])
    //   return ErrorLib::set_error("You must be a certifier to add a protocol family");
    
    // all clear!
    
    // set up the bifurcated data
    $mongo['name'] = $name;
    $mongo['square'] = 'draft';
    $mongo['shortname'] = $shortname;
    // $mongo['certifier'] = MongoLib::fix_id($certifier_id);
    
    // add the protocol
    if(!$pfam_id = MongoLib::insert('protocol_families', $mongo))
      return false;
    
    // add root perms to the pfam for this user
    PermLib::grant_user_root_perms('protocol_families', $pfam_id);
    
    // add mechanism family for protocol
    $mf_id = MechLib::add_family($name, $shortname, array('protocol_families', $pfam_id));
    $pf_mf_update['mech_family'] = $mf_id;
    MongoLib::set('protocol_families', $pfam_id, $pf_mf_update);
        
    // add transaction to history
    History::add('protocol_families', $pfam_id, array('action' => 'add'));
    
    return $pfam_id;
  }

  /** 
  * A protocol family's name can only be changed in the draft state -- once sandboxed, it is permanently set
  * @param string Protocol family id
  * @param string New family name
  * @param string New family shortname
  * @return id 
  * @key admin
  */ 
  static function change_name($family, $name, $shortname)
  {
    // get pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $family))
      return ErrorLib::set_error("That protocol family was not found");

    // check pfam.square
    if($pfam['square'] != 'draft')
      return ErrorLib::set_error("That protocol family has been published and can not be edited");

    // verify name
    if(!$name = trim($name))
      return ErrorLib::set_error("A valid name is required");
    
    // verify and fix shortname
    if(!$shortname = substr(trim($shortname ? $shortname : $name), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
    
    // check for name uniqueness 
    $unique_name_q['name'] = $name;
    $unique_name_q['_id']['$ne'] = $pfam['_id'];
    if(MongoLib::check('protocol_families', $unique_name_q)) 
      return ErrorLib::set_error("That protocol family name is taken");

    // check for shortname uniqueness 
    $unique_sname_q['shortname'] = $shortname;
    $unique_sname_q['_id']['$ne'] = $pfam['_id'];
    if(MongoLib::check('protocol_families', $unique_sname_q)) 
      return ErrorLib::set_error("The protocol family shortname must be unique");

    // all clear!

    // add transaction to history
    History::add('protocol_families', $pfam['_id'], array('action' => 'change_name', 'was' => array('name' => $pfam['name'], 'shortname' => $pfam['shortname'])));
    
    // update MF name
    Mechanism::change_name($pfam['mech_family'], $name, $shortname);
    
    // update the protocol family
    $update['name'] = $name;
    $update['shortname'] = $shortname;
    MongoLib::set('protocol_families', $pfam['_id'], $update);
    
    return $id;
  }
  
  /** 
  * Retire a protocol family, permanently 
  * @param string Protocol family
  * @return boolean 
  * @key admin
  */ 
  static function retire_family($id)
  {
    // get pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $id))
      return ErrorLib::set_error("That protocol family was not found");

    // check pfam.square
    if($pfam['square'] != 'active')
      return ErrorLib::set_error("Only active protocol families can be retired");

    // all clear!

    // add transaction to history
    History::add('protocol_families', $id, array('action' => 'retire'));

    // set deprecator
    $square_dep_update['square'] = 'deprecated';

    // deprecate all protocols for this family
    $filter['family'] = $pfam['_id'];
    MongoLib::set('protocols', $filter, $square_dep_update, true);
    
    // deprecate PF's MF
    Mechanism::retire_family($pfam['mech_family']);
    
    // update the protocol family
    $update['square'] = 'retired';
    MongoLib::set('protocol_families', $pfam['_id'], $update);
    
    return $id;
  }
  
  
  /** 
  * This is a really bad idea -- use only for testing 
  * @param string 
  * @return boolean 
  */ 
  static function destroy_family($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // get the pfam
    if(!$pfam = MongoLib::findOne('protocol_families', $id))
      return ErrorLib::set_error("Invalid protocol family id");
    
    // all clear!
    
    // add transaction to history
    History::add('protocol_families', $pfam['_id'], array('action' => 'destroy', 'was' => $pfam));
    
    // get all the protocols
    $protocols = MongoLib::find('protocols', array('family' => $pfam['_id']));

    // destroy all protocols
    foreach($protocols as $protocol) {
      History::add('protocols', $protocol['_id'], array('action' => 'destroy', 'was' => $protocol));
      MongoLib::removeOne('protocols', $protocol['_id']);
    }
    
    // destroy the PF's MF
    Mechanism::destroy_family($pfam['mech_family']);
    
    // destroy the PF
    DataLib::destroy('protocol_families', $pfam['_id']);
    return MongoLib::removeOne('protocol_families', $pfam['_id']);
  }
  
  
  //
  // PROTOCOL FUNCTIONS
  //
  
    
  /** 
  * Add a new protocol to a family
  * @param string Pfam id
  * @param string Used by the admin to distinguish various members of a protocol family (immutable)
  * @return id 
  * @key admin
  */ 
  static function add($family, $nickname)
  {
    // get the pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $family))
      return ErrorLib::set_error("That protocol family was not found");
    
    // verify nickname
    if(!$nickname = trim($nickname))
      return ErrorLib::set_error("A valid nickname is required");
    
    // check for nickname uniqueness within the family
    if(MongoLib::check('protocols', array('family' => $pfam['_id'], 'nickname' => $nickname))) 
      return ErrorLib::set_error("A protocol in that family already has that nickname");
    
    // all clear!
    
    // insert protocol
    $protocol['square'] = 'draft';
    $protocol['nickname'] = $nickname;
    $protocol['family'] = $pfam['_id'];
    $id = MongoLib::insert('protocols', $protocol);
    
    // add root perms to the protocol for this user
    PermLib::grant_user_root_perms('protocols', $id);
    
    // add a new mech for this protocol
    if(!$mech_id = MechLib::add_mech($pfam['mech_family'], $nickname, $id))
      return false;
    
    // push mech into protocol
    $update['mech'] = $mech_id;
    MongoLib::set('protocols', $id, $update);
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'add'));
    
    return $id;
  }


  /** 
  * Two protocols are better than one
  * Note to future self: this is named 'replicate' because it can't be named 'clone'.
  * @param string Protocol id
  * @return id 
  * @key admin
  */ 
  static function replicate($id, $nickname)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // get the pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $protocol['family']))
      return ErrorLib::set_error("That protocol family was not found");
    
    // check for nickname uniqueness within the family
    if(MongoLib::check('protocols', array('family' => $pfam['_id'], 'nickname' => $nickname))) 
      return ErrorLib::set_error("A protocol in that family already has that nickname");
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'replicate'));
    
    // edit the protocol
    $old_mech = $protocol['mech'];
    
    $protocol['parent'] = $protocol['_id'];
    $protocol['nickname'] = $nickname;
    $protocol['square'] = 'draft';
    unset($protocol['version']);
    unset($protocol['mech']);
    unset($protocol['_id']);
    
    // save it
    $new_id = MongoLib::insert('protocols', $protocol);
    
    // TODO: switch the perms
    
    // replicate the mech
    $new_mech_id = MechLib::replicate_mech($old_mech, $nickname, $new_id);
    $update['mech'] = $new_mech_id;
    MongoLib::set('protocols', $new_id, $update);
    
    return $new_id;
  }
  
  
  /** 
  * Push a protocol into the sandbox
  * @param string Protocol id
  * @param string Publication date
  * @return boolean 
  * @key admin
  */ 
  static function sandbox($id, $pubdate=NULL)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // get the pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $protocol['family']))
      return ErrorLib::set_error("That protocol family was not found");
    
    // check protocol's square
    if($protocol['square'] != 'draft')
      return ErrorLib::set_error("Only draft protocols can be sandboxed");
    
    // fix the pubdate and make sure there's nothing else scheduled around then
    if(!$pubdate = BonsaiLib::get_protocol_pubdate_timestamp($mech, $pubdate))
      return false; // error inside
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'sandbox'));
    
    // if the pfam is in draft, move it to sandbox
    if($pfam['square'] == 'draft') {
      $pfam_update['square'] = 'sandbox';
      MongoLib::set('protocol_families', $pfam['_id'], $pfam_update);

      // and grant open visibility
      PermLib::grant_members_view_perms('protocol_families', $pfam['_id']);
    }
    
    // if any pqs are in draft, move them into the sandbox
    foreach($protocol['q_list'] as $q)
      $pq_ids[] = $q['pq'];
    $pqs = MongoLib::findIn('protoquestions', $pq_ids);
    $pq_update['square'] = 'sandbox';
    foreach($pqs as $pq)
      if($pq['square'] == 'draft')
        MongoLib::set('protoquestions', $pq['_id'], $pq_update);
    
    // grant open visibility to protocol
    PermLib::grant_members_view_perms('protocols', $protocol['_id']);

    // sandbox the mech
    Mechanism::sandbox($protocol['mech']);

    // move protocol's square and set pubdate
    $update['square'] = 'sandbox';
    $update['pubdate'] = new MongoDate($pubdate);
    
    MongoLib::set('protocols', $protocol['_id'], $update);
    
    return $id;
  }
  
  
  /** 
  * Set the publication date of a protocol in the sandbox 
  * @param string Protocol id
  * @param string Publication date (must be at least two weeks in the future; can't be within two weeks of any other scheduled protocol in this family.)
  * @return string 
  * @key admin
  */ 
  static function change_pubdate($id, $pubdate)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // get the pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $protocol['family']))
      return ErrorLib::set_error("That protocol family was not found");
    
    // check protocol's square
    if($protocol['square'] != 'sandbox')
      return ErrorLib::set_error("Only sandboxed protocols can have their publication date changed");
    
    // fix the pubdate and make sure there's nothing else scheduled around then
    if(!$pubdate = BonsaiLib::get_protocol_pubdate_timestamp($mech, $pubdate))
      return false; // error inside
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'change_pubdate', 'was' => $protocol['pubdate']));
    
    // update protocol's pubdate
    $update['pubdate'] = new MongoDate($pubdate);

    MongoLib::set('protocols', $protocol['_id'], $update);
    
    return $id;
  }
  
  
  /** 
  * Pull a protocol out of the sandbox 
  * @param string Protocol id
  * @return boolean 
  * @key admin
  */ 
  static function redraft($id)
  {     
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // get the pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $protocol['family']))
      return ErrorLib::set_error("That protocol family was not found");
    
    // check p square
    if($protocol['square'] != 'sandbox')
      return ErrorLib::set_error("Only sandboxed protocols can be redrafted");
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'draft'));
    
    // revoke open visibility
    // NOTE: we leave pfam in the open, regardless of its publication status (once active, it never goes back to draft) [why is that?]
    PermLib::revoke_members_view_perms('protocols', $protocol['_id']);
    
    // if any pqs are in the sandbox for this and ONLY this protocol, move them back into draft
    // NOTE: we don't change the pq visibility -- they're always in the open
    foreach($protocol['q_list'] as $q)
      $pq_ids[] = $q['pq'];
    $pqs = MongoLib::findIn('protoquestions', $pq_ids);
    $pq_update['square'] = 'draft';
    foreach($pqs as $pq) {
      if(!MongoLib::check('protocols', array('q_list.pq_id' => $pq['_id']))) {
        if($pq['square'] == 'sandbox') {
          MongoLib::set('protoquestions', $pq['_id'], $pq_update);
        }        
      }
    }
    
    // redraft the mech
    Mechanism::redraft($protocol['mech']);

    // move protocol's square and unset pubdate
    $update['square'] = 'draft';
    $update['pubdate'] = false;
    
    MongoLib::set('protocols', $protocol['_id'], $update);
    
    return $id;
  }
    
  
  /**
  * Used by the trigger execs to set protocol triggers
  * @param string Protocol id
  * @param string Trigger type (promote, answer, or build)
  * @param string Trigger actions
  * @param string Trigger conditions
  * @return boolean
  * @key __member __exec
  */
  static function set_trigger($id, $type, $actions, $conditions)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // check protocol's square
    if($protocol['square'] != 'draft')
      return ErrorLib::set_error("Only draft protocols can have their triggers set");
    
    // check the type
    if(!in_array($type, array('promote', 'answer', 'build')))
      return ErrorLib::set_error("Invalid trigger type");
    
    // all clear!

    // add transaction to history
    History::add('protocols', $id, array('action' => 'set_trigger', 'was' => $protocol['triggers'][$type]));
    
    // update the protocol
    $update["triggers.$type.actions"] = $actions;
    $update["triggers.$type.conditions"] = $conditions;
    // TODO: push custom json'd triggers string into content for safe keeping...
    
    MongoLib::set('protocols', $protocol['_id'], $update);
    
    return $id;
  }
  
  
  /** 
  * The promote permits control who can start and promote a test
  * @param string Protocol id
  * @param string Permits for promotion, like ("user:20" "auditor:*" "company:123")
  * @return boolean 
  * @key admin
  */ 
  static function set_promote_permits($id, $permits)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // check and fix the permits
    if(!is_array($permits))
      $permits = array($permits);
    foreach($permits as $permit)
      if(!is_string($permit) || !strpos($permit, ':'))
        return ErrorLib::set_error("Those permits appear invalid");
    
    // check protocol's square
    if($protocol['square'] != 'draft')
      return ErrorLib::set_error("Only draft protocols can have their promote permits set");
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'set_promote_permits', 'was' => $protocol['promopermits']));
    
    // update the protocol
    $update['promopermits'] = MongoLib::fix_id($permits);
    MongoLib::set('protocols', $protocol['_id'], $update);
    
    return $id;
  }
  
  /** 
  * Startlock prevents users from starting a new test, for protocols like reviews that have to be auto-generated
  * @param string Protocol id
  * @param string Startlock, like :true or false
  * @return boolean 
  * @key admin
  */ 
  static function set_startlock($id, $startlock)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // check protocol's square
    if($protocol['square'] != 'draft')
      return ErrorLib::set_error("Only draft protocols can have their startlock set");
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'set_startlock', 'was' => $protocol['startlock']));
    
    // update the protocol
    $update['startlock'] = !!$startlock;
    MongoLib::set('protocols', $protocol['_id'], $update);
    
    return $id;
  }
  
  /** 
  * A protocol publisher that bypasses scheduling 
  * @param string The protocol id
  * @return string 
  * @key admin
  */ 
  static function publish($id)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That protocol was not found");
    
    // get the pfam
    if(!$pfam = MongoLib::findOne_editable('protocol_families', $protocol['family']))
      return ErrorLib::set_error("That protocol's family was not found");
    
    // check the pfam square
    if($pfam['square'] == 'draft')
      return ErrorLib::set_error("That protocol's family is still in draft");
    if($pfam['square'] == 'retired')
      return ErrorLib::set_error("That protocol's family has been retired");
    
    // check protocol's square
    if($protocol['square'] != 'sandbox')
      return ErrorLib::set_error("Only sandboxed protocols can be published");
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'publish'));
    
    // if the pfam is in sandbox, move it to active
    if($pfam['square'] == 'sandbox') {
      $pfam_update['square'] = 'active';
      MongoLib::set('protocol_families', $pfam['_id'], $pfam_update);
    } 
    
    // deal with sandboxed pqs
    foreach($protocol['q_list'] as $q)
      $pq_ids[] = $q['pq'];
    $pqs = MongoLib::findIn('protoquestions', $pq_ids);
    foreach($pqs as $pq) {
      if($pq['square'] == 'sandbox') {
        // publish pq and remove edit/root perms
        $pq['square'] = 'published';
        $pq['perms'] = array('user:*' => 'view');
        $pq['pcache'] = array('view' => 'user:*');
        MongoLib::update('protoquestions', $pq['_id'], $pq);
        // publish pq's st
        $pq_st_update['square'] = 'published';
        MongoLib::set('score_types', $pq['score_type'], $pq_st_update);
      }
    }
    
    // deprecate any currently published protocols for this family
    $filter['square'] = 'published';
    $filter['family'] = $pfam['_id'];
    if($current_p = MongoLib::findOne('protocols', $filter)) {
      $p_update['square'] = 'deprecated';
      MongoLib::set('protocols', $filter, $p_update);
    }
    
    // pub the mech
    MechLib::publish($protocol['mech']);
    
    // move protocol's square, set pubdate, and handle versioning
    $update['square'] = 'published';
    $update['pubdate'] = new MongoDate(time());
    $update['version'] = $current_p ? $current_p['version'] + 1 : 1; // OPT: little bit of a race condition here
    
    MongoLib::set('protocols', $protocol['_id'], $update);
    
    return $id;
  }
  
    
  /** 
  * Add a question to a protocol
  * @param string Protocol id
  * @param string Protoquestion id
  * @param array Protocol specific question data
  * @return string 
  * @key admin
  */ 
  static function add_question($id, $pq, $pdata)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That is not a valid protocol");
    
    // check the pq
    if(!MongoLib::check('protoquestions', $pq))
      return ErrorLib::set_error("That is not a valid protoquestion");
    
    // check protocol's square
    if($protocol['square'] != 'draft')
      return ErrorLib::set_error("Only draft protocols can be edited");
    
    // THINK: check the pq's square also?
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'add_q', 'index' => count($protocol['q_list']) + 1));
    
    // wrap the pq and qd
    $question = array('pq' => MongoLib::fix_id($pq), 'pdata' => $pdata);
    
    // push it into the protocol
    MongoLib::addToSet('protocols', $id, 'q_list', $question);    
    
    return $id;
  }

  /** 
  * Remove a question
  * @param string Protocol id
  * @param string The question's q_list index
  * @return string 
  * @key admin
  */ 
  static function remove_question($id, $index)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That is not a valid protocol");
    
    // check the index
    if(count($protocol['q_list']) <= $index)
      return ErrorLib::set_error("That is not a valid index");
    
    // check protocol's square
    if($protocol['square'] != 'draft')
      return ErrorLib::set_error("Only draft protocols can be edited");
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'remove_q', 'was' => $protocol['q_list'][$index]));
    
    // remove the question
    unset($protocol['q_list'][$index]);
    $protocol['q_list'] = array_values($protocol['q_list']);
    
    // update the protocol
    $update = array('q_list' => $protocol['q_list']);
    MongoLib::set('protocols', $id, $update);
    
    return $id;
  }
  
  /** 
  * Edit the pdata of a pq in a p's q_list 
  * @param string Protocol id
  * @param string The question's q_list index
  * @param string New pdata
  * @return string 
  * @key admin
  */ 
  static function edit_pdata($id, $index, $pdata)
  {
    // get the protocol
    if(!$protocol = MongoLib::findOne_editable('protocols', $id))
      return ErrorLib::set_error("That is not a valid protocol");
        
    // check the index
    if(count($protocol['q_list']) <= $index)
      return ErrorLib::set_error("That is not a valid index");
    
    // check protocol's square
    if($protocol['square'] != 'draft')
      return ErrorLib::set_error("Only draft protocols can be edited");
    
    // all clear!
    
    // add transaction to history
    History::add('protocols', $id, array('action' => 'edit_q', 'was' => $protocol['q_list'][$index]['pdata']));
    
    // update the protocol
    $update = array("q_list.$index.pdata" => $pdata);
    MongoLib::set('protocols', $id, $update);
    
    return $id;
  }
  
  
}

// EOT