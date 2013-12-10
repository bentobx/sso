<?php

/**
 * PQs live in the clouds, man
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Protoquestion
{
    
  /** 
  * Get pqs 
  * @param array PQ ids
  * @param array A string to match against public.text or shortname
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find($by_ids=NULL, $by_text=NULL, $options=NULL)
  {
    if(isset($by_ids)) {
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    }
      
    if(isset($by_text)) {
      if(!Processor::is_true($by_text)) // non-zero false
        return array();
      $query['$or'][0]['public.text'] = new MongoRegex("/$by_text/i");
      $query['$or'][1]['shortname'] = new MongoRegex("/$by_text/i");
    }
    
    // NOTE: PQs have perms so no one else can hijack your PQ while it's in the sandbox.
    return MongoLib::find_with_perms('protoquestions', $query, $options);
  }
  
  
  /** 
  * Add a protoquestion
  * @param string Short name (truncated at 20 characters)
  * @param string Question type
  * @param array Public data
  * @param array Private data
  * @return id 
  * @key admin
  */ 
  static function add($shortname, $type, $public, $private=NULL)
  {
    // verify shortname
    if(!$shortname = substr(trim($shortname), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
    
    // THINK: why no uniqueness for PQ shortname?
    // TODO: add uniqueness check for PQ shortname
    
    // verify type
    if(!$type)
      return ErrorLib::set_error("A valid type is required");
    
    // confirm Qtype exists
    if($type) {
      if(!MixMaster::check_for_mixin('question_types', $type))
        return false;
    }
    
    // all clear!
    
    // add it
    $pq['type'] = $type;
    $pq['public'] = $public;
    $pq['private'] = $private;
    $pq['shortname'] = $shortname;
    $pq['square'] = 'draft';
    $pq_id = MongoLib::insert('protoquestions', $pq);
    
    // add the corresponding score type
    $this_ref = array('protoquestions', $pq_id);
    $score_type_id = Score::add_type($shortname, 'PQ', $this_ref);
    
    // add the score type back into the pq
    MongoLib::set('protoquestions', $pq_id, array('score_type' => $score_type_id));

    // grant root perms to the user 
    PermLib::grant_user_root_perms('protoquestions', $pq_id);
    
    // grant open visibility (everyone can *always* see pqs)
    PermLib::grant_members_view_perms('protoquestions', $pq_id);
    
    // add transaction to history
    History::add('protoquestions', $pq_id, array('action' => 'add'));
    
    return $pq_id;
  }


  /** 
  * Set the shortname for a draft PQ 
  * @param string PQ id
  * @param string New shortname
  * @return id 
  * @key admin
  */ 
  static function set_shortname($id, $shortname)
  {
    // get pq
    if(!$pq = MongoLib::findOne_editable('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");
    
    // check pq square
    if($pq['square'] != 'draft')
      return ErrorLib::set_error("That protoquestion has been published and can not be edited");

    // verify shortname
    if(!$shortname = substr(trim($shortname), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
        
    // all clear!
    
    // call the admin version. this double-pumps the mongo call, but it's fast.
    return self::admin_set_shortname($id, $shortname);
  }

  /** 
  * Set the type for a draft PQ 
  * @param string PQ id
  * @param string Question type (date, file_upload, mc_plus_ultra, multiple_choice, numeric, text, text_list, yearly_numeric, yearly_text... you get the idea)
  * @return id 
  * @key admin
  */ 
  static function set_type($id, $type)
  {
    // get pq
    if(!$pq = MongoLib::findOne_editable('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");
    
    // check pq square
    if($pq['square'] != 'draft')
      return ErrorLib::set_error("That protoquestion has been published and can not be edited");

    // verify type
    if(!$type)
      return ErrorLib::set_error("A valid type is required");

    // ensure type exists
    if($type) {
      if(!MixMaster::check_for_mixin('question_types', $type))
        return false;
    }
    
    // all clear!
    
    // call the admin version. this double-pumps the mongo call, but it's fast.
    return self::admin_set_type($id, $type);
  }

  /** 
  * Set the public for a draft PQ 
  * @param string PQ id
  * @param string New public
  * @return id 
  * @key admin
  */ 
  static function set_public($id, $public)
  {
    // get pq
    if(!$pq = MongoLib::findOne_editable('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");
    
    // check pq square
    if($pq['square'] != 'draft')
      return ErrorLib::set_error("That protoquestion has been published and can not be edited");

    // all clear!
    
    // call the admin version. this double-pumps the mongo call, but it's fast.
    return self::admin_set_public($id, $public);
  }

  /** 
  * Set the private for a draft PQ 
  * @param string PQ id
  * @param string New private
  * @return id 
  * @key admin
  */ 
  static function set_private($id, $private)
  {
    // get pq
    if(!$pq = MongoLib::findOne_editable('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");
    
    // check pq square
    if($pq['square'] != 'draft')
      return ErrorLib::set_error("That protoquestion has been published and can not be edited");

    // all clear!
    
    // call the admin version. this double-pumps the mongo call, but it's fast.
    return self::admin_set_private($id, $private);
  }
  

  /** 
  * NO RESTRICTIONS! CAREFUL! Set the shortname for a draft PQ 
  * @param string PQ id
  * @param string New shortname
  * @return id 
  * @key admin
  */ 
  static function admin_set_shortname($id, $shortname)
  {
    // get pq
    if(!$pq = MongoLib::findOne('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");

    // verify shortname
    if(!$shortname = substr(trim($shortname), 0, 20))
      return ErrorLib::set_error("A valid shortname is required");
    
    // all clear!
    
    // add transaction to history
    History::add('protoquestions', $id, array('action' => 'set_shortname', 'was' => $pq['shortname']));
    
    // change the score type
    MongoLib::set('score_types', $pq['score_type'], array('shortname' => $shortname));

    // update shortname
    $update['shortname'] = $shortname;
    return MongoLib::set('protoquestions', $pq['_id'], $update);
  }

  /** 
  * NO RESTRICTIONS! CAREFUL! Set the type for a draft PQ 
  * @param string PQ id
  * @param string Question type (date, file_upload, mc_plus_ultra, multiple_choice, numeric, text, text_list, yearly_numeric, yearly_text... you get the idea)
  * @return id 
  * @key admin
  */ 
  static function admin_set_type($id, $type)
  {
    // get pq
    if(!$pq = MongoLib::findOne('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");

    // verify type
    if(!$type)
      return ErrorLib::set_error("A valid type is required");

    // ensure type exists
    if($type) {
      if(!MixMaster::check_for_mixin('question_types', $type))
        return false;
    }
    
    // all clear!
    
    // add transaction to history
    History::add('protoquestions', $id, array('action' => 'set_type', 'was' => $pq['type']));
    
    // update type
    $update['type'] = $type;
    return MongoLib::set('protoquestions', $pq['_id'], $update);
  }

  /** 
  * NO RESTRICTIONS! CAREFUL! Set the public for a draft PQ 
  * @param string PQ id
  * @param string New public
  * @return id 
  * @key admin
  */ 
  static function admin_set_public($id, $public)
  {
    // get pq
    if(!$pq = MongoLib::findOne('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");
    
    // all clear!
    
    // add transaction to history
    History::add('protoquestions', $id, array('action' => 'set_public', 'was' => $pq['public']));
    
    // update public
    $update['public'] = $public;
    return MongoLib::set('protoquestions', $pq['_id'], $update);
  }

  /** 
  * NO RESTRICTIONS! CAREFUL! Set the private for a draft PQ 
  * @param string PQ id
  * @param string New private
  * @return id 
  * @key admin
  */ 
  static function admin_set_private($id, $private)
  {
    // get pq
    if(!$pq = MongoLib::findOne('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion");
    
    // all clear!
    
    // add transaction to history
    History::add('protoquestions', $id, array('action' => 'set_private', 'was' => $pq['private']));
    
    // update private
    $update['private'] = $private;
    return MongoLib::set('protoquestions', $pq['_id'], $update);
  }
  
  
  /** 
  * This is a really bad idea -- use only for testing 
  * @param string Protoquestion id
  * @return boolean 
  */ 
  static function destroy($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");

    // get the pq
    if(!$pq = MongoLib::check('protoquestions', $id))
      return ErrorLib::set_error("No such protoquestion found");
    
    // all clear!
    
    // add transaction to history
    History::add('protoquestions', $id, array('action' => 'destroy', 'was' => $pq));
    
    // destroy the score type
    $this_ref = array('protoquestions', $id);
    $score_type = reset(Score::find_types(NULL, NULL, $this_ref));
    MongoLib::removeOne('score_types', $score_type['_id']);
    
    // destroy the protoquestion
    return MongoLib::removeOne('protoquestions', $id);
  }

}

// EOT