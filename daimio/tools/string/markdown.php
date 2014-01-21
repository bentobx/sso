
/**
* Apply textile to a string
* @param string The string
* @return string 
* @key __world
*/ 
static function markdown($on)
{
  $markdown = new Markdown();
  return $markdown->defaultTransform($on);
}
