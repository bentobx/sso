<?php

/**
 * Special commands for managing miso
 *
 * @package miso
 * @author dann toliver
 * @version 1.0
 */

class Admin
{
  
  /** 
  * Where is this member?
  *
  * Technically this does a member fetch first, to check perms. It's not fast or pretty, but it gets the job done.
  *
  * @param string Member id
  * @return array 
  * @key __member __lens __exec
  */ 
  static function where($is) 
  {
    $cfilter['member'] = MongoLib::fix_id($is);
    $cfilter['today'] = date('Y-m-d');
    $checkins = reset(MongoLib::find('checkins', $cfilter));
    
    if(!$checkins)
      return 'out';

    $last_checkin = end($checkins['pairs']);

    if($last_checkin['out']->sec > time()) // checkout is in the future (midnight), so we're still checked in
      return 'in';
    
    return 'out';
  } 
  
  
  /** 
  * Check someone in
  *
  * @param string A set of user ids or usernames
  * @param string A date
  * @return string 
  * @key admin
  */ 
  static function in($for, $date=NULL)
  {
    if(!ctype_digit((string) $for)) {
      $for = UserLib::get_ids_from_usernames($for);
      $for = reset($for);
    }
    $for = MongoLib::fix_id($for);
    
    $date = $date ? $date : 'now';
    $sdate = strtotime($date);
    $mdate = new MongoDate($sdate);
    $midnight = new MongoDate(strtotime(date('Y-m-d', $sdate) . ' + 1 day - 1 second'));
    
    if(!$member = MongoLib::findOne('members', MongoLib::fix_id($for)))
      return ErrorLib::set_error("That member is unavailable");
      
    // confirm that they're out
    if(Admin::where($member['_id']) == 'in' && date('Y-m-d', $sdate) == date('Y-m-d'))
      return ErrorLib::set_error("You aren't currently checked out");
    
    // check for plan status
    if($member['status'] == 'inbreach')
      return ErrorLib::set_error("You are currently in breach");
    
    // get checkins for targeted week
    $cfilter['member'] = $for;
    $cfilter['yearweek'] = date('Y-W', $sdate);
    $checkins_this_week = MongoLib::count('checkins', $cfilter);
    
    // any existing checkins from targeted date?
    $cfilter['today'] = date('Y-m-d', $sdate);
    if($checkins_for_date = MongoLib::findOne('checkins', $cfilter)) {
      $last_checkin_pair = end($checkins_for_date['pairs']);
    }

    // if we're adding a checkin to a day with checkins already, don't run the overage checking
    if(!$checkins_for_date) {
    
      // check for plan excesses
      // TODO: make this trigger an event
      // if($member['plan'] == 'never') {
      //   ErrorLib::set_warning("You will be charged extra");
      //   $trouble = true;
      // }
      if($member['plan'] == '1x' && $checkins_this_week >= 1) {
        ErrorLib::set_warning("You will be charged extra");
        $trouble = true;
      }
      if($member['plan'] == '3x' && $checkins_this_week >= 3) {
        ErrorLib::set_warning("You will be charged extra");
        $trouble = true;
      }
    }
    
    if($trouble) {
      ErrorLib::log_array(array('trouble!'));
      
      $user = UserLib::get_user_by_id($for);
      $profile = MongoLib::findOne('profiles', $for);
      $member = MongoLib::findOne('members', $for);
      $times = 0 + substr($member['plan'], 0, 1);
      
      // con mail
      
      $conmess = <<<EOT
Hello Concierge,

{$profile['my']['firstname']} {$profile['my']['lastname']} has checked in to Miso more times than the {$member['plan']} plan allows. To add a charge for this overage, please go to:

https://bentomiso.chargify.com/subscriptions/{$member['depot']['chargify_subscription']}/components/8584/usages/new

Set the quantity to 1, and record today’s date in the memo field.

All recorded usage for a billing period will be tallied and charged at the end of the period.
—The Bento Miso Bot
      
EOT;
      mail('concierge@bentobox.net, dann@bentobox.net', "Uh-oh. Looks like someone's in trouble...", $conmess, "From:thesystem@bentomiso.com");
      
      
      // member mail
      
      $memmess = <<<EOT
Hello {$profile['my']['firstname']},

Thanks for dropping in today! Your plan gives you access to Miso $times day per week and you've used up your days this week. We will add a drop-in charge to your monthly bill (unless we've made other arrangements with you).

Thanks for dropping in today! Your plan gives you access to Miso $times day per week and you've used up your days this week. We've added a drop-in charge ($20 + HST) to your monthly statement. Any extra days are tallied and charged at the end of your billing period. Please let us know if you have any questions about drop-in days, or if you would like to upgrade your membership.

-- Concierge
      
EOT;
      mail($member['depot']['email'], "You have used a drop-in day", $memmess, "From:concierge@bentomiso.com");
    }
    
    // all clear!
    
    // target is today?
    if(date('Y-m-d', $sdate) == date('Y-m-d')) {
      // this checkin is later than any other checkin?
      if(!$last_checkin_pair || $last_checkin_pair['in']->sec < $sdate) {
        // then update member
        $filter['_id'] = $for;
        $mupdate = array('last' => $mdate);
        MongoLib::set('members', $filter, $mupdate);
      }
    }
    
