<?php

/**
 * Some event stuff -- events are like a thing? that happens, like, at a time?
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class Event
{
  
  // validates the item
  // TODO: move this somewhere else!
  private static function validize($item) {
    $collection = 'events';
    $fields = array('name', 'location', 'key', 'start_date', 'end_date', 'square', 'organizers');
    
    if(!$item) return false;
    if($item['valid']) return true;
    
    foreach($fields as $key) 
      if($item[$key] === false) return false;
    
    // all clear!
    
    $update['valid'] = true;
    MongoLib::set($collection, $item['_id'], $update);
    
    return true;
  }
  
  
  /** 
  * Find some events 
  * @param string Event ids
  * @param string Event name
  * @param string Search a start date range -- accepts (:yesterday :tomorrow) or (1349504624 1349506624)
  * @param string Event location
  * @param string Event key (will only return exact matches)
  * @param string Event organizer id
  * @param string Event square
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return string 
  * @key __world
  */ 
  static function find($by_ids=NULL, $by_name=NULL, $by_date_range=NULL, $by_location=NULL, $by_key=NULL, $by_organizer=NULL, $by_square=NULL, $options=NULL)
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_name))
      $query['name'] = new MongoRegex("/$by_name/i");
    
		if(isset($by_key))
			$query['key'] = new MongoRegex("/^$by_key/i");
			
		if(isset($by_location))
			$query['location'] = new MongoRegex("/$by_location/i");

		if(isset($by_square))
			$query['square'] = new MongoRegex("/$by_square/i");
			
		if(isset($by_organizer))
		  $query['organizers'] = array('$in' => MongoLib::fix_ids($by_organizer));

    if(isset($by_date_range)) {
      $begin_date = $by_date_range[0];
      $begin_date = ctype_digit((string) $begin_date) ? $begin_date : strtotime($begin_date);
      
      $end_date = $by_date_range[1];
      $end_date = ctype_digit((string) $end_date) ? $end_date : strtotime($end_date);
      
      $query1['start_date']['$gte'] = new MongoDate($begin_date);
      $query1['start_date']['$lte'] = new MongoDate($end_date);

      $query2['end_date']['$gte'] = new MongoDate($begin_date);
      $query2['end_date']['$lte'] = new MongoDate($end_date);

      $query3['start_date']['$lte'] = new MongoDate($begin_date);
      $query3['end_date']['$gte'] = new MongoDate($end_date);

      $query['$or'][] = $query1;
      $query['$or'][] = $query2; 
      $query['$or'][] = $query3;
    }
        
    return MongoLib::find_with_perms('events', $query, $options);
  }
  
  /** 
  * Add an event draft
  * @return string 
  * @key __member
  */ 
  static function add()
  {
    $event['name'] = false;
    $event['ttypes'] = false;
    $event['location'] = false;
		$event['key'] = false;
    $event['start_date'] = false;
    $event['end_date'] = false;
    $event['capacity'] = false;
    $event['square'] = 'draft';
    $event['valid'] = false;
    $event['organizers'] = array($GLOBALS['X']['USER']['id']);  
    
    $id = MongoLib::insert('events', $event);
    
    PermLib::grant_permission(array('events', $id), "admin:*", 'root');
    PermLib::grant_permission(array('events', $id), "user:" . $GLOBALS['X']['USER']['id'], 'edit');
    
    History::add('events', $id, array('action' => 'add'));
    
    return $id;
  }
  
  /** 
  * Set the event's name 
  * @param string event id
  * @param string New name
  * @return string 
  * @key __member
  */ 
  static function set_name($id, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
    
    $value = Processor::sanitize($value);
    
    if(!$value || strlen($value) < 3 || strlen($value) > 200)
      return ErrorLib::set_error("Invalid event name");
    
    if($event['name'] == $value)
      return $id;
    
    // all clear!
    
    $update['name'] = $value;
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_name', 'value' => $value));
    
    $event['name'] = $value;
    self::validize($event);
    
    return $id;
  }
  
  /** 
  * Set the event's location 
  * @param string event id
  * @param string New location -- an arbitrary string
  * @return string 
  * @key __member
  */ 
  static function set_location($id, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");

    // TODO: check value
        
    if($event['location'] == $value)
      return $id;
    
    // all clear!
    
    $update['location'] = $value;
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_location', 'value' => $value));
    
    $event['location'] = $value;
    self::validize($event);
    
    return $id;
  }
  
  /** 
  * Set the event's capacity cap 
  * @param string event id
  * @param string New capacity -- a positive integer value
  * @return string 
  * @key __member
  */ 
  static function set_capacity($id, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");

    if($event['capacity'] == $value)
      return $id;
    
    if(!is_numeric($value) || $value < 1 || $value != round($value))
      return ErrorLib::set_error("Capacity cap must be a positive number");
    
    // all clear!
    
    $update['capacity'] = $value;
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_capacity', 'value' => $value));
    
    $event['capacity'] = $value;
    self::validize($event);
    
    return $id;
  }

	/** 
	* Adds a URL token for the event 
	* @param string Event id
	* @param string Value of the token
	* @return string 
	* @key __member
	*/ 
	static function set_key($id, $value)
	{     
		if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");

    if(!$value)
      return ErrorLib::set_error("This key has no value");

    if($event['key'] === $value)
      return $id;
    
    if(MongoLib::check('events', array('key' => $value)))
	    return ErrorLib::set_error("An event with this key already exists");
    if($value != QueryLib::scrub_string($value, '_', '_.-'))
      return ErrorLib::set_error("Token is not URL-safe");
    
    // all clear!
    
    $update['key'] = $value;
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_key', 'value' => $value));
    
    $event['key'] = $value;
    self::validize($event);
    
    return $id;	  	
	}
	
  
  
  /** 
  * Set the event's start date 
  * @param string event id
  * @param string New start date
  * @return string 
  * @key __member
  */ 
  static function set_start_date($id, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
    
    if(!$value = new MongoDate(ctype_digit((string) $value) ? $value : strtotime($value)))
      return ErrorLib::set_error("That is not a valid date");
    
    if($event['start_date'] == $value)
      return $id;
    
    // all clear!
    
    $update['start_date'] = $value;
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_start_date', 'value' => $value));
    
    $event['start_date'] = $value;
    self::validize($event);
    
    return $id;
  }
  
  /** 
  * Set the event's end date 
  * @param string event id
  * @param string New end date
  * @return string 
  * @key __member
  */ 
  static function set_end_date($id, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
    
    if(!$value = new MongoDate(ctype_digit((string) $value) ? $value : strtotime($value)))
      return ErrorLib::set_error("That is not a valid date");
    
    if($event['end_date'] == $value)
      return $id;
    
    // all clear!
    
    $update['end_date'] = $value;
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_end_date', 'value' => $value));
    
    $event['end_date'] = $value;
    self::validize($event);
    
    return $id;
  }
  
  /** 
  * Set an event's organizers (i.e. the people who have permission to edit the event)  
  * @return string 
  * @param string Event id
  * @param string Organizer ids
  * @key __member
  */ 
  static function set_organizers($id, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
    
    if(!$value)
      return ErrorLib::set_error("That is not a valid value");
    
    if($event['organizers'] == $value)
      return $id;
    
    if($event['square'] != "draft")
      return ErrorLib::set_error("Can not modify organizers for a pending or published event");
    
    // make sure all proposed organizers are valid members
    foreach ($value as $i) {
      if(!$member = MongoLib::findOne('members', $i)) 
        return ErrorLib::set_error("No such member exists");
    }
        
    // all clear!
    
    $update['organizers'] = $value;
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_organizers', 'value' => $value));
    
    // revoke permissions for previous users
    foreach ($event['organizers'] as $i) 
      PermLib::revoke_permission(array('events', $id), "user:" . $i, 'edit');

    // grant permissions to new organizers
    foreach ($value as $i) 
      PermLib::grant_permission(array('events', $id), "user:" . $i, 'edit');
   
    $event['organizers'] = $value;
    self::validize($event);
      
    return $id;    
  }
  
  
  /** 
  * Submit your event draft for approval
  * @param string Event id
  * @return string 
  * @key __member
  */ 
  static function submit_draft($id)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
      
    if($event['square'] != "draft")
      return ErrorLib::set_error("Event must be in draft status for this action");
    
    // all clear!
    
    // can no longer be edited by organizers
    foreach ($event['organizers'] as $i) {
      PermLib::revoke_permission(array('events', $id), "user:" . $i, 'edit');
      PermLib::grant_permission(array('events', $id), "user:" . $i, 'view');
    }
    
    // update the event's square
    $update['square'] = 'pending';
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'submit_draft'));
    
    return $id;
  }
  
  /** 
  * Publish a pending event 
  * @param string 
  * @return string 
  * @key admin __exec
  */ 
  static function publish($id)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
      
    if($event['square'] != "pending")
      return ErrorLib::set_error("Event must be in pending status for this action");
      
    if(!$event['valid'])
    return ErrorLib::set_error("This event is not valid.");  
    
    // now publicly viewable
    PermLib::grant_permission(array('events', $id), "world:*", 'view');
    
    // update the event's square
    $update['square'] = 'published';
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'publish'));  
    
    return $id;
  }
  
  /** 
  * Sets the event's square back to draft  
  * @return string 
  * @key admin __exec
  */ 
  static function unpublish($id)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
      
    if($event['square'] == "draft")
      return $id;
 
    foreach ($event['organizers'] as $i)
      PermLib::grant_permission(array('events', $id), "user:" . $i, 'edit');
    
    PermLib::revoke_permission(array('events', $id), "world:*", 'view');
        
    // update the event's square
    $update['square'] = 'draft';
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'unpublish'));  
    
    return $id; 
  }
  
  
  /** 
  * Add a new ticket type to this event
  * @param string Event id
  * @param string Ticket type id
  * @return string 
  * @key admin __exec
  */ 
  static function add_ttype($id, $ttype)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");

    if(!$ttype = MongoLib::findOne_viewable('ttypes', $ttype))
      return ErrorLib::set_error("That ticket type was not found");
            
    if($event['ttypes'][$ttype['key']])
      return ErrorLib::set_error("That ticket type is already associated with that event");
    
    // all clear!
    
    $tt_map['key'] = $ttype['key'];
    $tt_map['_id'] = $ttype['_id'];
    $tt_map['price'] = 0;
    $tt_map['capacity'] = $event['capacity'];
    $event['ttypes'][$ttype['key']] = $tt_map;
    
    $update['ttypes'] = $event['ttypes'];
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'add_ttype', 'value' => $tt_map));
    
    return $id;
  }
  
  /** 
  * Remove a ticket type from an event -- does not cancel tickets!
  * @param string Event id
  * @param string Ticket type id
  * @return string 
  * @key admin __exec
  */ 
  static function remove_ttype($id, $ttype)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");

    if(!$ttype = MongoLib::findOne_viewable('ttypes', $ttype))
      return ErrorLib::set_error("That ticket type was not found");
            
    if(!$event['ttypes'][$ttype['key']])
      return ErrorLib::set_error("That ticket type is not associated with that event");
    
    // all clear!
    
    unset($event['ttypes'][$ttype['key']]);
    
    $update['ttypes'] = $event['ttypes'];
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'remove_ttype', 'value' => $ttype['key']));
    
    return $id;
  }
  
  /** 
  * Sets the capacity of a ticket type for an event
  * @param string Event id
  * @param string Ticket type id
  * @param string A positive or negative integer
  * @return string 
  * @key admin __exec
  */
  static function set_ttype_capacity($id, $ttype, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");

    if(!$ttype = MongoLib::findOne_viewable('ttypes', $ttype))
      return ErrorLib::set_error("That ticket type was not found");
            
    if(!$event['ttypes'][$ttype['key']])
      return ErrorLib::set_error("That ticket type is not associated with that event");
    
    if(!is_numeric($value) || $value != round($value))
      return ErrorLib::set_error("Value must be an integer");
    
    // Check that number of tickets of this type already issued does not exceed capacity
     $tickets = Ticket::find(NULL, $id, $ttype);
     if (count($tickets) > $value)
      return ErrorLib::set_error("Desired capacity exceeded by existing sales");
    
    // all clear!
    
    // make change
    $event['ttypes'][$ttype['key']]['capacity'] = $value;
    $update['ttypes'] = $event['ttypes'];
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'mod_ttype_quantity', 'value' => $event['ttypes'][$ttype['key']]));
    
    return $event['_id'];   
  }
  
  
  /** 
  * Change the price of a ticket type for an event
  * @param string Event id
  * @param string Ticket type id
  * @param string A positive numeric value like 1234.56
  * @return string 
  * @key admin __exec
  */ 
  static function set_ttype_price($id, $ttype, $value)
  {
    if(!$event = MongoLib::findOne_editable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");

    if(!$ttype = MongoLib::findOne_viewable('ttypes', $ttype))
      return ErrorLib::set_error("That ticket type was not found");
            
    if(!$event['ttypes'][$ttype['key']])
      return ErrorLib::set_error("That ticket type is not associated with that event");
    
    if(!is_numeric($value) || $value < 0)
      return ErrorLib::set_error("Price must be a positive number");
    
    // all clear!
    
    // make change
    $event['ttypes'][$ttype['key']]['price'] = $value;
    $update['ttypes'] = $event['ttypes'];
    MongoLib::set('events', $id, $update);

    History::add('events', $id, array('action' => 'set_ttype_price', 'value' => $event['ttypes'][$ttype['key']]));
    
    return $event['_id'];
  }
  
  
  /** 
  * Clone a event
  * @param string event id
  * @return string 
  * @key admin __exec
  */ 
  static function replicate($id)
  {
    if(!$event = MongoLib::findOne_viewable('events', $id))
      return ErrorLib::set_error("That event is not within your domain");
    
    // all clear!
    
    $new_id = self::add();
    
    if($event['name']) 
      self::set_name($new_id, $event['name'] . ' _copy_');
    
    if($event['location']) 
      self::set_location($new_id, $event['location']);
    
    if($event['capacity']) 
      self::set_capacity($new_id, $event['capacity']);
    
    if($event['start_date']) 
      self::set_start_date($new_id, $event['start_date']->sec);
    
    if($event['end_date']) 
      self::set_end_date($new_id, $event['end_date']->sec);
    
    if($event['key']) 
      self::set_key($new_id, $event['key'] . '_copy_');

    if($event['organizers']) 
      self::set_organizers($new_id, $event['organizers']);

    if($event['ttypes']) {
      $update['ttypes'] = $event['ttypes'];
      MongoLib::set('events', $new_id, $update);
    }
        
    self::validize($event);

    return $new_id;
  }
  
  /** 
  * Destroy an event completely (this will *seriously* mess things up!) 
  * @param string event id
  * @return string 
  */ 
  static function destroy($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");
    
    // get event
    if(!$event = MongoLib::findOne('events', $id))
      return ErrorLib::set_error("No such event exists");
    
    // all clear

    // add transaction to history
    History::add('events', $id, array('action' => 'destroy', 'was' => $event));
    
    // destroy the event
    return MongoLib::removeOne('events', $id);
  }

}

// EOT