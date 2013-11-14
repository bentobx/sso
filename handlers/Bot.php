<?php

/**
 * Control your bots
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class Bot
{
  
  /** 
  * Make a new bot 
  * @param string The type of bot to create
  * @param string The bot's username
  * @return string 
  */ 
  static function create($bot_type, $username)
  {
    $username = trim($username);
    
    // check bot type
    if(!DataLib::fetch('bot_types', "keyword = $bot_type"))
      return ErrorLib::set_error("No such bot type exists");
    
    // create bot entry
    $bot = array('username' => $username, 'bot_type' => $bot_type);
    return DataLib::input('bots', $bot);
  }
  
  
  /** 
  * Disable a bot 
  * @param string 
  * @return int 
  */ 
  static function disable($id)
  {
    $bot = array('disabled' => '1');
    return DataLib::input('bots', $bot, $id);
  }
  
  
  /** 
  * Undisable a bot 
  * @param string 
  * @return int 
  */ 
  static function undisable($id)
  {
    $bot = array('disabled' => '0');
    return DataLib::input('bots', $bot, $id);
  }
  
  
  
  /** 
  * Send in the bots 
  * @param string Bot id (leave blank to activate them all)
  * @return string 
  */ 
  static function activate($id=NULL)
  {
    if($id)
      $bots = DataLib::fetch('bots', "id = '$id'");
    else
      $bots = DataLib::fetch('bots', 'disabled = 0'); // OPT: do this one at a time instead of all at once
    
    $temps = DataLib::fetch('bot_types');
    foreach($temps as $bot_type)
      $bot_types[$bot_type['keyword']] = $bot_type;
    
    $old_vars = $GLOBALS['X']['VARS']['STATIC'];
    $old_user = $GLOBALS['X']['USER'];
    
    foreach($bots as $bot) {
      $user = UserLib::get_clean_user($bot['username']);
      SessionLib::add_user_to_globals($user);
      __build_commands();
      
      $bot_type = $bot_types[$bot['bot_type']];
      Processor::process_string($bot_type['actions']);
    }
    
    SessionLib::add_user_to_globals($old_user);
    $GLOBALS['X']['VARS']['STATIC'] = $old_vars;
    __build_commands();
  }
  

}

// EOT