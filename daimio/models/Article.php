<?php

/**
 * Article commands for the beta blog
 *
 * @package betablog
 * @author dann
 * @version 1.0
 * @copyright Bento Box Media, Inc 2002-2009
 * @perms anon
 */

class Article
{
  
  // validates the item
  // TODO: move this somewhere else!
  private static function validize($item) {
    $collection = 'articles';
    $fields = array('name', 'author','pubdate', 'square');
    
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
  * @param string Event key -- needs to be URL-safe
  * @param string Event organizer id
  * @param string Supports sort, limit, skip, fields, nofields, count, i_can and attrs: {* (:limit 5 :skip 30 :sort {* (:name "-1")} :nofields (:pcache :scores))} or {* (:fields :name)} or {* (:count :true)} or {* (:tags :nifty)} or {* (:i_can :edit)}
  * @return string 
  * @key __world
  */ 
  static function find($by_ids=NULL, $by_name=NULL, $by_date_range=NULL, $by_author=NULL, $by_square=NULL, $by_key=NULL, $options=NULL)
  {
    if(isset($by_ids)) 
      $query['_id'] = array('$in' => MongoLib::fix_ids($by_ids));
    
    if(isset($by_name))
      $query['name'] = new MongoRegex("/$by_name/i");
		
		if(isset($by_key)) 
			$query['key'] = new MongoRegex("/$by_key/i");
			
		if(isset($by_author)) 
			$query['author'] = array('$in' => MongoLib::fix_ids($by_author));

    if(isset($by_date_range)) {
      $begin_date = $by_date_range[0];
      $begin_date = ctype_digit((string) $begin_date) ? $begin_date : strtotime($begin_date);
      
      $end_date = $by_date_range[1];
      $end_date = ctype_digit((string) $end_date) ? $end_date : strtotime($end_date);
      
      $query['pubdate']['$gte'] = new MongoDate($begin_date);
      $query['pubdate']['$lte'] = new MongoDate($end_date);
    }
    
    if(isset($by_square))
			$query['square'] = new MongoRegex("/$by_square/i");
		else if (!isset($by_ids))
		  $query['square'] = array('$in' => array('draft', 'pending', 'published'));
        
    return MongoLib::find_with_perms('articles', $query, $options);
  }
  
  
  /** 
  * Add a new article  
  * @return string 
  * @key admin blogger
  */ 
  static function add()
  {
    $article['name'] = false;
    $article['square'] = "draft";
    $article['pubdate'] = false;
    $article['author'] = $GLOBALS['X']['USER']['id'];  
    $article['valid'] = false;
    
    $id = MongoLib::insert('articles', $article);
    
    PermLib::grant_permission(array('articles', $id), "user:" . $GLOBALS['X']['USER']['id'], 'edit');;
    PermLib::grant_permission(array('articles', $id), "admin:*", 'root');
    
    History::add('articles', $id, array('action' => 'add'));
    
    return $id;    
  }

  /** 
  * Set the article's name 
  * @param string article id
  * @param string New name
  * @return string 
  * @key admin blogger
  */ 
  static function set_name($id, $value)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
    
    $value = Processor::sanitize($value);
    
    if(!$value || strlen($value) < 3 || strlen($value) > 200)
      return ErrorLib::set_error("Invalid article name");
    
    if($article['name'] == $value)
      return $id;
    
    // all clear!
    
    $update['name'] = $value;
    MongoLib::set('articles', $id, $update);

    History::add('articles', $id, array('action' => 'set_name', 'value' => $value));
    
    $article['name'] = $value;
    self::validize($article);
    
    return $id;
  }
  
  /** 
  * Set the author for the article 
  * @param string Article id
  * @param string Member id of author
  * @return string 
  * @key admin __exec
  */ 
  static function set_author($id, $value)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
      
    if($article['author'] == $value)
      return $id;
    
    if(!$author = MongoLib::findOne('members', $value)) 
      return ErrorLib::set_error("No such member exists");
      
    // all clear!
    
    $update['author'] = $value;
    MongoLib::set('articles', $id, $update);
    
