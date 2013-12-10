<?php

/**
 * Convert HTML into a PDF (wrapper for dompdf)
 *
 * @package daimio
 * @author dann toliver
 * @version 1.0
 */

class Domp
{
  
  /** 
  * Create a new pdf
  * @param string Some html to convert
  * @param string Name of pdf file
  * @param string Paper size, like "legal" or "letter" (defaults to letter)
  * @param string Orientation (accepts portrait and landscape, defaults to landscape) 
  * @return string 
  * @key __world
  */ 
  static function convert($html, $filename, $paper='', $orientation='')
  {
    // HAXXZORZ
    require_once($GLOBALS['X']['SETTINGS']['site_directory'] . '/dompdf/dompdf_config.inc.php');
    // END HAXXZORZ
    
    // set up paper and orientation
    if(!$paper)
      $paper = 'letter';
    if($orientation != 'portrait')
      $orientation = 'landscape';

    $dompdf = new DOMPDF();
    $dompdf->load_html($html);
    $dompdf->set_paper($paper, $orientation);
    $dompdf->render();
    $pdf = $dompdf->output();
    
    $filename = basename($filename);
    $long_filename = FileLib::create_file($filename, 'uploads/pdfs', $pdf, array('unique' => true));
    
    return $GLOBALS['X']['VARS']['SITE']['path'] . "/uploads/pdfs/" . basename($long_filename);
  }
  
  /** 
  * Convert pdfs into pngs
  * @param string PDF filename
  * @param string PNG filename
  * @return string 
  * @key __member
  */ 
  static function pdftopng($pdf, $png='')
  {
    $pdf = basename($pdf);
    $pdf_path = $GLOBALS['X']['SETTINGS']['site_directory'] . "/uploads/pdfs/$pdf";

    $png = $png ? basename($png) : $pdf . '.png';
    $png_path = $GLOBALS['X']['SETTINGS']['site_directory'] . "/uploads/pngs/$png";

    // $command = "/opt/local/bin/convert $pdf_path -resize 100x100 $png_path";
    $command = "/opt/local/bin/convert $pdf_path $png_path";
    
    exec($command, $msg, $return_val);

    return $GLOBALS['X']['VARS']['SITE']['path'] . "/uploads/pngs/" . $png;
  }
  

}

// EOT