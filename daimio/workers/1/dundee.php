<?php

/**
 * Wrangle hippos and other beasties
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

require_once(str_replace('//','/',dirname(__FILE__).'/') .'../../../includer.php');

// check the time
list($micro, $start_time) = explode(' ', microtime());

// get the hippos
$hippos = MixMaster::get_mixins('hippos');

do {
  // get an item from history and unmark as ~new
  $result = MongoLib::$mongo_db->command(
    array(
      'findandmodify' => 'history',
      'sort' => array('_id' => 1),
      'query' => array('new' => true),
      'update' => array('$unset' => array('new' => 1))
    )
  );
  
  if(!$result['ok']) // no new history items
    exit;

  // set up params
  $params['item'] = $result['value'];

  // feed it to the hippos
  foreach($hippos as $hippo) {
    MixMaster::make_the_call('hippos', 'chomp', $hippo, $params);
  }
  
  // stop after 57 seconds
  list($micro, $now) = explode(' ', microtime());
} while($now - $start_time < 57);
