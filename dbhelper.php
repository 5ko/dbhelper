<?php if (!defined('PmWiki')) exit();
/**
  Database-helper for PmWiki
  Written by (c) Petko Yotov 2017-2023   www.pmwiki.org/Petko

  This text is written for PmWiki; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published
  by the Free Software Foundation; either version 3 of the License, or
  (at your option) any later version. See pmwiki.php for full details
  and lack of warranty.
*/
$RecipeInfo['DBHelper']['Version'] = '20230131';

function ConnectDB($x=false) {
  static $pdo, $spec;
  if(is_array($x)) {
    $spec = array_merge(array('charset'=>'utf8'), $x);
    
    pm_session_start();
    $errormode = isset($_SESSION['authlist']['@admins'])? 
      PDO::ERRMODE_WARNING : PDO::ERRMODE_SILENT;

    $dsn = "mysql:host={$spec['host']};dbname={$spec['dbname']};charset={$spec['charset']}";
    $options = array(
      PDO::ATTR_ERRMODE            => $errormode,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    );
    try {
      $pdo = new PDO($dsn, $spec['user'], $spec['pass'], $options);
      if(@$spec['coldKey']) # encryption at rest
        $pdo->coldKey = $spec['coldKey'];
    }
    catch(Exception $e) {
      if(function_exists('xmps')) xmps(['ConnectDB_error'=>$e->getMessage()]);
      return false;
    }

  }
  
  if($pdo && isset($_SESSION['authlist']['@admins']))
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  
  return @$pdo;
}

function mydb($fetch, $args) {
  global $MyDBCache;
  $pdo = ConnectDB(); if(!$pdo) return false;

  $query = array_shift($args);
  if(! count($args)) $bind = null;
  elseif (is_array($args[0])) $bind = $args[0];
  else $bind = $args;
  
  if($bind && preg_match('/\\s+\\(\\s*\\?\\?\\s*\\)/i', $query, $m)) { // values IN ( ?? )
    $qq = implode(', ', array_fill(0, count($bind), '?') );
    $query = str_replace($m[0], " ( $qq )", $query);
  }
  
  $query = preg_replace('!enc5ko\\((.*?)\\)!', "AES_ENCRYPT($1, '{$pdo->coldKey}')", $query);
  $query = preg_replace('!dec5ko\\((.*?)\\)!', "AES_DECRYPT($1, '{$pdo->coldKey}')", $query);
  $query = str_replace('$$coldKey', $pdo->coldKey, $query);
  

  if($query == 'start') {
    $pdo->beginTransaction();
  }
  elseif($query == 'commit') {
    $pdo->commit();
  }
  elseif(is_null($bind)) {
    $x = $pdo->query($query);
  }
  else {
    if(! @$MyDBCache[$query]) {
      $x = $MyDBCache[$query] = @$pdo->prepare($query);
    }
    else $x = $MyDBCache[$query];
    if(!$x->execute($bind)) {
      if(function_exists('xmps')) xmps(['mydb_error'=>$x->errorInfo(), 'query' => $query, 'bind'=>$bind]);
    }
  }
  
  switch($fetch) {
    case 'allcol': return $x->fetchAll(PDO::FETCH_COLUMN); break;
    case 'all': return $x->fetchAll(); break;
    case 'one': return $x->fetch(); break;
    case 'col': return $x->fetchColumn(); break;
    case 'lid': return $x->lastInsertId(); break;
    case 'rowcnt': return $x->rowCount(); break;
  }
}

# $query, $bind=null
function dbz()  { return mydb('rowcnt', func_get_args()); } # db zero
function dblid()  { return mydb('lid', func_get_args()); } # lastInsertId
function db1()  { return mydb('one', func_get_args()); }
function dba()  { return mydb('all', func_get_args()); }
function dbc()  { return mydb('col', func_get_args()); }
function dbac() { return mydb('allcol', func_get_args()); }
function dbak1()  {
  $all = mydb('all', func_get_args());
  $key1 = array();
  foreach($all as $a) {
    $k = array_shift($a);
    if(count($a)==1) $a = array_shift($a);
    $key1[$k] = $a;
  }
  return $key1;
}


function MkTable($data, $opt = []) {

  SDVA($opt, ['class'=>'simpletable sortable filterable']);
  
  $table = '<table';
  foreach($opt as $attr=>$val) {
    $val = PHSC($val, ENT_QUOTES, null, false);
    $table .= " $attr=\"$val\"";
  }
  $table .= "><thead>\n<tr>";
  $header = array_shift($data);
  foreach($header as $h) {
    $c = '';
    if(preg_match('/^\\.(\\w+) (.+)$/', $h, $m)) {
      $c = " class='{$m[1]}'";
      $h = $m[2];
    }
    $table .= "<th$c>".XL($h)."</th>\n";
  }
  $table .= "</tr></thead><tbody>\n";
  
  foreach($data as $row) {
    $table .= "<tr>\n";
    
    foreach($row as $cell) {
      $c = '';
      if(preg_match('/^\\.(\\w+) (.+)$/', $cell, $m)) {
        $c = " class='{$m[1]}'";
        $cell = $m[2];
      }
      $table .= "<td$c>$cell</td>\n";
    }
    $table .= "</tr>\n";
  }
  
  $table .= "</tbody></table>\n";

  return $table;
}