    History::add('articles', $id, array('action' => 'set_author', 'value' => $value));
  }
  
  /** 
  * Sets the publication date for the article 
  * @param string
  * @param string 
  * @return string 
  * @key admin blogger
  */ 
  static function set_pubdate($id, $value)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");

    if(!$value = new MongoDate(ctype_digit((string) $value) ? $value : strtotime($value)))
      return ErrorLib::set_error("That is not a valid date");

    if($article['pubdate'] == $value)
      return $id;

    // all clear!

    $update['pubdate'] = $value;
    MongoLib::set('articles', $id, $update);

    History::add('articles', $id, array('action' => 'set_pubdate', 'value' => $value));

    $article['pubdate'] = $value;
    self::validize($article);

    return $id; 
  }
  
  /** 
	* Adds a URL token for the article 
	* @param string Event id
	* @param string Value of the key
	* @return string 
	* @key admin blogger
	*/ 
	static function set_key($id, $value)
	{     
		if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");

    if(!$value)
      return ErrorLib::set_error("This key has no value");

    if($article['key'] === $value)
      return $id;
    
    if(MongoLib::check('articles', array('key' => $value)))
	    return ErrorLib::set_error("An article with this key already exists");
    if($value != QueryLib::scrub_string($value, '_', '_.-'))
      return ErrorLib::set_error("Token is not URL-safe");
    
    // all clear!
    
    $update['key'] = $value;
    MongoLib::set('articles', $id, $update);

    History::add('articles', $id, array('action' => 'set_key', 'value' => $value));
    
    $article['key'] = $value;
    self::validize($article);
    
    return $id;	  	
	}
  
  /** 
  * Submits a draft article for approval 
  * @param string 
  * @return string 
  * @key __member
  */ 
  static function submit_draft($id)
  {
    ErrorLib::log_array(array("submit_draft $id", $id));
    
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
      
    if($article['square'] != "draft")
      return ErrorLib::set_error("Article must be in draft status for this action");
    
    // all clear!
    
    // can no longer be edited by author
    PermLib::revoke_permission(array('articles', $id), "user:" . $article['author'], 'edit');
    PermLib::grant_permission(array('articles', $id), "user:" . $article['author'], 'view');
    
    // update the event's square
    $update['square'] = 'pending';
    
    MongoLib::set('articles', $id, $update);

    History::add('articles', $id, array('action' => 'submit_draft'));
    
    return $id;    
  }
  
  /** 
  * Publish the article  
  * @return string 
  * @key admin __exec
  */ 
  static function publish($id)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
      
    if($article['square'] != "pending")
      return ErrorLib::set_error("Article must be in pending status for this action");
      
    if(!$article['valid'])
    return ErrorLib::set_error("This article is not valid.");  
    
    // now publicly viewable
    PermLib::grant_permission(array('articles', $id), "world:*", 'view');
    
    // update the article's square
    $update['square'] = 'published';
    MongoLib::set('articles', $id, $update);

    History::add('articles', $id, array('action' => 'publish'));  
    
    return $id;
  }
  
  /** 
  * Unpublish the article 
  * @param string Article id
  * @return string Article id
  * @key admin __exec
  */ 
  static function unpublish($id)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
      
    if($article['square'] == "draft")
      return $id;
 
    PermLib::grant_permission(array('articles', $id), "user:" . $article['author'], 'edit');
    PermLib::revoke_permission(array('articles', $id), "world:*", 'view');
        
    // update the event's square
    $update['square'] = 'draft';
    MongoLib::set('articles', $id, $update);

    History::add('articles', $id, array('action' => 'unpublish'));  
    
    return $id;
  }
  
  /** 
  * Deletes the article (still exists in DB; can be found by searching id directly or by_square="deleted"). Only admin can view deleted articles. 
  * @param string 
  * @return string 
  * @key admin __exec
  */ 
  static function delete($id)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
      
    if($article['square'] == "deleted")
      return $id;
 
    PermLib::revoke_permission(array('articles', $id), "user:" . $article['author'], 'edit');
    PermLib::revoke_permission(array('articles', $id), "user:" . $article['author'], 'view');
    PermLib::revoke_permission(array('articles', $id), "world:*", 'view');
        
    // update the event's square
    $update['square'] = 'deleted';
    MongoLib::set('articles', $id, $update);

    History::add('articles', $id, array('action' => 'delete'));  
    
    return $id;    
  }
  
  
  /** 
  * Allow article's author to make edits after having submitted for publication
  * @param string Article id 
  * @return string Article id
  * @key admin __exec
  */ 
  static function unlock($id)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
 
    if ($article['square'] == 'draft')
      return $id;
 
    PermLib::grant_permission(array('articles', $id), "user:" . $article['author'], 'edit');

    History::add('articles', $id, array('action' => 'unlock'));  
    
    return $id;    
  }
  
  /** 
  * Bars author from making further changes to a submitted article
  * @param string 
  * @return string 
  * @key admin __exec
  */ 
  static function lock($id)
  {
    if(!$article = MongoLib::findOne_editable('articles', $id))
      return ErrorLib::set_error("That article is not within your domain");
 
    if ($article['square'] == 'draft')
      return ErrorLib::set_error("Cannot lock a draft article");
 
    PermLib::revoke_permission(array('articles', $id), "user:" . $article['author'], 'edit');

    History::add('articles', $id, array('action' => 'lock'));  
    
    return $id;    
  }
  
  /** 
  * Destroy an article completely (this may *seriously* mess things up!) 
  * @param string article id
  * @return string 
  */ 
  static function destroy($id)
  {
    // check for production status
    if($GLOBALS['X']['SETTINGS']['production'])
      return ErrorLib::set_error("Destruction on production is strictly verboten!");
    
    // get event
    if(!$article = MongoLib::findOne('articles', $id))
      return ErrorLib::set_error("No such article exists");
    
    // all clear

    // add transaction to history
    History::add('articles', $id, array('action' => 'destroy', 'was' => $article));
    
    // destroy the event
    return MongoLib::removeOne('articles', $id);
  }
  
  /** 
  * Return an array of tags and counts  
  * @return array 
  * @key __world
  */ 
  static function tag_cloud()
	{
		// TODO: refactor this function into something more useful (like the field itself, perhaps)
		
		// NOTE: specific to articles.tags field
		$form = 'articles';
		$field_keyword = 'tags';
		$conditions = array('form' => $form, 'keyword' => $field_keyword);
		$field_array = QueryLib::get_matching_rows('form_fields', $conditions);
		$tag_field = current($field_array);
		$field_id = $tag_field['id'];
    
		// set up resonable defaults
		$posterize = $posterize ? $posterize - 1 : 8;
		$limit = $limit ? $limit : 20;
		
		// set up the link params
    // foreach($_GET as $key => $value)
    //  if($key != $field_name)
    //    $link_params[$key] = $value;    
		
		// get some bogo fields to filter
    // if(count($this->filter))
    // {
    //  $form_fields = Forms::get_form_fields($this->form_id);
    //  list($where_array, $from_string) = Forms::get_where_array($form_fields, $this->filter, $this->form_id);
    //  $from_string = $from_string ? "$from_string, $table_name" : ",$table_name";
    // }
		
		// complete the where array
    // if(count($where_array))
    //  $where_array[] = "fog.response_id = $table_name.id";
		$where_array[] = "fog.field_id = $field_id";
		$where_array[] = "fog.that_id = tags.id";
		$where_array[] = "articles.id = fog.this_id AND articles.status = 'live'";
		$where_string = 'WHERE ' . implode("\n AND ", $where_array);
		
		$sql = "
						SELECT tags.*, count(1) as count
						FROM
						form_option_glue as fog,
						form_tags as tags,
						form__articles as articles
						$from_string
						$where_string
						GROUP BY stripped_value
						ORDER BY count DESC
						LIMIT 0,$limit
					 ";

		$data_array = QueryLib::make_data_array_from_query($sql);
		
		if(!count($data_array))
			return array();
		
		// do the posterization
		foreach($data_array as $chunk)
		{
			if(!$minimum || $chunk['count'] < $minimum)
				$minimum = $chunk['count'];
			if($chunk['count'] > $maximum)
				$maximum = $chunk['count'];
		}
		
		$stepsize = ($maximum - $minimum) / $posterize;
		$stepsize = ($stepsize < 1) ? 1 : $stepsize;
		foreach($data_array as $key => $chunk)
		{
			// set the count
			$stripped_values[$key] = $chunk['stripped_value'];
			$data_array[$key]['count'] = floor(($chunk['count'] - $minimum) / $stepsize) + 1;
			
			// set the link tag
      // $link_params[$field_name] = $chunk['stripped_value'];
      // $data_array[$key]['taglink'] = Http::make_link($GLOBALS['X']['page_id'], $link_params);
			
			// set the fieldname
      // $data_array[$key]['field_name'] = $field_name;
		}

		// sort the data magically...
		if($data_array)
			array_multisort($stripped_values, SORT_ASC, $data_array);
		
		// and send 'em home alive
		return $data_array;
	}
}

// EOT