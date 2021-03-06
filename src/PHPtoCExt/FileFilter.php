<?php 
namespace PHPtoCExt;

class FileFilter 
{
  private $sourceFile;
  private $targetFile;
  private $inputDir;

  private $postSearches;
  private $postReplaces;

  private $converter;

  private $codeLines;
  private $codeASTXMLLines;

  public function __construct($sourceFile, $targetFile, $inputDir)
  {
    $this->sourceFile = $sourceFile;
    $this->targetFile = $targetFile;
    $this->inputDir = $inputDir;
    $this->postSearches = array();
    $this->postReplaces = array();
  }

  public function filter()
  {
    $sourceFileContent = trim(file_get_contents($this->sourceFile));  

    //first, remove all comments in file content 
    $sourceFileContent = $this->removeAllComments($sourceFileContent);
    $sourceFileContent = $this->putBracketsInNewLine($sourceFileContent);
    $sourceFileContent = $this->removeBlankLines($sourceFileContent);

    $parser = new \PhpParser\Parser(new \PhpParser\Lexer);
    $serializer = new \PhpParser\Serializer\XML();

    try { 
      //load all converters, order is very important
      $converterClasses = array(
        "\PHPtoCExt\Converter\TraitMergingConverter",
        "\PHPtoCExt\Converter\ForLoopToWhileLoopConverter",
        "\PHPtoCExt\Converter\PrintToEchoConverter",
        "\PHPtoCExt\Converter\ModuloCastingConverter",
        "\PHPtoCExt\Converter\IssetToNotEmptyConverter",
        "\PHPtoCExt\Converter\ClassHierarchyFlatterningConverter",
        "\PHPtoCExt\Converter\StaticVarAutoDefineConverter",
        "\PHPtoCExt\Converter\SelfStaticConverter",
        "\PHPtoCExt\Converter\CodeReformatConverter", //reformat the code and get ready for the final conversion 
        "\PHPtoCExt\Converter\CFunctionAutoConverter", //convert c_function_auto to c_function_call
        "\PHPtoCExt\Converter\CFunctionCallConverter", //convert c function calls
      );

      $searches = array();
      $replaces = array();
      $postSearches = array();
      $postReplaces = array();
      //go through all converters to convert the source code 
      foreach ($converterClasses as $converterClass) {
        $stmts = $parser->parse($sourceFileContent);
        $codeLines = explode("\n", $sourceFileContent);
        $codeASTXML = $serializer->serialize($stmts);
        $codeASTXMLLines = explode("\n", $codeASTXML);

        $this->codeLines = $codeLines;
        $this->codeASTXMLLines = $codeASTXMLLines;

        $this->converter = new $converterClass($codeLines, $codeASTXMLLines, $this->inputDir);
        $this->converter->convert();
        $searches = $this->converter->getSearches();
        $replaces = $this->converter->getReplaces();
        $sourceFileContent = str_replace($searches, $replaces, $sourceFileContent);

        $postSearches = array_merge($postSearches, $this->converter->getPostSearches());
        $postReplaces = array_merge($postReplaces, $this->converter->getPostReplaces());
      }

      file_put_contents($this->targetFile, $sourceFileContent);

      //add post searches and replaces 
      $this->postSearches = $postSearches;
      $this->postReplaces = $postReplaces;

    } catch (\PhpParser\Error $e) {
      throw new PHPtoCExtException("PHP Parser Error: ".$e->getMessage());
    }

  } 

  public function postFilter($file)
  { 
    $content = file_get_contents($file);

    $searchCount = count($this->postSearches);

    for ($i = 0; $i < $searchCount; $i++) {
      $content = str_replace($this->postSearches[$i], $this->postReplaces[$i], $content); 
    }

    $content = $this->removeBlankLines($content);
    file_put_contents($file, $content);
  }

  public function getCodeLines()
  {
    return $this->codeLines;
  }

  public function getCodeASTXMLLines()
  {
    return $this->codeASTXMLLines;
  }

  public function getInputDir()
  {
    return $this->inputDir;
  }

  public function getClassMap()
  {
    return $this->converter->getClassMap();
  }

  /**
   * remove all comments in php code 
   */
  private function removeAllComments($content)
  {
    //first remove all multiline comments
    $content = preg_replace('!/\*.*?\*/!s', '', $content);
    $content = preg_replace('/\n\s*\n/', "\n", $content);

    //now it is time to remove all single line comments
    $lines = explode("\n",$content);
    foreach($lines as $index => $line) {
      $line = preg_replace('~#[^\r\n]*~','',$line);
      $line = preg_replace('~//[^\r\n]*~','',$line);
      $line = preg_replace('~/\*.*?\*/~s','',$line);
      $lines[$index] = $line;
    }
    $result = implode("\n",$lines);
    return $result;
  }

  private function putBracketsInNewLine($content)
  {
    $lines = explode("\n",$content);
    $result = "";
    foreach($lines as $index => $line) {
      $indentLevel = strlen($line) - strlen(ltrim($line));
      $indentation = str_repeat(" ", $indentLevel);
      $lines[$index] = str_replace(array("{","}"), array("\n".$indentation."{", "\n".$indentation."}"), $line);
    }

    $result = implode("\n", $lines);

    return $result;
  }

  private function removeBlankLines($content)
  {
    //now remove all blank lines, credit: http://stackoverflow.com/questions/709669/how-do-i-remove-blank-lines-from-text-in-php   
    $content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $content);
    return $content;
  }
}
