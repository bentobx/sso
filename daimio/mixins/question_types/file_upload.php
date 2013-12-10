<?php

/**
 * Upload files and stuff
 *
 * @package bonsai
 * @author dann toliver
 * @version 2.0
 */

class Question_typesFile_upload
{
  
  /** 
  * Add a new answer to this question
  * If this returns false the adding process fails
  * For array returns, the 'answer' key allows complete control over the Answer array
  * The 'question' key allows pushing things into the question's locker (like stats n' stuff) 
  * @param array The test
  * @param array The protoquestion
  * @param array The question
  * @param array The answer
  * @return mixed 
  */ 
  static function add_answer($test, $pq, $question, $answer)
  {
    // could add stats info to question
    // could do cool stuff with the answer
    
    
    // is there a special answer already? then close up shop
    $first_answer = reset(MongoLib::find('answers', array('question' => $question['_id'], 'invalid' => array('$ne' => true))));
    
    if($first_answer['input'] == array('special' => 'nofile'))
      return ErrorLib::set_error("You must invalidate the No File answer before answering again");
    
    // is this a special answer (with no file upload)? fix it good
    if($answer['input']['special'] == 'nofile' && !$_FILES['input']['name']) {
      // if there's other answers already, this is an error
      if($first_answer)
        return ErrorLib::set_error("You must invalidate the existing files before setting this question to No File");
      
      // otherwise, fix the answer to exclude any files
      $modifier['answer']['input']['special'] = 'nofile';
      return $modifier;
    }

    
    // check for file
    if(!$_FILES['input']['name'])
      return ErrorLib::set_error("No file selected");
    
    // build the path
    $path = "/uploads/bonsai/{$test['_id']}/{$question['_id']}/";
    $webpath = $GLOBALS['X']['VARS']['SITE']['path'] . $path;
    $path = FileLib::build_path($path);
    
    // twiddle the file
    $file = $_FILES['input'];
    $filename = $file['name'];
    $safe_filename = QueryLib::scrub_string($filename, '_', '_.');
    $file_path = "$path/$safe_filename";
    
    // move the file
    rename($file['tmp_name'], $file_path);
    chmod($file_path, 0664);
    
    // set up input
    $result['answer']['input']['path'] = "$webpath/$safe_filename";
    $result['answer']['input']['filename'] = $filename;
    
    return $result;
  }
  
  /** 
  * Return a set of virtual scores
  * This takes a SET of answers -- you need to handle all of them
  * @param array The protoquestion
  * @param array The question
  * @param array A set of answers
  * @return number 
  */ 
  static function get_vscores($pq, $question, $answers)
  {
    // score is the number of files uploaded
    $count = 0; // need to return zero instead of false or null
    foreach($answers as $answer) 
      if($answer['input'] != array('special' => 'nofile'))
        $count++;
      
    return $count;
  }
  
  
  /** 
  * Returns true if the question is complete  
  * @param array The test
  * @param array The protoquestion
  * @param array The question
  * @return string 
  */ 
  static function is_complete($test, $pq, $question)
  {
    // get some answers
    $answers = MongoLib::find('answers', array('question' => $question['_id']));
    
    foreach($answers as $answer) {
      if(!$answer['invalid']) {
        return true;
      }
    }
    
    return false;
  }
  
  /** 
  * Returns a fake answer
  * @return string 
  */ 
  static function get_fake_answer()
  {
    $x['special'] = 'nofile';
    return $x;
  }
  
}

// EOT