    if($checkins_for_date) {
      if($last_checkin_pair && $last_checkin_pair['in']->sec > $sdate) {
        // this is an admin checkin earlier in the day than the last check-in for that day.
        // TODO: figure out what to do when this happens...

        // $pairs[$sdate] = array('in' => $mdate, 'out' => $midnight);
        // foreach($checkins_for_date as $checkin) 
        //   $pairs[$checkin['in']->sec] = $checkin;
        // ksort($pairs);
        // foreach($pairs as $insec => $pair) {
        //   if($insec )
        // }
        // $pairs = array_values($pairs);

        $pairs = $checkins_for_date['pairs'];
      } else {
        // this checkin is later than the last checkin for this day
        $pairs = $checkins_for_date['pairs'];
        if($pairs[count($pairs) - 1]['out']->sec > $sdate) {
          // user forgot to check out from earlier
          // THINK: whaddya do?
        } else {
          // user properly checked out earlier
          $pairs[] = array('in' => $mdate, 'out' => $midnight);
        }
      }
      
    } else {
      $pairs = array(array('in' => $mdate, 'out' => $midnight));
    }
    
    $checkin['member'] = $for;
    $checkin['pairs'] = $pairs;
    $checkin['today'] = date('Y-m-d', $sdate);
    $checkin['yearweek'] = date('Y-W', $sdate);
    
    return MongoLib::upsert('checkins', $cfilter, $checkin);
  }
  
  
  /** 
  * Check someone out
  * 
  * @param string A username 
  * @param string A date 
  * @return string 
  * @key admin
  */ 
  static function out($for=NULL, $date=NULL)
  {
    if(!ctype_digit((string) $for)) {
      $for = UserLib::get_ids_from_usernames($for);
      $for = reset($for);
    }
    $for = MongoLib::fix_id($for);
    
    $date = $date ? $date : 'now';
    $sdate = strtotime($date);
    $mdate = new MongoDate(strtotime($date));
    
    if(!$member = MongoLib::findOne('members', MongoLib::fix_id($for)))
      return ErrorLib::set_error("That member is unavailable");
      
    // confirm that they're in, if it's today
    if(Admin::where($member['_id']) == 'out' && date('Y-m-d', $sdate) == date('Y-m-d'))
      return ErrorLib::set_error("You aren't currently checked in");
    
    // get existing checkins from targeted date
    $cfilter['member'] = $for;
    $cfilter['yearweek'] = date('Y-W', $sdate);
    $cfilter['today'] = date('Y-m-d', $sdate);
    if(!$checkin = MongoLib::findOne('checkins', $cfilter))
      return ErrorLib::set_error("No valid check-in for that day");
    
    // all clear!
    
    $pairs = $checkin['pairs'];
    $pairs[count($checkin['pairs']) - 1]['out'] = $mdate;
    $cupdate['$set']['pairs'] = $pairs;
    
    return MongoLib::update('checkins', $checkin['_id'], $cupdate);
  }
  
  
  /** 
  * The plan keeps coming up again
  * 
  * @param string A set of usernames or a single user id 
  * @param string The plan (accepts 1x, 3x, unlimited, supporting, cohort and never)
  * @return string 
  * @key admin
  */ 
  static function set_plan($for, $to)
  {
    if(!ctype_digit((string) $for)) 
      $for = UserLib::get_ids_from_usernames($for);
    
    if(!in_array($to, array('1x', '3x', 'unlimited', 'supporting', 'cohort', 'never'))) // NOTE: this is the only place you need to update plan names. I think. but they need to match the Chargify name...
      return ErrorLib::set_error("Invalid plan: $to");
    
    // all clear!
    
    $filter['_id'] = array('$in' => MongoLib::fix_ids($for));
    $update = array('plan' => $to);
    return MongoLib::set('members', $filter, $update, true);
  }
  
  
  /** 
  * Status determines your mechanical nature
  * 
  * @param string A username
  * @param string The status (accepts pending, rejected, trialing, paid or inbreach)
  * @return string 
  * @key admin
  */ 
  static function set_status($for, $to)
  {
    if(!ctype_digit((string) $for)) 
      $for = UserLib::get_ids_from_usernames($for);
    
    $date = $date ? $date : 'now';
    $date = new MongoDate(strtotime($date));
    
    if(!in_array($to, array('pending', 'rejected', 'trialing', 'paid', 'inbreach')))
      return ErrorLib::set_error("Invalid status: $to");
    
    // all clear!
    
    $filter['_id'] = array('$in' => MongoLib::fix_ids($for));
    $update = array('status' => $to);
    return MongoLib::set('members', $filter, $update, true);
  }
  
  
  /** 
  * Send an email to someone else -- careful!!!!
  * 
  * @param string Email address
  * @param string The message subject
  * @param string The message body
  * @return string 
  * @key admin __exec
  */ 
  static function sendmail($address, $subject, $body)
  {
    // TODO: add address etc checking here!
    
    // all clear!
    
    mail($address, $subject, $body, 'From:info@dmg.to');
  }
  

  
}

// EOT
