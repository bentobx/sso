<?php

/**
 * Every user has a profile; it's like a silhouette or side view
 *
 * Profiles consist of common information across all user types ('member' is the default user type). 
 * They're generally entirely world viewable -- personal info usually goes in the membery collection, or somewhere else entirely.
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class Profile
{
  
  /** 
  * Get some profiles
  * @param string User ids (the user id and the profile id are the same)
  * @param string User type ('member' is the default type)
  * @param string Acceptable squares: pending, approved
  * @param string Supports sort, limit, skip, fields, nofields, count and attrs: {* (:limit 5 :skip "30" :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)}
  * @return array 
  * @key __member __lens __exec
  */ 
  static function find($by_ids=NULL, $by_type=NULL, $by_square=NULL, $options=NULL) 
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_type))
      $query['type'] = $by_type;
    
    if(isset($by_square))
      $query['square'] = $by_square;
    
    return MongoLib::find_with_perms('profiles', $query, $options);
  } 
  
}

// EOT