<?php

/**
 * The File Attribute holds non-user editable (but still permission-based) data.
 *
 * This is a helper for storing files. A user's profile picture, for example, would be attached to their profile using this attribute handler. You'll need to wrap this in an exec. Unlike most attributes, this one only works on one item at a time.
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class File
{
  
  /** 
  * Put a file reference into depot 
  *
  * Remember this requires a file upload field, so you can't run it directly from the terminal.
  *
  * @param array A thing is a collection and an id, like (:albums :keep_it_like_a_secret)
  * @param string Dot-delimited depot path: e.g. "face.silhouette" would be accessed as @thing.depot.face.silhouette
  * @param string Name of the file upload field
  * @return boolean 
  * @key __exec __trigger
  */ 
  static function set($thing, $path, $file)
  {
    if(!PermLib::permissible($thing[0]))
      return ErrorLib::set_error("Non-permissible collection");

    if(!MongoLib::good_path($path))
      return false; // error inside
    
    list($collection, $id) = array_values(MongoLib::resolveDBRef($thing));
    
    // check perms before we save the file
    if(!$thing = MongoLib::getDBRef($thing, array('perms')))
      return ErrorLib::set_error("Invalid thing");
      
    if(!PermLib::i_can('edit', $thing['perms']))
      return ErrorLib::set_error("You don't have permission to edit that thing");
    
    // check for file
    $file = $_FILES[$file];
    if(!$file['name'])
      return ErrorLib::set_error("No file selected");
    
    // all clear!
    
    // THINK: should we convert this to using GridFS instead?
    
    // build the path
    $salt = substr(base64_encode(pack("H*", sha1(mt_rand()))), 0, 8);
    $salt = QueryLib::scrub_string($salt, '_', '_.-', true);
    $filepath = "/uploads/depot/{$thing['_id']}/$salt/";
    $webpath = $GLOBALS['X']['VARS']['SITE']['path'] . $filepath;
    $filepath = FileLib::build_path($filepath);

    // twiddle the file
    $filename = $file['name'];
    $safe_filename = QueryLib::scrub_string($filename, '_', '_.');
    $file_path = "$filepath/$safe_filename";

    // move the file
    rename($file['tmp_name'], $file_path);
    chmod($file_path, 0664);

    // save it
    $update = array("files.$path" => "$webpath/$safe_filename");
    return MongoLib::set($collection, $id, $update);
  }

}

// EOT