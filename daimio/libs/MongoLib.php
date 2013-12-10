<?php

/**
 * Wrapper for fancy mongo interaction n' stuff
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

class MongoLib {
  
  static $mongo_connection;
  static $mongo_db;
  
  static function initialize($override=false)
  {
    if(self::$mongo_connection && !$override)
      return true;
    
    self::$mongo_connection = new Mongo($GLOBALS['X']['SETTINGS']['mongo']['server']);
    self::$mongo_db = self::$mongo_connection->selectDB($GLOBALS['X']['SETTINGS']['mongo']['database']);
  }
  
  
  //
  // GET AND SET HELPERS
  //

  
  /** 
  * Create a dbref, performing an additional check for thing validity (use for acting on a thing)
  * @param string Collection name or (:collection :id) or a real dbref
  * @param string Thing id
  * @return DBRef 
  */ 
  static function createDBRef($collection, $id='')
  {
    // try unpacking collection
    if(!$id && is_array($collection)) {
      list($collection, $id) = array_values($collection);
    }
    
    if(!$id)
      return false;
    
    $id = MongoLib::fix_id($id);
    
    if(!MongoLib::check($collection, $id)) // OPT: should we skip this for {* get} queries?
      return false;
    
    return MongoDBRef::create($collection, $id);
  }

  /** 
  * returns a canonical dbref or false (use for finding and other places that don't need an existence check)
  * @param string the dbref (handles both thingies and real dbrefs)
  * @param string an id -- if this is set the ref is assumed to be the collection
  * @return array 
  */ 
  static function resolveDBRef($ref, $id='')
  {
    // ref is collection name, so pack it
    if($id && is_string($ref)) {
      $ref = array('$ref' => $ref, '$id' => MongoLib::fix_id($id));
    }
    
    // ref is a raw thingy, so repack it
    if(is_array($ref) && !$ref['$ref']) {
      list($collection, $id) = array_values($ref);
      if($collection && $id) {
        $new_ref = array('$ref' => $collection, '$id' => MongoLib::fix_id($id));
        $ref = $new_ref;
      }
    }
    
    if($ref != array('$ref' => $ref['$ref'], '$id' => $ref['$id']))
      return false;
    
    return $ref;
  }

  /** 
  * get a dbref thing 
  * @param string the dbref
  * @param string array of fields to return
  * @return array 
  */ 
  static function getDBRef($ref, $fields=array())
  {
    if(!$ref = MongoLib::resolveDBRef($ref))
      return false;
    
    list($collection, $id) = array_values($ref);
    
    return MongoLib::findOne($collection, $id, $fields);
  }
  
  
  /** 
  * Update part of a thing 
  * @param string collection name
  * @param string item filter
  * @param array values to set
  * @param array change multiple values -- careful!
  * @return string 
  */ 
  static function set($collection, $filter, $value, $multiple=false)
  {
    $update = array('$set' => $value);
    return MongoLib::update($collection, $filter, $update, $multiple);
  }
  
  /** 
  * Push a value into an array if it isn't already there
  * @param string collection name
  * @param string item filter
  * @param string path to array
  * @param mixed value to push
  * @param array change multiple values -- careful!
  * @return string 
  */ 
  static function addToSet($collection, $filter, $path, $value, $multiple=false)
  {
    $update = array('$addToSet' => array($path => $value));
    return MongoLib::update($collection, $filter, $update, $multiple);
  }
  
  /** 
  * Remove the value from the array
  * @param string collection name
  * @param string item filter
  * @param string path to array
  * @param mixed value to pull
  * @param array change multiple values -- careful!
  * @return string 
  */ 
  static function pull($collection, $filter, $path, $value, $multiple=false)
  {
    $update = array('$pull' => array($path => $value));
    return MongoLib::update($collection, $filter, $update, $multiple);
  }
  
  
  
  //
  // MONGO WRAPPERS
  //
  
  /** 
  * insert a value 
  * @param string collection name
  * @param array values to insert
  * @return string 
  */ 
  static function insert($collection, $value)
  {
    $options = array('safe' => false, 'fsync' => false); // TODO: make these true and catch the exceptions

    try {
      MongoLib::$mongo_db->$collection->insert($value, $options);
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }

    return $value['_id'];
  }
  
  /** 
  * update a value  
  * @param string collection name
  * @param string item filter
  * @param array values to set
  * @param array change multiple values -- careful!
  * @return string 
  */ 
  static function update($collection, $filter, $value, $multiple=false)
  {
    if(!$filter = self::fix_filter($filter))
      return false;
    
    $options = array('multiple' => (boolean) $multiple, 'upsert' => false); // TODO: add some safety here
    
    try {
      return MongoLib::$mongo_db->$collection->update($filter, $value, $options); // THINK: better return value would be nice
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  /** 
  * update a value if it exists; insert it otherwise 
  * @param string collection name
  * @param string item filter
  * @param array values to set
  * @return string 
  */ 
  static function upsert($collection, $filter, $value)
  {
    if(!$filter = self::fix_filter($filter))
      return false;
      
    $options = array('multiple' => false, 'upsert' => true); // TODO: add some safety here

    try {
      return MongoLib::$mongo_db->$collection->update($filter, $value, $options); // THINK: better return value would be nice
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  /** 
  * remove one item from a collection 
  * @param string collection name
  * @param string item filter
  * @return string 
  */ 
  static function removeOne($collection, $filter)
  {
    if(!$filter = self::fix_filter($filter))
      return false;
      
    $options = array('justOne' => true);

    try {
      return MongoLib::$mongo_db->$collection->remove($filter, $options);
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  /** 
  * are there any matching items?
  * @param string collection name
  * @param string item filter
  * @return array 
  */ 
  static function check($collection, $filter)
  {
    if(!$filter || !$collection)
      return false;
    
    if(!$filter = self::fix_filter($filter))
      return false;
    
    try {
      return (boolean) MongoLib::$mongo_db->$collection->findOne($filter, array('_id' => 1));
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  
  /** 
  * find just one item 
  * @param string collection name
  * @param string item filter
  * @param string item fields
  * @return string 
  */ 
  static function findOne($collection, $filter, $fields=array())
  {
    if(!$filter)
      return false;
    
    if(!$filter = self::fix_filter($filter))
      return false;

    if(!$fields)
      $fields = array();
    if(!is_array($fields))
      $fields = array($fields => 1);
    
    try {
      return MongoLib::$mongo_db->$collection->findOne($filter, $fields);
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  /** 
  * find items with _id in $ids
  * @param string collection name
  * @param string item ids
  * @param string item fields
  * @return string 
  */ 
  static function findIn($collection, $ids, $fields=array(), $find_options=array())
  {
    if(!$ids)
      return array();
    
    if(!is_array($ids))
      $ids = array($ids);
      
    $query['_id'] = array('$in' => MongoLib::fix_ids($ids));
    return MongoLib::find($collection, $query, $fields, $find_options);
  }
  
  
  /** 
  * find things with my edit permits
  * @param string collection name
  * @param string item query
  * @param string supports sort, limit, skip, fields, nofields, and count: {* (:limit 5 :skip "30" :sort {* (:name "-1")} :nofields (:pcache :scores) :fields :name)} or {* (:count :true)}
  * @param string the permission level required
  * @return array
  */ 
  static function find_with_perms($collection, $query=array(), $find_options=array(), $level='view')
  {
    $query = MongoLib::permizer($query, $level);
    return MongoLib::find($collection, $query, NULL, $find_options);
  }
  
  /** 
  * find a thing with my view permits
  * @param string collection name
  * @param string item query
  * @param string fields to return
  * @return array
  */ 
  static function findOne_viewable($collection, $query=array(), $fields=array())
  {
    if(!$query) return false;
    $query = MongoLib::permizer($query, 'view');
    return MongoLib::findOne($collection, $query, $fields);
  }
  
  /** 
  * find a thing with my edit permits
  * @param string collection name
  * @param string item query
  * @param string fields to return
  * @return array
  */ 
  static function findOne_editable($collection, $query=array(), $fields=array())
  {
    if(!$query) return false;
    $query = MongoLib::permizer($query, 'edit');
    return MongoLib::findOne($collection, $query, $fields);
  }
  
  /** 
  * find a thing with my root permits
  * @param string collection name
  * @param string item query
  * @param string fields to return
  * @return array
  */ 
  static function findOne_rootable($collection, $query=array(), $fields=array())
  {
    if(!$query) return false;
    $query = MongoLib::permizer($query, 'root');
    return MongoLib::findOne($collection, $query, $fields);
  }
  
  
  /** 
  * find items
  * @param string collection name
  * @param string item query
  * @param string fields to return
  * @param string options for doing fancy things
  * @return array
  */ 
  static function find($collection, $query=array(), $fields=array(), $find_options=array())
  {
    if(!$fields)
      $fields = array();
    if(!is_array($fields))
      $fields = array($fields => 1);
    
    if(!$query)
      $query = array();
    if(!is_array($query))
      return ErrorLib::set_error("Improperly formatted mongo query");
    
    // fancy things: sort, count, limit, skip, fields (only if we haven't set them)
    // OPT: skip is inefficient for pagination, use _id ranges instead
    if(is_array($find_options['sort']))
      foreach($find_options['sort'] as $key => $value) 
        $sort[$key] = $value == "-1" ? -1 : 1;
    
    if($find_options['count'])
      $count = true;
    
    if(ctype_digit((string) $find_options['limit']))
      $limit = intval($find_options['limit']);
    
    if(ctype_digit((string) $find_options['skip']))
      $skip = intval($find_options['skip']);
    
    if(!$fields && $find_options['fields'])
      foreach((array)$find_options['fields'] as $value)
        if(is_string($value))
          $fields[$value] = 1;
    
    if(!$fields && $find_options['nofields'])
      foreach((array)$find_options['nofields'] as $value)
        if(is_string($value))
          $fields[$value] = 0;
    
    if(PermLib::permissible($collection)) {
      if($find_options['i_can']) {
        $query = MongoLib::permizer($query, $find_options['i_can']);
      }
      foreach($find_options as $attr => $attr_query) {
        if(in_array($attr, $GLOBALS['X']['SETTINGS']['permission']['attrs'])) {
          // for queries like {* (:my *{ (:firstname :HAL :lastname 2000)} )}
          if(is_array($attr_query)) {
            foreach($attr_query as $q_key => $q_value) {
              if(substr($q_key, 0, 1) == '$') {
                $query[$attr][$q_key] = $q_value; // for queries like {* (:tags *{ ("$in" (:pathos :bathos :qathos) )} )}
              } else {
                $query["$attr.$q_key"] = $q_value; // recompose the query so we're searching by dot notation instead of matching objects
              }
            }
          } else {
            $query[$attr] = $attr_query;
          }
        } else {
          // for queries like {* (:my.firstname :HAL :my.lastname 2000)}
          $first_word = reset(explode('.', $attr, 2));
          if(in_array($first_word, $GLOBALS['X']['SETTINGS']['permission']['attrs'])) {
            $query[$attr] = $attr_query;
          }
        }
      }
    }
    
    try {
      // OPT: careful, this can get huge and break things!
      $cursor = MongoLib::$mongo_db->$collection->find($query, $fields);
      if($count)
        return $cursor->count();
      if($sort)
        $cursor->sort($sort);
      if($limit)
        $cursor->limit($limit);
      if($skip)
        $cursor->skip($skip);
      return iterator_to_array($cursor);
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  /** 
  * count items
  * @param string collection name
  * @param string item query
  * @return string 
  */ 
  static function count($collection, $query)
  {
    try {
      return MongoLib::$mongo_db->$collection->count($query);
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  /** 
  * remove items
  * @param string collection name
  * @param string item query
  * @return string 
  */ 
  static function remove($collection, $query)
  {
    try {
      return MongoLib::$mongo_db->$collection->remove($query);
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  
  //
  // BRANCH STUFF
  //
  
  
  static function slice_branch($tree, $path) {
    if(!$path)
      return $tree;
    
    $path_parts = explode('.', $path);
    foreach($path_parts as $part)
      $tree = $tree[$part];
    
    return $tree;
  }
  
  static function set_branch($collection, $tree_id, $path, $branch) {
    if(!$tree_id = intval($tree_id))
      return ErrorLib::set_error("Invalid tree id");
    
    $filter = array('_id' => $tree_id);
    if($path)
      $update = array('$set' => array($path => $branch));
    else
      $update = $branch;
    $options = array('multiple' => false);

    try {
      self::$mongo_db->$collection->update($filter, $update, $options);
    } catch(Exception $e) {
      ErrorLib::set_error("There was an error in the Mongo query");
      ErrorLib::log_array(array($e));
      return false;
    }
  }
  
  
  
  
  //
  // EXECUTION AND ACTIONS
  //
  
  /** 
  * execute a js string
  * @param string the js to execute
  * @return string 
  * @key __world
  */ 
  static function execute_js($script)
  {
    // set up js content
    $path = "{$GLOBALS['X']['SETTINGS']['site_directory']}/daimio/bonsai/mongo_js_functions";
    $content = "load('$path/scoring.js');\n $script";
    
    // make a temp .js file
    $tempfile = FileLib::create_file('script.js', 'daimio/temp/mongojs', $content, array('unique' => true));
    
    // run the file in mongo
    $db = $GLOBALS['X']['SETTINGS']['mongo']['database'];
    exec("mongo $db $tempfile > /dev/null &");
    
    // TODO: scrub files older than 5 minutes w/ prob 1:40
    // FIXME: definite race condition. poo!
    // THINK: race condition with unlinking files here?
    // unlink($tempfile);
  }
  
  
  //
  // these are old and not really used right now...
  //
  
  
  static function activate($actions, $data=array()) {
    $vars = array();
    
    if($data && !is_array($data))
      $data = (array) $data;
  
    foreach($actions as $name => $value) {
      list($parser, $commands) = $value;
      
      // include parser
      if(!class_exists($parser, false)) {
        if(file_exists($class_file = "{$GLOBALS['X']['SETTINGS']['site_directory']}/daimio/mongo/parsers/$parser.php"))
          include_once $class_file;
        else
          return ErrorLib::set_error("File \"$class_file\" not found for parser \"$parser\" with commands \"$commands\"", 'mongo.activate');
      }
      
      $vars[$name] = call_user_func_array(array($parser, 'parse'), array($commands, $data, $vars));
    }
    
    return $vars;
  }
  
  static function walk_tree(&$tree, $operators) {
    // NOTE: each operator is an array($class, $method)
    // NOTE: operators need to take &$tree and $path their params
    if(!$operators || !is_array($operators))
      return ErrorLib::set_error("No valid operators found");
    
    // include operators 
    foreach($operators as $oper) {
      list($class, $method) = $oper;
      if(!class_exists($class, false)) {
        if(file_exists($class_file = "{$GLOBALS['X']['SETTINGS']['site_directory']}/daimio/mongo/operators/$class.php"))
          include_once $class_file;
        else
          return ErrorLib::set_error("File \"$class_file\" not found for operator \"$class\" with method \"$method\"", 'mongo.walk_tree');
      }
      if(!method_exists($class, $method))
        return ErrorLib::set_error("Method \"$method\" does not exist for class \"$class\"");
    }
    
    return self::do_walk_tree($tree, $operators);
  }
  
  static function do_walk_tree(&$tree, $operators, $path='') {
    foreach($operators as $oper)
      call_user_func_array($oper, array(&$tree, $path));
    
    if(!$tree)
      return false; // in case an oper chops it
    
    foreach($tree as $key => &$branch) 
      if(substr($key, 0, 1) != '_') 
        self::do_walk_tree($branch, $operators, $path ? "$path.$key" : $key);
  }
  
  /** 
  * find things with my view permits
  * @param string collection name
  * @param string item query
  * @param string supports sort, limit, skip, fields, nofields, and count: {* (:limit 5 :skip "30" :sort {* (:name "-1")} :nofields (:pcache :scores) :fields :name)} or {* (:count :true)}
  * @return array
  */ 
  static function find_editable($collection, $query=array(), $find_options=array())
  {
    return MongoLib::find_with_perms($collection, $query, $find_options, 'edit');
  }
  
  /** 
  * find things with my view permits
  * @param string collection name
  * @param string item query
  * @param string supports sort, limit, skip, fields, nofields, and count: {* (:limit 5 :skip "30" :sort {* (:name "-1")} :nofields (:pcache :scores) :fields :name)} or {* (:count :true)}
  * @return array
  */ 
  static function find_viewable($collection, $query=array(), $find_options=array())
  {
    return MongoLib::find_with_perms($collection, $query, $find_options, 'view');
  }
  
  //
  // FILES
  //
  
  
  /** 
  * Add a file to a btree 
  * @param string
  * @param string
  * @return string
  */ 
  static function add_file($id, $file, $metadata)
  {
    if(!$id)
      return ErrorLib::set_error("No btree id provided");
    
    if(!$file || !$_FILES[$file] || !$_FILES[$file]['size'] || !$_FILES[$file]['type'])
      return ErrorLib::set_error("No file provided");    
    
    // get grid connection
    $db = MongoLib::$bonsai_db;
    $grid = $db->getGridFS();
    
    // store file
    $name = $_FILES[$file]['name']; 
    $file_id = $grid->storeUpload($file, $name);
        
    if(!$file_id)
      return ErrorLib::set_error("File could not be created");
    
    $metadata['btree_id'] = $id;
    $metadata['type'] = $_FILES[$file]['type'];
    
    return Btree::edit_file($file_id, $metadata);
    
    // $files = (array) Btree::get_branch($id, '__roots.files');
    // $files[] = $file_id;
    // $branch = array('__roots.files' => $files);
    // return Btree::update($id, $branch);
  }


  
  /** 
  * Edit a file's metadata 
  * @param string
  * @param array
  * @return string
  */ 
  static function edit_file($file_id, $metadata)
  {
    // get grid connection
    $db = MongoLib::$bonsai_db;
    $grid = $db->getGridFS();

    $query['_id'] = MongoLib::fix_id($file_id);
    $metadata = array('$set' => array('metadata' => $metadata));
    
    return $grid->update($query, $metadata);  
  }


  /** 
  * Get all the files for a btree 
  * @param string 
  * @return array
  */ 
  static function get_files($id)
  {
    // get connection
    $db = MongoLib::$bonsai_db;
    
    $result = array();
    $query['metadata.btree_id'] = $id;
    $cursor = $db->fs->files->find($query);
    
    foreach ($cursor as $this_id => $value) {
      $result[$this_id]['_id'] = $value['_id'];
      $result[$this_id]['length'] = $value['length'];
      $result[$this_id]['filename'] = $value['filename'];
      $result[$this_id]['metadata'] = $value['metadata'];
      $result[$this_id]['uploadDate'] = $value['uploadDate']->sec;
    }
        
    return $result;    
  }
  
  /** 
  * Disassociate a file from a btree 
  * @param string 
  * @return boolean
  */ 
  static function remove_file($file_id)
  {
    // get grid connection
    $db = MongoLib::$bonsai_db;

    $query['_id'] = MongoLib::fix_id($file_id);
    $file = $db->fs->files->findOne($query);

    $file['metadata']['old_btree_id'] = $file['metadata']['btree_id'];
    unset($file['metadata']['btree_id']);
    
    return $db->fs->files->update($query, $file);  
  }
  
  
  /** 
  * Write a specific file 
  * @param string 
  * @return string 
  */ 
  static function write_file($file_id)
  {
    // get grid connection
    $db = MongoLib::$bonsai_db;
    $grid = $db->getGridFS();
    
    $query['_id'] = MongoLib::fix_id($file_id);
    $file = $grid->findOne($query);

    $site_dir = $GLOBALS['X']['SETTINGS']['site_directory'];
    $site_path = $GLOBALS['X']['VARS']['SITE']['path'];
    // $dir = FileLib::build_path('tempfiles');
    $filename = $file->file['filename'];
    $rand = dechex(rand(1048576, 16777215));
    
    $path = "$site_dir/tempfiles/{$rand}-$filename";
    $webpath = "$site_path/tempfiles/{$rand}-$filename";

    if(!$file)
      return ErrorLib::set_error("Could not find the requested file");
    
    $file->write($path);
    
    return $webpath;
    
    // 
    // $cursor = $db->fs->chunks->find(array("file_id" =>
    // $file->file['_id']))->sort(array("n" => 1));
    // 
    // foreach($cursor as $chunk) {
    //   echo $chunk['data'];
    // }
    // 
    // MongoGridFSFile then call write(). Followed by
    // > readfile($theFileIJustSaved);
    //     
  }

  
  //
  // GENERAL HELPER
  //
  

  /** 
  * quick fix for filter stuff 
  * @param mixed
  * @return array 
  */ 
  static function fix_filter($filter)
  {
    if(!$filter)
      return array();
    
    // default to _id
    if(!is_array($filter)) {
      $id = self::fix_id($filter);
      return array('_id' => $id); // NOTE: this prevents string queries (strings parse as $where)
    }
    
    // no $ in top-level keys [as first char, so we can still use keys like "things.$id"]
    $whitelist = array('$or', '$and', '$nor'); // seemingly harmless top-level query keys
    $keys = join(array_keys($filter), '');
    if(strpos($keys, '$') === 0) {
      foreach($keys as $key) {
        if(!in_array($key, $whitelist) && strpos($key, '$') != -1) {
          return ErrorLib::set_error("Invalid filter"); // non-whitelist $ operator
        }
      }
    }
    
    return $filter;
  }
  
  /** 
  * Fix an array of ids 
  * @param array Ids
  * @return array 
  */ 
  static function fix_ids($ids)
  {
    $fixed_ids = array();
    
    if(!$ids)
      return $fixed_ids;
    
    if(!is_array($ids))
      $ids = array($ids);
    
    foreach($ids as $id)
      if($id)
        $fixed_ids[] = MongoLib::fix_id($id);
    
    return $fixed_ids;
  }
  
  /** 
  * changes a string into a mongoid 
  * @param string 
  * @return object 
  */ 
  static function fix_id($id)
  {
    if(is_a($id, 'MongoId')) 
      return $id; // native mongo id class
      
    if(strlen($id) == 24 && ctype_xdigit((string) $id))
      return new MongoId($id); // string -> native mongo id class
    
    if(ctype_digit((string) $id))
      return intval($id); // imported mysql id
    
    return $id; // unique string as id
  }
  
  /** 
  * Add perm restrictions to a query filter 
  * @param string The query filter
  * @param string A level, like view or edit or root
  * @return array 
  */ 
  static function permizer($filter, $level)
  {
    // bypass perm check inside {permit superdo}
    if(in_array('__perm', $GLOBALS['X']['USER']['keychain']))
      return $filter;

    // bypass perm check for wizards
    if($GLOBALS['X']['USER']['wizard'])
      return $filter;

    $filter = MongoLib::fix_filter($filter);
    $permits = PermLib::get_mine();
    $filter["pcache.$level"] = array('$in' => $permits);
    
    return $filter;
  }
  
  /** 
  * Is this a good path? 
  * @param string String
  * @return boolean 
  */ 
  static function good_path($path)
  {
    if(preg_match("/[^\w.-]/", $path))
      return ErrorLib::set_error("Bad path");

    return true;
  }
  
  
  /** 
  * Log all the items n' stuff 
  * @param string 
  * @return string
  */ 
  static function log_array($items)
  {
    return ErrorLib::log_array(MongoLib::stringify($items));
  }
  
  
  /** 
  * Recursively convert all Mongoids to strings in an array 
  * @param array Some values
  * @return array 
  */ 
  static function stringify($array)
  {
    foreach($array as $key => $value) {
      if(is_a($value, 'MongoId'))
        $array[$key] = (string) $value;
      if(is_array($value))
        $array[$key] = self::stringify($value);
    }
    
    return $array;
  }
  
  /** 
  * Extract seconds since the epoch from a MongoId object or MongoDate
  * @param string MongoId or MongoDate
  * @return int 
  */ 
  static function extract_time($from='')
  {
    if(is_a($from, 'MongoDate'))
      return $from->sec;
    
    if(strlen($from) == 24 && ctype_xdigit((string) $from))
      $from = new MongoId($from); // string -> native mongo id class

    if(is_a($from, 'MongoId')) 
      return $from->getTimestamp($from);
    
    if(ctype_digit((string) $from))
      return $from; // already a timestamp
    
    return ErrorLib::set_error("No valid id or date found");
  }
  
  
  /** 
  * Create a bifurcated entity, keyed on the autoincrement id from mysql
  * @param string Name of the bifurcated entity collection / table
  * @param string Mongo data
  * @param string Shared data that goes to both mongo and mysql
  * @param string Mysql data
  * @return id
  */ 
  static function set_bifurcated_data($collection, $mongo, $shared=array(), $tagalog=array())
  {
    // check collection
    if(!$collection || !is_string($collection))
      return ErrorLib::set_error("No proper collection give");
    
    // check mongo array
    if(!$mongo || !is_array($mongo))
      return ErrorLib::set_error("No proper mongo array give");
    
    // mysql side
    $mysql = array();
    if($tagalog)
      $mysql = array_merge($mysql, $tagalog);
    if($shared)
      $mysql = array_merge($mysql, $shared);

    if(!$mysql)
      $mysql['user'] = 1; // this is a bit of a hack, because we need to pass _something_ into DataLib::input.

    if(!$id = DataLib::input($collection, $mysql))
      return ErrorLib::set_error("That bifurcated entity could not be created");
    
    // mongo side
    if($shared)
      $mongo = array_merge($mongo, $shared);
    
    $mongo['_id'] = $id;
    MongoLib::insert($collection, $mongo);
    
    return $id;
  }
  
  
  /** 
  * Get data from an object that's part in mongo and part in mysql 
  * @param string Type (like contracts, projects, bids or companies)
  * @param string A valid mongo query
  * @param string If true, include mysql data
  * @return array 
  */ 
  static function get_bifurcated_data($type, $query, $with, $find_options=array())
  {
    if(!$query)
      $query = array();
    
    // if(!$query = MongoLib::fix_filter($query))
    //   return false;
    $query = MongoLib::fix_filter($query);

    // TODO: this __lens override is really bad news. do something much better than this.
    // if(in_array('__lens', $GLOBALS['X']['USER']['keychain']))
    //   $things = MongoLib::find($type, $query, NULL, $find_options);
    // else
    //   $things = MongoLib::find_with_perms($type, $query, $find_options);
    $things = MongoLib::find_with_perms($type, $query, $find_options);
    
    if(!$things)
      return array();
     
    if($with == 'dform') {
      $thing_id_string = implode(',', array_keys($things));
      $mysql_things = DataLib::fetch($type, "id %= ($thing_id_string)");
      foreach($things as $key => $thing) {
        if(is_array($mysql_things[$key]))
          $combo[$key] = $thing + $mysql_things[$key];
        else
          $combo[$key] = $thing;
      }
      $things = $combo;
    }
    
    return $things;
  }
  
  
}

// EOT