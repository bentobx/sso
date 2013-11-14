<?php

/**
 * Run DAML commands from AQ
 *
 * @package mongoplus
 * @author dann toliver
 * @version 1.0
 */

require_once(str_replace('//','/',dirname(__FILE__).'/') .'../../../includer.php');

// check the time
list($micro, $start_time) = explode(' ', microtime());

do {
  // get an item from aq
  $item = Cthun::fetch('daml');
  
  if(!$item) // no daml items
    exit;

  // handle 'as'
  if($item['as']) {
    if($user = reset(UserLib::get_users("id = '{$item['as']}'"))) {
      SessionLib::add_user_to_globals($user);
      __build_commands();
    }
  }

  // TODO: handle 'keys'
  
  Processor::process_string($item['daml']);
  
  // stop after 57 seconds
  list($micro, $now) = explode(' ', microtime());
} while($now - $start_time < 57);
